<?php
// Pagamenti PayPal (abbonamento mensile) con attivazione automatica di Premium.
//
// Sicurezza: lo stato dell'abbonamento viene SEMPRE riletto dalle API PayPal
// (mai preso dal browser o dal corpo del webhook), e deve appartenere al negozio
// (custom_id = id negozio).
//
// Configurazione (.env): PAYPAL_CLIENT_ID, PAYPAL_SECRET, PAYPAL_PLAN_ID, PAYPAL_MODE=sandbox|live
// Il piano si crea con: php scripts/paypal-setup.php (dentro il container php)

function g_paypal_enabled(): bool
{
    return (bool)(env('PAYPAL_CLIENT_ID') && env('PAYPAL_SECRET') && env('PAYPAL_PLAN_ID'));
}

function g_paypal_base(): string
{
    return env('PAYPAL_API_URL') ?: (env('PAYPAL_MODE', 'sandbox') === 'live'
        ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com');
}

function g_paypal_token(): string
{
    static $token = null;
    if ($token) {
        return $token;
    }
    $ch = curl_init(g_paypal_base() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_USERPWD => env('PAYPAL_CLIENT_ID') . ':' . env('PAYPAL_SECRET'),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resp = json_decode((string)curl_exec($ch), true);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || empty($resp['access_token'])) {
        error_log('[paypal] token: ' . json_encode($resp));
        g_fail('PayPal non raggiungibile o credenziali errate', 502);
    }
    return $token = $resp['access_token'];
}

function g_paypal(string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(g_paypal_base() . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . g_paypal_token(), 'Content-Type: application/json', 'Accept: application/json'],
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($raw, true) ?: [];
    if ($http < 200 || $http >= 300) {
        error_log("[paypal] $method $path → $http $raw");
        g_fail('Errore PayPal: ' . ($data['message'] ?? $data['details'][0]['description'] ?? "HTTP $http"), 502);
    }
    return $data;
}

// Crea l'abbonamento e restituisce il link dove l'utente approva il pagamento.
function g_paypal_subscribe(): void
{
    $u = g_user();
    if (!g_paypal_enabled()) {
        g_fail('Pagamento con PayPal non ancora attivo', 503);
    }
    $st = g_ws_status($u['workspace_id']);
    if ($st['state'] === 'pro' && !$st['canceled']) {
        g_fail('Hai già Premium attivo');
    }
    $sub = g_paypal('POST', '/v1/billing/subscriptions', [
        'plan_id' => env('PAYPAL_PLAN_ID'),
        'custom_id' => (string)$u['workspace_id'],
        'subscriber' => ['email_address' => $u['email'], 'name' => ['given_name' => mb_substr($u['name'], 0, 140)]],
        'application_context' => [
            'brand_name' => mb_substr(g_app_name(), 0, 127),
            'locale' => 'it-IT',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'SUBSCRIBE_NOW',
            'return_url' => g_app_url() . '/app/?paypal=ok',
            'cancel_url' => g_app_url() . '/app/?paypal=annullato',
        ],
    ]);
    $approve = null;
    foreach ($sub['links'] ?? [] as $l) {
        if (($l['rel'] ?? '') === 'approve') {
            $approve = $l['href'];
        }
    }
    if (!$approve || empty($sub['id'])) {
        g_fail('Risposta PayPal inattesa', 502);
    }
    g_json(['url' => $approve, 'id' => $sub['id']]);
}

// Legge l'abbonamento da PayPal e aggiorna il negozio a cui appartiene.
function g_paypal_sync(string $subId, ?int $expectedWs = null): array
{
    if (!preg_match('/^I-[A-Z0-9]{6,30}$/', $subId)) {
        g_fail('Abbonamento non valido');
    }
    $sub = g_paypal('GET', '/v1/billing/subscriptions/' . $subId);
    $wsId = (int)($sub['custom_id'] ?? 0);
    if (!$wsId || ($expectedWs !== null && $wsId !== $expectedWs)) {
        g_fail('Questo abbonamento non appartiene al tuo negozio', 403);
    }
    $status = $sub['status'] ?? '';
    $pdo = g_pdo();
    if ($status === 'ACTIVE') {
        // Premium fino al prossimo addebito (+2 giorni di margine per i ritardi di PayPal).
        $next = strtotime($sub['billing_info']['next_billing_time'] ?? '') ?: (time() + 31 * 86400);
        $pdo->prepare("UPDATE g_workspaces SET plan = 'pro', paypal_subscription_id = ?, paid_until = ? WHERE id = ?")
            ->execute([$subId, date('Y-m-d H:i:s', $next + 2 * 86400), $wsId]);
    } elseif (in_array($status, ['CANCELLED', 'SUSPENDED', 'EXPIRED'], true)) {
        // Resta Premium fino alla fine del periodo già pagato, poi torna Free.
        $pdo->prepare("UPDATE g_workspaces SET plan = 'canceled' WHERE id = ? AND paypal_subscription_id = ? AND plan <> 'comp'")
            ->execute([$wsId, $subId]);
    }
    return ['status' => $status, 'workspace_id' => $wsId];
}

// Ritorno dal sito PayPal: attiva subito Premium senza aspettare il webhook.
function g_paypal_confirm(): void
{
    $u = g_user();
    $r = g_paypal_sync((string)(g_body()['subscription_id'] ?? ''), $u['workspace_id']);
    if ($r['status'] === 'ACTIVE') {
        g_track($u['workspace_id'], 'paypal_subscribed');
    }
    g_json(['status' => $r['status'], 'billing' => g_billing_info($u)]);
}

function g_paypal_cancel(): void
{
    $u = g_user();
    $stmt = g_pdo()->prepare('SELECT paypal_subscription_id FROM g_workspaces WHERE id = ?');
    $stmt->execute([$u['workspace_id']]);
    $subId = (string)$stmt->fetchColumn();
    if (!$subId) {
        g_fail('Nessun abbonamento PayPal attivo', 404);
    }
    g_paypal('POST', "/v1/billing/subscriptions/$subId/cancel", ['reason' => 'Disdetta dall\'app']);
    g_paypal_sync($subId, $u['workspace_id']);
    g_track($u['workspace_id'], 'paypal_cancelled');
    g_json(['ok' => true, 'billing' => g_billing_info($u)]);
}

// Webhook PayPal: rinnovi, disdette, pagamenti falliti. Usa solo l'id per rileggere da PayPal.
function g_paypal_webhook(): void
{
    $event = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $type = (string)($event['event_type'] ?? '');
    $res = $event['resource'] ?? [];
    $subId = str_starts_with($type, 'BILLING.SUBSCRIPTION.') ? ($res['id'] ?? null) : ($res['billing_agreement_id'] ?? null);
    if (is_string($subId) && g_paypal_enabled()) {
        try {
            $GLOBALS['g_throw'] = true;
            g_paypal_sync($subId);
        } catch (RuntimeException $e) {
            error_log("[paypal] webhook $type $subId: " . $e->getMessage());
        } finally {
            $GLOBALS['g_throw'] = false;
        }
    }
    g_json(['received' => true]);
}
