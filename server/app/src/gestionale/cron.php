<?php
// Attività pianificate del gestionale. Eseguito dal cron del container:
//   php /var/www/html/src/gestionale/cron.php [attività]
// Senza argomenti esegue tutte le attività giornaliere.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/api.php';

// Nel cron g_fail() non deve chiudere lo script: trasforma gli errori in eccezioni.
const G_CLI = true;

function cron_log(string $msg): void
{
    echo date('[Y-m-d H:i:s] ') . $msg . PHP_EOL;
}

// Email "la prova Premium finisce tra 3 giorni" (una sola volta per negozio).
function cron_trial_reminders(): void
{
    $pdo = g_pdo();
    $stmt = $pdo->prepare("SELECT id FROM g_workspaces WHERE plan = 'trial' AND trial_reminder_sent_at IS NULL
        AND trial_ends_at > ? AND trial_ends_at <= ?");
    $stmt->execute([g_now(), date('Y-m-d H:i:s', time() + 3 * 86400)]);
    $sent = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $wsId) {
        $st = g_ws_status((int)$wsId);
        if ($st['state'] !== 'trial') {
            continue;
        }
        $m = $pdo->prepare('SELECT name, email FROM g_users WHERE workspace_id = ?');
        $m->execute([$wsId]);
        foreach ($m->fetchAll() as $u) {
            g_mail($u['email'], 'La tua prova Premium sta per finire', "Ciao {$u['name']},\n\n"
                . "la prova Premium termina tra {$st['days_left']} giorni. Dopo passerai al piano Free: "
                . "i tuoi dati restano tutti, ma perderai carte illimitate, aggiornamento valori in blocco, soci e grafici.\n\n"
                . 'Puoi passare a Premium da Altro → Piano: ' . g_app_url() . '/app/#settings' . g_mail_footer());
            $sent++;
        }
        $pdo->prepare('UPDATE g_workspaces SET trial_reminder_sent_at = ? WHERE id = ?')->execute([g_now(), $wsId]);
    }
    cron_log("promemoria fine prova inviati: $sent");
}

// Aggiornamento notturno dei valori (negozi Premium con aggiornamento automatico attivo)
// + email riepilogativa degli avvisi di prezzo.
function cron_refresh_prices(): void
{
    $pdo = g_pdo();
    $workspaces = $pdo->query('SELECT id, name, price_strategy FROM g_workspaces WHERE auto_refresh = 1')->fetchAll();
    $totUpdated = 0;
    foreach ($workspaces as $ws) {
        $wsId = (int)$ws['id'];
        if (!g_is_premium($wsId)) {
            continue;
        }
        $stmt = $pdo->prepare("SELECT * FROM g_items WHERE workspace_id = ? AND status <> 'sold' AND blueprint_id IS NOT NULL AND grading_company IS NULL ORDER BY id");
        $stmt->execute([$wsId]);
        $alerts = [];
        $updated = 0;
        $errors = 0;
        foreach (array_map('g_item_format', $stmt->fetchAll()) as $item) {
            try {
                $r = g_refresh_item($item, $ws['price_strategy']);
                if (empty($r['skipped'])) {
                    $updated++;
                }
                $alerts = array_merge($alerts, $r['alerts'] ?? []);
            } catch (Throwable $e) {
                $errors++;
                if (str_contains($e->getMessage(), 'Token') || str_contains($e->getMessage(), 'raggiungibile')) {
                    cron_log('CardTrader non disponibile: ' . $e->getMessage());
                    return;
                }
            }
            usleep(150000); // gentile con le API CardTrader
        }
        $totUpdated += $updated;
        cron_log("negozio $wsId: $updated carte aggiornate, " . count($alerts) . " avvisi, $errors errori");
        if ($alerts) {
            $m = $pdo->prepare('SELECT name, email FROM g_users WHERE workspace_id = ?');
            $m->execute([$wsId]);
            foreach ($m->fetchAll() as $u) {
                g_mail($u['email'], 'Avvisi di prezzo: ' . count($alerts) . ' carte', "Ciao {$u['name']},\n\n"
                    . "con l'aggiornamento di stanotte:\n\n• " . implode("\n• ", array_slice($alerts, 0, 30))
                    . (count($alerts) > 30 ? "\n…e altri " . (count($alerts) - 30) : '')
                    . "\n\nApri il magazzino: " . g_app_url() . '/app/#items'
                    . "\nPuoi cambiare la soglia degli avvisi da Altro → Prezzi e avvisi." . g_mail_footer());
            }
        }
    }
    cron_log("aggiornamento prezzi completato: $totUpdated carte");
}

// Riallinea gli abbonamenti PayPal (rete di sicurezza se un webhook non è arrivato).
function cron_paypal_sync(): void
{
    if (!g_paypal_enabled()) {
        return;
    }
    $subs = g_pdo()->query('SELECT paypal_subscription_id FROM g_workspaces WHERE paypal_subscription_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    $ok = 0;
    foreach ($subs as $id) {
        try {
            g_paypal_sync($id);
            $ok++;
        } catch (Throwable $e) {
            cron_log("paypal $id: " . $e->getMessage());
        }
    }
    cron_log("abbonamenti PayPal verificati: $ok/" . count($subs));
}

// Fotografia giornaliera del valore del magazzino (per i grafici di andamento).
function cron_snapshots(): void
{
    $ids = g_pdo()->query('SELECT id FROM g_workspaces')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        g_snapshot_workspace((int)$id);
    }
    cron_log('snapshot valore magazzino: ' . count($ids) . ' negozi');
}

// Elenco attività giornaliere (le funzioni Premium ne aggiungono altre sotto).
function cron_tasks(): array
{
    return [
        'paypal' => 'cron_paypal_sync',
        'prices' => 'cron_refresh_prices',
        'snapshots' => 'cron_snapshots',
        'trial' => 'cron_trial_reminders',
    ];
}

$tasks = cron_tasks();
$only = $argv[1] ?? null;
foreach ($tasks as $name => $fn) {
    if ($only && $only !== $name) {
        continue;
    }
    try {
        $fn();
    } catch (Throwable $e) {
        cron_log("ERRORE $name: " . $e->getMessage());
    }
}
