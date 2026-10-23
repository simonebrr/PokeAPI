<?php
// Crea su PayPal il prodotto e il piano mensile Premium, e registra il webhook.
// Uso (con PAYPAL_CLIENT_ID e PAYPAL_SECRET nel .env):
//   docker compose exec php php /var/www/html/src/gestionale/paypal-setup.php
// Poi copia l'ID del piano stampato in PAYPAL_PLAN_ID nel .env e riavvia.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/api.php';
const G_CLI = true;

if (!env('PAYPAL_CLIENT_ID') || !env('PAYPAL_SECRET')) {
    fwrite(STDERR, "Imposta prima PAYPAL_CLIENT_ID e PAYPAL_SECRET nel file .env\n");
    exit(1);
}
$price = number_format(g_plan_price(), 2, '.', '');
echo 'Ambiente: ' . env('PAYPAL_MODE', 'sandbox') . "\n";

$product = g_paypal('POST', '/v1/catalogs/products', [
    'name' => g_app_name() . ' Premium',
    'description' => 'Abbonamento Premium al gestionale ' . g_app_name(),
    'type' => 'SERVICE',
    'category' => 'SOFTWARE',
]);
echo "Prodotto creato: {$product['id']}\n";

$plan = g_paypal('POST', '/v1/billing/plans', [
    'product_id' => $product['id'],
    'name' => 'Premium mensile',
    'description' => "Premium a $price € al mese",
    'status' => 'ACTIVE',
    'billing_cycles' => [[
        'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
        'tenure_type' => 'REGULAR',
        'sequence' => 1,
        'total_cycles' => 0,
        'pricing_scheme' => ['fixed_price' => ['value' => $price, 'currency_code' => 'EUR']],
    ]],
    'payment_preferences' => ['auto_bill_outstanding' => true, 'payment_failure_threshold' => 2],
]);
echo "\n>>> PAYPAL_PLAN_ID={$plan['id']}\n\n";

$url = rtrim((string)env('APP_URL'), '/') . '/api/billing/paypal/webhook';
if (str_starts_with($url, 'https://')) {
    $hook = g_paypal('POST', '/v1/notifications/webhooks', [
        'url' => $url,
        'event_types' => array_map(fn($t) => ['name' => $t], [
            'BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.UPDATED', 'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.SUSPENDED', 'BILLING.SUBSCRIPTION.EXPIRED', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
            'PAYMENT.SALE.COMPLETED',
        ]),
    ]);
    echo "Webhook registrato: {$hook['id']} → $url\n";
} else {
    echo "Webhook NON registrato: serve APP_URL in https (sito online). Fino ad allora l'attivazione avviene al ritorno da PayPal\n";
    echo "e i rinnovi/disdette vengono controllati ogni notte.\n";
}
