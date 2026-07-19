<?php
/**
 * JSON API: poll (nove poruke + status partnera), send (tekst), upload (slika/video).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$user = require_auth_api();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_check()) {
    json_out(['error' => 'csrf'], 403);
}

$pdo = db();
touch_activity($user);

switch ($action) {

    case 'poll': {
        $since = max(0, (int)($_GET['since'] ?? 0));

        // Klijent javlja do koje je poruke pročitao
        $readUpTo = (int)($_GET['read'] ?? 0);
        if ($readUpTo > 0) {
            $st = $pdo->prepare('INSERT INTO user_state (username, last_read_id, last_active) VALUES (?, ?, ?)
                ON CONFLICT(username) DO UPDATE SET
                    last_read_id = MAX(user_state.last_read_id, excluded.last_read_id),
                    last_active  = excluded.last_active');
            $st->execute([$user, $readUpTo, time()]);
        }

        $st = $pdo->prepare('SELECT id, sender, type, body, file, mime, size, created_at
            FROM messages WHERE id > ? ORDER BY id ASC LIMIT 500');
        $st->execute([$since]);
        $messages = $st->fetchAll();

        $partner = partner_of($user);
        $pState = ['online' => false, 'last_active' => 0, 'last_read_id' => 0];
        if ($partner !== null) {
            $st = $pdo->prepare('SELECT last_read_id, last_active FROM user_state WHERE username = ?');
            $st->execute([$partner]);
            if ($row = $st->fetch()) {
                $pState = [
                    'online'       => (time() - (int)$row['last_active']) < 15,
                    'last_active'  => (int)$row['last_active'],
                    'last_read_id' => (int)$row['last_read_id'],
                ];
            }
        }

        json_out([
            'messages' => $messages,
            'partner'  => ['name' => display_name($partner)] + $pState,
            'now'      => time(),
        ]);
    }

    case 'send': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 10000) json_out(['error' => 'empty'], 400);
        $st = $pdo->prepare('INSERT INTO messages (sender, type, body, created_at) VALUES (?, "text", ?, ?)');
        $st->execute([$user, $body, time()]);
        json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    case 'upload': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'method'], 405);
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            // Najčešći razlog: datoteka veća od PHP limita (upload_max_filesize)
            json_out(['error' => 'nofile', 'hint' => 'Datoteka nije stigla — vjerojatno je veća od limita servera.'], 400);
        }
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'upload_' . $f['error']], 400);
        if ($f['size'] > CHAT_MAX_UPLOAD) json_out(['error' => 'toobig'], 400);

        $mime = (string)(mime_content_type($f['tmp_name']) ?: $f['type']);
        $type = null; $ext = null;
        if (isset(CHAT_IMAGE_MIMES[$mime])) { $type = 'image'; $ext = CHAT_IMAGE_MIMES[$mime]; }
        if (isset(CHAT_VIDEO_MIMES[$mime])) { $type = 'video'; $ext = CHAT_VIDEO_MIMES[$mime]; }
        if ($type === null) json_out(['error' => 'type', 'mime' => $mime], 415);

        if (!is_dir(CHAT_UPLOAD_DIR)) mkdir(CHAT_UPLOAD_DIR, 0755, true);
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], CHAT_UPLOAD_DIR . '/' . $name)) {
            json_out(['error' => 'save'], 500);
        }

        $caption = trim((string)($_POST['body'] ?? ''));
        $st = $pdo->prepare('INSERT INTO messages (sender, type, body, file, mime, size, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$user, $type, $caption, $name, $mime, (int)$f['size'], time()]);
        json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    default:
        json_out(['error' => 'unknown_action'], 400);
}
