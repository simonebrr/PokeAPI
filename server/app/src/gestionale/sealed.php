<?php
// Premium: prodotti sigillati (bustina, ETB, box…) – sbustare o rivendere?
//
// Per un set scarica da CardTrader (2 chiamate, in cache 6 ore):
//   - l'elenco delle carte con rarità e dei prodotti sigillati del set
//   - tutte le offerte in vendita del set
// e restituisce, per ogni rarità, i prezzi delle carte e il pull rate (probabilità per bustina).
// Il calcolo del valore atteso e la simulazione degli sbustamenti li fa il browser,
// così l'utente può cambiare numero di bustine, commissioni e prezzo pagato all'istante.

const G_SEALED_CACHE_HOURS = 6;
const G_BULK_PER_PACK = 0.10; // comuni + non comuni + reverse "da bulk" per bustina

// Pull rate di default (probabilità per bustina), valori indicativi della community.
// Il proprietario può correggerli per ogni set dall'app.
const G_DEFAULT_PULL_RATES = [
    // Scarlatto e Violetto
    'rare' => 0.68,
    'double rare' => 0.20,
    'ultra rare' => 0.067,
    'illustration rare' => 0.077,
    'special illustration rare' => 0.031,
    'hyper rare' => 0.018,
    'ace spec rare' => 0.05,
    'shiny rare' => 0.08,
    'shiny ultra rare' => 0.015,
    // Spada e Scudo e precedenti
    'holo rare' => 0.33,
    'rare holo' => 0.33,
    'rare holo v' => 0.14,
    'rare holo vmax' => 0.06,
    'rare holo vstar' => 0.05,
    'rare ultra' => 0.06,
    'rare rainbow' => 0.015,
    'rare secret' => 0.014,
    'radiant rare' => 0.05,
    'amazing rare' => 0.05,
    'trainer gallery rare holo' => 0.10,
];

// Rarità vendute solo come "bulk" (già incluse in G_BULK_PER_PACK).
const G_BULK_RARITIES = ['common', 'uncommon', 'comune', 'non comune', 'promo'];

function g_cache_get(string $key): ?array
{
    $stmt = g_pdo()->prepare('SELECT v FROM g_cache WHERE k = ? AND expires_at > ?');
    $stmt->execute([$key, g_now()]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : json_decode($v, true);
}

function g_cache_set(string $key, array $value, int $hours): void
{
    $pdo = g_pdo();
    $pdo->prepare('DELETE FROM g_cache WHERE k = ?')->execute([$key]);
    $pdo->prepare('INSERT INTO g_cache (k, v, expires_at) VALUES (?, ?, ?)')
        ->execute([$key, json_encode($value), date('Y-m-d H:i:s', time() + $hours * 3600)]);
}

// Quante bustine contiene il prodotto, dal nome (modificabile dall'utente).
function g_sealed_packs(string $name): ?int
{
    $n = strtolower($name);
    return match (true) {
        (bool)preg_match('/booster box|display|36\s*(booster|pack)/', $n) => 36,
        (bool)preg_match('/half booster box|18\s*(booster|pack)/', $n) => 18,
        (bool)preg_match('/elite trainer|\betb\b|allenatore fuoriclasse/', $n) => 9,
        (bool)preg_match('/booster bundle|bundle/', $n) => 6,
        (bool)preg_match('/build\s*&?\s*battle/', $n) => 4,
        (bool)preg_match('/3\s*-?\s*pack|three.?pack|blister/', $n) => 3,
        (bool)preg_match('/sleeved booster|booster pack|^booster$|bustina|\bpack\b|\bbooster\b/', $n) => 1,
        default => null,
    };
}

function g_is_sealed_name(string $name): bool
{
    return (bool)preg_match('/booster|box|bundle|elite trainer|\betb\b|blister|pack|tin|collection|display|bustina/i', $name);
}

// Cerca i set (espansioni) Pokémon per nome o codice.
function g_sealed_sets(): void
{
    g_user();
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        g_json([]);
    }
    try {
        $stmt = g_pdo()->prepare('SELECT id, name, code FROM expansions WHERE game_id = 5 AND (LOWER(name) LIKE ? OR LOWER(code) = ?) ORDER BY id DESC LIMIT 15');
        $stmt->execute(['%' . mb_strtolower($q) . '%', mb_strtolower($q)]);
        $rows = $stmt->fetchAll();
        if ($rows) {
            g_json($rows);
        }
    } catch (PDOException $e) {
        // catalogo locale non ancora importato: uso l'elenco di CardTrader
    }
    $all = g_cache_get('ct_expansions');
    if ($all === null) {
        $all = array_values(array_map(fn($e) => ['id' => (int)$e['id'], 'name' => $e['name'], 'code' => $e['code']],
            array_filter(g_ct_get('/expansions', []), fn($e) => (int)($e['game_id'] ?? 0) === 5)));
        g_cache_set('ct_expansions', $all, 24);
    }
    $ql = mb_strtolower($q);
    $hits = array_filter($all, fn($e) => str_contains(mb_strtolower($e['name']), $ql) || mb_strtolower((string)$e['code']) === $ql);
    usort($hits, fn($a, $b) => $b['id'] <=> $a['id']);
    g_json(array_slice(array_values($hits), 0, 15));
}

// Prezzo più basso di una carta appena sbustata (quindi perfetta):
// lingua scelta + Near Mint, poi Near Mint in qualsiasi lingua, poi lingua scelta, poi qualsiasi.
function g_sealed_best_price(array $offers, ?string $lang): ?array
{
    $nm = fn($p) => in_array($p['properties_hash']['condition'] ?? 'Near Mint', ['Near Mint', 'Mint'], true);
    $inLang = fn($p) => g_product_language($p['properties_hash'] ?? []) === $lang;
    $attempts = [
        fn($p) => $inLang($p) && $nm($p),
        $nm,
        $inLang,
        fn($p) => true,
    ];
    foreach ($attempts as $i => $ok) {
        if (!$lang && ($i === 0 || $i === 2)) {
            continue;
        }
        $prices = [];
        foreach ($offers as $p) {
            if (empty($p['graded']) && isset($p['price']['cents']) && $ok($p)) {
                $prices[] = $p['price']['cents'] / 100;
            }
        }
        if ($prices) {
            sort($prices);
            return ['min' => $prices[0], 'median' => $prices[intdiv(count($prices), 2)], 'count' => count($prices), 'exact_lang' => $i === 0 || $i === 2];
        }
    }
    return null;
}

function g_sealed_data(int $expansionId, ?string $lang): array
{
    $key = "sealed:$expansionId:" . ($lang ?: 'any');
    if ($cached = g_cache_get($key)) {
        return $cached;
    }
    // Un set intero può avere decine di migliaia di offerte: servono più memoria e tempo.
    @ini_set('memory_limit', '768M');
    @set_time_limit(180);
    $blueprints = g_ct_get('/blueprints/export', ['expansion_id' => $expansionId]);
    $market = g_ct_get('/marketplace/products', ['expansion_id' => $expansionId]);
    $setName = null;
    $cards = [];
    $sealed = [];
    foreach ($blueprints as $b) {
        $bid = (int)($b['id'] ?? 0);
        $name = (string)($b['name'] ?? '');
        $price = g_sealed_best_price($market[(string)$bid] ?? [], $lang);
        $setName ??= $market[(string)$bid][0]['expansion']['name_en'] ?? null;
        $rarity = $b['fixed_properties']['pokemon_rarity'] ?? null;
        if ((int)($b['category_id'] ?? 0) !== 73 && g_is_sealed_name($name . ' ' . ($b['version'] ?? ''))) {
            $sealed[] = [
                'blueprint_id' => $bid,
                'name' => trim($name . ' ' . ($b['version'] ?? '')),
                'image_url' => g_ct_image($b['image_url'] ?? ($b['image']['show']['url'] ?? null)),
                'packs' => g_sealed_packs($name . ' ' . ($b['version'] ?? '')),
                'price' => $price,
            ];
        } elseif ($rarity) {
            $cards[] = [
                'blueprint_id' => $bid,
                'name' => $name,
                'number' => isset($b['fixed_properties']['collector_number']) ? explode(' ', $b['fixed_properties']['collector_number'])[0] : null,
                'rarity' => $rarity,
                'price' => $price['min'] ?? null,
                'image_url' => g_ct_image($b['image_url'] ?? ($b['image']['show']['url'] ?? null)),
            ];
        }
    }
    $data = ['expansion_id' => $expansionId, 'set_name' => $setName, 'language' => $lang, 'cards' => $cards, 'sealed' => $sealed,
        'updated_at' => g_now()];
    g_cache_set($key, $data, G_SEALED_CACHE_HOURS);
    return $data;
}

function g_sealed_rates(int $expansionId): array
{
    $stmt = g_pdo()->prepare('SELECT rarity, per_pack FROM g_pull_rates WHERE expansion_id = ?');
    $stmt->execute([$expansionId]);
    $custom = [];
    foreach ($stmt->fetchAll() as $r) {
        $custom[strtolower($r['rarity'])] = (float)$r['per_pack'];
    }
    return $custom;
}

function g_sealed_analyze(): void
{
    $u = g_user();
    g_require_premium($u, "L'analisi dei prodotti sigillati");
    $exp = (int)($_GET['expansion_id'] ?? 0);
    if (!$exp) {
        g_fail('Scegli un set');
    }
    $lang = g_str($_GET['language'] ?? null, 5);
    $data = g_sealed_data($exp, $lang ? strtolower($lang) : null);
    $custom = g_sealed_rates($exp);
    $community = g_community_stats($exp);

    // Raggruppa le carte per rarità; pull rate = stima (proprietario o default) corretta con i dati community.
    $groups = [];
    foreach ($data['cards'] as $c) {
        $key = strtolower(trim($c['rarity']));
        if (in_array($key, G_BULK_RARITIES, true)) {
            continue;
        }
        $groups[$key] ??= ['rarity' => $c['rarity'], 'cards' => []];
        if ($c['price'] !== null) {
            $groups[$key]['cards'][] = $c;
        }
    }
    $rarities = [];
    foreach ($groups as $key => $g) {
        $br = g_blended_rate($key, $custom[$key] ?? null, G_DEFAULT_PULL_RATES[$key] ?? null, $community);
        $rate = $br['rate'];
        $prices = array_column($g['cards'], 'price');
        usort($g['cards'], fn($a, $b) => $b['price'] <=> $a['price']);
        $rarities[] = [
            'rarity' => $g['rarity'],
            'per_pack' => $rate,
            'custom' => isset($custom[$key]),
            'source' => $br['source'],
            'community_rate' => $br['community_rate'],
            'community_hits' => $community['hits'][$key] ?? 0,
            'prior' => $custom[$key] ?? G_DEFAULT_PULL_RATES[$key] ?? null,
            'avg' => $prices ? round(array_sum($prices) / count($prices), 2) : 0,
            'cards' => array_map(fn($c) => ['name' => $c['name'], 'number' => $c['number'], 'price' => $c['price'], 'image_url' => $c['image_url']], $g['cards']),
        ];
    }
    usort($rarities, fn($a, $b) => $b['avg'] <=> $a['avg']);
    g_track($u['workspace_id'], 'sealed_analyze');
    g_json([
        'expansion_id' => $exp,
        'set_name' => $data['set_name'],
        'language' => $data['language'],
        'updated_at' => $data['updated_at'],
        'bulk_per_pack' => G_BULK_PER_PACK,
        'rarities' => $rarities,
        'sealed' => $data['sealed'],
        'can_edit_rates' => g_is_admin($u),
        'community' => ['packs' => $community['packs'], 'openings' => $community['openings'], 'users' => $community['users']],
    ]);
}

// Il proprietario corregge i pull rate di un set: {rates: {"Double Rare": 0.2, …}} (vuoto = default).
function g_sealed_rates_save(int $expansionId): void
{
    g_admin();
    $pdo = g_pdo();
    $pdo->prepare('DELETE FROM g_pull_rates WHERE expansion_id = ?')->execute([$expansionId]);
    foreach ((array)(g_body()['rates'] ?? []) as $rarity => $v) {
        $rarity = mb_substr(trim((string)$rarity), 0, 80);
        $v = (float)str_replace(',', '.', (string)$v);
        if ($rarity === '' || $v <= 0) {
            continue;
        }
        if ($v > 10) {
            g_fail("Pull rate non valido per $rarity");
        }
        $pdo->prepare('INSERT INTO g_pull_rates (expansion_id, rarity, per_pack) VALUES (?, ?, ?)')
            ->execute([$expansionId, $rarity, round($v, 5)]);
    }
    g_json(['ok' => true]);
}
