<?php
// Connessione e schema del gestionale.
// DSN configurabile via env (GESTIONALE_DSN), default: MySQL del docker-compose.

function g_pdo(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $dsn  = getenv('GESTIONALE_DSN') ?: 'mysql:host=db;dbname=app_db;charset=utf8mb4';
    $user = getenv('GESTIONALE_DB_USER') ?: 'app_user';
    $pass = getenv('GESTIONALE_DB_PASSWORD') ?: 'app_password';
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
}
