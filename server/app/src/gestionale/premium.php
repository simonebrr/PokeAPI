<?php
// Funzioni carte e Premium: storico prezzi, aggiornamento automatico, avvisi, quote soci,
// import CSV, foto, report annuale.

const G_GRADERS = ['PSA', 'BGS', 'CGC', 'ACE', 'GetGraded', 'SGC', 'Altro'];
const G_MAX_PHOTOS = 4;

// ---------- Storico prezzi ----------

// Registra un nuovo prezzo solo se diverso dall'ultimo registrato.
function g_record_price(int $wsId, int $itemId, ?float $price, string $source): void
{
    if ($price === null) {
        return;
    }
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT price FROM g_price_history WHERE item_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1');
    $stmt->execute([$itemId]);
    $last = $stmt->fetchColumn();
    if ($last !== false && abs((float)$last - $price) < 0.005) {
        return;
    }
    $pdo->prepare('INSERT INTO g_price_history (workspace_id, item_id, price, source, recorded_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$wsId, $itemId, $price, $source, g_now()]);
}

function g_item_history(int $id): void
{
    $u = g_user();
    g_require_premium($u, 'Lo storico prezzi');
    g_item_get($u, $id);
    $stmt = g_pdo()->prepare('SELECT price, source, recorded_at FROM g_price_history WHERE item_id = ? AND workspace_id = ? ORDER BY recorded_at, id');
    $stmt->execute([$id, $u['workspace_id']]);
    g_json(array_map(fn($r) => ['price' => (float)$r['price'], 'source' => $r['source'], 'at' => $r['recorded_at']], $stmt->fetchAll()));
}

// Valore del magazzino giorno per giorno (snapshot notturni).
function g_value_history(): void
{
    $u = g_user();
    g_require_premium($u, "L'andamento del valore");
    $days = max(7, min(365, (int)($_GET['days'] ?? 90)));
    $stmt = g_pdo()->prepare('SELECT day, stock_value, cards, revenue_total, spent_total FROM g_stock_snapshots
        WHERE workspace_id = ? AND day >= ? ORDER BY day');
    $stmt->execute([$u['workspace_id'], date('Y-m-d', time() - $days * 86400)]);
    g_json(array_map(fn($r) => [
        'day' => $r['day'], 'stock_value' => (float)$r['stock_value'], 'cards' => (int)$r['cards'],
        'revenue_total' => (float)$r['revenue_total'], 'spent_total' => (float)$r['spent_total'],
    ], $stmt->fetchAll()));
}

function g_snapshot_workspace(int $wsId): void
{
    $pdo = g_pdo();
    $stmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN status <> 'sold' THEN COALESCE(est_value, 0) * quantity ELSE 0 END), 0) AS stock,
            COALESCE(SUM(CASE WHEN status <> 'sold' THEN quantity ELSE 0 END), 0) AS cards,
            COALESCE(SUM(CASE WHEN status = 'sold' THEN sale_price - COALESCE(sale_fees, 0) ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN lot_id IS NULL THEN COALESCE(purchase_cost, 0) * quantity ELSE 0 END), 0) AS singles
        FROM g_items WHERE workspace_id = ?");
    $stmt->execute([$wsId]);
    $r = $stmt->fetch();
    $lots = $pdo->prepare('SELECT COALESCE(SUM(cost), 0) FROM g_lots WHERE workspace_id = ?');
    $lots->execute([$wsId]);
    $day = date('Y-m-d');
    $pdo->prepare('DELETE FROM g_stock_snapshots WHERE workspace_id = ? AND day = ?')->execute([$wsId, $day]);
    $pdo->prepare('INSERT INTO g_stock_snapshots (workspace_id, day, stock_value, cards, revenue_total, spent_total) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$wsId, $day, round((float)$r['stock'], 2), (int)$r['cards'], round((float)$r['revenue'], 2),
            round((float)$lots->fetchColumn() + (float)$r['singles'], 2)]);
}

// ---------- Aggiornamento prezzi e avvisi ----------

// Aggiorna il valore di una carta da CardTrader. Usato dal pulsante "in blocco" e dal cron notturno.
function g_refresh_item(array $item, string $strategy): array
{
    $base = ['id' => $item['id'], 'old' => $item['est_value'], 'new' => $item['est_value'], 'quantity' => $item['quantity']];
    if (!$item['blueprint_id']) {
        return $base + ['skipped' => 'no_catalog'];
    }
    if ($item['grading_company']) {
        return $base + ['skipped' => 'graded'];
    }
    $p = g_ct_price($item['blueprint_id'], $item['language'], $item['card_condition'], !$item['image_url']);
    if (!$p['count']) {
        return $base + ['skipped' => 'no_offers'];
    }
    $new = round((float)$p[$strategy === 'median' ? 'median' : 'min'], 2);
    g_pdo()->prepare('UPDATE g_items SET est_value = ?, est_value_updated_at = ?, image_url = COALESCE(image_url, ?) WHERE id = ? AND workspace_id = ?')
        ->execute([$new, g_now(), $p['image_url'] ?? null, $item['id'], $item['workspace_id']]);
    g_record_price($item['workspace_id'], $item['id'], $new, 'cardtrader');
    $base['new'] = $new;
    $base['alerts'] = g_check_alerts($item, $item['est_value'], $new);
    return $base;
}

// Controlla soglie di prezzo e variazioni forti; salva le notifiche nell'app.
function g_check_alerts(array $item, ?float $old, float $new): array
{
    static $pct = [];
    $ws = $item['workspace_id'];
    if (!isset($pct[$ws])) {
        $stmt = g_pdo()->prepare('SELECT alert_pct FROM g_workspaces WHERE id = ?');
        $stmt->execute([$ws]);
        $v = $stmt->fetchColumn();
        $pct[$ws] = ($v === null || $v === false) ? 20 : (int)$v; // default 20%
    }
    $name = $item['name'] . ($item['card_number'] ? ' ' . $item['card_number'] : '');
    $f = fn($v) => number_format($v, 2, ',', '.') . ' €';
    $msgs = [];
    if ($item['alert_above'] !== null && $new >= $item['alert_above'] && ($old === null || $old < $item['alert_above'])) {
        $msgs[] = "🎯 $name ha raggiunto {$f($new)} (soglia {$f($item['alert_above'])})";
    }
    if ($item['alert_below'] !== null && $new <= $item['alert_below'] && ($old === null || $old > $item['alert_below'])) {
        $msgs[] = "📉 $name è sceso a {$f($new)} (soglia {$f($item['alert_below'])})";
    }
    if (!$msgs && $pct[$ws] > 0 && $old !== null && $old >= 1 && abs($new - $old) / $old * 100 >= $pct[$ws]) {
        $diff = round(($new - $old) / $old * 100);
        $msgs[] = ($diff > 0 ? '📈' : '📉') . " $name: {$f($old)} → {$f($new)} (" . ($diff > 0 ? '+' : '') . "$diff%)";
    }
    foreach ($msgs as $m) {
        g_pdo()->prepare('INSERT INTO g_notifications (workspace_id, item_id, message, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$ws, $item['id'], mb_substr($m, 0, 500), g_now()]);
    }
    return $msgs;
}

function g_notifications_list(): void
{
    $u = g_user();
    $stmt = g_pdo()->prepare('SELECT id, item_id, message, created_at FROM g_notifications WHERE workspace_id = ? ORDER BY id DESC LIMIT 30');
    $stmt->execute([$u['workspace_id']]);
    g_json($stmt->fetchAll());
}

function g_workspace_settings(): void
{
    $u = g_user();
    $b = g_body();
    $pct = max(0, min(100, (int)($b['alert_pct'] ?? 20)));
    $strategy = ($b['price_strategy'] ?? 'min') === 'median' ? 'median' : 'min';
    $auto = !empty($b['auto_refresh']) ? 1 : 0;
    g_pdo()->prepare('UPDATE g_workspaces SET alert_pct = ?, price_strategy = ?, auto_refresh = ? WHERE id = ?')
        ->execute([$pct, $strategy, $auto, $u['workspace_id']]);
    g_me();
}

// ---------- Quote soci per lotto ----------

function g_members(int $wsId): array
{
    $stmt = g_pdo()->prepare('SELECT id, name FROM g_users WHERE workspace_id = ? ORDER BY id');
    $stmt->execute([$wsId]);
    return $stmt->fetchAll();
}

// Quote di un lotto: quelle salvate oppure parti uguali tra i soci attuali.
function g_lot_shares_map(int $wsId, int $lotId, array $members): array
{
    $stmt = g_pdo()->prepare('SELECT user_id, share_pct FROM g_lot_shares WHERE lot_id = ? AND workspace_id = ?');
    $stmt->execute([$lotId, $wsId]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(int)$r['user_id']] = (float)$r['share_pct'];
    }
    if (!$map && $members) {
        foreach ($members as $m) {
            $map[(int)$m['id']] = 100 / count($members);
        }
    }
    return $map;
}

function g_lot_shares_get(int $lotId): void
{
    $u = g_user();
    g_lot_get($u, $lotId);
    $members = g_members($u['workspace_id']);
    $stmt = g_pdo()->prepare('SELECT COUNT(*) FROM g_lot_shares WHERE lot_id = ?');
    $stmt->execute([$lotId]);
    g_json(['custom' => (int)$stmt->fetchColumn() > 0, 'members' => $members,
        'shares' => g_lot_shares_map($u['workspace_id'], $lotId, $members)]);
}

function g_lot_shares_set(int $lotId): void
{
    $u = g_user();
    g_require_premium($u, 'La divisione profitti tra soci');
    g_lot_get($u, $lotId);
    $memberIds = array_map(fn($m) => (int)$m['id'], g_members($u['workspace_id']));
    $shares = [];
    foreach ((array)(g_body()['shares'] ?? []) as $userId => $pct) {
        if (!in_array((int)$userId, $memberIds, true)) {
            g_fail('Socio non valido');
        }
        $pct = (float)str_replace(',', '.', (string)$pct);
        if ($pct < 0 || $pct > 100) {
            g_fail('Le quote vanno da 0 a 100');
        }
        if ($pct > 0) {
            $shares[(int)$userId] = round($pct, 2);
        }
    }
    if ($shares && abs(array_sum($shares) - 100) > 0.5) {
        g_fail('La somma delle quote deve essere 100% (ora è ' . round(array_sum($shares), 1) . '%)');
    }
    $pdo = g_pdo();
    $pdo->prepare('DELETE FROM g_lot_shares WHERE lot_id = ? AND workspace_id = ?')->execute([$lotId, $u['workspace_id']]);
    foreach ($shares as $uid => $pct) {
        $pdo->prepare('INSERT INTO g_lot_shares (workspace_id, lot_id, user_id, share_pct) VALUES (?, ?, ?, ?)')
            ->execute([$u['workspace_id'], $lotId, $uid, $pct]);
    }
    g_lot_shares_get($lotId);
}

// Resoconto per socio: quanto ha messo, quanto ha incassato, quanto vale la sua parte di magazzino.
// Lotti: secondo le quote. Carte singole: di chi le ha inserite (parti uguali se non è più socio).
function g_partners_report(): void
{
    $u = g_user();
    g_require_premium($u, 'La divisione profitti tra soci');
    $ws = $u['workspace_id'];
    $pdo = g_pdo();
    $members = g_members($ws);
    $out = [];
    foreach ($members as $m) {
        $out[(int)$m['id']] = ['user_id' => (int)$m['id'], 'name' => $m['name'], 'invested' => 0.0, 'revenue' => 0.0, 'stock_value' => 0.0];
    }
    if (!$out) {
        g_json([]);
    }
    $add = function (array $split, float $invested, float $revenue, float $stock) use (&$out) {
        foreach ($split as $uid => $pct) {
            if (!isset($out[$uid])) {
                continue;
            }
            $out[$uid]['invested'] += $invested * $pct / 100;
            $out[$uid]['revenue'] += $revenue * $pct / 100;
            $out[$uid]['stock_value'] += $stock * $pct / 100;
        }
    };
    $equal = array_fill_keys(array_keys($out), 100 / count($out));

    $stmt = $pdo->prepare(g_lot_stats_sql() . ' WHERE l.workspace_id = ? GROUP BY l.id');
    $stmt->execute([$ws]);
    foreach ($stmt->fetchAll() as $l) {
        $split = g_lot_shares_map($ws, (int)$l['id'], $members);
        $add($split, (float)$l['cost'], (float)$l['revenue'], (float)$l['stock_value']);
    }
    $stmt = $pdo->prepare("SELECT created_by, COALESCE(purchase_cost, 0) * quantity AS cost,
            CASE WHEN status = 'sold' THEN sale_price - COALESCE(sale_fees, 0) ELSE 0 END AS revenue,
            CASE WHEN status <> 'sold' THEN COALESCE(est_value, 0) * quantity ELSE 0 END AS stock
        FROM g_items WHERE workspace_id = ? AND lot_id IS NULL");
    $stmt->execute([$ws]);
    foreach ($stmt->fetchAll() as $i) {
        $owner = (int)$i['created_by'];
        $add(isset($out[$owner]) ? [$owner => 100] : $equal, (float)$i['cost'], (float)$i['revenue'], (float)$i['stock']);
    }
    g_json(array_values(array_map(function ($r) {
        foreach (['invested', 'revenue', 'stock_value'] as $k) {
            $r[$k] = round($r[$k], 2);
        }
        $r['profit'] = round($r['revenue'] - $r['invested'], 2);
        $r['potential'] = round($r['revenue'] + $r['stock_value'] - $r['invested'], 2);
        return $r;
    }, $out)));
}

// ---------- Import CSV ----------

function g_csv_key(string $h): ?string
{
    $h = strtolower(trim($h, " \t\"'\xEF\xBB\xBF"));
    $h = strtr($h, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    $aliases = [
        'name' => ['name', 'nome', 'carta', 'card', 'nome_carta'],
        'set_name' => ['set_name', 'set', 'espansione', 'expansion'],
        'card_number' => ['card_number', 'numero', 'number', 'n', 'num', 'collector_number'],
        'language' => ['language', 'lingua', 'lang'],
        'card_condition' => ['card_condition', 'condizione', 'condition', 'stato_carta'],
        'quantity' => ['quantity', 'quantita', 'qty', 'qta', 'copie'],
        'est_value' => ['est_value', 'valore', 'value', 'valore_stimato', 'prezzo_stimato', 'prezzo'],
        'purchase_cost' => ['purchase_cost', 'costo', 'cost', 'costo_acquisto', 'prezzo_acquisto'],
        'status' => ['status', 'stato'],
        'sale_price' => ['sale_price', 'prezzo_vendita', 'venduta_a', 'incasso'],
        'sale_fees' => ['sale_fees', 'commissioni', 'fees'],
        'sale_date' => ['sale_date', 'data_vendita'],
        'sale_channel' => ['sale_channel', 'canale'],
        'notes' => ['notes', 'note'],
        'lot_title' => ['lot_title', 'lotto', 'lot'],
        'grading_company' => ['grading_company', 'ente', 'grading', 'gradazione'],
        'grade' => ['grade', 'voto'],
        'blueprint_id' => ['blueprint_id'],
    ];
    foreach ($aliases as $key => $list) {
        if (in_array($h, $list, true)) {
            return $key;
        }
    }
    return null;
}

function g_items_import(): void
{
    $u = g_user();
    $b = g_body();
    $csv = (string)($b['csv'] ?? '');
    if (strlen($csv) > 2_000_000) {
        g_fail('File troppo grande (max 2 MB)');
    }
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $csv));
    $lines = array_values(array_filter(explode("\n", $csv), fn($l) => trim($l) !== ''));
    if (count($lines) < 2) {
        g_fail('Il file deve avere una riga di intestazione e almeno una carta');
    }
    if (count($lines) > 2001) {
        g_fail('Massimo 2000 carte per importazione');
    }
    $first = $lines[0];
    $delim = array_search(max($c = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")]), $c, true);
    $header = array_map('g_csv_key', str_getcsv($first, $delim));
    if (!in_array('name', $header, true)) {
        g_fail('Manca la colonna con il nome della carta (es. "nome")');
    }

    $rows = [];
    foreach (array_slice($lines, 1) as $n => $line) {
        $vals = str_getcsv($line, $delim);
        $row = [];
        foreach ($header as $k => $key) {
            if ($key !== null && isset($vals[$k])) {
                $v = trim($vals[$k]);
                $row[$key] = ($v !== '' && $v[0] === "'") ? substr($v, 1) : $v; // protezione formule dell'export
            }
        }
        $rows[$n + 2] = $row;
    }

    $toStock = count(array_filter($rows, fn($r) => g_csv_status($r['status'] ?? '') !== 'sold'));
    if (!g_is_premium($u['workspace_id']) && g_items_in_stock($u['workspace_id']) + $toStock > g_free_item_limit()) {
        g_fail('Con il piano Free puoi avere al massimo ' . g_free_item_limit() . ' carte in magazzino: questo file ne aggiungerebbe '
            . $toStock . '. Passa a Premium o importa meno righe.', 402);
    }

    $defaultLot = !empty($b['lot_id']) ? (int)$b['lot_id'] : null;
    if ($defaultLot) {
        g_lot_get($u, $defaultLot);
    }
    $pdo = g_pdo();
    $lotIds = [];
    $lotsCreated = 0;
    $imported = 0;
    $errors = [];
    $GLOBALS['g_throw'] = true; // gli errori di validazione diventano eccezioni per riga
    $pdo->beginTransaction();
    foreach ($rows as $lineNo => $r) {
        try {
            if (empty($r['name'])) {
                throw new RuntimeException('nome mancante');
            }
            $lotId = $defaultLot;
            if (!$lotId && !empty($r['lot_title'])) {
                $title = mb_substr($r['lot_title'], 0, 190);
                if (!isset($lotIds[$title])) {
                    $stmt = $pdo->prepare('SELECT id FROM g_lots WHERE workspace_id = ? AND title = ?');
                    $stmt->execute([$u['workspace_id'], $title]);
                    $lotIds[$title] = (int)$stmt->fetchColumn();
                    if (!$lotIds[$title]) {
                        $pdo->prepare('INSERT INTO g_lots (workspace_id, title, cost, created_by, created_at) VALUES (?, ?, 0, ?, ?)')
                            ->execute([$u['workspace_id'], $title, $u['id'], g_now()]);
                        $lotIds[$title] = (int)$pdo->lastInsertId();
                        $lotsCreated++;
                    }
                }
                $lotId = $lotIds[$title];
            }
            $r['lot_id'] = $lotId;
            $r['status'] = g_csv_status($r['status'] ?? '');
            if (!empty($r['sale_date']) && preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $r['sale_date'], $m)) {
                $r['sale_date'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            if (isset($r['language'])) {
                $r['language'] = strtolower(substr($r['language'], 0, 2));
            }
            if (isset($r['quantity']) && $r['quantity'] === '') {
                unset($r['quantity']);
            }
            $f = g_item_fields($u, $r);
            $cols = array_keys($f);
            $pdo->prepare('INSERT INTO g_items (workspace_id, created_by, created_at, ' . implode(', ', $cols) . ') VALUES (?, ?, ?, '
                . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_merge([$u['workspace_id'], $u['id'], g_now()], array_values($f)));
            g_record_price($u['workspace_id'], (int)$pdo->lastInsertId(), $f['est_value'], 'manual');
            $imported++;
        } catch (RuntimeException $e) {
            $errors[] = ['line' => $lineNo, 'error' => $e->getMessage()];
        }
    }
    $GLOBALS['g_throw'] = false;
    $pdo->commit();
    g_json(['imported' => $imported, 'lots_created' => $lotsCreated, 'errors' => array_slice($errors, 0, 50), 'error_count' => count($errors)]);
}

function g_csv_status(string $s): string
{
    $s = strtolower(trim($s));
    return match (true) {
        in_array($s, ['sold', 'venduta', 'venduto', 'v'], true) => 'sold',
        in_array($s, ['listed', 'in vendita', 'in_vendita'], true) => 'listed',
        default => 'in_stock',
    };
}

// ---------- Foto ----------

function g_photo_dir(int $wsId): string
{
    return g_storage_dir() . "/ws$wsId/items";
}

function g_item_photo_add(int $id): void
{
    $u = g_user();
    $item = g_item_get($u, $id);
    if (count($item['photos']) >= G_MAX_PHOTOS) {
        g_fail('Massimo ' . G_MAX_PHOTOS . ' foto per carta');
    }
    $data = (string)(g_body()['data'] ?? '');
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $data, $m)) {
        g_fail('Formato immagine non supportato (JPG, PNG o WebP)');
    }
    $bin = base64_decode(substr($data, strpos($data, ',') + 1), true);
    if ($bin === false || strlen($bin) > 3 * 1024 * 1024) {
        g_fail('Immagine non valida o troppo grande (max 3 MB)');
    }
    $info = @getimagesizefromstring($bin);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) {
        g_fail('Il file non è un\'immagine valida');
    }
    $dir = g_photo_dir($u['workspace_id']);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        g_fail('Archivio foto non disponibile', 500);
    }
    $name = $id . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    file_put_contents("$dir/$name", $bin);
    $photos = array_merge($item['photos'], [$name]);
    g_pdo()->prepare('UPDATE g_items SET photos = ? WHERE id = ? AND workspace_id = ?')
        ->execute([json_encode($photos), $id, $u['workspace_id']]);
    g_json(g_item_get($u, $id), 201);
}

function g_item_photo_name(array $item, string $name): string
{
    if (!in_array($name, $item['photos'], true) || !preg_match('/^\d+-[0-9a-f]{12}\.(jpg|png|webp)$/', $name)) {
        g_fail('Foto non trovata', 404);
    }
    return $name;
}

function g_item_photo_get(int $id, string $name): void
{
    $u = g_user();
    $item = g_item_get($u, $id);
    $file = g_photo_dir($u['workspace_id']) . '/' . g_item_photo_name($item, $name);
    if (!is_file($file)) {
        g_fail('Foto non trovata', 404);
    }
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

function g_item_photo_delete(int $id, string $name): void
{
    $u = g_user();
    $item = g_item_get($u, $id);
    $name = g_item_photo_name($item, $name);
    @unlink(g_photo_dir($u['workspace_id']) . "/$name");
    $photos = array_values(array_diff($item['photos'], [$name]));
    g_pdo()->prepare('UPDATE g_items SET photos = ? WHERE id = ? AND workspace_id = ?')
        ->execute([$photos ? json_encode($photos) : null, $id, $u['workspace_id']]);
    g_json(g_item_get($u, $id));
}

function g_item_files_delete(array $item): void
{
    foreach ($item['photos'] ?? [] as $name) {
        if (preg_match('/^\d+-[0-9a-f]{12}\.(jpg|png|webp)$/', $name)) {
            @unlink(g_photo_dir($item['workspace_id']) . "/$name");
        }
    }
}

// ---------- Report annuale ----------

function g_annual_data(int $ws, int $year): array
{
    $pdo = g_pdo();
    $from = "$year-01-01";
    $to = ($year + 1) . '-01-01';
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $months[sprintf('%04d-%02d', $year, $m)] = ['month' => sprintf('%04d-%02d', $year, $m), 'purchases' => 0.0, 'gross' => 0.0, 'fees' => 0.0, 'net' => 0.0, 'sold' => 0];
    }
    $purchases = [];
    $stmt = $pdo->prepare('SELECT title, COALESCE(purchase_date, SUBSTR(created_at, 1, 10)) AS d, cost, source FROM g_lots
        WHERE workspace_id = ? AND COALESCE(purchase_date, SUBSTR(created_at, 1, 10)) >= ? AND COALESCE(purchase_date, SUBSTR(created_at, 1, 10)) < ? ORDER BY d');
    $stmt->execute([$ws, $from, $to]);
    foreach ($stmt->fetchAll() as $l) {
        $purchases[] = ['date' => $l['d'], 'what' => 'Lotto: ' . $l['title'], 'amount' => round((float)$l['cost'], 2), 'source' => $l['source']];
    }
    $stmt = $pdo->prepare('SELECT name, SUBSTR(created_at, 1, 10) AS d, COALESCE(purchase_cost, 0) * quantity AS cost FROM g_items
        WHERE workspace_id = ? AND lot_id IS NULL AND purchase_cost IS NOT NULL AND created_at >= ? AND created_at < ? ORDER BY d');
    $stmt->execute([$ws, $from, $to]);
    foreach ($stmt->fetchAll() as $i) {
        $purchases[] = ['date' => $i['d'], 'what' => 'Carta: ' . $i['name'], 'amount' => round((float)$i['cost'], 2), 'source' => null];
    }
    foreach ($purchases as $p) {
        $months[substr($p['date'], 0, 7)]['purchases'] += $p['amount'];
    }
    $stmt = $pdo->prepare("SELECT name, card_number, quantity, sale_date, sale_price, COALESCE(sale_fees, 0) AS fees, sale_channel FROM g_items
        WHERE workspace_id = ? AND status = 'sold' AND sale_date >= ? AND sale_date < ? ORDER BY sale_date");
    $stmt->execute([$ws, $from, $to]);
    $sales = [];
    $channels = [];
    foreach ($stmt->fetchAll() as $s) {
        $gross = (float)$s['sale_price'];
        $fees = (float)$s['fees'];
        $k = substr($s['sale_date'], 0, 7);
        $months[$k]['gross'] += $gross;
        $months[$k]['fees'] += $fees;
        $months[$k]['net'] += $gross - $fees;
        $months[$k]['sold'] += (int)$s['quantity'];
        $ch = $s['sale_channel'] ?: 'Non indicato';
        $channels[$ch] = ($channels[$ch] ?? 0) + $gross - $fees;
        $sales[] = ['date' => $s['sale_date'], 'what' => $s['name'] . ($s['card_number'] ? ' ' . $s['card_number'] : ''), 'quantity' => (int)$s['quantity'],
            'gross' => round($gross, 2), 'fees' => round($fees, 2), 'net' => round($gross - $fees, 2), 'channel' => $s['sale_channel']];
    }
    $sum = fn($k) => round(array_sum(array_column($months, $k)), 2);
    arsort($channels);
    return [
        'year' => $year,
        'totals' => ['purchases' => $sum('purchases'), 'gross' => $sum('gross'), 'fees' => $sum('fees'), 'net' => $sum('net'),
            'sold' => (int)$sum('sold'), 'balance' => round($sum('net') - $sum('purchases'), 2)],
        'months' => array_values(array_map(function ($m) {
            foreach (['purchases', 'gross', 'fees', 'net'] as $k) {
                $m[$k] = round($m[$k], 2);
            }
            return $m;
        }, $months)),
        'channels' => array_map(fn($c, $v) => ['channel' => $c, 'net' => round($v, 2)], array_keys($channels), $channels),
        'purchases' => $purchases,
        'sales' => $sales,
    ];
}

function g_annual_years(int $ws): array
{
    $pdo = g_pdo();
    $years = [(int)date('Y')];
    foreach ([
        "SELECT DISTINCT SUBSTR(sale_date, 1, 4) FROM g_items WHERE workspace_id = ? AND sale_date IS NOT NULL",
        'SELECT DISTINCT SUBSTR(COALESCE(purchase_date, created_at), 1, 4) FROM g_lots WHERE workspace_id = ?',
    ] as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ws]);
        $years = array_merge($years, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
    $years = array_unique(array_filter($years));
    rsort($years);
    return array_values($years);
}

function g_annual_report(): void
{
    $u = g_user();
    g_require_premium($u, 'Il report annuale');
    $year = (int)($_GET['year'] ?? date('Y'));
    g_json(g_annual_data($u['workspace_id'], $year) + ['years' => g_annual_years($u['workspace_id'])]);
}

function g_annual_csv(): void
{
    $u = g_user();
    g_require_premium($u, 'Il report annuale');
    $year = (int)($_GET['year'] ?? date('Y'));
    $d = g_annual_data($u['workspace_id'], $year);
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"report-$year.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = fn($v) => (is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
    fputcsv($out, ["Report $year"], ';');
    fputcsv($out, ['Acquisti', $d['totals']['purchases']], ';');
    fputcsv($out, ['Vendite lorde', $d['totals']['gross']], ';');
    fputcsv($out, ['Commissioni', $d['totals']['fees']], ';');
    fputcsv($out, ['Vendite nette', $d['totals']['net']], ';');
    fputcsv($out, ['Saldo', $d['totals']['balance']], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Tipo', 'Data', 'Descrizione', 'Quantità', 'Lordo', 'Commissioni', 'Netto', 'Canale/Provenienza'], ';');
    foreach ($d['purchases'] as $p) {
        fputcsv($out, array_map($safe, ['Acquisto', $p['date'], $p['what'], '', -$p['amount'], '', -$p['amount'], $p['source'] ?? '']), ';');
    }
    foreach ($d['sales'] as $s) {
        fputcsv($out, array_map($safe, ['Vendita', $s['date'], $s['what'], $s['quantity'], $s['gross'], $s['fees'], $s['net'], $s['channel'] ?? '']), ';');
    }
    exit;
}
