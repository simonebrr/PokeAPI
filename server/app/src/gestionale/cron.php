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

// Elenco attività giornaliere (le funzioni Premium ne aggiungono altre sotto).
function cron_tasks(): array
{
    return [
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
