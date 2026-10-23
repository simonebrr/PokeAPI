<?php
// Crescita: programma inviti, statistiche d'uso per il pannello proprietario.

const G_GAMES = ['pokemon' => 'Pokémon', 'magic' => 'Magic', 'yugioh' => 'Yu-Gi-Oh!', 'onepiece' => 'One Piece', 'lorcana' => 'Lorcana', 'altro' => 'Altro'];
const G_CLIENT_EVENTS = ['scan', 'scan_ok', 'listing_text', 'app_installed', 'offline_sync'];
const G_REFERRAL_DAYS = 30;
const G_REFERRAL_MAX = 12;

// ---------- Eventi d'uso (solo conteggi, nessun dato personale) ----------

function g_track(int $wsId, string $event): void
{
    try {
        g_pdo()->prepare('INSERT INTO g_events (workspace_id, event, created_at) VALUES (?, ?, ?)')
            ->execute([$wsId, $event, g_now()]);
    } catch (PDOException $e) {
        // le statistiche non devono mai bloccare l'app
    }
}

function g_event_from_client(): void
{
    $u = g_user();
    $event = (string)(g_body()['event'] ?? '');
    if (in_array($event, G_CLIENT_EVENTS, true)) {
        g_track($u['workspace_id'], $event);
    }
    g_json(['ok' => true]);
}

// ---------- Programma inviti ----------
// Chi invita riceve 30 giorni di Premium quando l'amico conferma l'email (max 12 volte).
// L'amico parte con 30 giorni di prova Premium invece di 14.

function g_referrer_id(?string $code): ?int
{
    $code = strtoupper(trim((string)$code));
    if ($code === '' || !preg_match('/^[A-Z0-9]{4,16}$/', $code)) {
        return null;
    }
    $stmt = g_pdo()->prepare('SELECT id FROM g_users WHERE referral_code = ?');
    $stmt->execute([$code]);
    return ($id = $stmt->fetchColumn()) ? (int)$id : null;
}

function g_extend_premium_trial(int $wsId, int $days): void
{
    $st = g_ws_status($wsId);
    if (in_array($st['state'], ['comp', 'pro'], true)) {
        return; // ha già Premium
    }
    $stmt = g_pdo()->prepare('SELECT trial_ends_at FROM g_workspaces WHERE id = ?');
    $stmt->execute([$wsId]);
    $from = max(time(), strtotime((string)$stmt->fetchColumn()) ?: 0);
    g_pdo()->prepare("UPDATE g_workspaces SET plan = 'trial', trial_ends_at = ?, trial_reminder_sent_at = NULL WHERE id = ?")
        ->execute([date('Y-m-d H:i:s', $from + $days * 86400), $wsId]);
}

// Chiamata quando un utente conferma l'email.
function g_referral_reward(int $userId): void
{
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT id, name, workspace_id, referred_by, referral_rewarded_at FROM g_users WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u || !$u['referred_by'] || $u['referral_rewarded_at']) {
        return;
    }
    $stmt = $pdo->prepare('SELECT id, name, email, workspace_id FROM g_users WHERE id = ?');
    $stmt->execute([$u['referred_by']]);
    $ref = $stmt->fetch();
    $pdo->prepare('UPDATE g_users SET referral_rewarded_at = ? WHERE id = ?')->execute([g_now(), $userId]);
    if (!$ref || (int)$ref['workspace_id'] === (int)$u['workspace_id']) {
        return; // il socio dello stesso negozio non conta come invito
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM g_users WHERE referred_by = ? AND referral_rewarded_at IS NOT NULL');
    $stmt->execute([$ref['id']]);
    if ((int)$stmt->fetchColumn() > G_REFERRAL_MAX) {
        return;
    }
    g_extend_premium_trial((int)$ref['workspace_id'], G_REFERRAL_DAYS);
    g_track((int)$ref['workspace_id'], 'referral_reward');
    g_mail($ref['email'], 'Hai guadagnato ' . G_REFERRAL_DAYS . ' giorni di Premium', "Ciao {$ref['name']},\n\n"
        . "{$u['name']} si è iscritto con il tuo invito: ti abbiamo regalato " . G_REFERRAL_DAYS . ' giorni di Premium.'
        . " (Se hai già un abbonamento attivo, grazie lo stesso!)\n\nContinua a invitare amici da Altro → Invita amici." . g_mail_footer());
}

function g_referral_info(array $u): array
{
    $stmt = g_pdo()->prepare('SELECT COUNT(*) AS invited, SUM(CASE WHEN referral_rewarded_at IS NOT NULL THEN 1 ELSE 0 END) AS confirmed
        FROM g_users WHERE referred_by = ?');
    $stmt->execute([$u['id']]);
    $r = $stmt->fetch();
    return [
        'code' => $u['referral_code'],
        'link' => g_app_url() . '/app/?signup&ref=' . $u['referral_code'],
        'invited' => (int)$r['invited'],
        'confirmed' => (int)$r['confirmed'],
        'days' => G_REFERRAL_DAYS,
    ];
}

// ---------- Statistiche d'uso (pannello proprietario) ----------

function g_admin_usage(): void
{
    g_admin();
    $pdo = g_pdo();
    $since = date('Y-m-d H:i:s', time() - 30 * 86400);
    $stmt = $pdo->prepare('SELECT event, COUNT(*) AS n, COUNT(DISTINCT workspace_id) AS shops FROM g_events WHERE created_at > ? GROUP BY event ORDER BY n DESC');
    $stmt->execute([$since]);
    $events = array_map(fn($r) => ['event' => $r['event'], 'count' => (int)$r['n'], 'shops' => (int)$r['shops']], $stmt->fetchAll());

    // Imbuto: iscritti → primo lotto → prima carta → prima vendita → Premium pagato
    $ws = array_map('intval', $pdo->query('SELECT id FROM g_workspaces')->fetchAll(PDO::FETCH_COLUMN));
    $withLots = (int)$pdo->query('SELECT COUNT(DISTINCT workspace_id) FROM g_lots')->fetchColumn();
    $withItems = (int)$pdo->query('SELECT COUNT(DISTINCT workspace_id) FROM g_items')->fetchColumn();
    $withSales = (int)$pdo->query("SELECT COUNT(DISTINCT workspace_id) FROM g_items WHERE status = 'sold'")->fetchColumn();
    $paying = count(array_filter($ws, fn($id) => g_ws_status($id)['state'] === 'pro'));
    $active = $pdo->prepare('SELECT COUNT(DISTINCT workspace_id) FROM g_events WHERE created_at > ?');
    $active->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);
    $referred = (int)$pdo->query('SELECT COUNT(*) FROM g_users WHERE referred_by IS NOT NULL')->fetchColumn();
    $games = $pdo->query("SELECT COALESCE(game, 'pokemon') AS g, COUNT(*) AS n FROM g_items GROUP BY COALESCE(game, 'pokemon') ORDER BY n DESC")->fetchAll();

    g_json([
        'events' => $events,
        'funnel' => [
            ['step' => 'Negozi creati', 'value' => count($ws)],
            ['step' => 'Hanno creato un lotto', 'value' => $withLots],
            ['step' => 'Hanno aggiunto carte', 'value' => $withItems],
            ['step' => 'Hanno registrato una vendita', 'value' => $withSales],
            ['step' => 'Pagano Premium', 'value' => $paying],
        ],
        'active_7d' => (int)$active->fetchColumn(),
        'referred_users' => $referred,
        'games' => array_map(fn($r) => ['game' => G_GAMES[$r['g']] ?? $r['g'], 'cards' => (int)$r['n']], $games),
    ]);
}
