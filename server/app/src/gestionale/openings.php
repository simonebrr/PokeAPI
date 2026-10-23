<?php
// Sbustamenti registrati dagli utenti → pull rate reali per set (dati della community).
//
// Si registrano solo le "hit": carte più rare di una Rara normale (le rarità bulk e la Rara
// del rare slot non servono). Il pull rate usato nelle analisi è una media pesata:
//   rate = (hit_community + prior × K) / (bustine_community + K)
// dove prior = valore del proprietario (K = 1000) o di default (K = 300).
// Con pochi dati prevale la stima, con migliaia di bustine prevalgono i dati reali.

const G_OPENING_MAX_PACKS = 360;      // 10 box per registrazione
const G_OPENING_MAX_PER_DAY = 30;     // registrazioni per utente al giorno
const G_PRIOR_WEIGHT_DEFAULT = 300;
const G_PRIOR_WEIGHT_CUSTOM = 1000;
const G_COMMUNITY_MIN_PACKS = 100;    // senza stima: dati community usati solo da qui in su
const G_NOT_TRACKED = ['rare', 'rara'];

const G_PRODUCTS = ['Bustina' => 1, 'Blister 3 bustine' => 3, 'Build & Battle' => 4, 'Booster Bundle' => 6,
    'Elite Trainer Box' => 9, 'Booster Box' => 36, 'Altro' => null];

function g_is_tracked_rarity(string $rarity): bool
{
    $k = strtolower(trim($rarity));
    return !in_array($k, G_BULK_RARITIES, true) && !in_array($k, G_NOT_TRACKED, true);
}

// Statistiche community per set: solo registrazioni complete (tutte le hit segnate) e non escluse.
function g_community_stats(int $expansionId): array
{
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(packs), 0) AS packs, COUNT(*) AS n, COUNT(DISTINCT user_id) AS users FROM g_openings WHERE expansion_id = ? AND excluded = 0 AND complete = 1');
    $stmt->execute([$expansionId]);
    $tot = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT LOWER(h.rarity) AS r, SUM(h.qty) AS hits FROM g_opening_hits h
        JOIN g_openings o ON o.id = h.opening_id WHERE h.expansion_id = ? AND o.excluded = 0 AND o.complete = 1 GROUP BY LOWER(h.rarity)');
    $stmt->execute([$expansionId]);
    $hits = [];
    foreach ($stmt->fetchAll() as $r) {
        $hits[$r['r']] = (int)$r['hits'];
    }
    return ['packs' => (int)$tot['packs'], 'openings' => (int)$tot['n'], 'users' => (int)$tot['users'], 'hits' => $hits];
}

// Pull rate finale per una rarità e da dove viene.
function g_blended_rate(string $rarityKey, ?float $custom, ?float $default, array $community): array
{
    $packs = $community['packs'];
    $hits = $community['hits'][$rarityKey] ?? 0;
    $prior = $custom ?? $default;
    $k = $custom !== null ? G_PRIOR_WEIGHT_CUSTOM : G_PRIOR_WEIGHT_DEFAULT;
    $communityRate = $packs > 0 ? $hits / $packs : null;
    if (!g_is_tracked_rarity($rarityKey)) {
        return ['rate' => $prior, 'source' => $custom !== null ? 'owner' : ($prior !== null ? 'default' : null), 'community_rate' => null];
    }
    if ($prior !== null) {
        $rate = $packs > 0 ? ($hits + $prior * $k) / ($packs + $k) : $prior;
        $source = $packs >= $k ? 'community' : ($custom !== null ? 'owner' : 'default');
    } elseif ($packs >= G_COMMUNITY_MIN_PACKS) {
        $rate = $communityRate;
        $source = 'community';
    } else {
        $rate = null;
        $source = null;
    }
    return ['rate' => $rate !== null ? round($rate, 5) : null, 'source' => $source, 'community_rate' => $communityRate !== null ? round($communityRate, 5) : null];
}

// Carte "hit" del set da cui scegliere (gratis per tutti, serve per registrare).
function g_opening_set(): void
{
    g_user();
    $exp = (int)($_GET['expansion_id'] ?? 0);
    if (!$exp) {
        g_fail('Scegli un set');
    }
    $data = g_sealed_data($exp, g_str($_GET['language'] ?? null, 5));
    $groups = [];
    foreach ($data['cards'] as $c) {
        if (!g_is_tracked_rarity($c['rarity'])) {
            continue;
        }
        $groups[$c['rarity']][] = ['blueprint_id' => $c['blueprint_id'], 'name' => $c['name'], 'number' => $c['number'],
            'price' => $c['price'], 'image_url' => $c['image_url']];
    }
    $rarities = [];
    foreach ($groups as $r => $cards) {
        usort($cards, fn($a, $b) => strnatcmp((string)$a['number'], (string)$b['number']));
        $rarities[] = ['rarity' => $r, 'cards' => $cards];
    }
    $c = g_community_stats($exp);
    g_json(['expansion_id' => $exp, 'set_name' => $data['set_name'], 'rarities' => $rarities, 'products' => G_PRODUCTS,
        'community' => ['packs' => $c['packs'], 'openings' => $c['openings'], 'users' => $c['users']]]);
}

function g_opening_create(): void
{
    $u = g_user();
    $b = g_body();
    $exp = (int)($b['expansion_id'] ?? 0);
    $packs = (int)($b['packs'] ?? 0);
    if (!$exp) {
        g_fail('Scegli un set');
    }
    if ($packs < 1 || $packs > G_OPENING_MAX_PACKS) {
        g_fail('Numero di bustine non valido (1-' . G_OPENING_MAX_PACKS . ')');
    }
    $product = g_str($b['product'] ?? null, 80) ?? 'Altro';
    $lang = g_str($b['language'] ?? null, 5);
    $paid = g_money($b['paid'] ?? null);
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM g_openings WHERE user_id = ? AND created_at > ?');
    $stmt->execute([$u['id'], date('Y-m-d H:i:s', time() - 86400)]);
    if ((int)$stmt->fetchColumn() >= G_OPENING_MAX_PER_DAY) {
        g_fail('Hai raggiunto il limite di registrazioni per oggi', 429);
    }

    // Le carte devono appartenere al set: rarità e prezzo li prendo dai dati CardTrader, non dal browser.
    $data = g_sealed_data($exp, $lang ? strtolower($lang) : null);
    $byId = [];
    foreach ($data['cards'] as $c) {
        $byId[$c['blueprint_id']] = $c;
    }
    $hits = [];
    $totalHits = 0;
    foreach ((array)($b['hits'] ?? []) as $h) {
        $bid = (int)($h['blueprint_id'] ?? 0);
        $qty = (int)($h['qty'] ?? 1);
        if (!isset($byId[$bid]) || !g_is_tracked_rarity($byId[$bid]['rarity'])) {
            g_fail('Carta non valida per questo set');
        }
        if ($qty < 1 || $qty > $packs) {
            g_fail('Quantità non valida per ' . $byId[$bid]['name']);
        }
        $hits[$bid] = ($hits[$bid] ?? 0) + $qty;
        $totalHits += $qty;
    }
    if ($totalHits > $packs * 3) {
        g_fail('Troppe carte speciali per ' . $packs . ' bustine: controlla le quantità');
    }
    if (!empty($b['add_to_inventory']) && !g_is_premium($u['workspace_id'])
        && g_items_in_stock($u['workspace_id']) + count($hits) > g_free_item_limit()) {
        g_fail('Con il piano Free puoi avere al massimo ' . g_free_item_limit() . ' carte in magazzino: registra senza aggiungerle o passa a Premium', 402);
    }
    $value = $packs * G_BULK_PER_PACK;
    foreach ($hits as $bid => $qty) {
        $value += ($byId[$bid]['price'] ?? 0) * $qty;
    }

    $pdo->beginTransaction();
    $lotId = null;
    if (!empty($b['add_to_inventory'])) {
        // Lo sbustamento diventa un lotto: costo = prezzo pagato, carte = le hit trovate.
        $title = mb_substr("Sbustamento $product · " . ($data['set_name'] ?? 'set'), 0, 190);
        $pdo->prepare('INSERT INTO g_lots (workspace_id, title, purchase_date, cost, source, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$u['workspace_id'], $title, date('Y-m-d'), $paid ?? 0, 'Sbustamento', "$packs bustine", $u['id'], g_now()]);
        $lotId = (int)$pdo->lastInsertId();
    }
    $complete = !empty($b['complete']) ? 1 : 0;
    $pdo->prepare('INSERT INTO g_openings (workspace_id, user_id, expansion_id, set_name, product, packs, language, paid, value, lot_id, complete, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$u['workspace_id'], $u['id'], $exp, $data['set_name'], $product, $packs, $lang, $paid, round($value, 2), $lotId, $complete, g_now()]);
    $openingId = (int)$pdo->lastInsertId();
    foreach ($hits as $bid => $qty) {
        $c = $byId[$bid];
        $pdo->prepare('INSERT INTO g_opening_hits (workspace_id, opening_id, expansion_id, blueprint_id, rarity, card_name, qty) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$u['workspace_id'], $openingId, $exp, $bid, $c['rarity'], mb_substr($c['name'], 0, 190), $qty]);
        if ($lotId) {
            $pdo->prepare('INSERT INTO g_items (workspace_id, lot_id, blueprint_id, name, set_name, card_number, language, card_condition, quantity, est_value, est_value_updated_at, status, image_url, game, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$u['workspace_id'], $lotId, $bid, $c['name'], $data['set_name'], $c['number'], $lang, 'Near Mint', $qty,
                    $c['price'], $c['price'] !== null ? g_now() : null, 'in_stock', $c['image_url'], null, $u['id'], g_now()]);
            g_record_price($u['workspace_id'], (int)$pdo->lastInsertId(), $c['price'], 'cardtrader');
        }
    }
    $pdo->commit();
    g_track($u['workspace_id'], 'opening');
    $comm = g_community_stats($exp);
    g_json(['id' => $openingId, 'value' => round($value, 2), 'paid' => $paid, 'hits' => $totalHits, 'packs' => $packs, 'lot_id' => $lotId, 'complete' => (bool)$complete,
        'community' => ['packs' => $comm['packs'], 'openings' => $comm['openings'], 'users' => $comm['users']]], 201);
}

function g_openings_list(): void
{
    $u = g_user();
    $stmt = g_pdo()->prepare('SELECT id, expansion_id, set_name, product, packs, language, paid, value, lot_id, created_at FROM g_openings WHERE workspace_id = ? ORDER BY id DESC LIMIT 100');
    $stmt->execute([$u['workspace_id']]);
    $rows = $stmt->fetchAll();
    $h = g_pdo()->prepare('SELECT opening_id, card_name, rarity, qty FROM g_opening_hits WHERE workspace_id = ? ORDER BY id');
    $h->execute([$u['workspace_id']]);
    $byOpening = [];
    foreach ($h->fetchAll() as $r) {
        $byOpening[(int)$r['opening_id']][] = ['name' => $r['card_name'], 'rarity' => $r['rarity'], 'qty' => (int)$r['qty']];
    }
    g_json(array_map(fn($r) => [
        'id' => (int)$r['id'], 'expansion_id' => (int)$r['expansion_id'], 'set_name' => $r['set_name'], 'product' => $r['product'],
        'packs' => (int)$r['packs'], 'language' => $r['language'], 'paid' => $r['paid'] !== null ? (float)$r['paid'] : null,
        'value' => (float)$r['value'], 'lot_id' => $r['lot_id'] ? (int)$r['lot_id'] : null, 'created_at' => $r['created_at'],
        'hits' => $byOpening[(int)$r['id']] ?? [],
    ], $rows));
}

function g_opening_delete(int $id): void
{
    $u = g_user();
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT id FROM g_openings WHERE id = ? AND workspace_id = ?');
    $stmt->execute([$id, $u['workspace_id']]);
    if (!$stmt->fetch()) {
        g_fail('Registrazione non trovata', 404);
    }
    $pdo->prepare('DELETE FROM g_opening_hits WHERE opening_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM g_openings WHERE id = ?')->execute([$id]);
    g_json(['ok' => true]);
}

// ---------- Proprietario: controllo qualità dei dati ----------

function g_admin_openings(): void
{
    g_admin();
    $pdo = g_pdo();
    $rows = $pdo->query('SELECT o.id, o.set_name, o.product, o.packs, o.value, o.excluded, o.complete, o.created_at, u.email,
            (SELECT COALESCE(SUM(qty), 0) FROM g_opening_hits h WHERE h.opening_id = o.id) AS hits
        FROM g_openings o LEFT JOIN g_users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 200')->fetchAll();
    $sets = $pdo->query('SELECT expansion_id, MAX(set_name) AS set_name, SUM(packs) AS packs, COUNT(*) AS n, COUNT(DISTINCT user_id) AS users
        FROM g_openings WHERE excluded = 0 AND complete = 1 GROUP BY expansion_id ORDER BY SUM(packs) DESC LIMIT 50')->fetchAll();
    g_json([
        'openings' => array_map(fn($r) => ['id' => (int)$r['id'], 'set_name' => $r['set_name'], 'product' => $r['product'], 'packs' => (int)$r['packs'],
            'hits' => (int)$r['hits'], 'hits_per_pack' => $r['packs'] ? round($r['hits'] / $r['packs'], 2) : 0,
            'value' => (float)$r['value'], 'excluded' => (bool)$r['excluded'], 'complete' => (bool)$r['complete'], 'email' => $r['email'], 'created_at' => $r['created_at']], $rows),
        'sets' => array_map(fn($r) => ['expansion_id' => (int)$r['expansion_id'], 'set_name' => $r['set_name'], 'packs' => (int)$r['packs'],
            'openings' => (int)$r['n'], 'users' => (int)$r['users']], $sets),
    ]);
}

function g_admin_opening_update(int $id): void
{
    g_admin();
    g_pdo()->prepare('UPDATE g_openings SET excluded = ? WHERE id = ?')->execute([!empty(g_body()['excluded']) ? 1 : 0, $id]);
    g_admin_openings();
}
