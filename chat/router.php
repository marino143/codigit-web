<?php
/**
 * Router za PHP-ov ugrađeni server (php -S), koji ne čita .htaccess:
 * blokira direktan pristup data/ mapi (baza + slike/videi).
 * Pokretanje:  php -S 0.0.0.0:8080 router.php
 */
declare(strict_types=1);

// ---- sigurnosna zaglavlja (chat je javno dostupan preko interneta) ----
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');                      // nitko ne smije ugraditi chat u iframe
header('Referrer-Policy: no-referrer');               // adresa chata ne curi na vanjske stranice
header('Permissions-Policy: geolocation=(), camera=(), payment=(), usb=()');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
// Sve je s vlastite domene; 'unsafe-inline' treba jer stranice imaju male ugrađene skripte.
header("Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; "
    . "font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; "
    . "frame-ancestors 'none'");

$uri = urldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#(^|/)(data|vendor)(/|$)#', $uri)
    || preg_match('#composer\.(json|lock)$#', $uri)
    || preg_match('#\.(md|sh|plist|yml|log|sqlite|command)$#i', $uri)
    || preg_match('#(^|/)\.#', $uri)) {          // sve skriveno (.git, .htaccess, .env…)
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
