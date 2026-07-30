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

$messageId = (int)($argv[1] ?? 0);
if ($messageId <= 0) exit(1);

$pdo = db();

$st = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
$st->execute([$messageId]);
$msg = $st->fetch();
$st->closeCursor(); // ne drži čitalačku transakciju dok traje mrežno slanje
if (!$msg) exit(0);

$conv = conv_get((int)$msg['conversation_id']);
if ($conv === null) exit(0);

$keysFile = CHAT_DATA_DIR . '/push-keys.json';
if (!is_file($keysFile)) exit(0);
$vapid = json_decode((string)file_get_contents($keysFile), true);

// Primatelji: članovi razgovora, bez pošiljatelja, bez upravo aktivnih (oni to vide uživo)
$recipients = [];
foreach (conv_members($conv) as $u) {
    if ($u === $msg['sender']) continue;
    $row = user_row($u);
    if ($row === null || !(int)$row['active']) continue;
    if (time() - (int)$row['last_active'] < 10) continue;
    $recipients[] = $u;
}
if (!$recipients) exit(0);

$in = implode(',', array_fill(0, count($recipients), '?'));
$st = $pdo->prepare("SELECT * FROM push_subs WHERE username IN ($in)");
$st->execute($recipients);
$subs = $st->fetchAll();
if (!$subs) exit(0);

$senderName = display_name($msg['sender']);
$title = $conv['type'] === 'dm'
    ? $senderName
    : $senderName . ' · ' . conv_display_name($conv, '');
$bodyText = $msg['type'] === 'image' ? '📷 Photo'
    : ($msg['type'] === 'video' ? '🎬 Video'
    : ($msg['type'] === 'audio' ? '🎤 Voice message' : mb_substr((string)$msg['body'], 0, 120)));

$payload = json_encode([
    'title' => $title,
    'body'  => $bodyText,
    'conv'  => (int)$msg['conversation_id'],
    'tag'   => 'conv-' . $msg['conversation_id'],
], JSON_UNESCAPED_UNICODE);

$webPush = new WebPush([
    'VAPID' => [
        'subject'    => $vapid['subject'],
        'publicKey'  => $vapid['publicKey'],
        'privateKey' => $vapid['privateKey'],
    ],
]);

foreach ($subs as $s) {
    $webPush->queueNotification(Subscription::create([
        'endpoint' => $s['endpoint'],
        'keys' => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
    ]), $payload);
}

foreach ($webPush->flush() as $report) {
    if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
        $pdo->prepare('DELETE FROM push_subs WHERE endpoint = ?')
            ->execute([$report->getEndpoint()]);
    }
}
