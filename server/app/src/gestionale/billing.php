<?php
// Modello freemium: Free per sempre + Premium facoltativo (Stripe o attivazione manuale).
// Premium: carte illimitate, aggiornamento valori in blocco, soci, andamento mensile.

// Proprietario della piattaforma: email in ADMIN_EMAILS (separate da virgola).
// Se non configurato, è proprietario il primo utente registrato.
function g_is_admin(array $u): bool
{
    $list = array_filter(array_map('trim', explode(',', strtolower((string)getenv('ADMIN_EMAILS')))));
    if ($list) {
        return in_array(strtolower($u['email']), $list, true);
    }
    static $firstId = null;
    $firstId ??= (int)g_pdo()->query('SELECT MIN(id) FROM g_users')->fetchColumn();
    return (int)$u['id'] === $firstId;
}

function g_plan_price(): float
{
    return (float)(getenv('PLAN_PRICE') ?: 4.99);
}

function g_free_item_limit(): int
{
    return (int)(getenv('FREE_ITEM_LIMIT') ?: 200);
}

function g_stripe_enabled(): bool
{
    return (bool)(getenv('STRIPE_SECRET_KEY') && getenv('STRIPE_PRICE_ID'));
}

// Piano di un negozio: comp (Premium gratis) | pro (Premium pagato) | trial (prova Premium) | free
function g_ws_status(int $wsId): array
{
    static $cache = [];
    if (isset($cache[$wsId])) {
        return $cache[$wsId];
    }
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT plan, trial_ends_at, paid_until, stripe_customer_id FROM g_workspaces WHERE id = ?');
    $stmt->execute([$wsId]);
    $ws = $stmt->fetch() ?: [];
    $stmt = $pdo->prepare('SELECT id, email FROM g_users WHERE workspace_id = ?');
    $stmt->execute([$wsId]);
    $hasAdmin = false;
    foreach ($stmt->fetchAll() as $m) {
        $hasAdmin = $hasAdmin || g_is_admin($m);
    }

    $now = time();
    $daysLeft = fn($d) => $d ? max(0, (int)ceil((strtotime($d) - $now) / 86400)) : null;
    if ($hasAdmin || ($ws['plan'] ?? '') === 'comp') {
        $s = ['state' => 'comp', 'active' => true, 'days_left' => null, 'until' => null];
    } elseif (!empty($ws['paid_until']) && strtotime($ws['paid_until']) > $now) {
        $s = ['state' => 'pro', 'active' => true, 'days_left' => $daysLeft($ws['paid_until']), 'until' => $ws['paid_until']];
    } elseif (!empty($ws['trial_ends_at']) && strtotime($ws['trial_ends_at']) > $now) {
        $s = ['state' => 'trial', 'active' => true, 'days_left' => $daysLeft($ws['trial_ends_at']), 'until' => $ws['trial_ends_at']];
    } else {
        $s = ['state' => 'free', 'active' => true, 'days_left' => null, 'until' => null];
    }
    $s['premium'] = $s['state'] !== 'free';
    $s['canceled'] = ($ws['plan'] ?? '') === 'canceled';
    $s['has_stripe_customer'] = !empty($ws['stripe_customer_id']);
    return $cache[$wsId] = $s;
}

function g_is_premium(int $wsId): bool
{
    return g_ws_status($wsId)['premium'];
}

function g_require_premium(array $u, string $feature): void
{
    if (!g_is_premium($u['workspace_id'])) {
        g_fail("$feature è una funzione Premium", 402);
    }
}

// Carte non vendute (righe) nel negozio: il limite del piano Free.
function g_items_in_stock(int $wsId): int
{
    $stmt = g_pdo()->prepare("SELECT COUNT(*) FROM g_items WHERE workspace_id = ? AND status <> 'sold'");
    $stmt->execute([$wsId]);
    return (int)$stmt->fetchColumn();
}

function g_check_item_limit(array $u): void
{
    if (!g_is_premium($u['workspace_id']) && g_items_in_stock($u['workspace_id']) >= g_free_item_limit()) {
        g_fail('Hai raggiunto il limite di ' . g_free_item_limit() . ' carte del piano Free: passa a Premium per averne illimitate', 402);
    }
}

// Più persone nello stesso negozio solo con Premium.
function g_check_can_add_member(int $wsId): void
{
    $stmt = g_pdo()->prepare('SELECT COUNT(*) FROM g_users WHERE workspace_id = ?');
    $stmt->execute([$wsId]);
    if ((int)$stmt->fetchColumn() >= 1 && !g_is_premium($wsId)) {
        g_fail('Questo negozio è sul piano Free: per aggiungere soci serve Premium', 402);
    }
}

function g_billing_info(array $u): array
{
    return g_ws_status($u['workspace_id']) + [
        'price' => g_plan_price(),
        'stripe_enabled' => g_stripe_enabled(),
        'trial_days' => g_trial_days(),
        'free_item_limit' => g_free_item_limit(),
        'items_in_stock' => g_items_in_stock($u['workspace_id']),
    ];
}

// ---------- Stripe ----------

function g_stripe(string $path, array $params): array
{
    $ch = curl_init('https://api.stripe.com/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_USERPWD => getenv('STRIPE_SECRET_KEY') . ':',
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true) ?: [];
    if ($http !== 200) {
        error_log('[stripe] ' . $resp);
        g_fail('Errore pagamento: ' . ($data['error']['message'] ?? "HTTP $http"), 502);
    }
    return $data;
}

function g_app_url(): string
{
    if ($url = getenv('APP_URL')) {
        return rtrim($url, '/');
    }
    // In produzione imposta sempre APP_URL: i link nelle email non devono dipendere dall'header Host.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8080');
}

function g_billing_checkout(): void
{
    $u = g_user();
    if (!g_stripe_enabled()) {
        g_fail('Pagamenti online non ancora attivi: contatta il proprietario', 503);
    }
    $stmt = g_pdo()->prepare('SELECT stripe_customer_id FROM g_workspaces WHERE id = ?');
    $stmt->execute([$u['workspace_id']]);
    $customer = $stmt->fetchColumn();
    $params = [
        'mode' => 'subscription',
        'line_items' => [['price' => getenv('STRIPE_PRICE_ID'), 'quantity' => 1]],
        'success_url' => g_app_url() . '/app/#settings?pagamento=ok',
        'cancel_url' => g_app_url() . '/app/#settings',
        'client_reference_id' => (string)$u['workspace_id'],
        'metadata' => ['workspace_id' => $u['workspace_id']],
        'subscription_data' => ['metadata' => ['workspace_id' => $u['workspace_id']]],
        'allow_promotion_codes' => 'true',
    ];
    if ($customer) {
        $params['customer'] = $customer;
    } else {
        $params['customer_email'] = $u['email'];
    }
    $session = g_stripe('checkout/sessions', $params);
    g_json(['url' => $session['url']]);
}

function g_billing_portal(): void
{
    $u = g_user();
    $stmt = g_pdo()->prepare('SELECT stripe_customer_id FROM g_workspaces WHERE id = ?');
    $stmt->execute([$u['workspace_id']]);
    $customer = $stmt->fetchColumn();
    if (!g_stripe_enabled() || !$customer) {
        g_fail('Nessun abbonamento online da gestire', 404);
    }
    $session = g_stripe('billing_portal/sessions', ['customer' => $customer, 'return_url' => g_app_url() . '/app/#settings']);
    g_json(['url' => $session['url']]);
}

function g_stripe_verify(string $payload): array
{
    $secret = (string)getenv('STRIPE_WEBHOOK_SECRET');
    $header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if (!$secret || !$header) {
        g_fail('Firma mancante', 400);
    }
    $t = null;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') {
            $t = (int)$v;
        } elseif ($k === 'v1') {
            $sigs[] = $v;
        }
    }
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    $ok = false;
    foreach ($sigs as $s) {
        $ok = $ok || hash_equals($expected, $s);
    }
    if (!$t || !$ok || abs(time() - $t) > 300) {
        g_fail('Firma non valida', 400);
    }
    return json_decode($payload, true) ?: [];
}

function g_billing_webhook(): void
{
    $event = g_stripe_verify((string)file_get_contents('php://input'));
    $o = $event['data']['object'] ?? [];
    $pdo = g_pdo();
    $wsByCustomer = function (?string $customer) use ($pdo) {
        if (!$customer) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT id FROM g_workspaces WHERE stripe_customer_id = ?');
        $stmt->execute([$customer]);
        return $stmt->fetchColumn() ?: null;
    };

    switch ($event['type'] ?? '') {
        case 'checkout.session.completed':
            $ws = (int)($o['client_reference_id'] ?? 0);
            if ($ws) {
                // Il periodo esatto arriva con invoice.paid; intanto attiva per un mese.
                $pdo->prepare("UPDATE g_workspaces SET plan = 'pro', stripe_customer_id = ?, stripe_subscription_id = ?,
                        paid_until = CASE WHEN paid_until IS NULL OR paid_until < ? THEN ? ELSE paid_until END WHERE id = ?")
                    ->execute([$o['customer'] ?? null, $o['subscription'] ?? null, g_now(), date('Y-m-d H:i:s', time() + 32 * 86400), $ws]);
            }
            break;

        case 'invoice.paid':
            $meta = $o['parent']['subscription_details']['metadata'] ?? $o['subscription_details']['metadata'] ?? [];
            $ws = (int)($meta['workspace_id'] ?? 0) ?: $wsByCustomer($o['customer'] ?? null);
            $end = $o['lines']['data'][0]['period']['end'] ?? (time() + 31 * 86400);
            if ($ws) {
                $pdo->prepare("UPDATE g_workspaces SET plan = 'pro', stripe_customer_id = COALESCE(stripe_customer_id, ?), paid_until = ? WHERE id = ?")
                    ->execute([$o['customer'] ?? null, date('Y-m-d H:i:s', $end + 86400), $ws]);
            }
            break;

        case 'customer.subscription.deleted':
            // Resta attivo fino a paid_until, poi scade.
            if ($ws = $wsByCustomer($o['customer'] ?? null)) {
                $pdo->prepare("UPDATE g_workspaces SET plan = 'canceled' WHERE id = ? AND plan <> 'comp'")->execute([$ws]);
            }
            break;
    }
    g_json(['received' => true]);
}

// ---------- Pannello proprietario ----------

function g_admin(): array
{
    $u = g_user();
    if (!g_is_admin($u)) {
        g_fail('Solo il proprietario', 403);
    }
    return $u;
}

function g_admin_overview(): void
{
    g_admin();
    $pdo = g_pdo();

    $workspaces = $pdo->query('SELECT id, name, plan, trial_ends_at, paid_until, stripe_customer_id, created_at FROM g_workspaces ORDER BY id DESC')->fetchAll();
    $members = [];
    foreach ($pdo->query('SELECT id, workspace_id, name, email, created_at FROM g_users ORDER BY id')->fetchAll() as $m) {
        $members[(int)$m['workspace_id']][] = $m;
    }
    $usage = [];
    foreach ($pdo->query("SELECT workspace_id, COALESCE(SUM(quantity), 0) AS cards,
            COALESCE(SUM(CASE WHEN status <> 'sold' THEN COALESCE(est_value, 0) * quantity ELSE 0 END), 0) AS stock_value,
            COALESCE(SUM(CASE WHEN status = 'sold' THEN sale_price - COALESCE(sale_fees, 0) ELSE 0 END), 0) AS revenue,
            MAX(created_at) AS last_activity
            FROM g_items GROUP BY workspace_id")->fetchAll() as $r) {
        $usage[(int)$r['workspace_id']] = $r;
    }
    $lots = [];
    foreach ($pdo->query('SELECT workspace_id, COUNT(*) AS n, MAX(created_at) AS last FROM g_lots GROUP BY workspace_id')->fetchAll() as $r) {
        $lots[(int)$r['workspace_id']] = $r;
    }

    $counts = ['comp' => 0, 'pro' => 0, 'trial' => 0, 'free' => 0];
    $rows = [];
    foreach ($workspaces as $w) {
        $id = (int)$w['id'];
        $st = g_ws_status($id);
        $counts[$st['state']]++;
        $us = $usage[$id] ?? [];
        $last = max((string)($us['last_activity'] ?? ''), (string)($lots[$id]['last'] ?? ''), (string)$w['created_at']);
        $rows[] = [
            'id' => $id,
            'name' => $w['name'],
            'state' => $st['state'],
            'canceled' => $st['canceled'],
            'until' => $st['until'],
            'days_left' => $st['days_left'],
            'stripe' => !empty($w['stripe_customer_id']),
            'created_at' => $w['created_at'],
            'members' => array_map(fn($m) => ['name' => $m['name'], 'email' => $m['email']], $members[$id] ?? []),
            'cards' => (int)($us['cards'] ?? 0),
            'lots' => (int)($lots[$id]['n'] ?? 0),
            'stock_value' => round((float)($us['stock_value'] ?? 0), 2),
            'revenue' => round((float)($us['revenue'] ?? 0), 2),
            'last_activity' => $last,
        ];
    }

    // Nuovi iscritti per mese (ultimi 12 mesi)
    $signups = [];
    for ($i = 11; $i >= 0; $i--) {
        $signups[date('Y-m', strtotime("first day of -$i month"))] = 0;
    }
    foreach ($pdo->query('SELECT SUBSTR(created_at, 1, 7) AS m, COUNT(*) AS n FROM g_users GROUP BY SUBSTR(created_at, 1, 7)')->fetchAll() as $r) {
        if (isset($signups[$r['m']])) {
            $signups[$r['m']] = (int)$r['n'];
        }
    }

    $active7 = count(array_filter($rows, fn($r) => $r['last_activity'] >= date('Y-m-d H:i:s', time() - 7 * 86400)));
    g_json([
        'users' => array_sum(array_map('count', $members)),
        'workspaces' => count($rows),
        'counts' => $counts,
        'mrr' => round($counts['pro'] * g_plan_price(), 2),
        'price' => g_plan_price(),
        'active_7d' => $active7,
        'trials_expiring' => count(array_filter($rows, fn($r) => $r['state'] === 'trial' && $r['days_left'] <= 3)),
        'signups' => array_map(fn($m, $n) => ['month' => $m, 'value' => $n], array_keys($signups), $signups),
        'stripe_enabled' => g_stripe_enabled(),
        'rows' => $rows,
    ]);
}

function g_admin_workspace_update(int $id): void
{
    g_admin();
    $b = g_body();
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT paid_until FROM g_workspaces WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        g_fail('Negozio non trovato', 404);
    }
    $days = max(1, min(3650, (int)($b['days'] ?? 30)));
    switch ($b['action'] ?? '') {
        case 'extend': // abbonamento pagato a mano (bonifico, PayPal…)
            $from = max(time(), $row['paid_until'] ? strtotime($row['paid_until']) : 0);
            $pdo->prepare("UPDATE g_workspaces SET plan = 'pro', paid_until = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s', $from + $days * 86400), $id]);
            break;
        case 'trial':
            $pdo->prepare("UPDATE g_workspaces SET plan = CASE WHEN plan = 'comp' THEN 'trial' ELSE plan END, trial_ends_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s', time() + $days * 86400), $id]);
            break;
        case 'comp':
            $pdo->prepare("UPDATE g_workspaces SET plan = 'comp' WHERE id = ?")->execute([$id]);
            break;
        case 'free': // torna al piano Free
            $pdo->prepare("UPDATE g_workspaces SET plan = 'trial', trial_ends_at = ?, paid_until = NULL WHERE id = ?")
                ->execute([g_now(), $id]);
            break;
        default:
            g_fail('Azione non valida');
    }
    g_admin_overview();
}
