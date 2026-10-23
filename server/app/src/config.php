<?php
// Configurazione letta dalle variabili d'ambiente (file server/.env, passato da docker-compose).

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

// Token API CardTrader: variabile CARDTRADER_TOKEN.
// Compatibilità: se manca, prova il vecchio file src/api/token.txt (escluso da git).
function cardtrader_token(): string
{
    $token = env('CARDTRADER_TOKEN');
    if (!$token) {
        $file = env('CARDTRADER_TOKEN_FILE', __DIR__ . '/api/token.txt');
        $token = is_file($file) ? trim((string)file_get_contents($file)) : '';
    }
    return $token;
}

function db_config(): array
{
    return [
        'host' => env('DB_HOST', 'db'),
        'name' => env('DB_NAME', 'app_db'),
        'user' => env('DB_USER', 'app_user'),
        'password' => env('DB_PASSWORD', 'app_password'),
    ];
}
