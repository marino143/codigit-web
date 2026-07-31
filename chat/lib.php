<?php
/**
 * Privatni chat — zajedničke funkcije (baza, sesija, autentikacija, razgovori).
 * Sve se sprema lokalno na server: SQLite baza + uploads mapa u chat/data/.
 *
 * v2: više korisnika (tablica users u bazi), razgovori (dm | group | channel).
 * Stara instalacija (users.json + poruke bez razgovora) migrira se automatski.
 */
declare(strict_types=1);

const CHAT_DATA_DIR   = __DIR__ . '/data';
const CHAT_UPLOAD_DIR = CHAT_DATA_DIR . '/uploads';
const CHAT_DB_FILE    = CHAT_DATA_DIR . '/chat.sqlite';
const CHAT_USERS_FILE = CHAT_DATA_DIR . '/users.json'; // v1 — koristi se samo za migraciju

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
// Glasovne poruke (kind=audio kod uploada). iOS snima audio/mp4 (m4a),
// Chrome audio/webm — mime_content_type zna prijaviti i video/* kontejner.
const CHAT_AUDIO_MIMES = [
    'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/mp4' => 'm4a',
    'audio/aac' => 'aac', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav',
    'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'video/webm' => 'webm',
    'audio/ogg' => 'ogg',
];

// Lokalna transkripcija glasovnih poruka (whisper.cpp) — putanje neovisne o stroju:
// binarka iz Homebrewa (Apple Silicon ili Intel), model u <repo>/whisper/
define('WHISPER_BIN', is_file('/opt/homebrew/bin/whisper-cli')
    ? '/opt/homebrew/bin/whisper-cli' : '/usr/local/bin/whisper-cli');
// medium: osjetno točniji za hrvatski od small ("I kaj sad, da ne kužim?" vs
// "i kaj sad, najkožem"), po cijenu ~1,5× duljeg vremena obrade
define('WHISPER_MODEL', dirname(__DIR__) . '/whisper/ggml-medium.bin');

const CHAT_USERNAME_RE = '/^[a-z0-9_.-]{2,30}$/';

// Zaštita prijave od pogađanja lozinki (bitno otkad je chat javno dostupan preko Funnela)
const CHAT_LOGIN_MAX_FAILS = 8;
const CHAT_LOGIN_LOCK_SECS = 900; // 15 min

/** Stvarna IP adresa klijenta (iza lokalnog proxyja — Funnel/HTTPS — čita X-Forwarded-For). */
function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (($ip === '127.0.0.1' || $ip === '::1') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if ($first !== '') $ip = $first;
    }
    return $ip;
}

/** Je li prijava trenutno zaključana za bilo koji od ključeva (npr. IP, korisničko ime)? */
function login_locked(string ...$keys): bool {
    $st = db()->prepare('SELECT fails, last_fail FROM login_fails WHERE key = ?');
    foreach ($keys as $k) {
        $st->execute([$k]);
        if (($row = $st->fetch())
            && (int)$row['fails'] >= CHAT_LOGIN_MAX_FAILS
            && time() - (int)$row['last_fail'] < CHAT_LOGIN_LOCK_SECS) {
            return true;
        }
    }
    return false;
}

function login_fail(string ...$keys): void {
    $st = db()->prepare('INSERT INTO login_fails (key, fails, last_fail) VALUES (?, 1, ?)
        ON CONFLICT(key) DO UPDATE SET
            fails = CASE WHEN ? - login_fails.last_fail > ' . CHAT_LOGIN_LOCK_SECS . ' THEN 1 ELSE login_fails.fails + 1 END,
            last_fail = ?');
    foreach ($keys as $k) $st->execute([$k, time(), time(), time()]);
}

function login_clear(string ...$keys): void {
    $st = db()->prepare('DELETE FROM login_fails WHERE key = ?');
    foreach ($keys as $k) $st->execute([$k]);
}

/**
 * Putanja do datoteke s verzijom (?v=vrijeme izmjene), da nova verzija
 * probije Cloudflareov i preglednikov cache čim je objavimo.
 */
function asset(string $path): string {
    $file = __DIR__ . '/' . ltrim($path, '/');
    $v = is_file($file) ? (string)filemtime($file) : '0';
    return htmlspecialchars($path . '?v=' . $v);
}

/**
 * Postavi temu prije crtanja stranice (inače bljesne svijetla pa se prebaci).
 * Izbor je po uređaju (localStorage): auto | light | dark.
 */
function theme_boot_script(): string {
    return '<script>(function(){try{var t=localStorage.getItem("theme");'
        . 'if(t==="dark"||t==="light")document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>';
}

/**
 * Radi li ovaj zahtjev preko HTTPS-a? Tunel prosljeđuje promet na lokalni HTTP,
 * pa se oslanjamo i na proxy zaglavlja te na to da je pristup preko javnog
 * imena domene uvijek HTTPS (lokalna mreža ide na IP ili localhost).
 */
function chat_is_https(): bool {
    if (!empty($_SERVER['HTTPS'])) return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    if (str_contains((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"')) return true;

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = explode(':', $host)[0];
    $isLocal = $host === 'localhost' || $host === ''
        || filter_var($host, FILTER_VALIDATE_IP) !== false;
    return !$isLocal; // pristup imenom domene znači da je došao izvana, preko HTTPS-a
}

function chat_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('PRIVCHAT');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 90, // 90 dana — da se ne morate stalno logirati
        'path'     => '/',
        'secure'   => chat_is_https(),
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
    $pdo->exec('PRAGMA busy_timeout = 5000'); // pozadinski poslovi pišu paralelno sa serverom
    chat_migrate($pdo);
    return $pdo;
}

function chat_migrate(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        username TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "member",   -- admin | member
        active INTEGER NOT NULL DEFAULT 1,
        must_change INTEGER NOT NULL DEFAULT 0, -- 1 = mora promijeniti lozinku pri prijavi
        last_active INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,                     -- dm | group | channel
        name TEXT,                              -- null za dm
        dm_key TEXT UNIQUE,                     -- "user1|user2" (sortirano), samo za dm
        created_by TEXT,
        created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS members (
        conversation_id INTEGER NOT NULL,
        username TEXT NOT NULL,
        joined_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (conversation_id, username)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL DEFAULT 0,
        sender TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT "text",      -- text | image | video
        body TEXT NOT NULL DEFAULT "",
        file TEXT,                              -- ime datoteke u data/uploads
        mime TEXT,
        size INTEGER,
        created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reads (
        conversation_id INTEGER NOT NULL,
        username TEXT NOT NULL,
        last_read_id INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (conversation_id, username)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_fails (
        key TEXT PRIMARY KEY,
        fails INTEGER NOT NULL DEFAULT 0,
        last_fail INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS push_subs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        endpoint TEXT NOT NULL UNIQUE,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )');
    // ---- migracija v1 → v2 ----

    // 1) korisnici iz users.json → tablica users (prvi iz datoteke postaje admin)
    $haveUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    $imported = [];
    if (!$haveUsers && is_file(CHAT_USERS_FILE)) {
        $old = json_decode((string)file_get_contents(CHAT_USERS_FILE), true);
        if (is_array($old) && $old !== []) {
            $st = $pdo->prepare('INSERT OR IGNORE INTO users (username, name, hash, role, created_at)
                VALUES (?, ?, ?, ?, ?)');
            $i = 0;
            foreach ($old as $u => $row) {
                $st->execute([(string)$u, (string)($row['name'] ?? $u), (string)($row['hash'] ?? ''),
                    $i === 0 ? 'admin' : 'member', time() + $i]);
                $imported[] = (string)$u;
                $i++;
            }
        }
    }

    // 2) stara tablica messages nema conversation_id → dodaj stupac
    $cols = array_column($pdo->query('PRAGMA table_info(messages)')->fetchAll(), 'name');
    if (!in_array('conversation_id', $cols, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN conversation_id INTEGER NOT NULL DEFAULT 0');
    }
    // tek sada stupac sigurno postoji (i kod svježe i kod migrirane baze)
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_conv ON messages (conversation_id, id)');

    // v3: transkript glasovnih poruka (NULL = u tijeku/nije audio, '' = nije uspjelo)
    if (!in_array('transcript', $cols, true)) {
        $colsNow = array_column($pdo->query('PRAGMA table_info(messages)')->fetchAll(), 'name');
        if (!in_array('transcript', $colsNow, true)) {
            $pdo->exec('ALTER TABLE messages ADD COLUMN transcript TEXT');
        }
    }

    // v6: kad je pretplata zadnji put potvrđena — stare (mrtvi preglednici,
    // stare instalacije) inače ostanu zauvijek i šalju duple notifikacije
    $subCols = array_column($pdo->query('PRAGMA table_info(push_subs)')->fetchAll(), 'name');
    if (!in_array('last_seen', $subCols, true)) {
        $pdo->exec('ALTER TABLE push_subs ADD COLUMN last_seen INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('UPDATE push_subs SET last_seen = created_at WHERE last_seen = 0');
    }

    // v5: vremenska zona korisnika (prazno = nije postavljena)
    $userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('timezone', $userCols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN timezone TEXT NOT NULL DEFAULT ""');
    }

    // v4: kanali više nisu automatski vidljivi svima — imaju birane članove.
    // Postojeći kanali (bez ijednog retka u members) dobivaju sve tadašnje
    // aktivne korisnike, da se nikome ništa ne promijeni migracijom.
    foreach ($pdo->query('SELECT id FROM conversations WHERE type = "channel"')->fetchAll(PDO::FETCH_COLUMN) as $chId) {
        $has = $pdo->prepare('SELECT COUNT(*) FROM members WHERE conversation_id = ?');
        $has->execute([(int)$chId]);
        if ((int)$has->fetchColumn() === 0) {
            $ins = $pdo->prepare('INSERT OR IGNORE INTO members (conversation_id, username, joined_at) VALUES (?, ?, ?)');
            foreach ($pdo->query('SELECT username FROM users WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN) as $u) {
                $ins->execute([(int)$chId, $u, time()]);
            }
        }
    }

    // 3) poruke bez razgovora → privatni razgovor prva dva korisnika
    $orphans = (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE conversation_id = 0')->fetchColumn();
    if ($orphans > 0) {
        $pair = $imported !== []
            ? array_slice($imported, 0, 2)
            : $pdo->query('SELECT username FROM users ORDER BY created_at LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
        if (count($pair) === 2) {
            $convId = dm_conversation($pdo, $pair[0], $pair[1]);
            $pdo->prepare('UPDATE messages SET conversation_id = ? WHERE conversation_id = 0')
                ->execute([$convId]);

            // stara user_state tablica → reads (pročitano) + users.last_active
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('user_state', $tables, true)) {
                foreach ($pdo->query('SELECT username, last_read_id, last_active FROM user_state')->fetchAll() as $s) {
                    $pdo->prepare('INSERT OR REPLACE INTO reads (conversation_id, username, last_read_id) VALUES (?, ?, ?)')
                        ->execute([$convId, $s['username'], (int)$s['last_read_id']]);
                    $pdo->prepare('UPDATE users SET last_active = ? WHERE username = ?')
                        ->execute([(int)$s['last_active'], $s['username']]);
                }
                $pdo->exec('DROP TABLE user_state');
            }
        }
    }
}

// ---------- korisnici ----------

function chat_is_configured(): bool {
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

/** @return array{username:string,name:string,hash:string,role:string,active:int,must_change:int,last_active:int}|null */
function user_row(string $username): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Svi aktivni korisnici. */
function active_users(): array {
    return db()->query('SELECT username, name, role, last_active, timezone FROM users WHERE active = 1 ORDER BY name')->fetchAll();
}

/** Je li string valjana IANA vremenska zona (npr. "Europe/Zagreb")? */
function valid_timezone(string $tz): bool {
    return $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true);
}

/**
 * Trenutno vrijeme u zoni korisnika: ['time' => '14:32', 'offset' => '+2h'] ili
 * null ako korisnik nije postavio zonu ili je ista kao gledateljeva.
 */
function user_local_time(string $tz, string $viewerTz = ''): ?array {
    if (!valid_timezone($tz)) return null;
    if ($viewerTz !== '' && $viewerTz === $tz) return null; // ista zona — nema što prikazati

    $now = new DateTimeImmutable('now', new DateTimeZone($tz));
    $base = new DateTimeImmutable('now', new DateTimeZone(valid_timezone($viewerTz) ? $viewerTz : date_default_timezone_get()));
    $diffMin = (int)round(($now->getOffset() - $base->getOffset()) / 60);
    if ($diffMin === 0) return null; // efektivno isto vrijeme

    $sign = $diffMin > 0 ? '+' : '−';
    $h = intdiv(abs($diffMin), 60);
    $m = abs($diffMin) % 60;
    return [
        'time'   => $now->format('H:i'),
        'offset' => $sign . $h . ($m ? ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) : '') . 'h',
    ];
}

function is_admin(string $username): bool {
    $u = user_row($username);
    return $u !== null && $u['role'] === 'admin';
}

function current_user(): ?string {
    chat_session_start();
    $u = $_SESSION['chat_user'] ?? null;
    if ($u !== null) {
        $row = user_row($u);
        if ($row === null || !(int)$row['active']) {
            unset($_SESSION['chat_user']);
            return null;
        }
    }
    return $u;
}

function display_name(?string $user): string {
    if ($user === null) return '';
    $row = user_row($user);
    return $row !== null ? $row['name'] : $user;
}

function touch_activity(string $user): void {
    db()->prepare('UPDATE users SET last_active = ? WHERE username = ?')->execute([time(), $user]);
}

// ---------- razgovori ----------

function dm_key(string $a, string $b): string {
    $p = [$a, $b];
    sort($p);
    return implode('|', $p);
}

/** Nađi ili kreiraj privatni razgovor (dm) između dva korisnika. */
function dm_conversation(PDO $pdo, string $a, string $b): int {
    $key = dm_key($a, $b);
    $st = $pdo->prepare('SELECT id FROM conversations WHERE dm_key = ?');
    $st->execute([$key]);
    $id = $st->fetchColumn();
    if ($id !== false) return (int)$id;

    $pdo->prepare('INSERT INTO conversations (type, dm_key, created_by, created_at) VALUES ("dm", ?, ?, ?)')
        ->execute([$key, $a, time()]);
    $id = (int)$pdo->lastInsertId();
    $mst = $pdo->prepare('INSERT OR IGNORE INTO members (conversation_id, username, joined_at) VALUES (?, ?, ?)');
    $mst->execute([$id, $a, time()]);
    $mst->execute([$id, $b, time()]);
    return $id;
}

function conv_get(int $id): ?array {
    $st = db()->prepare('SELECT * FROM conversations WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** @return string[] korisnička imena članova */
function conv_members(array $conv): array {
    $st = db()->prepare('SELECT username FROM members WHERE conversation_id = ? ORDER BY joined_at');
    $st->execute([(int)$conv['id']]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Svi tipovi razgovora (dm, grupa i kanal) traže eksplicitno članstvo. */
function is_conv_member(array $conv, string $user): bool {
    $st = db()->prepare('SELECT 1 FROM members WHERE conversation_id = ? AND username = ?');
    $st->execute([(int)$conv['id'], $user]);
    return (bool)$st->fetchColumn();
}

/** Ime razgovora iz perspektive korisnika (za dm: ime druge osobe). */
function conv_display_name(array $conv, string $me): string {
    if ($conv['type'] !== 'dm') return (string)$conv['name'];
    foreach (explode('|', (string)$conv['dm_key']) as $u) {
        if ($u !== $me) return display_name($u);
    }
    return display_name($me); // dm sam sa sobom ne postoji, ali za svaki slučaj
}

/** Smije li korisnik upravljati članstvom (grupe i kanali: osnivač ili admin). */
function can_manage_conv(array $conv, string $user): bool {
    if ($conv['type'] === 'dm') return false;
    return $conv['created_by'] === $user || is_admin($user);
}

// ---------- sesija / API pomoćne ----------

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

// ---------- push notifikacije ----------

function push_vapid_public_key(): string {
    $file = CHAT_DATA_DIR . '/push-keys.json';
    if (!is_file($file)) return '';
    $keys = json_decode((string)file_get_contents($file), true);
    return (string)($keys['publicKey'] ?? '');
}

/**
 * Pošalji push notifikacije za novu poruku — u pozadinskom procesu,
 * da slanje poruke (i PHP-ov jednonitni server) ne čeka na Appleove/Googleove servere.
 */
function push_notify_async(int $messageId): void {
    $php = PHP_BINARY;
    $script = __DIR__ . '/send-push.php';
    exec(sprintf('%s %s %d > /dev/null 2>&1 &',
        escapeshellarg($php), escapeshellarg($script), $messageId));
}

/** Pošalji testnu notifikaciju korisniku (provjera iz postavki). */
function push_test_async(string $username): void {
    exec(sprintf('%s %s --test %s > /dev/null 2>&1 &',
        escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/send-push.php'),
        escapeshellarg($username)));
}

/** Pokreni transkripciju glasovne poruke u pozadinskom procesu. */
function transcribe_async(int $messageId): void {
    $php = PHP_BINARY;
    $script = __DIR__ . '/transcribe.php';
    exec(sprintf('%s %s %d > /dev/null 2>&1 &',
        escapeshellarg($php), escapeshellarg($script), $messageId));
}
