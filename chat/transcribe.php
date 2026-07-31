<?php
/**
 * Pozadinska transkripcija glasovne poruke (poziva se iz lib.php preko
 * transcribe_async, nikad izravno iz weba).
 *
 *   php transcribe.php <message_id>
 *
 * Konverzija u 16 kHz WAV ide macOS-ovim afconvert-om (podržava m4a/aac/mp3/wav;
 * webm/opus iz desktop Chromea NE podržava — tada transkript ostaje prazan),
 * a transkripcija lokalnim whisper.cpp modelom. Ništa ne napušta ovaj Mac.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/lib.php';

$messageId = (int)($argv[1] ?? 0);
if ($messageId <= 0) exit(1);

$pdo = db();
$st = $pdo->prepare('SELECT * FROM messages WHERE id = ? AND type = "audio" AND file IS NOT NULL');
$st->execute([$messageId]);
$msg = $st->fetch();
$st->closeCursor(); // inače drži čitalačku transakciju otvorenom dok whisper radi
if (!$msg) exit(0);

$fail = function () use ($pdo, $messageId): never {
    // '' = pokušano ali nije uspjelo (razlikuje se od NULL = u tijeku)
    $pdo->prepare('UPDATE messages SET transcript = "" WHERE id = ? AND transcript IS NULL')
        ->execute([$messageId]);
    exit(0);
};

if (!is_file(WHISPER_BIN) || !is_file(WHISPER_MODEL)) $fail();

$src = CHAT_UPLOAD_DIR . '/' . basename((string)$msg['file']);
if (!is_file($src)) $fail();

$tmpBase = sys_get_temp_dir() . '/chat-transcribe-' . $messageId;
$wav = $tmpBase . '.wav';

// 1) u 16 kHz mono WAV (format koji whisper.cpp očekuje)
exec(sprintf('/usr/bin/afconvert -f WAVE -d LEI16@16000 -c 1 %s %s 2>/dev/null',
    escapeshellarg($src), escapeshellarg($wav)), $o, $rc);
if ($rc !== 0 || !is_file($wav)) $fail();

// 2) Odredi jezik. Whisper na kratkim porukama zna hrvatski proglasiti ruskim
// ili srpskim, pa svaku takvu detekciju tumačimo kao hrvatski; ostali jezici
// (engleski, njemački…) razlikuju se pouzdano i ostaju kako su detektirani.
$lang = 'hr';
exec(sprintf('%s -m %s -f %s --detect-language 2>&1',
    escapeshellarg(WHISPER_BIN), escapeshellarg(WHISPER_MODEL),
    escapeshellarg($wav)), $detOut);
if (preg_match('/auto-detected language:\s*([a-z]{2})/i', implode("\n", $detOut), $m)) {
    $detected = strtolower($m[1]);
    $slavicMixups = ['ru', 'sr', 'bs', 'sl', 'mk', 'uk', 'be', 'bg'];
    $lang = in_array($detected, $slavicMixups, true) ? 'hr' : $detected;
}

// 3) whisper.cpp — greedy dekodiranje (--beam-size 1): brže uz zanemariv gubitak
exec(sprintf('%s -m %s -f %s --language %s --no-timestamps -np --beam-size 1 --best-of 1 -otxt -of %s 2>/dev/null',
    escapeshellarg(WHISPER_BIN), escapeshellarg(WHISPER_MODEL),
    escapeshellarg($wav), escapeshellarg($lang), escapeshellarg($tmpBase)), $o, $rc);

$txtFile = $tmpBase . '.txt';
$text = is_file($txtFile) ? trim((string)file_get_contents($txtFile)) : '';
@unlink($wav);
@unlink($txtFile);

if ($rc !== 0) $fail();

// prazan rezultat (tišina) spremamo kao '' da UI ne prikazuje "Transcribing…" zauvijek
$text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
$pdo->prepare('UPDATE messages SET transcript = ? WHERE id = ?')->execute([$text, $messageId]);
