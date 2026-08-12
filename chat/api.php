<?php
/**
 * JSON API: poll (poruke + popis razgovora + prisutnost), send, upload,
 * kreiranje razgovora (dm/grupa/kanal), upravljanje članovima, promjena lozinke.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$user = require_auth_api();
$action = $_GET['action'] ?? '';

// Service worker nema pristup CSRF tokenu stranice; taj poziv brani SameSite=Lax
// kolačić (cross-site POST ne nosi sesiju) i to što upisuje samo vlastitu pretplatu.
$csrfExempt = ['push_resubscribe'];
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array($action, $csrfExempt, true)
    && !csrf_check()) {
    json_out(['error' => 'csrf'], 403);
}

$pdo = db();

// "Aktivan" znači da korisnik STVARNO gleda chat — aplikacija u pozadini se ne
// broji, inače bi izgledao prisutan i nikad ne bi dobio push notifikaciju.
if ($action !== 'poll' || ($_GET['visible'] ?? '') === '1') {
    touch_activity($user);
}

/** Razgovor iz parametra `conv` — 404/403 ako ne postoji ili korisnik nije član. */
function require_conv(string $user, ?string $param = null): array {
    $id = (int)($param ?? ($_GET['conv'] ?? $_POST['conv'] ?? 0));
    $conv = conv_get($id);
    if ($conv === null) json_out(['error' => 'no_conv'], 404);
    if (!is_conv_member($conv, $user)) json_out(['error' => 'forbidden'], 403);
    return $conv;
}

/** Popis razgovora korisnika sa zadnjom porukom i brojem nepročitanih. */
function conv_list(PDO $pdo, string $user): array {
    $st = $pdo->prepare('SELECT c.* FROM conversations c
        JOIN members m ON m.conversation_id = c.id AND m.username = ?');
    $st->execute([$user]);
    $out = [];
    foreach ($st->fetchAll() as $conv) {
        $cid = (int)$conv['id'];
        $last = $pdo->prepare('SELECT sender, type, body, created_at, id FROM messages
            WHERE conversation_id = ? ORDER BY id DESC LIMIT 1');
        $last->execute([$cid]);
        $lm = $last->fetch() ?: null;

        $rd = $pdo->prepare('SELECT last_read_id FROM reads WHERE conversation_id = ? AND username = ?');
        $rd->execute([$cid, $user]);
        $lastRead = (int)$rd->fetchColumn();

        $un = $pdo->prepare('SELECT COUNT(*) FROM messages
            WHERE conversation_id = ? AND id > ? AND sender != ?');
        $un->execute([$cid, $lastRead, $user]);

        $out[] = [
            'id'          => $cid,
            'type'        => $conv['type'],
            'name'        => conv_display_name($conv, $user),
            'created_by'  => $conv['created_by'],
            'unread'      => (int)$un->fetchColumn(),
            'last_body'   => $lm ? ($lm['type'] === 'text' ? mb_substr($lm['body'], 0, 80)
                : ($lm['type'] === 'image' ? '📷 Photo' : ($lm['type'] === 'audio' ? '🎤 Voice message' : '🎬 Video'))) : '',
            'last_sender' => $lm ? display_name($lm['sender']) : '',
            'last_ts'     => $lm ? (int)$lm['created_at'] : (int)$conv['created_at'],
        ];
    }
    // Prikvačeni idu na vrh, redoslijedom koji je korisnik sam posložio;
    // ostali ispod, po vremenu zadnje poruke.
    $pin = $pdo->prepare('SELECT conversation_id, sort_order FROM pins WHERE username = ?');
    $pin->execute([$user]);
    $pins = $pin->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($out as &$c) {
        $c['pinned'] = isset($pins[$c['id']]);
        $c['pin_order'] = (int)($pins[$c['id']] ?? 0);
    }
    unset($c);

    usort($out, function ($a, $b) {
        if ($a['pinned'] !== $b['pinned']) return $b['pinned'] <=> $a['pinned'];
        if ($a['pinned']) return $a['pin_order'] <=> $b['pin_order'];
        return $b['last_ts'] <=> $a['last_ts'];
    });
    return $out;
}

switch ($action) {

    case 'poll': {
        $response = [
            'convs' => conv_list($pdo, $user),
            'users' => active_users(),
            'me'    => [
                'username' => $user,
                'name'     => display_name($user),
                'admin'    => is_admin($user),
                'timezone' => (string)user_row($user)['timezone'],
            ],
            'now'   => time(),
        ];

        if (isset($_GET['conv']) && (int)$_GET['conv'] > 0) {
            $conv = require_conv($user);
            $cid = (int)$conv['id'];
            $since = max(0, (int)($_GET['since'] ?? 0));

            // Klijent javlja do koje je poruke pročitao
            $readUpTo = (int)($_GET['read'] ?? 0);
            if ($readUpTo > 0) {
                $st = $pdo->prepare('INSERT INTO reads (conversation_id, username, last_read_id) VALUES (?, ?, ?)
                    ON CONFLICT(conversation_id, username) DO UPDATE SET
                        last_read_id = MAX(reads.last_read_id, excluded.last_read_id)');
                $st->execute([$cid, $user, $readUpTo]);
            }

            $st = $pdo->prepare('SELECT m.id, m.sender, m.type, m.body, m.file, m.mime, m.size,
                    m.created_at, m.transcript, m.reply_to, m.edited_at,
                    r.sender AS reply_sender, r.type AS reply_type, r.body AS reply_body,
                    r.transcript AS reply_transcript
                FROM messages m
                LEFT JOIN messages r ON r.id = m.reply_to
                WHERE m.conversation_id = ? AND m.id > ? AND m.topic_id IS NULL
                ORDER BY m.id ASC LIMIT 500');
            $st->execute([$cid, $since]);
            $messages = $st->fetchAll();
            foreach ($messages as &$m) {
                $m['sender_name'] = display_name($m['sender']);
                if ($m['reply_to'] !== null && $m['reply_sender'] !== null) {
                    $txt = (string)$m['reply_body'];
                    if ($txt === '') {
                        $txt = $m['reply_type'] === 'image' ? '📷 Photo'
                            : ($m['reply_type'] === 'video' ? '🎬 Video'
                            : ($m['reply_type'] === 'audio'
                                ? ('🎤 ' . mb_substr((string)$m['reply_transcript'], 0, 60)) : ''));
                    }
                    $m['reply'] = [
                        'id'     => (int)$m['reply_to'],
                        'sender' => display_name((string)$m['reply_sender']),
                        'text'   => mb_substr($txt, 0, 90),
                    ];
                }
                unset($m['reply_sender'], $m['reply_type'], $m['reply_body'], $m['reply_transcript']);
            }
            unset($m);
            $response['messages'] = $messages;
            $response['can_delete_any'] = can_manage_conv($conv, $user);

            // teme otvorene u ovom razgovoru (za oznaku "N replies" na poruci)
            $tp = $pdo->prepare('SELECT t.id, t.root_message_id, t.title, COUNT(m.id) replies
                FROM topics t LEFT JOIN messages m ON m.topic_id = t.id
                WHERE t.conversation_id = ? GROUP BY t.id');
            $tp->execute([$cid]);
            $topics = [];
            foreach ($tp->fetchAll() as $t) {
                $topics[(string)$t['root_message_id']] = [
                    'id'      => (int)$t['id'],
                    'title'   => $t['title'],
                    'replies' => (int)$t['replies'],
                ];
            }
            $response['topics'] = $topics;

            // reakcije na porukama ovog razgovora (grupirano po poruci i emojiju)
            $rx = $pdo->prepare('SELECT r.message_id, r.emoji, COUNT(*) n,
                    SUM(CASE WHEN r.username = ? THEN 1 ELSE 0 END) mine,
                    GROUP_CONCAT(r.username) who
                FROM reactions r JOIN messages m ON m.id = r.message_id
                WHERE m.conversation_id = ?
                GROUP BY r.message_id, r.emoji');
            $rx->execute([$user, $cid]);
            $reactions = [];
            foreach ($rx->fetchAll() as $r) {
                $names = array_map('display_name', explode(',', (string)$r['who']));
                $reactions[(string)$r['message_id']][] = [
                    'emoji' => $r['emoji'],
                    'n'     => (int)$r['n'],
                    'mine'  => (int)$r['mine'] > 0,
                    'who'   => implode(', ', $names),
                ];
            }
            $response['reactions'] = $reactions;

            // moje oznake u ovom razgovoru
            $fl = $pdo->prepare('SELECT f.message_id FROM flags f
                JOIN messages m ON m.id = f.message_id
                WHERE f.username = ? AND m.conversation_id = ?');
            $fl->execute([$user, $cid]);
            $response['flagged'] = array_map('intval', $fl->fetchAll(PDO::FETCH_COLUMN));

            // Klijent mora znati što je obrisano da makne poruku s ekrana
            $del = $pdo->prepare('SELECT message_id FROM deleted_messages WHERE conversation_id = ?');
            $del->execute([$cid]);
            $response['deleted'] = array_map('intval', $del->fetchAll(PDO::FETCH_COLUMN));

            // Transkripti stižu naknadno (pozadinski posao) — šaljemo ih za već
            // isporučene audio poruke da ih klijent može naknadno upisati
            $tr = $pdo->prepare('SELECT id, transcript FROM messages
                WHERE conversation_id = ? AND type = "audio" AND transcript IS NOT NULL');
            $tr->execute([$cid]);
            $response['transcripts'] = $tr->fetchAll(PDO::FETCH_KEY_PAIR);
            $response['conv'] = ['id' => $cid, 'type' => $conv['type'], 'name' => conv_display_name($conv, $user)];

            // Kvačice "pročitano" samo u privatnom razgovoru
            if ($conv['type'] === 'dm') {
                $partner = null;
                foreach (explode('|', (string)$conv['dm_key']) as $u2) {
                    if ($u2 !== $user) $partner = $u2;
                }
                $rd = $pdo->prepare('SELECT last_read_id FROM reads WHERE conversation_id = ? AND username = ?');
                $rd->execute([$cid, $partner]);
                $response['partner_read'] = (int)$rd->fetchColumn();
                $response['partner'] = $partner;

                // Koliko je sati kod sugovornika (null ako nema zonu ili je ista kao naša)
                $pRow = user_row((string)$partner);
                $meRow = user_row($user);
                $response['partner_time'] = $pRow
                    ? user_local_time((string)$pRow['timezone'], (string)$meRow['timezone'])
                    : null;
            }
        }
        json_out($response);
    }

    case 'send': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $conv = require_conv($user);
        if (demo_rate_exceeded($user)) json_out(['error' => 'demo_rate'], 429);
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 10000) json_out(['error' => 'empty'], 400);

        // citirana poruka mora biti iz istog razgovora
        $replyTo = (int)($_POST['reply_to'] ?? 0) ?: null;
        if ($replyTo !== null) {
            $chk = $pdo->prepare('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ?');
            $chk->execute([$replyTo, (int)$conv['id']]);
            if (!$chk->fetchColumn()) $replyTo = null;
        }

        // poruka može ići u temu (nit) unutar istog razgovora
        $topicId = (int)($_POST['topic'] ?? 0) ?: null;
        if ($topicId !== null) {
            $tc = $pdo->prepare('SELECT 1 FROM topics WHERE id = ? AND conversation_id = ?');
            $tc->execute([$topicId, (int)$conv['id']]);
            if (!$tc->fetchColumn()) $topicId = null;
        }

        // "pošalji i u razgovor": poruka iz teme se pojavi i u glavnom toku,
        // s citatom korijenske poruke da se zna iz koje je teme došla
        $alsoToConv = $topicId !== null && ($_POST['also_conv'] ?? '') === '1';
        if ($alsoToConv) {
            $rt = $pdo->prepare('SELECT root_message_id FROM topics WHERE id = ?');
            $rt->execute([$topicId]);
            $rootId = (int)$rt->fetchColumn() ?: null;
            $pdo->prepare('INSERT INTO messages (conversation_id, sender, type, body, created_at, reply_to)
                VALUES (?, ?, "text", ?, ?, ?)')
                ->execute([(int)$conv['id'], $user, $body, time(), $rootId]);
        }

        $st = $pdo->prepare('INSERT INTO messages (conversation_id, sender, type, body, created_at, reply_to, topic_id)
            VALUES (?, ?, "text", ?, ?, ?, ?)');
        $st->execute([(int)$conv['id'], $user, $body, time(), $replyTo, $topicId]);
        $id = (int)$pdo->lastInsertId();
        push_notify_async($id);
        bot_reply_async($id);
        json_out(['ok' => true, 'id' => $id]);
    }

    case 'upload': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $conv = require_conv($user);
        if (demo_rate_exceeded($user)) json_out(['error' => 'demo_rate'], 429);
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            // Najčešći razlog: datoteka veća od PHP limita (upload_max_filesize)
            json_out(['error' => 'nofile', 'hint' => 'The file did not arrive — it is probably larger than the server limit.'], 400);
        }
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'upload_' . $f['error']], 400);
        if ($f['size'] > chat_max_upload()) json_out(['error' => 'toobig'], 400);

        $mime = (string)(mime_content_type($f['tmp_name']) ?: $f['type']);
        $type = null; $ext = null;
        if (($_POST['kind'] ?? '') === 'audio') {
            // glasovna poruka — iOS je snima u mp4 kontejner pa mime zna biti video/*
            if (isset(CHAT_AUDIO_MIMES[$mime])) { $type = 'audio'; $ext = CHAT_AUDIO_MIMES[$mime]; }
        } else {
            if (isset(CHAT_IMAGE_MIMES[$mime])) { $type = 'image'; $ext = CHAT_IMAGE_MIMES[$mime]; }
            if (isset(CHAT_VIDEO_MIMES[$mime])) { $type = 'video'; $ext = CHAT_VIDEO_MIMES[$mime]; }
        }
        if ($type === null) json_out(['error' => 'type', 'mime' => $mime], 415);

        if (!is_dir(CHAT_UPLOAD_DIR)) mkdir(CHAT_UPLOAD_DIR, 0755, true);
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], CHAT_UPLOAD_DIR . '/' . $name)) {
            json_out(['error' => 'save'], 500);
        }

        $caption = trim((string)($_POST['body'] ?? ''));
        $topicId = (int)($_POST['topic'] ?? 0) ?: null;
        if ($topicId !== null) {
            $tc = $pdo->prepare('SELECT 1 FROM topics WHERE id = ? AND conversation_id = ?');
            $tc->execute([$topicId, (int)$conv['id']]);
            if (!$tc->fetchColumn()) $topicId = null;
        }

        $st = $pdo->prepare('INSERT INTO messages (conversation_id, sender, type, body, file, mime, size, created_at, topic_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([(int)$conv['id'], $user, $type, $caption, $name, $mime, (int)$f['size'], time(), $topicId]);
        $id = (int)$pdo->lastInsertId();
        if ($type === 'audio') transcribe_async($id);
        push_notify_async($id);
        json_out(['ok' => true, 'id' => $id]);
    }

    case 'create_dm': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $other = strtolower(trim((string)($_POST['user'] ?? '')));
        $row = user_row($other);
        if ($other === $user || $row === null || !(int)$row['active']) json_out(['error' => 'no_user'], 400);
        $id = dm_conversation($pdo, $user, $other);
        json_out(['ok' => true, 'id' => $id]);
    }

    case 'create_group': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) json_out(['error' => 'name'], 400);
        $members = json_decode((string)($_POST['members'] ?? '[]'), true);
        if (!is_array($members)) $members = [];
        $members = array_unique(array_merge([$user], array_map('strval', $members)));

        $pdo->prepare('INSERT INTO conversations (type, name, created_by, created_at) VALUES ("group", ?, ?, ?)')
            ->execute([$name, $user, time()]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('INSERT OR IGNORE INTO members (conversation_id, username, joined_at) VALUES (?, ?, ?)');
        foreach ($members as $m) {
            $row = user_row($m);
            if ($row !== null && (int)$row['active']) $st->execute([$id, $m, time()]);
        }
        json_out(['ok' => true, 'id' => $id]);
    }

    case 'create_channel': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        if (!is_admin($user)) json_out(['error' => 'forbidden'], 403);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) json_out(['error' => 'name'], 400);
        $members = json_decode((string)($_POST['members'] ?? '[]'), true);
        if (!is_array($members)) $members = [];
        $members = array_unique(array_merge([$user], array_map('strval', $members)));

        $pdo->prepare('INSERT INTO conversations (type, name, created_by, created_at) VALUES ("channel", ?, ?, ?)')
            ->execute([$name, $user, time()]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('INSERT OR IGNORE INTO members (conversation_id, username, joined_at) VALUES (?, ?, ?)');
        foreach ($members as $m) {
            $row = user_row($m);
            if ($row !== null && (int)$row['active']) $st->execute([$id, $m, time()]);
        }
        json_out(['ok' => true, 'id' => $id]);
    }

    case 'conv_info': {
        $conv = require_conv($user);
        $meTz = (string)user_row($user)['timezone'];
        $members = [];
        foreach (conv_members($conv) as $m) {
            $mRow = user_row($m);
            $members[] = [
                'username'   => $m,
                'name'       => display_name($m),
                'local_time' => $mRow ? user_local_time((string)$mRow['timezone'], $meTz) : null,
            ];
        }
        json_out([
            'id'         => (int)$conv['id'],
            'type'       => $conv['type'],
            'name'       => conv_display_name($conv, $user),
            'created_by' => $conv['created_by'],
            'members'    => $members,
            'can_manage' => can_manage_conv($conv, $user),
        ]);
    }

    case 'add_member':
    case 'remove_member': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $conv = require_conv($user);
        if (!can_manage_conv($conv, $user)) json_out(['error' => 'forbidden'], 403);
        $target = strtolower(trim((string)($_POST['user'] ?? '')));
        if ($action === 'add_member') {
            $row = user_row($target);
            if ($row === null || !(int)$row['active']) json_out(['error' => 'no_user'], 400);
            $pdo->prepare('INSERT OR IGNORE INTO members (conversation_id, username, joined_at) VALUES (?, ?, ?)')
                ->execute([(int)$conv['id'], $target, time()]);
        } else {
            if ($target === $conv['created_by']) json_out(['error' => 'owner'], 400);
            $pdo->prepare('DELETE FROM members WHERE conversation_id = ? AND username = ?')
                ->execute([(int)$conv['id'], $target]);
        }
        json_out(['ok' => true]);
    }

    case 'leave': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $conv = require_conv($user);
        if ($conv['type'] === 'dm') json_out(['error' => 'type'], 400);
        if ($conv['created_by'] === $user) json_out(['error' => 'owner', 'hint' => 'The owner cannot leave.'], 400);
        $pdo->prepare('DELETE FROM members WHERE conversation_id = ? AND username = ?')
            ->execute([(int)$conv['id'], $user]);
        json_out(['ok' => true]);
    }

    /** Prikvači/otkvači razgovor (osobno). Novi prikvačeni ide na vrh. */
    case 'pin': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $conv = require_conv($user);
        $cid = (int)$conv['id'];

        $has = $pdo->prepare('SELECT 1 FROM pins WHERE username = ? AND conversation_id = ?');
        $has->execute([$user, $cid]);
        if ($has->fetchColumn()) {
            $pdo->prepare('DELETE FROM pins WHERE username = ? AND conversation_id = ?')->execute([$user, $cid]);
            json_out(['ok' => true, 'pinned' => false]);
        }
        $min = $pdo->prepare('SELECT COALESCE(MIN(sort_order), 0) - 1 FROM pins WHERE username = ?');
        $min->execute([$user]);
        $pdo->prepare('INSERT INTO pins (username, conversation_id, sort_order) VALUES (?, ?, ?)')
            ->execute([$user, $cid, (int)$min->fetchColumn()]);
        json_out(['ok' => true, 'pinned' => true]);
    }

    /** Pomakni prikvačeni razgovor gore/dolje u vlastitom redoslijedu. */
    case 'pin_move': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $conv = require_conv($user);
        $cid = (int)$conv['id'];
        $dir = ($_POST['dir'] ?? '') === 'down' ? 'down' : 'up';

        $st = $pdo->prepare('SELECT conversation_id FROM pins WHERE username = ? ORDER BY sort_order');
        $st->execute([$user]);
        $order = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $i = array_search($cid, $order, true);
        if ($i === false) json_out(['error' => 'not_pinned'], 400);
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if ($j < 0 || $j >= count($order)) json_out(['ok' => true, 'moved' => false]);

        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        $upd = $pdo->prepare('UPDATE pins SET sort_order = ? WHERE username = ? AND conversation_id = ?');
        foreach ($order as $pos => $id) $upd->execute([$pos, $user, $id]);
        json_out(['ok' => true, 'moved' => true]);
    }

    /** Dodaj/ukloni emoji reakciju na poruku (vide je svi u razgovoru). */
    case 'react': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $id = (int)($_POST['id'] ?? 0);
        $emoji = (string)($_POST['emoji'] ?? '');
        if (!in_array($emoji, CHAT_REACTIONS, true)) json_out(['error' => 'bad_emoji'], 400);

        $st = $pdo->prepare('SELECT conversation_id FROM messages WHERE id = ?');
        $st->execute([$id]);
        $convId = $st->fetchColumn();
        if ($convId === false) json_out(['error' => 'not_found'], 404);

        $conv = conv_get((int)$convId);
        if ($conv === null || !is_conv_member($conv, $user)) json_out(['error' => 'forbidden'], 403);

        $has = $pdo->prepare('SELECT 1 FROM reactions WHERE message_id = ? AND username = ? AND emoji = ?');
        $has->execute([$id, $user, $emoji]);
        if ($has->fetchColumn()) {
            $pdo->prepare('DELETE FROM reactions WHERE message_id = ? AND username = ? AND emoji = ?')
                ->execute([$id, $user, $emoji]);
            json_out(['ok' => true, 'added' => false]);
        }
        $pdo->prepare('INSERT INTO reactions (message_id, username, emoji, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$id, $user, $emoji, time()]);
        json_out(['ok' => true, 'added' => true]);
    }

    /** Označi/odznači poruku (oznaka je osobna — vide je samo vlastite oči). */
    case 'flag': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $id = (int)($_POST['id'] ?? 0);

        $st = $pdo->prepare('SELECT conversation_id FROM messages WHERE id = ?');
        $st->execute([$id]);
        $convId = $st->fetchColumn();
        if ($convId === false) json_out(['error' => 'not_found'], 404);

        $conv = conv_get((int)$convId);
        if ($conv === null || !is_conv_member($conv, $user)) json_out(['error' => 'forbidden'], 403);

        $has = $pdo->prepare('SELECT 1 FROM flags WHERE message_id = ? AND username = ?');
        $has->execute([$id, $user]);
        if ($has->fetchColumn()) {
            $pdo->prepare('DELETE FROM flags WHERE message_id = ? AND username = ?')->execute([$id, $user]);
            json_out(['ok' => true, 'flagged' => false]);
        }
        $pdo->prepare('INSERT INTO flags (message_id, username, created_at) VALUES (?, ?, ?)')
            ->execute([$id, $user, time()]);
        json_out(['ok' => true, 'flagged' => true]);
    }

    /** Sve moje označene poruke (kroz sve razgovore u kojima jesam). */
    case 'flagged': {
        $ids = [];
        foreach (conv_list($pdo, $user) as $c) $ids[(int)$c['id']] = $c;
        if (!$ids) json_out(['results' => []]);
        $in = implode(',', array_map('intval', array_keys($ids)));

        $st = $pdo->prepare("SELECT m.id, m.conversation_id, m.sender, m.type, m.body, m.transcript, m.created_at
            FROM flags f JOIN messages m ON m.id = f.message_id
            WHERE f.username = ? AND m.conversation_id IN ($in)
            ORDER BY f.created_at DESC LIMIT 100");
        $st->execute([$user]);

        $out = [];
        foreach ($st->fetchAll() as $m) {
            $text = (string)$m['body'];
            if ($text === '' && $m['type'] === 'audio') $text = (string)$m['transcript'];
            $out[] = [
                'id'          => (int)$m['id'],
                'conv'        => (int)$m['conversation_id'],
                'conv_name'   => $ids[(int)$m['conversation_id']]['name'],
                'conv_type'   => $ids[(int)$m['conversation_id']]['type'],
                'sender_name' => display_name($m['sender']),
                'type'        => $m['type'],
                'snippet'     => mb_substr($text, 0, 120),
                'created_at'  => (int)$m['created_at'],
            ];
        }
        json_out(['results' => $out]);
    }

    /** Izmjena vlastite poruke (tekst ili opis uz privitak). */
    case 'edit_message': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $id = (int)($_POST['id'] ?? 0);
        $body = trim((string)($_POST['body'] ?? ''));

        $st = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
        $st->execute([$id]);
        $msg = $st->fetch();
        if (!$msg) json_out(['error' => 'not_found'], 404);
        if ($msg['sender'] !== $user) json_out(['error' => 'forbidden'], 403);

        $conv = conv_get((int)$msg['conversation_id']);
        if ($conv === null || !is_conv_member($conv, $user)) json_out(['error' => 'forbidden'], 403);

        // tekstualna poruka ne smije ostati prazna; uz privitak smije (briše se opis)
        if ($body === '' && $msg['type'] === 'text') json_out(['error' => 'empty'], 400);
        if (mb_strlen($body) > 10000) json_out(['error' => 'too_long'], 400);
        if ($body === (string)$msg['body']) json_out(['ok' => true, 'unchanged' => true]);

        $pdo->prepare('UPDATE messages SET body = ?, edited_at = ? WHERE id = ?')
            ->execute([$body, time(), $id]);
        json_out(['ok' => true, 'id' => $id]);
    }

    /**
     * Brisanje poruke: vlastite uvijek, tuđe samo ako si osnivač grupe/kanala
     * ili administrator. Briše se i pripadajuća datoteka s diska.
     */
    case 'delete_message': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $id = (int)($_POST['id'] ?? 0);

        $st = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
        $st->execute([$id]);
        $msg = $st->fetch();
        if (!$msg) json_out(['error' => 'not_found'], 404);

        $conv = conv_get((int)$msg['conversation_id']);
        if ($conv === null || !is_conv_member($conv, $user)) json_out(['error' => 'forbidden'], 403);

        $mine = $msg['sender'] === $user;
        if (!$mine && !can_manage_conv($conv, $user)) json_out(['error' => 'forbidden'], 403);

        if (!empty($msg['file'])) {
            @unlink(CHAT_UPLOAD_DIR . '/' . basename((string)$msg['file']));
        }
        $pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([$id]);
        $pdo->prepare('INSERT OR REPLACE INTO deleted_messages (message_id, conversation_id, deleted_at)
            VALUES (?, ?, ?)')->execute([$id, (int)$conv['id'], time()]);
        // stara evidencija više nikome ne treba
        $pdo->prepare('DELETE FROM deleted_messages WHERE deleted_at < ?')->execute([time() - 30 * 86400]);
        json_out(['ok' => true, 'id' => $id]);
    }

    /** Sve slike, videi i glasovne poruke iz razgovora, grupirano po tipu. */
    case 'files': {
        $conv = require_conv($user);
        $st = $pdo->prepare('SELECT id, sender, type, body, mime, size, created_at, transcript
            FROM messages WHERE conversation_id = ? AND file IS NOT NULL
            ORDER BY id DESC LIMIT 500');
        $st->execute([(int)$conv['id']]);

        $groups = ['image' => [], 'video' => [], 'audio' => []];
        foreach ($st->fetchAll() as $m) {
            $type = (string)$m['type'];
            if (!isset($groups[$type])) continue;
            $groups[$type][] = [
                'id'          => (int)$m['id'],
                'sender_name' => display_name($m['sender']),
                'mine'        => $m['sender'] === $user,
                'size'        => (int)$m['size'],
                'created_at'  => (int)$m['created_at'],
                'caption'     => mb_substr((string)$m['body'], 0, 80),
                'transcript'  => $type === 'audio' ? mb_substr((string)$m['transcript'], 0, 100) : null,
            ];
        }
        json_out(['groups' => $groups, 'conv_name' => conv_display_name($conv, $user)]);
    }

    case 'search': {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) json_out(['results' => []]);
        // LIKE bez specijalnih znakova (\ je escape)
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

        // samo razgovori u kojima korisnik smije čitati
        $ids = [];
        foreach (conv_list($pdo, $user) as $c) $ids[$c['id']] = $c;
        if (!$ids) json_out(['results' => []]);
        $in = implode(',', array_map('intval', array_keys($ids)));

        $st = $pdo->prepare("SELECT id, conversation_id, sender, type, body, transcript, created_at
            FROM messages
            WHERE conversation_id IN ($in)
              AND (body LIKE ? ESCAPE '\\' OR transcript LIKE ? ESCAPE '\\')
            ORDER BY id DESC LIMIT 50");
        $st->execute([$like, $like]);

        $results = [];
        foreach ($st->fetchAll() as $m) {
            $text = (string)($m['type'] === 'audio' && $m['body'] === '' ? $m['transcript'] : $m['body']);
            if ($text === '') $text = (string)$m['transcript'];
            $results[] = [
                'id'          => (int)$m['id'],
                'conv'        => (int)$m['conversation_id'],
                'conv_name'   => $ids[(int)$m['conversation_id']]['name'],
                'conv_type'   => $ids[(int)$m['conversation_id']]['type'],
                'sender_name' => display_name($m['sender']),
                'type'        => $m['type'],
                'snippet'     => mb_substr($text, 0, 120),
                'created_at'  => (int)$m['created_at'],
            ];
        }
        json_out(['results' => $results, 'q' => $q]);
    }

    case 'set_timezone': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $tz = trim((string)($_POST['timezone'] ?? ''));
        if (!valid_timezone($tz)) json_out(['error' => 'bad_tz'], 400);
        // ne pregazi ono što je korisnik sam odabrao u postavkama
        $pdo->prepare('UPDATE users SET timezone = ? WHERE username = ? AND timezone = ""')
            ->execute([$tz, $user]);
        json_out(['ok' => true]);
    }

    case 'push_subscribe': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $endpoint = (string)($_POST['endpoint'] ?? '');
        $p256dh = (string)($_POST['p256dh'] ?? '');
        $auth = (string)($_POST['auth'] ?? '');
        if (!str_starts_with($endpoint, 'https://') || $p256dh === '' || $auth === '') {
            json_out(['error' => 'bad_sub'], 400);
        }
        $pdo->prepare('INSERT INTO push_subs (username, endpoint, p256dh, auth, created_at, last_seen)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(endpoint) DO UPDATE SET username = excluded.username,
                p256dh = excluded.p256dh, auth = excluded.auth, last_seen = excluded.last_seen')
            ->execute([$user, $endpoint, $p256dh, $auth, time(), time()]);
        json_out(['ok' => true]);
    }

    /**
     * Service worker javlja da je preglednik zamijenio pretplatu.
     * Bez CSRF-a jer poziv dolazi iz service workera (sesija ga i dalje veže
     * uz korisnika, a upisuje se samo vlastita pretplata).
     */
    case 'push_resubscribe': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $endpoint = (string)($_POST['endpoint'] ?? '');
        $p256dh = (string)($_POST['p256dh'] ?? '');
        $auth = (string)($_POST['auth'] ?? '');
        $old = (string)($_POST['old_endpoint'] ?? '');
        if (!str_starts_with($endpoint, 'https://') || $p256dh === '' || $auth === '') {
            json_out(['error' => 'bad_sub'], 400);
        }
        if ($old !== '') {
            $pdo->prepare('DELETE FROM push_subs WHERE endpoint = ? AND username = ?')->execute([$old, $user]);
        }
        $pdo->prepare('INSERT INTO push_subs (username, endpoint, p256dh, auth, created_at, last_seen)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(endpoint) DO UPDATE SET username = excluded.username,
                p256dh = excluded.p256dh, auth = excluded.auth, last_seen = excluded.last_seen')
            ->execute([$user, $endpoint, $p256dh, $auth, time(), time()]);
        json_out(['ok' => true]);
    }

    /** Otvori (ili kreiraj) temu vezanu uz poruku. */
    case 'topic_open': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $msgId = (int)($_POST['message_id'] ?? 0);

        $st = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
        $st->execute([$msgId]);
        $msg = $st->fetch();
        if (!$msg) json_out(['error' => 'not_found'], 404);
        if ($msg['topic_id'] !== null) json_out(['error' => 'already_in_topic'], 400);

        $conv = conv_get((int)$msg['conversation_id']);
        if ($conv === null || !is_conv_member($conv, $user)) json_out(['error' => 'forbidden'], 403);

        $ex = $pdo->prepare('SELECT * FROM topics WHERE root_message_id = ?');
        $ex->execute([$msgId]);
        if ($topic = $ex->fetch()) {
            json_out(['ok' => true, 'id' => (int)$topic['id'], 'title' => $topic['title'], 'created' => false]);
        }

        // naslov teme: iz same poruke (ili tip privitka)
        $title = trim((string)$msg['body']);
        if ($title === '') {
            $title = $msg['type'] === 'image' ? '📷 Photo'
                : ($msg['type'] === 'video' ? '🎬 Video'
                : ($msg['type'] === 'audio' ? '🎤 Voice message' : 'Topic'));
        }
        $title = mb_substr($title, 0, 80);

        $pdo->prepare('INSERT INTO topics (conversation_id, root_message_id, title, created_by, created_at)
            VALUES (?, ?, ?, ?, ?)')
            ->execute([(int)$msg['conversation_id'], $msgId, $title, $user, time()]);
        json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'title' => $title, 'created' => true]);
    }

    /** Poruke unutar teme (+ podaci o korijenskoj poruci). */
    case 'topic_messages': {
        $tid = (int)($_GET['topic'] ?? 0);
        $tp = $pdo->prepare('SELECT * FROM topics WHERE id = ?');
        $tp->execute([$tid]);
        $topic = $tp->fetch();
        if (!$topic) json_out(['error' => 'not_found'], 404);

        $conv = conv_get((int)$topic['conversation_id']);
        if ($conv === null || !is_conv_member($conv, $user)) json_out(['error' => 'forbidden'], 403);

        $root = $pdo->prepare('SELECT id, sender, type, body, file, mime, size, created_at, transcript, edited_at
            FROM messages WHERE id = ?');
        $root->execute([(int)$topic['root_message_id']]);
        $rootMsg = $root->fetch() ?: null;
        if ($rootMsg) $rootMsg['sender_name'] = display_name($rootMsg['sender']);

        $st = $pdo->prepare('SELECT id, sender, type, body, file, mime, size, created_at, transcript, edited_at
            FROM messages WHERE topic_id = ? ORDER BY id ASC LIMIT 500');
        $st->execute([$tid]);
        $messages = $st->fetchAll();
        foreach ($messages as &$m) $m['sender_name'] = display_name($m['sender']);
        unset($m);

        json_out([
            'id'       => $tid,
            'title'    => $topic['title'],
            'conv'     => (int)$topic['conversation_id'],
            'root'     => $rootMsg,
            'messages' => $messages,
        ]);
    }

    /** Popis tema u razgovoru (s brojem odgovora i zadnjom aktivnošću). */
    case 'topics': {
        $conv = require_conv($user);
        $st = $pdo->prepare('SELECT t.id, t.title, t.created_at, t.root_message_id,
                COUNT(m.id) AS replies, COALESCE(MAX(m.created_at), t.created_at) AS last_at
            FROM topics t LEFT JOIN messages m ON m.topic_id = t.id
            WHERE t.conversation_id = ?
            GROUP BY t.id ORDER BY last_at DESC LIMIT 100');
        $st->execute([(int)$conv['id']]);
        $out = [];
        foreach ($st->fetchAll() as $t) {
            $out[] = [
                'id'      => (int)$t['id'],
                'title'   => $t['title'],
                'replies' => (int)$t['replies'],
                'last_at' => (int)$t['last_at'],
                'root'    => (int)$t['root_message_id'],
            ];
        }
        json_out(['topics' => $out]);
    }

    /**
     * Dugo držani zahtjev za native aplikaciju: vraća čim stigne nova poruka
     * (ili nakon ~25 s praznim odgovorom). Aplikacija na uređajima bez Google
     * servisa ovime dobiva obavijesti bez ijednog vanjskog posrednika.
     */
    case 'wait': {
        // Sesija se otpušta odmah da ne drži zaključavanje dok traje upit.
        session_write_close();

        $since = max(0, (int)($_GET['since'] ?? 0));

        // razgovori u kojima korisnik jest
        $cv = $pdo->prepare('SELECT conversation_id FROM members WHERE username = ?');
        $cv->execute([$user]);
        $ids = array_map('intval', $cv->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) json_out(['messages' => [], 'last_id' => $since]);
        $in = implode(',', $ids);

        $q = $pdo->prepare("SELECT m.id, m.conversation_id, m.sender, m.type, m.body, m.transcript, m.created_at
            FROM messages m
            WHERE m.conversation_id IN ($in) AND m.id > ? AND m.sender != ?
            ORDER BY m.id ASC LIMIT 20");

        // Namjerno bez dugog čekanja: PHP-ov ugrađeni server obrađuje veze
        // jednu po jednu, pa bi viseći zahtjev usporio chat svima. Aplikacija
        // umjesto toga pita svakih nekoliko sekundi.
        {
            $q->execute([$since, $user]);
            $rows = $q->fetchAll();
            $q->closeCursor();

            if ($rows) {
                $out = [];
                $last = $since;
                foreach ($rows as $m) {
                    $conv = conv_get((int)$m['conversation_id']);
                    $text = $m['type'] === 'text' ? (string)$m['body']
                        : ($m['type'] === 'image' ? '📷 Photo'
                        : ($m['type'] === 'video' ? '🎬 Video' : '🎤 Voice message'));
                    $out[] = [
                        'id'     => (int)$m['id'],
                        'conv'   => (int)$m['conversation_id'],
                        'title'  => $conv && $conv['type'] === 'dm'
                            ? display_name((string)$m['sender'])
                            : display_name((string)$m['sender']) . ' · ' . conv_display_name($conv ?? [], ''),
                        'body'   => mb_substr($text, 0, 140),
                    ];
                    $last = max($last, (int)$m['id']);
                }
                json_out(['messages' => $out, 'last_id' => $last]);
            }
            json_out(['messages' => [], 'last_id' => $since]);
        }
    }

    /** Uređaji s kojih sam se prijavljivao (obavijest o novoj prijavi). */
    case 'signin_devices': {
        $st = $pdo->prepare('SELECT device_id, label, first_seen, last_seen
            FROM known_devices WHERE username = ? ORDER BY last_seen DESC');
        $st->execute([$user]);
        $me = device_id();
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'label'   => $r['label'] ?: 'Unknown device',
                'current' => $r['device_id'] === $me,
                'first'   => (int)$r['first_seen'],
                'last'    => (int)$r['last_seen'],
            ];
        }
        json_out(['devices' => $rows]);
    }

    /** Zaboravi sve uređaje osim ovog — iduća prijava s njih javlja upozorenje. */
    case 'signin_forget_others': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $st = $pdo->prepare('DELETE FROM known_devices WHERE username = ? AND device_id != ?');
        $st->execute([$user, device_id()]);
        json_out(['ok' => true, 'removed' => $st->rowCount()]);
    }

    /** Popis uređaja s notifikacijama + uklanjanje ostalih (rješava duple poruke). */
    case 'push_devices': {
        $mine = (string)($_GET['endpoint'] ?? '');
        $st = $pdo->prepare('SELECT id, endpoint, last_seen FROM push_subs WHERE username = ? ORDER BY last_seen DESC');
        $st->execute([$user]);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'id'      => (int)$r['id'],
                'current' => $mine !== '' && $r['endpoint'] === $mine,
                'service' => str_contains($r['endpoint'], 'apple') ? 'Apple (iPhone/Safari)' : 'Google (Chrome/Android)',
                'days'    => (int)floor((time() - (int)$r['last_seen']) / 86400),
            ];
        }
        json_out(['devices' => $rows]);
    }

    case 'push_forget_others': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $keep = (string)($_POST['endpoint'] ?? '');
        if ($keep === '') json_out(['error' => 'no_endpoint'], 400);
        $st = $pdo->prepare('DELETE FROM push_subs WHERE username = ? AND endpoint != ?');
        $st->execute([$user, $keep]);
        json_out(['ok' => true, 'removed' => $st->rowCount()]);
    }

    /** Pošalji testnu notifikaciju samom sebi (gumb u postavkama). */
    case 'push_test': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $st = $pdo->prepare('SELECT COUNT(*) FROM push_subs WHERE username = ?');
        $st->execute([$user]);
        if ((int)$st->fetchColumn() === 0) json_out(['error' => 'no_subs'], 400);
        push_test_async($user);
        json_out(['ok' => true]);
    }

    case 'push_unsubscribe': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $endpoint = (string)($_POST['endpoint'] ?? '');
        $pdo->prepare('DELETE FROM push_subs WHERE endpoint = ? AND username = ?')
            ->execute([$endpoint, $user]);
        json_out(['ok' => true]);
    }

    case 'change_password': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        // Demo račun je posuđen svima i lozinka mu je javna — kad bi je jedan
        // posjetitelj promijenio, zaključao bi demo svima ostalima.
        if (is_demo_account($user)) json_out(['error' => 'demo_account'], 403);
        $old = (string)($_POST['old'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $row = user_row($user);
        if (strlen($new) < 8) json_out(['error' => 'short'], 400);
        if (!password_verify($old, $row['hash'])) json_out(['error' => 'wrong'], 403);
        $pdo->prepare('UPDATE users SET hash = ?, must_change = 0 WHERE username = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user]);
        json_out(['ok' => true]);
    }

    default:
        json_out(['error' => 'unknown_action'], 400);
}
