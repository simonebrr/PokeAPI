<?php

class PokemonDatabase {
    private $pdo;

    // Parametri di connessione al database
    private $host = 'db';
    private $dbName = 'app_db';
    private $user = 'app_user';
    private $password = 'app_password';

    // Costruttore per la connessione al database
    public function __construct() {
        $dsn = "mysql:host={$this->host};dbname={$this->dbName}";
        try {
            // Connessione al database con PDO
            $this->pdo = new PDO($dsn, $this->user, $this->password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            echo "Errore di connessione: " . $e->getMessage();
            //phpinfo();
        }
    }

    // Funzione per verificare se esiste già un record con lo stesso ID
    private function recordExists($table, $column, $value) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
        $stmt->execute([$value]);
        return $stmt->fetchColumn() > 0;
    }

    // Funzione per popolare la tabella delle espansioni
    public function insertExpansion($id, $game_id, $code, $name) {
        if ($this->recordExists('expansions', 'id', $id)) {
            return;
        }
        $stmt = $this->pdo->prepare("INSERT INTO expansions (id, game_id, code, name) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $game_id, $code, $name]);
    }

    // Funzione per popolare la tabella delle carte singole
    public function insertSingleCard($blueprint_id, $name_en, $expansion_id, $imageUrl, $collector_number) {
        if ($this->recordExists('single_cards', 'blueprint_id', $blueprint_id)) {
            return;
        }
        $stmt = $this->pdo->prepare("INSERT INTO single_cards (blueprint_id, name_en, expansion_id, image_url, collector_number) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$blueprint_id, $name_en, $expansion_id, $imageUrl, $collector_number]);
    }

    // Funzione per popolare la tabella dei Pokémon
    public function insertPokemon($name) {
        if ($this->recordExists('pokemon', 'name', $name)) {
            return;
        }
        $stmt = $this->pdo->prepare("INSERT INTO pokemon (name) VALUES (?)");
        $stmt->execute([$name]);
    }

    // Funzione per popolare la relazione N:N tra Pokémon e carte singole
    public function insertPokemonSingleCardRelation($pokemon_id, $single_card_id) {
        if ($this->recordExists('pokemon_single_card', 'pokemon_id', $pokemon_id) && $this->recordExists('pokemon_single_card', 'single_card_id', $single_card_id)) {
            return;
        }
        $stmt = $this->pdo->prepare("INSERT INTO pokemon_single_card (pokemon_id, single_card_id) VALUES (?, ?)");
        $stmt->execute([$pokemon_id, $single_card_id]);
    }

    // Funzione per recuperare tutte le espansioni
    public function getExpansions() {
        $stmt = $this->pdo->prepare("SELECT * FROM expansions");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUnprocessedExpansions() {
        $stmt = $this->pdo->prepare("SELECT * FROM expansions WHERE processed = FALSE LIMIT 100");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setProcessedExpansion($expansion_id) {
        $stmt = $this->pdo->prepare("UPDATE expansions SET processed = '1' WHERE id = ?");
        $stmt->execute([$expansion_id]);
        echo "Espansione con ID $expansion_id marcata come processata.\n";
    }

    // Funzione per recuperare tutte le carte singole di una determinata espansione
    public function getSingleCardsByExpansion($expansion_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM single_cards WHERE expansion_id = ?");
        $stmt->execute([$expansion_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Funzione per recuperare tutti i Pokémon
    public function getAllPokemon() {
        $stmt = $this->pdo->prepare("SELECT * FROM pokemon");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPokemon($name) {
        $stmt = $this->pdo->prepare("SELECT * FROM pokemon WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Funzione per recuperare tutte le carte singole associate a un determinato Pokémon
    public function getSingleCardsByPokemon($pokemon_id) {
        $stmt = $this->pdo->prepare("
            SELECT sc.*
            FROM single_cards sc
            JOIN pokemon_single_card psc ON sc.blueprint_id = psc.single_card_id
            WHERE psc.pokemon_id = ?
        ");
        $stmt->execute([$pokemon_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSingleCardsByPokemonAndCode($pokemon_id, $code) {
        $stmt = $this->pdo->prepare("
            SELECT sc.*
            FROM single_cards sc
            JOIN pokemon_single_card psc ON sc.blueprint_id = psc.single_card_id
            JOIN expansions e ON sc.expansion_id = e.id
            WHERE psc.pokemon_id = ? AND sc.collector_number = ? LIMIT 1
        ");
        $stmt->execute([$pokemon_id, $code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Funzione per chiudere la connessione al database
    public function closeConnection() {
        $this->pdo = null;
    }
}

?>