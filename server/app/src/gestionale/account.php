<?php
// Account: invio email, verifica email, recupero password, limiti di accesso, eliminazione (GDPR).

// ---------- Email ----------

function g_app_name(): string
{
    return env('APP_NAME', 'CardLedger');
}

// Invia un'email di solo testo. Senza SMTP configurato la scrive nei log
// (docker compose logs php) così in locale si possono comunque usare i link.
function g_mail(string $to, string $subject, string $body): bool
{
    $to = str_replace(["\r", "\n"], '', $to);
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    if (!env('SMTP_HOST')) {
        error_log("[mail] A: $to | Oggetto: $subject\n$body");
        return true;
    }
    try {
        g_smtp_send($to, $subject, $body);
        return true;
    } catch (Throwable $e) {
        error_log('[mail] invio fallito a ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

function g_smtp_send(string $to, string $subject, string $body): void
{
    $host = env('SMTP_HOST');
    $port = (int)env('SMTP_PORT', '587');
    $secure = env('SMTP_SECURE', $port === 465 ? 'ssl' : 'tls'); // ssl | tls | none
    $from = env('SMTP_FROM', env('SMTP_USER', 'noreply@localhost'));
    $fromName = g_app_name();

    $fp = stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 15);
    if (!$fp) {
        throw new RuntimeException("connessione SMTP fallita: $errstr");
    }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): string {
        $resp = '';
        while (($line = fgets($fp, 515)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $resp;
    };
    $cmd = function (?string $c, array $ok) use ($fp, $read): string {
        if ($c !== null) {
            fwrite($fp, $c . "\r\n");
        }
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) {
            throw new RuntimeException('SMTP: ' . trim($r));
        }
        return $r;
    };

    $cmd(null, [220]);
    $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
    $cmd($ehlo, [250]);
    if ($secure === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS fallito');
        }
        $cmd($ehlo, [250]);
    }
    if ($user = env('SMTP_USER')) {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode((string)env('SMTP_PASSWORD', '')), [235]);
    }
    $cmd("MAIL FROM:<$from>", [250]);
    $cmd("RCPT TO:<$to>", [250, 251]);
    $cmd('DATA', [354]);

    $headers = [
        'Date: ' . date('r'),
        'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$from>",
        "To: <$to>",
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
    fwrite($fp, $data . "\r\n.\r\n");
    $cmd(null, [250]);
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
}

function g_mail_footer(): string
{
    return "\n\n— " . g_app_name() . "\n" . g_app_url() . "/";
}

// ---------- Token monouso (verifica email, reset password) ----------

function g_token_create(int $userId, string $type, int $ttlSeconds): string
{
    $token = bin2hex(random_bytes(24));
    $pdo = g_pdo();
    // Invalida i token precedenti dello stesso tipo.
    $pdo->prepare('UPDATE g_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL')
        ->execute([g_now(), $userId, $type]);
    $pdo->prepare('INSERT INTO g_tokens (user_id, type, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $type, hash('sha256', $token), date('Y-m-d H:i:s', time() + $ttlSeconds), g_now()]);
    return $token;
}

function g_token_use(string $token, string $type): int
{
    $stmt = g_pdo()->prepare('SELECT id, user_id, expires_at, used_at FROM g_tokens WHERE token_hash = ? AND type = ?');
    $stmt->execute([hash('sha256', $token), $type]);
    $t = $stmt->fetch();
    if (!$t || $t['used_at'] || strtotime($t['expires_at']) < time()) {
        g_fail('Link non valido o scaduto: richiedine uno nuovo', 400);
    }
    g_pdo()->prepare('UPDATE g_tokens SET used_at = ? WHERE id = ?')->execute([g_now(), $t['id']]);
    return (int)$t['user_id'];
}

// ---------- Limiti di accesso (anti brute-force) ----------

function g_client_ip(): string
{
    // Dietro Caddy l'IP reale arriva in X-Forwarded-For.
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $ip = $fwd ? trim(explode(',', $fwd)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr($ip, 0, 45);
}

// Blocca se ci sono troppi tentativi recenti per email o IP.
function g_rate_limit(string $kind, string $email, int $maxPerEmail, int $maxPerIp, int $windowMinutes): void
{
    $since = date('Y-m-d H:i:s', time() - $windowMinutes * 60);
    $stmt = g_pdo()->prepare('SELECT
            SUM(CASE WHEN email = ? THEN 1 ELSE 0 END) AS by_email,
            SUM(CASE WHEN ip = ? THEN 1 ELSE 0 END) AS by_ip
        FROM g_login_attempts WHERE kind = ? AND created_at > ?');
    $stmt->execute([$email, g_client_ip(), $kind, $since]);
    $r = $stmt->fetch();
    if ((int)$r['by_email'] >= $maxPerEmail || (int)$r['by_ip'] >= $maxPerIp) {
        g_fail("Troppi tentativi: riprova tra $windowMinutes minuti", 429);
    }
}

function g_rate_hit(string $kind, string $email): void
{
    $pdo = g_pdo();
    $pdo->prepare('INSERT INTO g_login_attempts (ip, email, kind, created_at) VALUES (?, ?, ?, ?)')
        ->execute([g_client_ip(), $email, $kind, g_now()]);
    // Pulizia occasionale dei record vecchi.
    if (random_int(1, 50) === 1) {
        $pdo->prepare('DELETE FROM g_login_attempts WHERE created_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
    }
}

function g_rate_clear(string $kind, string $email): void
{
    g_pdo()->prepare('DELETE FROM g_login_attempts WHERE kind = ? AND email = ?')->execute([$kind, $email]);
}

// ---------- Endpoint ----------

function g_send_verification(int $userId, string $email, string $name): void
{
    $token = g_token_create($userId, 'verify', 7 * 86400);
    g_mail($email, 'Conferma la tua email', "Ciao $name,\n\nconferma il tuo indirizzo email aprendo questo link:\n"
        . g_app_url() . "/app/#verify/$token\n\nIl link vale 7 giorni." . g_mail_footer());
}

function g_send_welcome(string $email, string $name): void
{
    $days = g_trial_days();
    g_mail($email, 'Benvenuto in ' . g_app_name(), "Ciao $name,\n\nbenvenuto! Il tuo negozio è pronto.\n\n"
        . "Per iniziare:\n1. Crea un lotto con quanto hai pagato\n2. Aggiungi le carte che valgono (cerca nel catalogo o incolla il link CardTrader)\n"
        . "3. Segna le vendite: il riepilogo calcola i profitti da solo\n\n"
        . "Hai $days giorni di Premium in prova: carte illimitate, aggiornamento valori in blocco, soci e grafici.\n"
        . "Dopo resti sul piano Free, gratis per sempre." . g_mail_footer());
}

function g_forgot_password(): void
{
    $email = strtolower(trim((string)(g_body()['email'] ?? '')));
    g_rate_limit('forgot', $email, 3, 10, 60);
    g_rate_hit('forgot', $email);
    $stmt = g_pdo()->prepare('SELECT id, name FROM g_users WHERE email = ?');
    $stmt->execute([$email]);
    if ($u = $stmt->fetch()) {
        $token = g_token_create((int)$u['id'], 'reset', 3600);
        g_mail($email, 'Reimposta la password', "Ciao {$u['name']},\n\nper scegliere una nuova password apri questo link:\n"
            . g_app_url() . "/app/#reset/$token\n\nIl link vale 1 ora. Se non hai chiesto tu il reset, ignora questa email." . g_mail_footer());
    }
    // Stessa risposta che l'email esista o no (non rivela chi è iscritto).
    g_json(['ok' => true]);
}

function g_reset_password(): void
{
    $b = g_body();
    $password = (string)($b['password'] ?? '');
    if (strlen($password) < 8) {
        g_fail('La password deve avere almeno 8 caratteri');
    }
    $uid = g_token_use((string)($b['token'] ?? ''), 'reset');
    $pdo = g_pdo();
    // Chi riceve il link ha dimostrato di possedere l'email: la consideriamo verificata.
    $pdo->prepare('UPDATE g_users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), g_now(), $uid]);
    $stmt = $pdo->prepare('SELECT email FROM g_users WHERE id = ?');
    $stmt->execute([$uid]);
    g_rate_clear('login', (string)$stmt->fetchColumn());
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    g_me();
}

function g_verify_email(): void
{
    $uid = g_token_use((string)(g_body()['token'] ?? ''), 'verify');
    g_pdo()->prepare('UPDATE g_users SET email_verified_at = ? WHERE id = ?')->execute([g_now(), $uid]);
    g_json(['ok' => true]);
}

function g_resend_verification(): void
{
    $u = g_user();
    g_rate_limit('verify', $u['email'], 3, 10, 60);
    g_rate_hit('verify', $u['email']);
    g_send_verification($u['id'], $u['email'], $u['name']);
    g_json(['ok' => true]);
}

function g_change_password(): void
{
    $u = g_user();
    $b = g_body();
    $stmt = g_pdo()->prepare('SELECT password_hash FROM g_users WHERE id = ?');
    $stmt->execute([$u['id']]);
    if (!password_verify((string)($b['current'] ?? ''), (string)$stmt->fetchColumn())) {
        g_fail('Password attuale errata', 403);
    }
    if (strlen((string)($b['password'] ?? '')) < 8) {
        g_fail('La nuova password deve avere almeno 8 caratteri');
    }
    g_pdo()->prepare('UPDATE g_users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash((string)$b['password'], PASSWORD_DEFAULT), $u['id']]);
    g_json(['ok' => true]);
}

// Elimina l'account. Se era l'ultimo membro del negozio, elimina anche tutti i dati del negozio.
function g_delete_account(): void
{
    $u = g_user();
    $stmt = g_pdo()->prepare('SELECT password_hash FROM g_users WHERE id = ?');
    $stmt->execute([$u['id']]);
    if (!password_verify((string)(g_body()['password'] ?? ''), (string)$stmt->fetchColumn())) {
        g_fail('Password errata', 403);
    }
    $pdo = g_pdo();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM g_users WHERE workspace_id = ?');
    $stmt->execute([$u['workspace_id']]);
    $lastMember = (int)$stmt->fetchColumn() <= 1;

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM g_tokens WHERE user_id = ?')->execute([$u['id']]);
    $pdo->prepare('DELETE FROM g_users WHERE id = ?')->execute([$u['id']]);
    if ($lastMember) {
        g_delete_workspace_data($pdo, $u['workspace_id']);
    }
    $pdo->commit();
    if ($lastMember) {
        g_delete_workspace_files($u['workspace_id']);
    }
    $_SESSION = [];
    session_destroy();
    g_json(['ok' => true, 'workspace_deleted' => $lastMember]);
}

// Tabelle con dati del negozio: estesa dalle funzioni successive (storico, avvisi…).
function g_workspace_tables(): array
{
    return ['g_price_history', 'g_stock_snapshots', 'g_notifications', 'g_lot_shares', 'g_items', 'g_lots'];
}

function g_delete_workspace_data(PDO $pdo, int $wsId): void
{
    foreach (g_workspace_tables() as $table) {
        $pdo->prepare("DELETE FROM $table WHERE workspace_id = ?")->execute([$wsId]);
    }
    $pdo->prepare('DELETE FROM g_workspaces WHERE id = ?')->execute([$wsId]);
}

function g_delete_workspace_files(int $wsId): void
{
    $dir = g_storage_dir() . "/ws$wsId";
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function g_storage_dir(): string
{
    return env('STORAGE_DIR', '/var/www/html/storage');
}
