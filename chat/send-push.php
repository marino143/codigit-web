<?php
/**
 * Pozadinsko slanje push notifikacija za jednu poruku (poziva se iz lib.php
 * preko push_notify_async, nikad izravno iz weba).
 *
 *   php send-push.php <message_id>
 *
 * Šalje svim članovima razgovora osim pošiljatelja, preskačući korisnike
 * koji su upravo aktivni u aplikaciji. Mrtve pretplate (410) se brišu.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/lib.php';
require __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$pdo = db();

$keysFile = CHAT_DATA_DIR . '/push-keys.json';
if (!is_file($keysFile)) exit(0);
$vapid = json_decode((string)file_get_contents($keysFile), true);

/** Kratki zapis u log da se problemi mogu dijagnosticirati. */
$log = function (string $line): void {
    @file_put_contents(CHAT_DATA_DIR . '/push.log',
        date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND);
};

// --- testni način: php send-push.php --test <username> ---
if (($argv[1] ?? '') === '--test') {
    $recipients = [(string)($argv[2] ?? '')];
    $title = 'Our Chat';
    $bodyText = 'Test notification ✅ — notifications are working.';
    $tag = 'selftest';
    $convId = 0;
} elseif (($argv[1] ?? '') === '--signin') {
    // upozorenje o prijavi s novog uređaja
    $recipients = [(string)($argv[2] ?? '')];
    $title = '🔐 New sign-in';
    $bodyText = (string)($argv[3] ?? 'Unknown device') . ' just signed in. '
        . 'If this was not you, change your password.';
    $tag = 'signin-' . time();
    $convId = 0;
} else {
    $messageId = (int)($argv[1] ?? 0);
    if ($messageId <= 0) exit(1);

    $st = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
    $st->execute([$messageId]);
    $msg = $st->fetch();
    $st->closeCursor(); // ne drži čitalačku transakciju dok traje mrežno slanje
    if (!$msg) exit(0);

    $conv = conv_get((int)$msg['conversation_id']);
    if ($conv === null) exit(0);

    // Primatelji: članovi razgovora, bez pošiljatelja, bez upravo aktivnih (oni to vide uživo)
    $recipients = [];
    foreach (conv_members($conv) as $u) {
        if ($u === $msg['sender']) continue;
        $row = user_row($u);
        if ($row === null || !(int)$row['active']) continue;
        if (time() - (int)$row['last_active'] < 10) continue;
        $recipients[] = $u;
    }

    $senderName = display_name($msg['sender']);
    $title = $conv['type'] === 'dm' ? $senderName : $senderName . ' · ' . conv_display_name($conv, '');
    $bodyText = $msg['type'] === 'image' ? '📷 Photo'
        : ($msg['type'] === 'video' ? '🎬 Video'
        : ($msg['type'] === 'audio' ? '🎤 Voice message' : mb_substr((string)$msg['body'], 0, 120)));
    $tag = 'conv-' . $msg['conversation_id'];
    $convId = (int)$msg['conversation_id'];
}

if (!$recipients) exit(0);

// Pretplate koje se nisu javile 60 dana su iz preglednika/instalacija koje se
// više ne koriste — one bi samo slale duplikate, pa ih čistimo.
$pdo->prepare('DELETE FROM push_subs WHERE last_seen > 0 AND last_seen < ?')
    ->execute([time() - 60 * 86400]);

$in = implode(',', array_fill(0, count($recipients), '?'));
$st = $pdo->prepare("SELECT * FROM push_subs WHERE username IN ($in)");
$st->execute($recipients);
$subs = $st->fetchAll();
if (!$subs) exit(0);

/** Ukupno nepročitanih poruka korisnika — za broj na ikoni aplikacije. */
$unreadFor = function (string $u) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM messages m
        JOIN members mem ON mem.conversation_id = m.conversation_id AND mem.username = ?
        LEFT JOIN reads r ON r.conversation_id = m.conversation_id AND r.username = ?
        WHERE m.sender != ? AND m.id > COALESCE(r.last_read_id, 0)');
    $st->execute([$u, $u, $u]);
    return (int)$st->fetchColumn();
};

$webPush = new WebPush([
    'VAPID' => [
        'subject'    => $vapid['subject'],
        'publicKey'  => $vapid['publicKey'],
        'privateKey' => $vapid['privateKey'],
    ],
]);

// payload se razlikuje po primatelju (broj nepročitanih je osobni podatak)
$payloadFor = function (string $u) use ($title, $bodyText, $convId, $tag, $unreadFor): string {
    return json_encode([
        'title' => $title,
        'body'  => $bodyText,
        'conv'  => $convId,
        'tag'   => $tag,
        'badge' => $unreadFor($u),
    ], JSON_UNESCAPED_UNICODE);
};

// Svaka pretplata ide zasebno: neispravni ključevi bacaju iznimku pri
// enkripciji, a ne smiju spriječiti isporuku ostalima.
$ok = 0; $failed = 0;
foreach ($subs as $s) {
    $host = parse_url($s['endpoint'], PHP_URL_HOST) ?: '?';
    try {
        $report = $webPush->sendOneNotification(Subscription::create([
            'endpoint' => $s['endpoint'],
            'keys' => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
        ]), $payloadFor((string)$s['username']));

        if ($report->isSuccess()) { $ok++; continue; }
        $failed++;
        if ($report->isSubscriptionExpired()) {
            $pdo->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$s['id']]);
            $log("expired, removed #{$s['id']} ({$s['username']}, $host)");
        } else {
            $log("FAILED #{$s['id']} ({$s['username']}, $host): " . substr($report->getReason(), 0, 160));
        }
    } catch (Throwable $e) {
        // npr. neispravni ključevi pretplate — takva pretplata je neupotrebljiva
        $failed++;
        $pdo->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$s['id']]);
        $log("BROKEN, removed #{$s['id']} ({$s['username']}, $host): " . substr($e->getMessage(), 0, 120));
    }
}
if ($failed > 0 || $tag === 'selftest') {
    $log(sprintf('%s → sent %d, failed %d (%s)', $tag, $ok, $failed, implode(',', $recipients)));
}
