<?php 

require_once '/var/www/html/public/api.php';

$db = new PokemonDatabase();

fetch_expansions($db);