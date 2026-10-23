<?php
require_once __DIR__ . '/config.php';
try {
    // Parametri di connessione al database
    ['host' => $host, 'name' => $dbName, 'user' => $user, 'password' => $password] = db_config();
    $dsn = "mysql:host=$host";

    // Connessione al database
    $pdo = new PDO($dsn, $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Crea il database se non esiste
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbName");
    $pdo->exec("USE $dbName");

    // Crea la tabella espansioni
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS expansions (
            id INT PRIMARY KEY,
            game_id INT NOT NULL,
            code VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            processed BOOLEAN DEFAULT FALSE
        )
    ");

    // Crea la tabella carte singole
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS single_cards (
            blueprint_id INT PRIMARY KEY,
            name_en VARCHAR(255) NOT NULL,
            expansion_id INT NOT NULL,
            collector_number VARCHAR(50),
            FOREIGN KEY (expansion_id) REFERENCES expansions(id) ON DELETE CASCADE
        )
    ");

    // Crea la tabella pokemon
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pokemon (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL
        )
    ");

    // Crea la tabella per la relazione N:N tra Pokémon e carte singole
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pokemon_single_card (
            pokemon_id INT NOT NULL,
            single_card_id INT NOT NULL,
            PRIMARY KEY (pokemon_id, single_card_id),
            FOREIGN KEY (pokemon_id) REFERENCES pokemon(id) ON DELETE CASCADE,
            FOREIGN KEY (single_card_id) REFERENCES single_cards(blueprint_id) ON DELETE CASCADE
        )
    ");

    echo "Database e tabelle creati con successo.";
} catch (PDOException $e) {
    echo "Errore durante la creazione del database: " . $e->getMessage();
}
