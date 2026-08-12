<?php
/**
 * Slaganje demo sadržaja za javni demo (chat.codigit.io).
 *
 * Pokretanje:  php demo-seed.php            — posij demo stanje
 *              php demo-seed.php --off      — ugasi demo način (makne data/demo.json)
 *
 * Skripta je ponovljiva: svako pokretanje obriše demo sadržaj i posije isti
 * početni razgovor, pa služi i kao noćni reset. Administratorski računi se NE
 * diraju — briše se samo ono što je demo posijao.
 *
 * Namjerno radi samo iz komandne linije: nema web sučelja koje bi netko izvana
 * mogao pozvati.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("demo-seed.php se pokreće samo iz komandne linije.\n");
}

require __DIR__ . '/lib.php';

// ---------- demo računi ----------
// Lozinke su javne — to je smisao demoa. Nijedan račun nije administrator.
const DEMO_PASSWORD = 'demo1234';
const DEMO_USERS = [
    ['user' => 'alex', 'name' => 'Alex Carter', 'note' => 'Product manager — has the most going on'],
    ['user' => 'sam',  'name' => 'Sam Rivera',  'note' => 'Designer'],
    ['user' => 'jo',   'name' => 'Jo Park',     'note' => 'Developer'],
];

$pdo = db();

if (in_array('--off', $argv, true)) {
    @unlink(CHAT_DEMO_FILE);
    echo "Demo način ugašen (data/demo.json obrisan). Sadržaj je ostao netaknut.\n";
    exit;
}

$names = array_column(DEMO_USERS, 'user');
$in    = implode(',', array_fill(0, count($names), '?'));

echo "Brišem prethodni demo sadržaj...\n";
$pdo->beginTransaction();

// Razgovori kojih je demo korisnik član — s njima ide i sve što na njima visi.
$st = $pdo->prepare("SELECT DISTINCT conversation_id FROM members WHERE username IN ($in)");
$st->execute($names);
$convIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

if ($convIds) {
    $cin = implode(',', array_fill(0, count($convIds), '?'));
    $mst = $pdo->prepare("SELECT id FROM messages WHERE conversation_id IN ($cin)");
    $mst->execute($convIds);
    $msgIds = array_map('intval', $mst->fetchAll(PDO::FETCH_COLUMN));
    if ($msgIds) {
        $min = implode(',', array_fill(0, count($msgIds), '?'));
        foreach (['reactions', 'flags', 'deleted_messages'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE message_id IN ($min)")->execute($msgIds);
        }
    }
    foreach (['messages', 'members', 'reads', 'pins', 'topics'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE conversation_id IN ($cin)")->execute($convIds);
    }
    $pdo->prepare("DELETE FROM conversations WHERE id IN ($cin)")->execute($convIds);
}
foreach (['push_subs', 'known_devices'] as $t) {
    $pdo->prepare("DELETE FROM $t WHERE username IN ($in)")->execute($names);
}
$pdo->prepare("DELETE FROM users WHERE username IN ($in)")->execute($names);

// Redovi koji su ostali bez svog razgovora ili svoje poruke — inače bi se
// gomilali svaki reset i demo bi s vremenom vukao nevidljivo smeće.
foreach (['messages', 'members', 'reads', 'pins', 'topics'] as $t) {
    $pdo->exec("DELETE FROM $t WHERE conversation_id NOT IN (SELECT id FROM conversations)");
}
foreach (['reactions', 'flags', 'deleted_messages'] as $t) {
    $pdo->exec("DELETE FROM $t WHERE message_id NOT IN (SELECT id FROM messages)");
}

// ---------- korisnici ----------
echo "Slažem demo korisnike...\n";
$hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
$now  = time();
$ins  = $pdo->prepare(
    'INSERT INTO users (username, name, hash, role, active, must_change, last_active, created_at)
     VALUES (?, ?, ?, "member", 1, 0, ?, ?)'
);
foreach (DEMO_USERS as $u) {
    $ins->execute([$u['user'], $u['name'], $hash, $now - 300, $now - 86400 * 30]);
}

// ---------- pomoćnici ----------
$mkConv = function (string $type, ?string $name, ?string $dmKey, string $by, array $members, int $at) use ($pdo): int {
    $pdo->prepare('INSERT INTO conversations (type, name, dm_key, created_by, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$type, $name, $dmKey, $by, $at]);
    $id = (int)$pdo->lastInsertId();
    $m  = $pdo->prepare('INSERT INTO members (conversation_id, username, joined_at) VALUES (?, ?, ?)');
    foreach ($members as $u) $m->execute([$id, $u, $at]);
    return $id;
};
$mkMsg = function (int $conv, string $from, string $body, int $at, array $opt = []) use ($pdo): int {
    $pdo->prepare('INSERT INTO messages (conversation_id, sender, type, body, created_at, reply_to, topic_id, edited_at)
                   VALUES (?, ?, "text", ?, ?, ?, ?, ?)')
        ->execute([$conv, $from, $body, $at, $opt['reply_to'] ?? null, $opt['topic_id'] ?? null, $opt['edited_at'] ?? null]);
    return (int)$pdo->lastInsertId();
};
$react = function (int $msg, string $user, string $emoji, int $at) use ($pdo): void {
    $pdo->prepare('INSERT OR IGNORE INTO reactions (message_id, username, emoji, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$msg, $user, $emoji, $at]);
};

$H = 3600;
$D = 86400;

// ---------- 1) DM: Alex ↔ Sam ----------
echo "Sijem razgovore...\n";
$dm = $mkConv('dm', null, dm_key('alex', 'sam'), 'alex', ['alex', 'sam'], $now - 5 * $D);
$t  = $now - 2 * $H;
$m1 = $mkMsg($dm, 'sam',  "Morning! I pushed the new empty states to the shared file — mind taking a look before standup?", $t - 40 * 60);
$m2 = $mkMsg($dm, 'alex', "Just looked. The illustration on the first one is lovely. 🙂", $t - 34 * 60);
$m3 = $mkMsg($dm, 'alex', "One thought: the copy says \"Nothing here yet\" on both screens. Could we make the second one say what to do next?", $t - 33 * 60);
$m4 = $mkMsg($dm, 'sam',  "Good catch. \"Invite someone to start a conversation\" — with the button right underneath?", $t - 28 * 60, ['reply_to' => $m3]);
$m5 = $mkMsg($dm, 'alex', "Exactly that. Ship it.", $t - 26 * 60);
$m6 = $mkMsg($dm, 'sam',  "Done and updated. Also fixed the spacing on mobile while I was in there.", $t - 12 * 60);
$react($m2, 'sam',  '❤️', $t - 30 * 60);
$react($m5, 'sam',  '🎉', $t - 25 * 60);
$react($m6, 'alex', '👍', $t - 10 * 60);

// ---------- 2) Grupa: Product Team ----------
$grp = $mkConv('group', 'Product Team', null, 'alex', ['alex', 'sam', 'jo'], $now - 12 * $D);
$t   = $now - 5 * $H;
$g1 = $mkMsg($grp, 'alex', "Standup in 10. Quick written version if you'd rather not talk: what you did, what's next, what's blocking you.", $t - 180 * 60);
$g2 = $mkMsg($grp, 'jo',   "Done: offline outbox, so messages queue up when you lose signal and send themselves when it comes back. Next: push notifications on Android. Blocked on nothing.", $t - 162 * 60);
$g3 = $mkMsg($grp, 'sam',  "Done: empty states + mobile spacing. Next: the settings screen. Slightly blocked — I need a decision on whether themes are per-device or per-account.", $t - 150 * 60);
$g4 = $mkMsg($grp, 'alex', "Per-device. My phone is dark at night and my laptop never is.", $t - 138 * 60, ['reply_to' => $g3]);
$g5 = $mkMsg($grp, 'jo',   "Agreed, and it's less to sync. I'll keep the theme in local storage then.", $t - 132 * 60);
$react($g2, 'alex', '🔥', $t - 156 * 60);
$react($g2, 'sam',  '👏', $t - 156 * 60);
$react($g4, 'sam',  '✅', $t - 132 * 60);

// tema (nit) — sporedni razgovor zakvačen za jednu poruku
$root = $mkMsg($grp, 'alex', "Separate thing: we need a name for the release. Ideas welcome — I'll start a topic so it doesn't drown out standup.", $t - 90 * 60);
$pdo->prepare('INSERT INTO topics (conversation_id, root_message_id, title, created_by, created_at) VALUES (?, ?, ?, ?, ?)')
    ->execute([$grp, $root, 'Name for the release', 'alex', $t - 90 * 60]);
$topicId = (int)$pdo->lastInsertId();
$k1 = $mkMsg($grp, 'sam', "\"Lighthouse\"? It fits the offline-first idea — still there when everything else goes dark.", $t - 80 * 60, ['topic_id' => $topicId]);
$k2 = $mkMsg($grp, 'jo',  "I like it. Short, easy to say, and nobody has to spell it twice on a call.", $t - 74 * 60, ['topic_id' => $topicId]);
$k3 = $mkMsg($grp, 'alex', "Lighthouse it is. I'll put it in the release notes.", $t - 70 * 60, ['topic_id' => $topicId]);
$react($k1, 'alex', '💡', $t - 78 * 60);
$react($k3, 'jo',   '🎉', $t - 68 * 60);

// ---------- 3) Kanal: Announcements ----------
$ch = $mkConv('channel', 'Announcements', null, 'alex', ['alex', 'sam', 'jo'], $now - 20 * $D);
$t  = $now - 26 * $H;
$c1 = $mkMsg($ch, 'alex', "👋 Welcome to the demo. This is a small self-hosted chat: it runs on one machine, keeps everything in a single folder, and sends no data to anyone else.", $t - $D);
$c2 = $mkMsg($ch, 'alex', "Things worth trying: reply to a message, react with an emoji, start a topic under a message, record a voice note, or drop in a photo.", $t - $D + 600);
$c3 = $mkMsg($ch, 'jo',   "It also works with no connection — write something with wi-fi off and watch it send itself when you come back.", $t - 20 * $H);
$c4 = $mkMsg($ch, 'sam',  "And it installs to the home screen like a normal app, on both phones and desktop.", $t - 6 * $H,
             ['edited_at' => $t - 5 * $H]);
$react($c1, 'sam', '👋', $t - $D + 300);
$react($c1, 'jo',  '❤️', $t - $D + 400);
$react($c3, 'alex', '👀', $t - 19 * $H);

// ---------- pročitanost ----------
// Alex je sve pročitao; ostali imaju koju nepročitanu — da demo izgleda živo.
$lastIn = function (int $conv) use ($pdo): int {
    $s = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?');
    $s->execute([$conv]);
    return (int)$s->fetchColumn();
};
$mkRead = $pdo->prepare('INSERT OR REPLACE INTO reads (conversation_id, username, last_read_id) VALUES (?, ?, ?)');
foreach ([$dm, $grp, $ch] as $conv) {
    $last = $lastIn($conv);
    $mkRead->execute([$conv, 'alex', $last]);
    $mkRead->execute([$conv, 'sam',  max(0, $last - 2)]);
    $mkRead->execute([$conv, 'jo',   max(0, $last - 1)]);
}

$pdo->commit();

// ---------- zaostale datoteke ----------
// Sve što su posjetitelji poslali ostalo je u uploads bez svoje poruke; brišemo
// samo datoteke na koje više nitko ne pokazuje, pa ne možemo dirati tuđi sadržaj.
$kept = array_flip(array_map('strval', $pdo->query('SELECT file FROM messages WHERE file IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)));
$removed = 0;
foreach (glob(CHAT_UPLOAD_DIR . '/*') ?: [] as $path) {
    $base = basename($path);
    if ($base === '.htaccess' || isset($kept[$base]) || !is_file($path)) continue;
    if (@unlink($path)) $removed++;
}
if ($removed) echo "Obrisano zaostalih datoteka: $removed\n";

// ---------- demo.json ----------
$accounts = [];
foreach (DEMO_USERS as $u) {
    $accounts[] = ['user' => $u['user'], 'pass' => DEMO_PASSWORD, 'name' => $u['name'], 'note' => $u['note']];
}
file_put_contents(CHAT_DEMO_FILE, json_encode([
    'accounts' => $accounts,
    'seeded_at' => $now,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$msgCount = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
echo "\nGotovo.\n";
echo "  korisnici : " . implode(', ', $names) . "  (lozinka: " . DEMO_PASSWORD . ")\n";
echo "  razgovori : DM Alex↔Sam, grupa Product Team (s temom), kanal Announcements\n";
echo "  poruka u bazi: $msgCount\n";
echo "  demo način upisan u: " . CHAT_DEMO_FILE . "\n";
