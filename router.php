<?php
/**
 * Router for the PHP built-in server, which does not read .htaccess.
 * Replicates the friendly-URL rewrites for local development:
 *   php -S localhost:8000 router.php
 * Not used in production (Apache/LiteSpeed handles .htaccess).
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/linea/[0-9]{4}/?$#', $path)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

if (preg_match('#^/cargar(/[0-9]{4})?/?$#', $path)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/cargar.html');
    return true;
}

if (preg_match('#^/validar/([a-f0-9]{64})/?$#', $path, $m)) {
    $_GET['token'] = $m[1];
    require __DIR__ . '/api/verify.php';
    return true;
}

// The built-in server falls back to index.html for unknown extensionless
// URIs; return a real 404 instead so dev matches production (Apache).
if ($path !== '/' && !str_contains(basename($path), '.') && !is_dir(__DIR__ . $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    return true;
}

// Anything else: let the built-in server resolve the file normally.
return false;
