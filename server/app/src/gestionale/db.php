<?php
require_once __DIR__ . '/../config.php';
// Connessione e schema del gestionale.
// DSN configurabile via env (GESTIONALE_DSN), default: MySQL del docker-compose.

function g_pdo(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = db_config();
    $dsn  = getenv('GESTIONALE_DSN') ?: "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
    $user = $c['user'];
    $pass = $c['password'];
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    g_migrate($pdo);
    return $pdo;
}

function g_is_sqlite(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

function g_migrate(PDO $pdo): void
{
    $id = g_is_sqlite($pdo) ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

    $pdo->exec("CREATE TABLE IF NOT EXISTS g_workspaces (
        id $id,
        name VARCHAR(120) NOT NULL,
        invite_code VARCHAR(16) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS g_users (
        id $id,
        workspace_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS g_lots (
        id $id,
        workspace_id INT NOT NULL,
        title VARCHAR(190) NOT NULL,
        purchase_date DATE NULL,
        cost DECIMAL(10,2) NOT NULL DEFAULT 0,
        source VARCHAR(190) NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL
    )");

    // Ogni riga = una carta (o N copie identiche, vedi quantity).
    // status: in_stock | listed | sold
    $pdo->exec("CREATE TABLE IF NOT EXISTS g_items (
        id $id,
        workspace_id INT NOT NULL,
        lot_id INT NULL,
        blueprint_id INT NULL,
        name VARCHAR(190) NOT NULL,
        set_name VARCHAR(190) NULL,
        card_number VARCHAR(50) NULL,
        language VARCHAR(10) NULL,
        card_condition VARCHAR(40) NULL,
        quantity INT NOT NULL DEFAULT 1,
        purchase_cost DECIMAL(10,2) NULL,
        est_value DECIMAL(10,2) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'in_stock',
        sale_price DECIMAL(10,2) NULL,
        sale_fees DECIMAL(10,2) NULL,
        sale_date DATE NULL,
        sale_channel VARCHAR(80) NULL,
        image_url VARCHAR(2048) NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS g_meta (
        k VARCHAR(50) PRIMARY KEY,
        v VARCHAR(255) NOT NULL
    )");
    $version = (int)($pdo->query("SELECT v FROM g_meta WHERE k = 'schema'")->fetchColumn() ?: 1);

    if ($version < 2) {
        g_add_columns($pdo, [
            ['g_workspaces', 'plan', "VARCHAR(20) NOT NULL DEFAULT 'trial'"],
            ['g_workspaces', 'trial_ends_at', 'DATETIME NULL'],
            ['g_workspaces', 'paid_until', 'DATETIME NULL'],
            ['g_workspaces', 'stripe_customer_id', 'VARCHAR(80) NULL'],
            ['g_workspaces', 'stripe_subscription_id', 'VARCHAR(80) NULL'],
            ['g_items', 'est_value_updated_at', 'DATETIME NULL'],
        ]);
        // I negozi già esistenti partono con una prova piena.
        $pdo->prepare('UPDATE g_workspaces SET trial_ends_at = ? WHERE trial_ends_at IS NULL')
            ->execute([date('Y-m-d H:i:s', time() + g_trial_days() * 86400)]);
        g_set_schema($pdo, 2);
    }

    if ($version < 3) {
        // Account: verifica email, recupero password, protezione login, inviti.
        g_add_columns($pdo, [
            ['g_users', 'email_verified_at', 'DATETIME NULL'],
            ['g_users', 'last_login_at', 'DATETIME NULL'],
            ['g_users', 'referral_code', 'VARCHAR(16) NULL'],
            ['g_users', 'referred_by', 'INT NULL'],
            ['g_workspaces', 'trial_reminder_sent_at', 'DATETIME NULL'],
        ]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_tokens (
            id $id,
            user_id INT NOT NULL,
            type VARCHAR(20) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_login_attempts (
            id $id,
            ip VARCHAR(45) NOT NULL,
            email VARCHAR(190) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL
        )");
        // Gli utenti già esistenti sono considerati verificati.
        $pdo->exec('UPDATE g_users SET email_verified_at = created_at WHERE email_verified_at IS NULL');
        g_set_schema($pdo, 3);
    }
}

function g_add_columns(PDO $pdo, array $cols): void
{
    foreach ($cols as [$table, $col, $def]) {
        try {
            $pdo->query("SELECT $col FROM $table LIMIT 1");
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
        }
    }
}

function g_set_schema(PDO $pdo, int $v): void
{
    try {
        $pdo->exec("DELETE FROM g_meta WHERE k = 'schema'");
        $pdo->exec("INSERT INTO g_meta (k, v) VALUES ('schema', '$v')");
    } catch (PDOException $e) {
        // Migrazione concorrente già completata da un'altra richiesta.
    }
}

function g_trial_days(): int
{
    return (int)(getenv('TRIAL_DAYS') ?: 14);
}
