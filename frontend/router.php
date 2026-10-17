<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Si l'URI commence déjà par /backend/
if (str_starts_with($uri, '/backend/')) {
    $backendFile = __DIR__ . $uri;
    if (is_file($backendFile)) {
        chdir(__DIR__ . '/backend');
        require $backendFile;
        return true;
    }
}

$backendFile = __DIR__ . '/backend' . $uri;
if ($uri !== '/' && is_file($backendFile)) {
    chdir(__DIR__ . '/backend');
    require $backendFile;
    return true;
}

return false;