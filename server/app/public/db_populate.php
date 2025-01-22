<?php

file_put_contents('/var/www/html/public/cron_output.log', "Cron partito: " . date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND);

require_once '/var/www/html/src/PokemonDatabase.php';
require_once 'api.php';

$database = new PokemonDatabase();

$unprocessedExpansions = $database->getUnprocessedExpansions();

if(count($unprocessedExpansions) === 0) {
    file_put_contents('/var/www/html/public/cron_output.log', "Nessuna espansione da processare." . PHP_EOL, FILE_APPEND);
    exit;
} else {
    foreach ($unprocessedExpansions as $expansion) {
        echo ("Expansion: " . $expansion['name'] . " " . $expansion["id"] . "\n");
        $database->setProcessedExpansion($expansion['id']);
        fetch_expansion_single_cards($expansion['id'], $database);
    }
}

file_put_contents('/var/www/html/public/cron_output.log', "Cron finito: " . date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND);