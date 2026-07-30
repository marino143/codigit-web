<?php
/**
 * Posluživanje slika i videa iz data/uploads — samo prijavljenim korisnicima.
 * Podržava HTTP Range zahtjeve (potrebno za premotavanje videa na iPhoneu).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$user = current_user();
if ($user === null) {
    http_response_code(401);
    exit('Unauthorized');
}

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT file, mime, conversation_id FROM messages WHERE id = ? AND file IS NOT NULL');
$st->execute([$id]);
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    exit('Not found');
}

// Medije smiju vidjeti samo članovi razgovora
$conv = conv_get((int)$row['conversation_id']);
if ($conv === null || !is_conv_member($conv, $user)) {
    http_response_code(403);
    exit('Forbidden');
}

// basename() kao dodatna zaštita od path traversala
$path = CHAT_UPLOAD_DIR . '/' . basename((string)$row['file']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$size = filesize($path);
$mime = (string)$row['mime'] ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');

$start = 0;
$end = $size - 1;

if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    if ($m[1] === '' && $m[2] !== '') { // sufiks: zadnjih N bajtova
        $start = max(0, $size - (int)$m[2]);
        $end = $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

header('Content-Length: ' . ($end - $start + 1));

$fp = fopen($path, 'rb');
fseek($fp, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, min(1024 * 512, $remaining));
    if ($chunk === false) break;
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($fp);
