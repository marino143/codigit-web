<?php
/**
 * Bot koji odgovara u chatu (Claude Haiku 4.5).
 *
 * Pokreće ga api.php u pozadini nakon svake poslane poruke:
 *     php bot.php <id-poruke>
 *
 * Odgovara u DM-u uvijek, a u grupi i kanalu samo kad ga netko spomene s @.
 * Postavke i API ključ su u data/bot.json (izvan gita):
 *
 *     {
 *       "user": "robi",
 *       "api_key": "sk-ant-...",
 *       "model": "claude-haiku-4-5",
 *       "replies_per_hour": 60
 *     }
 *
 * Bez te datoteke bot jednostavno ne postoji i chat radi kao i prije.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bot.php se pokreće samo iz komandne linije.\n");
}

require __DIR__ . '/lib.php';
require __DIR__ . '/vendor/autoload.php';

const BOT_LOG          = CHAT_DATA_DIR . '/bot.log';
const BOT_CONTEXT_MSGS = 20;   // koliko zadnjih poruka bot vidi
const BOT_MAX_TOKENS   = 400;  // kratki odgovori — i manji račun

function bot_log(string $line): void {
    @file_put_contents(BOT_LOG, date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND);
}

$messageId = (int)($argv[1] ?? 0);
if ($messageId <= 0) exit;

$bot = bot_username();
if ($bot === '') exit;                       // bot nije postavljen

$cfg   = chat_bot();
$model = (string)($cfg['model'] ?? 'claude-haiku-4-5');
$pdo   = db();

// ---------- poruka koja je pokrenula bota ----------
$st = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
$st->execute([$messageId]);
$msg = $st->fetch();
if (!$msg) exit;
if (is_bot((string)$msg['sender'])) exit;    // ne odgovaramo sami sebi

$conv = conv_get((int)$msg['conversation_id']);
if ($conv === null || !is_conv_member($conv, $bot)) exit;   // bot nije u razgovoru

// ---------- smije li bot uopće odgovoriti ----------
$botRow = user_row($bot);
if ($botRow === null || !(int)$botRow['active']) exit;

// U DM-u je svaka poruka njemu upućena; u grupi i kanalu čekamo spominjanje.
$body = (string)$msg['body'];
if ($conv['type'] !== 'dm') {
    $names = [$bot, strtolower((string)$botRow['name'])];
    $hit = false;
    foreach (array_unique($names) as $n) {
        if ($n !== '' && preg_match('/(^|\W)@' . preg_quote($n, '/') . '(\W|$)/iu', $body)) {
            $hit = true;
            break;
        }
    }
    if (!$hit) exit;
}

// Gornja granica troška — bot ne smije pisati bez kraja.
$rl = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE sender = ? AND created_at > ?');
$rl->execute([$bot, time() - 3600]);
if ((int)$rl->fetchColumn() >= bot_hourly_limit()) {
    bot_log("limit dosegnut, preskacem poruku #$messageId");
    exit;
}

// ---------- kontekst razgovora ----------
// Tema je zaseban tok, pa bot čita samo nju kad je poruka iz teme.
$topicId = $msg['topic_id'] !== null ? (int)$msg['topic_id'] : null;
if ($topicId !== null) {
    $ctx = $pdo->prepare('SELECT sender, body, type FROM messages
                          WHERE conversation_id = ? AND topic_id = ? AND id <= ?
                          ORDER BY id DESC LIMIT ' . BOT_CONTEXT_MSGS);
    $ctx->execute([(int)$conv['id'], $topicId, $messageId]);
} else {
    $ctx = $pdo->prepare('SELECT sender, body, type FROM messages
                          WHERE conversation_id = ? AND topic_id IS NULL AND id <= ?
                          ORDER BY id DESC LIMIT ' . BOT_CONTEXT_MSGS);
    $ctx->execute([(int)$conv['id'], $messageId]);
}
$rows = array_reverse($ctx->fetchAll());

// Poruke se slažu u parove korisnik/asistent. API traži da se uloge izmjenjuju
// i da razgovor počne korisnikom, pa uzastopne iste uloge spajamo u jednu.
$messages = [];
foreach ($rows as $r) {
    $text = trim((string)$r['body']);
    if ($r['type'] !== 'text') {
        $text = $text !== '' ? $text : '[' . $r['type'] . ']';   // slika/video/glas
    }
    if ($text === '') continue;

    $mine = is_bot((string)$r['sender']);
    $role = $mine ? 'assistant' : 'user';
    $line = $mine ? $text : display_name((string)$r['sender']) . ': ' . $text;

    $last = count($messages) - 1;
    if ($last >= 0 && $messages[$last]['role'] === $role) {
        $messages[$last]['content'] .= "\n" . $line;
    } else {
        $messages[] = ['role' => $role, 'content' => $line];
    }
}
while ($messages && $messages[0]['role'] === 'assistant') array_shift($messages);
if (!$messages) exit;

// ---------- upit ----------
$where = $conv['type'] === 'dm'
    ? 'a direct message'
    : 'the ' . $conv['type'] . ' "' . (string)$conv['name'] . '"';

$system = implode("\n", [
    'You are ' . $botRow['name'] . ', a friendly assistant living inside a small self-hosted chat app.',
    'You are talking in ' . $where . '. Several people may be in the conversation;',
    'each of their messages is prefixed with their name, but do not prefix your own replies.',
    '',
    'Keep replies short — usually one to three sentences, as people write in a chat.',
    'Be warm and concrete. Use plain text; this chat does not render Markdown.',
    'Reply in whatever language the person wrote to you in.',
    'If you do not know something about this chat or the people in it, say so plainly.',
    'This is a public demo: anyone can talk to you, and every night the conversation is wiped.',
]);

try {
    $client = new Anthropic\Client(apiKey: (string)$cfg['api_key']);
    $reply  = $client->messages->create(
        model:     $model,
        maxTokens: BOT_MAX_TOKENS,
        system:    $system,
        messages:  $messages,
    );
} catch (Anthropic\Core\Exceptions\APIStatusException $e) {
    bot_log("API greska ({$e->type?->value}) na poruci #$messageId: " . $e->getMessage());
    exit;
} catch (Throwable $e) {
    bot_log("greska na poruci #$messageId: " . $e->getMessage());
    exit;
}

$text = '';
foreach ($reply->content as $block) {
    if ($block->type === 'text') $text .= $block->text;
}
$text = trim($text);
if ($text === '') {
    bot_log("prazan odgovor na poruku #$messageId (stop: {$reply->stopReason})");
    exit;
}

// ---------- odgovor u chat ----------
$ins = $pdo->prepare('INSERT INTO messages (conversation_id, sender, type, body, created_at, reply_to, topic_id)
                      VALUES (?, ?, "text", ?, ?, ?, ?)');
$ins->execute([(int)$conv['id'], $bot, $text, time(), $messageId, $topicId]);
$newId = (int)$pdo->lastInsertId();
touch_activity($bot);
push_notify_async($newId);

$u = $reply->usage;
bot_log(sprintf('#%d -> #%d  %s  ulaz %d, izlaz %d tokena',
    $messageId, $newId, $model, $u->inputTokens, $u->outputTokens));
