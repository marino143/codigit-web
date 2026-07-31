<?php
/**
 * Router za PHP-ov ugrađeni server (php -S), koji ne čita .htaccess:
 * blokira direktan pristup data/ mapi (baza + slike/videi).
 * Pokretanje:  php -S 0.0.0.0:8080 router.php
 */
declare(strict_types=1);

$uri = urldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#(^|/)(data|vendor)(/|$)#', $uri) || preg_match('#composer\.(json|lock)$#', $uri)) {
    http_response_code(403);
    exit('Forbidden');
}

// sw.js i manifest.json ne smiju ostati u CDN cacheu — inače uređaji tjednima
// vrte staru verziju aplikacije (assets/ imaju ?v= pa se smiju keširati)
if (preg_match('#/(sw\.js|manifest\.json)$#', $uri)) {
    $file = __DIR__ . $uri;
    if (is_file($file)) {
        header('Content-Type: ' . (str_ends_with($uri, '.js') ? 'application/javascript' : 'application/manifest+json'));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($file);
        return true;
    }
}
return false; // sve ostalo poslužuje server normalno
