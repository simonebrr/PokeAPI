<?php
$method = $_SERVER['REQUEST_METHOD'];
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

$segments = explode('/', $path);

if ($segments[0] === 'api') {
    // Gestionale compravendita carte
    require_once __DIR__ . '/../src/gestionale/api.php';
    g_route($method, array_slice($segments, 1));
}

if ($path === '') {
    // Pagina di presentazione
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/home.html');
    exit;
}

header("Content-Type: application/json");

if ($segments[0] === 'card') {
    require_once '../src/api/single_cards.php';
} else if ($segments[0] === 'create-db') {
    require_once '../src/database.php';
    require_once 'api.php';
} else {
    echo json_encode(['error' => 'Route not found']);
}
