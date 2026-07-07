<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
$_SERVER['APP_ENV'] = 'test';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: null;
if ($environment !== 'test') {
    throw new RuntimeException(sprintf(
        'Refus de lancer PHPUnit avec APP_ENV="%s"; attendu "test".',
        $environment ?? '(inconnu)',
    ));
}

$databaseUrl = $_SERVER['DATABASE_URL_TEST'] ?? $_ENV['DATABASE_URL_TEST'] ?? getenv('DATABASE_URL_TEST') ?: null;
$databasePath = is_string($databaseUrl) ? parse_url($databaseUrl, PHP_URL_PATH) : null;
$database = is_string($databasePath) ? ltrim($databasePath, '/') : null;

if ($database !== 'app_test') {
    throw new RuntimeException(sprintf(
        'Refus de lancer PHPUnit: DATABASE_URL_TEST cible "%s"; attendu "app_test".',
        $database ?? '(inconnue)',
    ));
}
