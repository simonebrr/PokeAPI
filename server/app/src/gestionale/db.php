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

    if ($version < 4) {
        // Storico prezzi, avvisi, quote soci, foto, carte gradate, impostazioni negozio.
        g_add_columns($pdo, [
            ['g_items', 'grading_company', 'VARCHAR(20) NULL'],
            ['g_items', 'grade', 'VARCHAR(10) NULL'],
            ['g_items', 'photos', 'TEXT NULL'],
            ['g_items', 'alert_above', 'DECIMAL(10,2) NULL'],
            ['g_items', 'alert_below', 'DECIMAL(10,2) NULL'],
            ['g_workspaces', 'alert_pct', 'INT NULL'],
            ['g_workspaces', 'price_strategy', "VARCHAR(10) NOT NULL DEFAULT 'min'"],
            ['g_workspaces', 'auto_refresh', 'INT NOT NULL DEFAULT 1'],
        ]);
        $pdo->exec('UPDATE g_workspaces SET alert_pct = 20 WHERE alert_pct IS NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_price_history (
            id $id,
            workspace_id INT NOT NULL,
            item_id INT NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            source VARCHAR(20) NOT NULL,
            recorded_at DATETIME NOT NULL
        )");
        try {
            $pdo->exec('CREATE INDEX idx_ph_item ON g_price_history (item_id, recorded_at)');
        } catch (PDOException $e) {
            // indice già presente
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_stock_snapshots (
            id $id,
            workspace_id INT NOT NULL,
            day DATE NOT NULL,
            stock_value DECIMAL(12,2) NOT NULL,
            cards INT NOT NULL,
            revenue_total DECIMAL(12,2) NOT NULL,
            spent_total DECIMAL(12,2) NOT NULL,
            UNIQUE (workspace_id, day)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_notifications (
            id $id,
            workspace_id INT NOT NULL,
            item_id INT NULL,
            message VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_lot_shares (
            id $id,
            workspace_id INT NOT NULL,
            lot_id INT NOT NULL,
            user_id INT NOT NULL,
            share_pct DECIMAL(5,2) NOT NULL,
            UNIQUE (lot_id, user_id)
        )");
        // Il valore attuale delle carte diventa il primo punto dello storico.
        $pdo->exec("INSERT INTO g_price_history (workspace_id, item_id, price, source, recorded_at)
            SELECT workspace_id, id, est_value, 'manual', COALESCE(est_value_updated_at, created_at)
            FROM g_items WHERE est_value IS NOT NULL");
        g_set_schema($pdo, 4);
    }

    if ($version < 5) {
        // Altri giochi, premi inviti, statistiche d'uso.
        g_add_columns($pdo, [
            ['g_items', 'game', 'VARCHAR(20) NULL'],
            ['g_users', 'referral_rewarded_at', 'DATETIME NULL'],
        ]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_events (
            id $id,
            workspace_id INT NOT NULL,
            event VARCHAR(40) NOT NULL,
            created_at DATETIME NOT NULL
        )");
        try {
            $pdo->exec('CREATE INDEX idx_ev_time ON g_events (created_at, event)');
        } catch (PDOException $e) {
            // indice già presente
        }
        // Codice invito personale anche per gli utenti registrati prima.
        foreach ($pdo->query('SELECT id FROM g_users WHERE referral_code IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $pdo->prepare('UPDATE g_users SET referral_code = ? WHERE id = ?')->execute([g_invite_code(), $uid]);
        }
        g_set_schema($pdo, 5);
    }

    if ($version < 6) {
        g_add_columns($pdo, [
            ['g_workspaces', 'paypal_subscription_id', 'VARCHAR(40) NULL'],
        ]);
        g_set_schema($pdo, 6);
    }

    if ($version < 7) {
        // Analisi prodotti sigillati: cache dati CardTrader e pull rate personalizzati per set.
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_cache (
            k VARCHAR(190) PRIMARY KEY,
            v MEDIUMTEXT NOT NULL,
            expires_at DATETIME NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_pull_rates (
            id $id,
            expansion_id INT NOT NULL,
            rarity VARCHAR(80) NOT NULL,
            per_pack DECIMAL(8,5) NOT NULL,
            UNIQUE (expansion_id, rarity)
        )");
        g_set_schema($pdo, 7);
    }

    if ($version < 8) {
        // Sbustamenti registrati dagli utenti: pull rate reali dalla community.
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_openings (
            id $id,
            workspace_id INT NOT NULL,
            user_id INT NOT NULL,
            expansion_id INT NOT NULL,
            set_name VARCHAR(190) NULL,
            product VARCHAR(80) NOT NULL,
            packs INT NOT NULL,
            language VARCHAR(5) NULL,
            paid DECIMAL(10,2) NULL,
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            lot_id INT NULL,
            excluded INT NOT NULL DEFAULT 0,
            complete INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS g_opening_hits (
            id $id,
            workspace_id INT NOT NULL,
            opening_id INT NOT NULL,
            expansion_id INT NOT NULL,
            blueprint_id INT NOT NULL,
            rarity VARCHAR(80) NOT NULL,
            card_name VARCHAR(190) NOT NULL,
            qty INT NOT NULL
        )");
        try {
            $pdo->exec('CREATE INDEX idx_open_exp ON g_openings (expansion_id, excluded)');
            $pdo->exec('CREATE INDEX idx_hits_exp ON g_opening_hits (expansion_id, opening_id)');
        } catch (PDOException $e) {
            // indici già presenti
        }
        g_set_schema($pdo, 8);
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
