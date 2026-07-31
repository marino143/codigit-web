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
    usort($out, fn($a, $b) => $b['last_ts'] <=> $a['last_ts']);
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

            $st = $pdo->prepare('SELECT id, sender, type, body, file, mime, size, created_at, transcript
                FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id ASC LIMIT 500');
            $st->execute([$cid, $since]);
            $messages = $st->fetchAll();
            foreach ($messages as &$m) {
                $m['sender_name'] = display_name($m['sender']);
            }
            unset($m);
            $response['messages'] = $messages;

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
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 10000) json_out(['error' => 'empty'], 400);
        $st = $pdo->prepare('INSERT INTO messages (conversation_id, sender, type, body, created_at)
            VALUES (?, ?, "text", ?, ?)');
        $st->execute([(int)$conv['id'], $user, $body, time()]);
        $id = (int)$pdo->lastInsertId();
        push_notify_async($id);
        json_out(['ok' => true, 'id' => $id]);
    }

    case 'upload': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $conv = require_conv($user);
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            // Najčešći razlog: datoteka veća od PHP limita (upload_max_filesize)
            json_out(['error' => 'nofile', 'hint' => 'The file did not arrive — it is probably larger than the server limit.'], 400);
        }
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'upload_' . $f['error']], 400);
        if ($f['size'] > CHAT_MAX_UPLOAD) json_out(['error' => 'toobig'], 400);

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
        $st = $pdo->prepare('INSERT INTO messages (conversation_id, sender, type, body, file, mime, size, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([(int)$conv['id'], $user, $type, $caption, $name, $mime, (int)$f['size'], time()]);
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
