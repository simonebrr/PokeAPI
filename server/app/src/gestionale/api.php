<?php
// API JSON del gestionale: /api/...
// Autenticazione con sessione PHP (cookie httpOnly). Ogni dato è isolato per workspace.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/premium.php';
require_once __DIR__ . '/growth.php';
require_once __DIR__ . '/paypal.php';
require_once __DIR__ . '/sealed.php';

const G_STATUSES = ['in_stock', 'listed', 'sold'];

function g_json($data, int $status = 200): void
{
    if (defined('G_CLI') || !empty($GLOBALS['g_throw'])) {
        if ($status >= 400) {
            throw new RuntimeException($data['error'] ?? "errore $status");
        }
        return;
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function g_fail(string $msg, int $status = 400): void
{
    g_json(['error' => $msg], $status);
}

function g_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        g_fail('JSON non valido');
    }
    return $data;
}

function g_now(): string
{
    return date('Y-m-d H:i:s');
}

function g_str($v, int $max = 190): ?string
{
    if ($v === null) {
        return null;
    }
    $v = trim((string)$v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

function g_money($v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    $v = str_replace(',', '.', (string)$v);
    if (!is_numeric($v)) {
        g_fail('Importo non valido: ' . $v);
    }
    return round((float)$v, 2);
}

function g_date($v): ?string
{
    $v = g_str($v, 10);
    if ($v === null) {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        g_fail('Data non valida (usa AAAA-MM-GG)');
    }
    return $v;
}

function g_invite_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function g_user(): array
{
    if (empty($_SESSION['uid'])) {
        g_fail('Non autenticato', 401);
    }
    $stmt = g_pdo()->prepare('SELECT id, workspace_id, name, email, email_verified_at, referral_code FROM g_users WHERE id = ?');
    $stmt->execute([$_SESSION['uid']]);
    $u = $stmt->fetch();
    if (!$u) {
        session_destroy();
        g_fail('Non autenticato', 401);
    }
    $u['id'] = (int)$u['id'];
    $u['workspace_id'] = (int)$u['workspace_id'];
    return $u;
}

// ---------- Auth ----------

function g_register(): void
{
    $b = g_body();
    $name = g_str($b['name'] ?? null, 120);
    $email = strtolower((string)g_str($b['email'] ?? null));
    $password = (string)($b['password'] ?? '');
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        g_fail('Nome ed email validi sono obbligatori');
    }
    if (strlen($password) < 8) {
        g_fail('La password deve avere almeno 8 caratteri');
    }
    if (empty($b['accept_terms'])) {
        g_fail('Devi accettare termini e privacy');
    }
    g_rate_limit('register', '', PHP_INT_MAX, 10, 60);
    g_rate_hit('register', '');
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT id FROM g_users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        g_fail('Email già registrata', 409);
    }

    $referrer = g_referrer_id($b['ref'] ?? null);
    $pdo->beginTransaction();
    $workspaceId = null;
    $invite = g_str($b['invite_code'] ?? null, 16);
    if ($invite) {
        $stmt = $pdo->prepare('SELECT id FROM g_workspaces WHERE invite_code = ?');
        $stmt->execute([strtoupper($invite)]);
        $workspaceId = $stmt->fetchColumn();
        if (!$workspaceId) {
            $pdo->rollBack();
            g_fail('Codice invito non valido', 404);
        }
        $members = $pdo->prepare('SELECT COUNT(*) FROM g_users WHERE workspace_id = ?');
        $members->execute([$workspaceId]);
        if ((int)$members->fetchColumn() >= 1 && !g_is_premium((int)$workspaceId)) {
            $pdo->rollBack();
            g_check_can_add_member((int)$workspaceId); // risponde con l'errore Premium
        }
    } else {
        $pdo->prepare("INSERT INTO g_workspaces (name, invite_code, created_at, plan, trial_ends_at) VALUES (?, ?, ?, 'trial', ?)")
            ->execute(['Negozio di ' . $name, g_invite_code(), g_now(),
                date('Y-m-d H:i:s', time() + ($referrer ? max(g_trial_days(), G_REFERRAL_DAYS) : g_trial_days()) * 86400)]);
        $workspaceId = $pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO g_users (workspace_id, name, email, password_hash, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$workspaceId, $name, $email, password_hash($password, PASSWORD_DEFAULT), g_now()]);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE g_users SET referral_code = ?, referred_by = ? WHERE id = ?')->execute([g_invite_code(), $referrer, $uid]);
    $pdo->commit();
    g_track((int)$workspaceId, $referrer ? 'signup_referral' : 'signup');

    g_send_verification($uid, $email, $name);
    g_send_welcome($email, $name);
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    g_me();
}

function g_login(): void
{
    $b = g_body();
    $email = strtolower(trim((string)($b['email'] ?? '')));
    g_rate_limit('login', $email, 5, 20, 15);
    $stmt = g_pdo()->prepare('SELECT id, password_hash FROM g_users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify((string)($b['password'] ?? ''), $u['password_hash'])) {
        g_rate_hit('login', $email);
        g_fail('Email o password errate', 401);
    }
    g_rate_clear('login', $email);
    g_pdo()->prepare('UPDATE g_users SET last_login_at = ? WHERE id = ?')->execute([g_now(), $u['id']]);
    $stmt = g_pdo()->prepare('SELECT workspace_id FROM g_users WHERE id = ?');
    $stmt->execute([$u['id']]);
    g_track((int)$stmt->fetchColumn(), 'login');
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    g_me();
}

function g_logout(): void
{
    $_SESSION = [];
    session_destroy();
    g_json(['ok' => true]);
}

function g_me(): void
{
    $u = g_user();
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT id, name, invite_code, alert_pct, price_strategy, auto_refresh FROM g_workspaces WHERE id = ?');
    $stmt->execute([$u['workspace_id']]);
    $ws = $stmt->fetch();
    $ws['alert_pct'] = $ws['alert_pct'] === null ? 20 : (int)$ws['alert_pct'];
    $ws['auto_refresh'] = (int)$ws['auto_refresh'];
    $stmt = $pdo->prepare('SELECT id, name, email FROM g_users WHERE workspace_id = ? ORDER BY id');
    $stmt->execute([$u['workspace_id']]);
    $u['is_admin'] = g_is_admin($u);
    g_json(['user' => $u, 'workspace' => $ws, 'members' => $stmt->fetchAll(), 'billing' => g_billing_info($u),
        'referral' => g_referral_info($u), 'games' => G_GAMES]);
}

function g_workspace_update(): void
{
    $u = g_user();
    $name = g_str(g_body()['name'] ?? null, 120);
    if (!$name) {
        g_fail('Nome obbligatorio');
    }
    g_pdo()->prepare('UPDATE g_workspaces SET name = ? WHERE id = ?')->execute([$name, $u['workspace_id']]);
    g_me();
}

function g_workspace_join(): void
{
    $u = g_user();
    $code = strtoupper((string)g_str(g_body()['code'] ?? null, 16));
    $stmt = g_pdo()->prepare('SELECT id FROM g_workspaces WHERE invite_code = ?');
    $stmt->execute([$code]);
    $wsId = $stmt->fetchColumn();
    if (!$wsId) {
        g_fail('Codice invito non valido', 404);
    }
    g_check_can_add_member((int)$wsId);
    g_pdo()->prepare('UPDATE g_users SET workspace_id = ? WHERE id = ?')->execute([$wsId, $u['id']]);
    g_me();
}

// ---------- Lotti ----------

function g_lot_stats_sql(): string
{
    // Valore trovato = venduto (netto) + valore stimato dell'invenduto.
    return "
        SELECT l.*,
            COALESCE(SUM(i.quantity), 0) AS cards,
            COALESCE(SUM(CASE WHEN i.status = 'sold' THEN i.quantity ELSE 0 END), 0) AS cards_sold,
            COALESCE(SUM(CASE WHEN i.status = 'sold' THEN i.sale_price - COALESCE(i.sale_fees, 0) ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN i.status <> 'sold' THEN COALESCE(i.est_value, 0) * i.quantity ELSE 0 END), 0) AS stock_value
        FROM g_lots l
        LEFT JOIN g_items i ON i.lot_id = l.id
    ";
}

function g_lot_format(array $l): array
{
    foreach (['id', 'workspace_id', 'created_by', 'cards', 'cards_sold'] as $k) {
        $l[$k] = $l[$k] === null ? null : (int)$l[$k];
    }
    foreach (['cost', 'revenue', 'stock_value'] as $k) {
        $l[$k] = round((float)$l[$k], 2);
    }
    $l['found_value'] = round($l['revenue'] + $l['stock_value'], 2);
    $l['profit'] = round($l['revenue'] - $l['cost'], 2);
    $l['roi'] = $l['cost'] > 0 ? round($l['found_value'] / $l['cost'], 2) : null;
    return $l;
}

function g_lot_get(array $u, int $id): array
{
    $stmt = g_pdo()->prepare(g_lot_stats_sql() . ' WHERE l.id = ? AND l.workspace_id = ? GROUP BY l.id');
    $stmt->execute([$id, $u['workspace_id']]);
    $l = $stmt->fetch();
    if (!$l) {
        g_fail('Lotto non trovato', 404);
    }
    return g_lot_format($l);
}

function g_lots_list(): void
{
    $u = g_user();
    $stmt = g_pdo()->prepare(g_lot_stats_sql() . ' WHERE l.workspace_id = ? GROUP BY l.id ORDER BY l.purchase_date DESC, l.id DESC');
    $stmt->execute([$u['workspace_id']]);
    g_json(array_map('g_lot_format', $stmt->fetchAll()));
}

function g_lot_fields(array $b): array
{
    $title = g_str($b['title'] ?? null);
    if (!$title) {
        g_fail('Il nome del lotto è obbligatorio');
    }
    return [
        'title' => $title,
        'purchase_date' => g_date($b['purchase_date'] ?? null),
        'cost' => g_money($b['cost'] ?? 0) ?? 0,
        'source' => g_str($b['source'] ?? null),
        'notes' => g_str($b['notes'] ?? null, 5000),
    ];
}

function g_lot_create(): void
{
    $u = g_user();
    $f = g_lot_fields(g_body());
    $pdo = g_pdo();
    $pdo->prepare('INSERT INTO g_lots (workspace_id, title, purchase_date, cost, source, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$u['workspace_id'], $f['title'], $f['purchase_date'], $f['cost'], $f['source'], $f['notes'], $u['id'], g_now()]);
    g_json(g_lot_get($u, (int)$pdo->lastInsertId()), 201);
}

function g_lot_show(int $id): void
{
    $u = g_user();
    $lot = g_lot_get($u, $id);
    $stmt = g_pdo()->prepare('SELECT * FROM g_items WHERE lot_id = ? AND workspace_id = ? ORDER BY est_value DESC, id DESC');
    $stmt->execute([$id, $u['workspace_id']]);
    $lot['items'] = array_map('g_item_format', $stmt->fetchAll());
    g_json($lot);
}

function g_lot_update(int $id): void
{
    $u = g_user();
    g_lot_get($u, $id);
    $f = g_lot_fields(g_body());
    g_pdo()->prepare('UPDATE g_lots SET title = ?, purchase_date = ?, cost = ?, source = ?, notes = ? WHERE id = ? AND workspace_id = ?')
        ->execute([$f['title'], $f['purchase_date'], $f['cost'], $f['source'], $f['notes'], $id, $u['workspace_id']]);
    g_json(g_lot_get($u, $id));
}

function g_lot_delete(int $id): void
{
    $u = g_user();
    g_lot_get($u, $id);
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT * FROM g_items WHERE lot_id = ? AND workspace_id = ?');
    $stmt->execute([$id, $u['workspace_id']]);
    $items = array_map('g_item_format', $stmt->fetchAll());
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM g_price_history WHERE workspace_id = ? AND item_id IN (SELECT id FROM g_items WHERE lot_id = ?)')->execute([$u['workspace_id'], $id]);
    $pdo->prepare('DELETE FROM g_lot_shares WHERE lot_id = ? AND workspace_id = ?')->execute([$id, $u['workspace_id']]);
    $pdo->prepare('DELETE FROM g_items WHERE lot_id = ? AND workspace_id = ?')->execute([$id, $u['workspace_id']]);
    $pdo->prepare('DELETE FROM g_lots WHERE id = ? AND workspace_id = ?')->execute([$id, $u['workspace_id']]);
    $pdo->commit();
    array_map('g_item_files_delete', $items);
    g_json(['ok' => true]);
}

// ---------- Carte ----------

function g_item_format(array $i): array
{
    foreach (['id', 'workspace_id', 'lot_id', 'blueprint_id', 'quantity', 'created_by'] as $k) {
        $i[$k] = $i[$k] === null ? null : (int)$i[$k];
    }
    foreach (['purchase_cost', 'est_value', 'sale_price', 'sale_fees', 'alert_above', 'alert_below'] as $k) {
        $i[$k] = ($i[$k] ?? null) === null ? null : round((float)$i[$k], 2);
    }
    $i['photos'] = json_decode((string)($i['photos'] ?? ''), true) ?: [];
    return $i;
}

function g_item_get(array $u, int $id): array
{
    $stmt = g_pdo()->prepare('SELECT * FROM g_items WHERE id = ? AND workspace_id = ?');
    $stmt->execute([$id, $u['workspace_id']]);
    $i = $stmt->fetch();
    if (!$i) {
        g_fail('Carta non trovata', 404);
    }
    return g_item_format($i);
}

function g_item_fields(array $u, array $b): array
{
    $name = g_str($b['name'] ?? null);
    if (!$name) {
        g_fail('Il nome della carta è obbligatorio');
    }
    $lotId = isset($b['lot_id']) && $b['lot_id'] !== '' && $b['lot_id'] !== null ? (int)$b['lot_id'] : null;
    if ($lotId) {
        g_lot_get($u, $lotId);
    }
    $status = $b['status'] ?? 'in_stock';
    if (!in_array($status, G_STATUSES, true)) {
        g_fail('Stato non valido');
    }
    $qty = (int)($b['quantity'] ?? 1);
    if ($qty < 1) {
        g_fail('Quantità minima 1');
    }
    $f = [
        'lot_id' => $lotId,
        'blueprint_id' => !empty($b['blueprint_id']) ? (int)$b['blueprint_id'] : null,
        'name' => $name,
        'set_name' => g_str($b['set_name'] ?? null),
        'card_number' => g_str($b['card_number'] ?? null, 50),
        'language' => g_str($b['language'] ?? null, 10),
        'card_condition' => g_str($b['card_condition'] ?? null, 40),
        'quantity' => $qty,
        'purchase_cost' => g_money($b['purchase_cost'] ?? null),
        'est_value' => g_money($b['est_value'] ?? null),
        'status' => $status,
        'sale_price' => g_money($b['sale_price'] ?? null),
        'sale_fees' => g_money($b['sale_fees'] ?? null),
        'sale_date' => g_date($b['sale_date'] ?? null),
        'sale_channel' => g_str($b['sale_channel'] ?? null, 80),
        'image_url' => g_str($b['image_url'] ?? null, 2048),
        'notes' => g_str($b['notes'] ?? null, 5000),
        'grading_company' => g_str($b['grading_company'] ?? null, 20),
        'game' => isset(G_GAMES[$b['game'] ?? '']) && ($b['game'] ?? '') !== 'pokemon' ? $b['game'] : null,
        'grade' => g_str($b['grade'] ?? null, 10),
        'alert_above' => g_money($b['alert_above'] ?? null),
        'alert_below' => g_money($b['alert_below'] ?? null),
    ];
    if ($f['grading_company'] && !in_array($f['grading_company'], G_GRADERS, true)) {
        g_fail('Ente di gradazione non valido');
    }
    if (!$f['grading_company']) {
        $f['grade'] = null;
    }
    $f['image_url'] = g_ct_image($f['image_url']);
    if ($status === 'sold') {
        if ($f['sale_price'] === null) {
            g_fail('Inserisci il prezzo di vendita');
        }
        $f['sale_date'] = $f['sale_date'] ?? date('Y-m-d');
    }
    return $f;
}

function g_items_list(): void
{
    $u = g_user();
    $where = ['i.workspace_id = ?'];
    $params = [$u['workspace_id']];
    if (!empty($_GET['status']) && in_array($_GET['status'], G_STATUSES, true)) {
        $where[] = 'i.status = ?';
        $params[] = $_GET['status'];
    } elseif (($_GET['status'] ?? '') === 'unsold') {
        $where[] = "i.status <> 'sold'";
    }
    if (!empty($_GET['lot_id'])) {
        $where[] = 'i.lot_id = ?';
        $params[] = (int)$_GET['lot_id'];
    }
    if (!empty($_GET['q'])) {
        $where[] = '(LOWER(i.name) LIKE ? OR LOWER(i.set_name) LIKE ? OR i.card_number LIKE ?)';
        $q = '%' . mb_strtolower(trim($_GET['q'])) . '%';
        array_push($params, $q, $q, $q);
    }
    $order = ($_GET['status'] ?? '') === 'sold' ? 'i.sale_date DESC, i.id DESC' : 'i.est_value DESC, i.id DESC';
    $stmt = g_pdo()->prepare('SELECT i.*, l.title AS lot_title FROM g_items i LEFT JOIN g_lots l ON l.id = i.lot_id WHERE '
        . implode(' AND ', $where) . " ORDER BY $order LIMIT 1000");
    $stmt->execute($params);
    g_json(array_map('g_item_format', $stmt->fetchAll()));
}

function g_item_create(): void
{
    $u = g_user();
    g_check_item_limit($u);
    $f = g_item_fields($u, g_body());
    $cols = array_keys($f);
    $pdo = g_pdo();
    $sql = 'INSERT INTO g_items (workspace_id, created_by, created_at, ' . implode(', ', $cols) . ') VALUES (?, ?, ?, '
        . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $pdo->prepare($sql)->execute(array_merge([$u['workspace_id'], $u['id'], g_now()], array_values($f)));
    $newId = (int)$pdo->lastInsertId();
    g_record_price($u['workspace_id'], $newId, $f['est_value'], 'manual');
    g_track($u['workspace_id'], $f['status'] === 'sold' ? 'item_sold' : 'item_create');
    g_json(g_item_get($u, $newId), 201);
}

function g_item_update(int $id): void
{
    $u = g_user();
    $current = g_item_get($u, $id);
    // Aggiornamento parziale: i campi non inviati restano come sono.
    $f = g_item_fields($u, array_merge($current, g_body()));
    if ($current['status'] === 'sold' && $f['status'] !== 'sold') {
        g_check_item_limit($u);
    }
    if ($f['status'] !== 'sold') {
        $f['sale_price'] = $f['sale_fees'] = $f['sale_date'] = $f['sale_channel'] = null;
    }
    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($f)));
    g_pdo()->prepare("UPDATE g_items SET $set WHERE id = ? AND workspace_id = ?")
        ->execute(array_merge(array_values($f), [$id, $u['workspace_id']]));
    if ($f['est_value'] !== $current['est_value']) {
        g_record_price($u['workspace_id'], $id, $f['est_value'], 'manual');
    }
    if ($f['status'] === 'sold' && $current['status'] !== 'sold') {
        g_track($u['workspace_id'], 'item_sold');
    }
    g_json(g_item_get($u, $id));
}

function g_item_delete(int $id): void
{
    $u = g_user();
    $item = g_item_get($u, $id);
    $pdo = g_pdo();
    $pdo->prepare('DELETE FROM g_price_history WHERE item_id = ? AND workspace_id = ?')->execute([$id, $u['workspace_id']]);
    $pdo->prepare('DELETE FROM g_items WHERE id = ? AND workspace_id = ?')->execute([$id, $u['workspace_id']]);
    g_item_files_delete($item);
    g_json(['ok' => true]);
}

// ---------- Dashboard ----------

function g_dashboard(): void
{
    $u = g_user();
    $pdo = g_pdo();
    $ws = $u['workspace_id'];

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(cost), 0) AS lots_cost, COUNT(*) AS lots FROM g_lots WHERE workspace_id = ?');
    $stmt->execute([$ws]);
    $lots = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN lot_id IS NULL THEN COALESCE(purchase_cost, 0) * quantity ELSE 0 END), 0) AS singles_cost,
            COALESCE(SUM(CASE WHEN status = 'sold' THEN sale_price - COALESCE(sale_fees, 0) ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN status <> 'sold' THEN COALESCE(est_value, 0) * quantity ELSE 0 END), 0) AS stock_value,
            COALESCE(SUM(CASE WHEN status <> 'sold' THEN quantity ELSE 0 END), 0) AS cards_in_stock,
            COALESCE(SUM(CASE WHEN status = 'listed' THEN quantity ELSE 0 END), 0) AS cards_listed,
            COALESCE(SUM(CASE WHEN status = 'sold' THEN quantity ELSE 0 END), 0) AS cards_sold
        FROM g_items WHERE workspace_id = ?
    ");
    $stmt->execute([$ws]);
    $it = $stmt->fetch();

    $spent = (float)$lots['lots_cost'] + (float)$it['singles_cost'];
    $revenue = (float)$it['revenue'];
    $stock = (float)$it['stock_value'];

    $stmt = $pdo->prepare("SELECT * FROM g_items WHERE workspace_id = ? AND status <> 'sold' ORDER BY est_value DESC LIMIT 5");
    $stmt->execute([$ws]);
    $top = array_map('g_item_format', $stmt->fetchAll());

    $stmt = $pdo->prepare("SELECT * FROM g_items WHERE workspace_id = ? AND status = 'sold' ORDER BY sale_date DESC, id DESC LIMIT 5");
    $stmt->execute([$ws]);
    $recent = array_map('g_item_format', $stmt->fetchAll());

    $stmt = $pdo->prepare(g_lot_stats_sql() . ' WHERE l.workspace_id = ? GROUP BY l.id');
    $stmt->execute([$ws]);
    $allLots = array_map('g_lot_format', $stmt->fetchAll());
    usort($allLots, fn($a, $b) => ($b['roi'] ?? 0) <=> ($a['roi'] ?? 0));

    g_json([
        'spent' => round($spent, 2),
        'revenue' => round($revenue, 2),
        'stock_value' => round($stock, 2),
        'balance' => round($revenue - $spent, 2),
        'potential' => round($revenue + $stock - $spent, 2),
        'lots' => (int)$lots['lots'],
        'cards_in_stock' => (int)$it['cards_in_stock'],
        'cards_listed' => (int)$it['cards_listed'],
        'cards_sold' => (int)$it['cards_sold'],
        'top_items' => $top,
        'recent_sales' => $recent,
        'best_lots' => array_slice($allLots, 0, 5),
        'months' => g_is_premium($ws) ? g_monthly($ws) : null,
    ]);
}

// Incassi netti e spese per mese (ultimi 12 mesi).
function g_monthly(int $ws): array
{
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $months[date('Y-m', strtotime("first day of -$i month"))] = ['revenue' => 0.0, 'spent' => 0.0];
    }
    $pdo = g_pdo();
    $queries = [
        'revenue' => "SELECT SUBSTR(sale_date, 1, 7) AS m, SUM(sale_price - COALESCE(sale_fees, 0)) AS v
                      FROM g_items WHERE workspace_id = ? AND status = 'sold' AND sale_date IS NOT NULL GROUP BY SUBSTR(sale_date, 1, 7)",
        'spent_lots' => 'SELECT SUBSTR(COALESCE(purchase_date, created_at), 1, 7) AS m, SUM(cost) AS v
                      FROM g_lots WHERE workspace_id = ? GROUP BY SUBSTR(COALESCE(purchase_date, created_at), 1, 7)',
        'spent_items' => 'SELECT SUBSTR(created_at, 1, 7) AS m, SUM(COALESCE(purchase_cost, 0) * quantity) AS v
                      FROM g_items WHERE workspace_id = ? AND lot_id IS NULL GROUP BY SUBSTR(created_at, 1, 7)',
    ];
    foreach ($queries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ws]);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']][$key === 'revenue' ? 'revenue' : 'spent'] += (float)$r['v'];
            }
        }
    }
    $out = [];
    foreach ($months as $m => $v) {
        $out[] = ['month' => $m, 'revenue' => round($v['revenue'], 2), 'spent' => round($v['spent'], 2),
            'profit' => round($v['revenue'] - $v['spent'], 2)];
    }
    return $out;
}

// ---------- Catalogo (dati CardTrader già importati) ----------

// CardTrader restituisce spesso percorsi relativi ("/uploads/..."): li rende assoluti.
function g_ct_image(?string $url): ?string
{
    if (!$url) {
        return null;
    }
    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }
    if (str_starts_with($url, '/')) {
        return 'https://www.cardtrader.com' . $url;
    }
    return preg_match('#^https?://#i', $url) ? $url : null;
}

function g_ct_get(string $path, array $query): array
{
    static $cache = [];
    $key = $path . '?' . http_build_query($query);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (count($cache) > 300) {
        $cache = [];
    }
    return $cache[$key] = g_ct_fetch($path, $query);
}

function g_ct_fetch(string $path, array $query): array
{
    $token = cardtrader_token();
    if (!$token) {
        g_fail('Token CardTrader non configurato (CARDTRADER_TOKEN in server/.env)', 503);
    }
    $base = getenv('CARDTRADER_API_URL') ?: 'https://api.cardtrader.com/api/v2';
    $ch = curl_init($base . $path . '?' . http_build_query($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $http === 0) {
        g_fail('CardTrader non raggiungibile: ' . $curlErr, 502);
    }
    if ($http === 401 || $http === 403) {
        g_fail('Token CardTrader non valido o scaduto', 502);
    }
    if ($http !== 200) {
        g_fail("CardTrader ha risposto con errore $http", 502);
    }
    $data = json_decode((string)$resp, true);
    return is_array($data) ? $data : [];
}

// Immagine e numero della carta, dall'export dell'espansione (i prodotti non li includono).
function g_ct_blueprint(int $bp, int $expansionId): array
{
    foreach (g_ct_get('/blueprints/export', ['expansion_id' => $expansionId]) as $b) {
        if ((int)($b['id'] ?? 0) === $bp) {
            return [
                'image_url' => g_ct_image($b['image_url'] ?? ($b['image']['show']['url'] ?? null)),
                'card_number' => isset($b['fixed_properties']['collector_number'])
                    ? explode(' ', $b['fixed_properties']['collector_number'])[0] : null,
            ];
        }
    }
    return [];
}

function g_catalog_search(): void
{
    g_user();
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    if (mb_strlen($q) < 2) {
        g_json([]);
    }
    // Supporta "pikachu 25/102": l'ultimo token con cifre è il numero carta.
    $number = null;
    if (preg_match('/^(.*?)\s+(\S*\d\S*)$/u', $q, $m)) {
        $q = trim($m[1]);
        $number = $m[2];
    } elseif (preg_match('#^\d{1,3}/\d{1,3}$#', $q)) {
        // Solo numero (es. dalla scansione): "63/113"
        $number = $q;
        $q = '';
    }
    try {
        $sql = 'SELECT s.blueprint_id, s.name_en AS name, s.collector_number AS card_number, s.image_url, e.name AS set_name
                FROM single_cards s LEFT JOIN expansions e ON e.id = s.expansion_id
                WHERE 1 = 1';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND s.name_en LIKE ?';
            $params[] = '%' . $q . '%';
        }
        if ($number !== null) {
            $sql .= ' AND s.collector_number LIKE ?';
            $params[] = $number . '%';
        }
        $stmt = g_pdo()->prepare($sql . ' ORDER BY s.name_en LIMIT 20');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['image_url'] = g_ct_image($r['image_url']);
        }
        g_json($rows);
    } catch (PDOException $e) {
        g_json([]); // catalogo non ancora popolato
    }
}

// La lingua su CardTrader si chiama pokemon_language, magic_language, … a seconda del gioco.
function g_product_language(array $props): ?string
{
    foreach ($props as $k => $v) {
        if (is_string($v) && str_ends_with((string)$k, '_language')) {
            return strtolower($v);
        }
    }
    return null;
}

// Prezzo di mercato attuale: prova lingua + condizione, poi allarga i filtri.
function g_ct_price(int $bp, ?string $lang, ?string $cond, bool $withImage): array
{
    $data = g_ct_get('/marketplace/products', ['blueprint_id' => $bp]);
    $products = $data[(string)$bp] ?? [];
    // Esclude le carte gradate (PSA ecc.): falserebbero il prezzo della carta raw.
    $products = array_values(array_filter($products, fn($p) => empty($p['graded'])));

    $attempts = [[$lang, $cond], [$lang, null], [null, $cond], [null, null]];
    $prices = [];
    $used = [null, null];
    foreach ($attempts as [$l, $c]) {
        $prices = [];
        foreach ($products as $p) {
            $props = $p['properties_hash'] ?? [];
            if ($l && g_product_language($props) !== $l) {
                continue;
            }
            if ($c && ($props['condition'] ?? null) !== $c) {
                continue;
            }
            if (isset($p['price']['cents'])) {
                $prices[] = $p['price']['cents'] / 100;
            }
        }
        if ($prices) {
            $used = [$l, $c];
            break;
        }
    }
    sort($prices);
    $n = count($prices);
    $first = $products[0] ?? [];
    $extra = [];
    if ($withImage && !empty($first['expansion']['id'])) {
        $extra = g_ct_blueprint($bp, (int)$first['expansion']['id']);
    }
    return $extra + [
        'count' => $n,
        'total_offers' => count($products),
        'min' => $n ? $prices[0] : null,
        'median' => $n ? $prices[intdiv($n, 2)] : null,
        'currency' => 'EUR',
        'language_used' => $used[0],
        'condition_used' => $used[1],
        'name' => $first['name_en'] ?? null,
        'set_name' => $first['expansion']['name_en'] ?? null,
        'expansion_id' => isset($first['expansion']['id']) ? (int)$first['expansion']['id'] : null,
    ];
}

function g_catalog_price(): void
{
    g_user();
    $bp = (int)($_GET['blueprint_id'] ?? 0);
    if (!$bp) {
        g_fail('blueprint_id mancante');
    }
    g_json(g_ct_price($bp, g_str($_GET['language'] ?? null, 10), g_str($_GET['condition'] ?? null, 40), !empty($_GET['with_image'])));
}

// Aggiornamento valore di una carta (usato dall'aggiornamento in blocco).
function g_item_refresh_price(int $id): void
{
    $u = g_user();
    g_require_premium($u, "L'aggiornamento valori in blocco");
    $strategy = (g_body()['strategy'] ?? 'min') === 'median' ? 'median' : 'min';
    g_track($u['workspace_id'], 'price_refresh');
    g_json(g_refresh_item(g_item_get($u, $id), $strategy));
}

// ---------- Export ----------

function g_export_csv(): void
{
    $u = g_user();
    $stmt = g_pdo()->prepare('SELECT i.*, l.title AS lot_title FROM g_items i LEFT JOIN g_lots l ON l.id = i.lot_id WHERE i.workspace_id = ? ORDER BY i.id');
    $stmt->execute([$u['workspace_id']]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="carte.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM per Excel
    $cols = ['id', 'lot_title', 'name', 'set_name', 'card_number', 'language', 'card_condition', 'quantity',
        'purchase_cost', 'est_value', 'status', 'sale_price', 'sale_fees', 'sale_date', 'sale_channel', 'notes'];
    fputcsv($out, $cols, ';');
    while ($row = $stmt->fetch()) {
        $line = [];
        foreach ($cols as $c) {
            $v = (string)($row[$c] ?? '');
            // Evita formula injection in Excel
            if ($v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) {
                $v = "'" . $v;
            }
            $line[] = $v;
        }
        fputcsv($out, $line, ';');
    }
    exit;
}

// ---------- Router ----------

function g_route(string $method, array $seg): void
{
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']),
        'lifetime' => 60 * 60 * 24 * 30,
    ]);
    session_start();

    // Webhook Stripe: nessuna sessione, verificato con la firma.
    if ($method === 'POST' && implode('/', $seg) === 'billing/webhook') {
        g_billing_webhook();
    }
    if ($method === 'POST' && implode('/', $seg) === 'billing/paypal/webhook') {
        g_paypal_webhook();
    }

    // Le scritture accettano solo JSON: blocca form cross-site (CSRF).
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)
        && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        g_fail('Content-Type deve essere application/json', 415);
    }

    try {
        // Es. "PUT items/12" -> chiave "PUT items/:id", $id = 12
        $id = null;
        $parts = [];
        foreach (array_filter($seg, 'strlen') as $part) {
            if (ctype_digit($part) && $id === null) {
                $id = (int)$part;
                $part = ':id';
            }
            $parts[] = $part;
        }
        $key = $method . ' ' . implode('/', $parts);
        // Foto: /items/:id/photos/<nome-file>
        if (count($parts) === 4 && $parts[0] === 'items' && $parts[2] === 'photos') {
            if ($method === 'GET') {
                g_item_photo_get($id, $seg[3]);
            }
            if ($method === 'DELETE') {
                g_item_photo_delete($id, $seg[3]);
            }
        }

        switch ($key) {
            case 'POST auth/register': g_register();
            case 'POST auth/login': g_login();
            case 'POST auth/logout': g_logout();
            case 'GET me': g_me();
            case 'POST auth/forgot': g_forgot_password();
            case 'POST auth/reset': g_reset_password();
            case 'POST auth/verify': g_verify_email();
            case 'POST auth/resend-verification': g_resend_verification();
            case 'PUT account/password': g_change_password();
            case 'DELETE account': g_delete_account();
            case 'PUT workspace': g_workspace_update();
            case 'POST workspace/join': g_workspace_join();
            case 'GET dashboard': g_dashboard();
            case 'GET lots': g_lots_list();
            case 'POST lots': g_lot_create();
            case 'GET lots/:id': g_lot_show($id);
            case 'PUT lots/:id': g_lot_update($id);
            case 'DELETE lots/:id': g_lot_delete($id);
            case 'GET items': g_items_list();
            case 'POST items': g_item_create();
            case 'PUT items/:id': g_item_update($id);
            case 'DELETE items/:id': g_item_delete($id);
            case 'GET catalog/search': g_catalog_search();
            case 'GET catalog/price': g_catalog_price();
            case 'GET export/items.csv': g_export_csv();
            case 'POST items/:id/refresh-price': g_item_refresh_price($id);
            case 'GET items/:id/history': g_item_history($id);
            case 'POST items/:id/photos': g_item_photo_add($id);
            case 'POST items/import': g_items_import();
            case 'GET stats/value-history': g_value_history();
            case 'GET notifications': g_notifications_list();
            case 'PUT workspace/settings': g_workspace_settings();
            case 'GET lots/:id/shares': g_lot_shares_get($id);
            case 'PUT lots/:id/shares': g_lot_shares_set($id);
            case 'GET reports/partners': g_partners_report();
            case 'GET reports/annual': g_annual_report();
            case 'GET reports/annual.csv': g_annual_csv();
            case 'POST events': g_event_from_client();
            case 'GET sealed/sets': g_sealed_sets();
            case 'GET sealed/analyze': g_sealed_analyze();
            case 'PUT sealed/rates/:id': g_sealed_rates_save($id);
            case 'GET admin/usage': g_admin_usage();
            case 'POST billing/checkout': g_billing_checkout();
            case 'POST billing/portal': g_billing_portal();
            case 'POST billing/paypal/subscribe': g_paypal_subscribe();
            case 'POST billing/paypal/confirm': g_paypal_confirm();
            case 'POST billing/paypal/cancel': g_paypal_cancel();
            case 'GET admin/overview': g_admin_overview();
            case 'PUT admin/workspaces/:id': g_admin_workspace_update($id);
        }
        g_fail('Endpoint non trovato', 404);
    } catch (PDOException $e) {
        error_log('[gestionale] ' . $e->getMessage());
        g_fail('Errore database', 500);
    }
}
