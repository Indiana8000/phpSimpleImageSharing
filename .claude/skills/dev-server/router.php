<?php
// Router for `php -S` that mimics the Apache rules in .htaccess (the built-in server ignores .htaccess).
// Usage (from the project root): php -S localhost:8000 .claude/skills/dev-server/router.php
$root = dirname(__DIR__, 3);
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$token = '[0-9A-HJKMNP-TV-Z]{26}';

if (preg_match('#^/config\.php$#i', $path) || preg_match('#^/(app|\.claude|\.git)(/|$)#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if (preg_match("#^/s/($token)/?$#i", $path, $m)) {
    $_GET['share'] = $m[1];
} elseif (preg_match('#^/s/#i', $path)) {
    $_GET['invalid_share'] = '1';
} elseif (preg_match("#^/($token)/?$#i", $path, $m) && !is_file($root . $path) && !is_dir($root . $path)) {
    $_GET['share'] = $m[1];
} elseif ($path !== '/' && $path !== '/index.php' && is_file($root . $path)) {
    return false; // serve other static files as-is
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir($root);
require $root . '/index.php';
