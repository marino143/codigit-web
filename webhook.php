<?php
/**
 * GitHub Webhook handler — auto-deploys codigit.hr on every push to main
 */

$secret = 'a447efd25f70990a37e13f4a3cfe2d0944f5f5dd67cf5430';

$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload   = file_get_contents('php://input');
$expected  = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    die('Forbidden');
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
$data  = json_decode($payload, true);

if ($event !== 'push' || ($data['ref'] ?? '') !== 'refs/heads/main') {
    http_response_code(200);
    die('OK');
}

// Pull latest from GitHub
exec('cd /home/codihr/repos/codigit-web && git pull origin main 2>&1', $pull_out, $pull_code);

// Copy files to public_html
exec('cp /home/codihr/repos/codigit-web/index.html /home/codihr/public_html/ 2>&1', $cp1_out);
exec('cp /home/codihr/repos/codigit-web/.htaccess /home/codihr/public_html/ 2>&1', $cp2_out);

http_response_code(200);
echo json_encode([
    'status' => 'deployed',
    'pull'   => implode("\n", $pull_out),
]);
