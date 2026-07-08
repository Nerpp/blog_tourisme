<?php

declare(strict_types=1);

$publicDirectory = dirname(__DIR__, 2).'/public';
$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$requestedFile = realpath($publicDirectory.$requestPath);

if (
    $requestPath !== '/'
    && is_string($requestedFile)
    && str_starts_with($requestedFile, $publicDirectory.DIRECTORY_SEPARATOR)
    && is_file($requestedFile)
) {
    return false;
}

require $publicDirectory.'/index.php';
