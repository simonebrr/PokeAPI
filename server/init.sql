-- Crea il database se non esiste
CREATE DATABASE IF NOT EXISTS app_db;
USE app_db;

-- Crea la tabella espansioni
CREATE TABLE IF NOT EXISTS expansions (
    id INT PRIMARY KEY,
    game_id INT NOT NULL,
    code VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    processed BOOLEAN DEFAULT FALSE
);

-- Crea la tabella carte singole
CREATE TABLE IF NOT EXISTS single_cards (
    blueprint_id INT PRIMARY KEY,
    name_en VARCHAR(255) NOT NULL,
    expansion_id INT NOT NULL,
    image_url VARCHAR(2048) NOT NULL,
    collector_number VARCHAR(50),
    FOREIGN KEY (expansion_id) REFERENCES expansions(id) ON DELETE CASCADE
);

-- Crea la tabella pokemon
CREATE TABLE IF NOT EXISTS pokemon (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL
);

-- Crea la tabella per la relazione N:N tra Pokémon e carte singole
CREATE TABLE IF NOT EXISTS pokemon_single_card (
    pokemon_id INT NOT NULL,
    single_card_id INT NOT NULL,
    PRIMARY KEY (pokemon_id, single_card_id),
    FOREIGN KEY (pokemon_id) REFERENCES pokemon(id) ON DELETE CASCADE,
    FOREIGN KEY (single_card_id) REFERENCES single_cards(blueprint_id) ON DELETE CASCADE
);
