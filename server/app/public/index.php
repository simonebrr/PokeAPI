<?php
header("Content-Type: application/json");

$method = $_SERVER['REQUEST_METHOD'];
$path = trim($_SERVER['REQUEST_URI'], '/');

$segments = explode('/', $path);

if ($segments[0] === 'card') {
    require_once '../src/api/single_cards.php';
} else if ($segments[0] === 'create-db') {
    require_once '../src/database.php';
} else {
    echo json_encode(['error' => 'Route not found']);
}