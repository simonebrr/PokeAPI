<?php

require_once '/var/www/html/src/PokemonDatabase.php';

/**
 * Effettua una richiesta GET all'API con autorizzazione Bearer e restituisce la risposta come array PHP.
 *
 * @param string $url L'URL dell'API.
 * @param string $tokenFilePath Percorso del file contenente il token.
 * @param array $queryParams Array associativo di parametri query (opzionale).
 * @return array|null La risposta decodificata come array o null in caso di errore.
 */
function fetchFromApi(string $url, string $tokenFilePath, array $queryParams = []): ?array
{
    // Carica il token dal file
    if (!file_exists($tokenFilePath)) {
        die("Il file $tokenFilePath non esiste.\n");
    }
    $token = trim(file_get_contents($tokenFilePath));

    if (!$token) {
        die("Il token è vuoto. Assicurati che il file $tokenFilePath contenga un token valido.\n");
    }

    // Aggiungi i parametri query all'URL, se presenti
    if (!empty($queryParams)) {
        $url .= '?' . http_build_query($queryParams);
    }

    // Inizializza cURL
    $ch = curl_init();

    // Imposta le opzioni di cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
    ]);

    // Esegui la richiesta
    $response = curl_exec($ch);

    // Gestisci errori di cURL
    if (curl_errno($ch)) {
        echo "Errore cURL: " . curl_error($ch) . "\n";
        curl_close($ch);
        return null;
    }

    // Chiudi la connessione cURL
    curl_close($ch);

    // Decodifica la risposta JSON in un array PHP
    $decodedResponse = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Errore nella decodifica JSON: " . json_last_error_msg() . "\n";
        return null;
    }

    return $decodedResponse;
}

function fetch_expansion_single_cards($expansion_id, $database)
{
    $url = 'https://api.cardtrader.com/api/v2/blueprints/export';
    $queryParams = ['expansion_id' => $expansion_id];
    $tokenFile = '/var/www/html/src/api/token.txt';
    $pokemons = $database->getAllPokemon();

    $response = fetchFromApi($url, $tokenFile, $queryParams);
    
    if ($response) {
        foreach ($response as $card) {
            if ($card['category_id'] == 73) {
                $database->insertSingleCard($card['id'], $card['name'], $card['expansion_id'], $card["image"]["show"]["url"], explode(" ", $card['fixed_properties']['collector_number'])[0]);

                if (count(array_filter($pokemons, function ($pokemon) use ($card) {
                    return $pokemon["name"] == $card['name'];
                })) == 0) {
                    $database->insertPokemon($card['name']);
                    $pokemons = $database->getAllPokemon();
                    $pokemon_id = array_search($card['name'], array_column($pokemons, 'name'));
                    $database->insertPokemonSingleCardRelation($pokemons[$pokemon_id]["id"], $card['id']);
                } else {
                    $pokemon_id = array_search($card['name'], array_column($pokemons, 'name'));
                    $database->insertPokemonSingleCardRelation($pokemons[$pokemon_id]["id"], $card['id']);
                }
            }
        }
    } else {
        echo "Nessuna risposta valida ricevuta dall'API.\n";
    }
}

function fetch_expansions($database)
{
    $url = 'https://api.cardtrader.com/api/v2/expansions';
    $tokenFile = '/var/www/html/src/api/token.txt';

    $response = fetchFromApi($url, $tokenFile);

    // filter the expansions and keep only the ones with the gmae_id = 5
    $response = array_filter($response, function ($expansion) {
        return $expansion['game_id'] == 5;
    });

    if ($response) {

        foreach ($response as $expansion) {
            echo ("Expansion: " . $expansion['name'] . " " . $expansion["id"] . "\n");
            $database->insertExpansion($expansion['id'], $expansion['game_id'], $expansion['code'], $expansion['name']);
            //fetch_expansion_single_cards($expansion['id'], $database);
        }
    } else {
        echo "Nessuna risposta valida ricevuta dall'API.\n";
    }
}