<?php
/**
 * Privatni chat — zajedničke funkcije (baza, sesija, autentikacija).
 * Sve se sprema lokalno na server: SQLite baza + uploads mapa u chat/data/.
 */
declare(strict_types=1);

const CHAT_DATA_DIR   = __DIR__ . '/data';
const CHAT_UPLOAD_DIR = CHAT_DATA_DIR . '/uploads';
const CHAT_DB_FILE    = CHAT_DATA_DIR . '/chat.sqlite';
const CHAT_USERS_FILE = CHAT_DATA_DIR . '/users.json';

// Maksimalna veličina jedne datoteke (server limiti i dalje vrijede — vidi README)
const CHAT_MAX_UPLOAD = 200 * 1024 * 1024; // 200 MB

// Dozvoljeni tipovi datoteka
const CHAT_IMAGE_MIMES = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
    'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif',
];
const CHAT_VIDEO_MIMES = [
    'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
    'video/x-m4v' => 'm4v', 'video/3gpp' => '3gp',
];

function chat_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('PRIVCHAT');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 90, // 90 dana — da se ne morate stalno logirati
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    if (!is_dir(CHAT_DATA_DIR)) mkdir(CHAT_DATA_DIR, 0755, true);
    if (!is_dir(CHAT_UPLOAD_DIR)) mkdir(CHAT_UPLOAD_DIR, 0755, true);
    $pdo = new PDO('sqlite:' . CHAT_DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT "text",   -- text | image | video
        body TEXT NOT NULL DEFAULT "",
        file TEXT,                            -- ime datoteke u data/uploads
        mime TEXT,
        size INTEGER,
        created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_state (
        username TEXT PRIMARY KEY,
        last_read_id INTEGER NOT NULL DEFAULT 0,
        last_active INTEGER NOT NULL DEFAULT 0
    )');
    return $pdo;
}

/** @return array<string, array{name:string, hash:string}> */
function chat_users(): array {
    if (!is_file(CHAT_USERS_FILE)) return [];
    $data = json_decode((string)file_get_contents(CHAT_USERS_FILE), true);
    return is_array($data) ? $data : [];
}

function chat_is_configured(): bool {
    return count(chat_users()) >= 2;
}

function current_user(): ?string {
    chat_session_start();
    $u = $_SESSION['chat_user'] ?? null;
    if ($u !== null && !isset(chat_users()[$u])) {
        unset($_SESSION['chat_user']);
        return null;
    }
    return $u;
}

/** Drugi korisnik u chatu (partner). */
function partner_of(string $user): ?string {
    foreach (array_keys(chat_users()) as $u) {
        if ($u !== $user) return $u;
    }
    return null;
}

function display_name(?string $user): string {
    if ($user === null) return '';
    return chat_users()[$user]['name'] ?? $user;
}

function csrf_token(): string {
    chat_session_start();
    if (empty($_SESSION['chat_csrf'])) {
        $_SESSION['chat_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['chat_csrf'];
}

function csrf_check(): bool {
    chat_session_start();
    $sent = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
    return is_string($sent) && $sent !== ''
        && hash_equals($_SESSION['chat_csrf'] ?? '', $sent);
}

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Za API pozive: vrati korisnika ili završi s 401. */
function require_auth_api(): string {
    $u = current_user();
    if ($u === null) json_out(['error' => 'unauthorized'], 401);
    return $u;
}

/** Za stranice: preusmjeri na login ako korisnik nije prijavljen. */
function require_auth_page(): string {
    $u = current_user();
    if ($u === null) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function touch_activity(string $user): void {
    $st = db()->prepare('INSERT INTO user_state (username, last_active) VALUES (?, ?)
        ON CONFLICT(username) DO UPDATE SET last_active = excluded.last_active');
    $st->execute([$user, time()]);
}
