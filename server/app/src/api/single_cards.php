<?php
require_once 'response.php';
require_once __DIR__ . '/../config.php';
require_once '../src/PokemonDatabase.php';

$database = new PokemonDatabase();

function fetchFromApi(string $url, string $tokenFilePath, array $queryParams = []): ?array
{
    // Token da variabile d'ambiente CARDTRADER_TOKEN (vedi server/.env.example)
    $token = cardtrader_token();
    if (!$token) {
        die("Token CardTrader mancante: imposta CARDTRADER_TOKEN nel file server/.env\n");
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

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        
        $name = $_GET['pokemon'] ?? null;
        $code = $_GET['code'] ?? null;
        if ($name && $code) {
            $pokemon = $database->getPokemon($name);
            if ($pokemon) {
                $blueprint = $database->getSingleCardsByPokemonAndCode($pokemon["id"], $code);
            }
            if ($blueprint) {
                

                //example: http://localhost:8080/card/?pokemon=zapdos&code=15/62

                $url = 'https://api.cardtrader.com/api/v2/marketplace/products';
                $queryParams = ['blueprint_id' => $blueprint['blueprint_id']];
                $tokenFile = '/var/www/html/src/api/token.txt';

                $response = fetchFromApi($url, $tokenFile, $queryParams);
                //print_r($response);
                respond(array($blueprint["image_url"] ?? "", $response));
            } else {
                respond(['error' => 'Pokemon not found'], 404);
            }
        } else {
        }
        break;



    default:
        respond(['error' => 'Method not allowed'], 405);
}
