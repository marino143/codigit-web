<?php
/**
 * Prvo pokretanje: provjeri preduvjete, kreiraj administratorski račun i
 * ključeve za notifikacije. Nakon što prvi račun postoji stranica se zaključava.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
chat_session_start();

if (chat_is_configured()) {
    http_response_code(403);
    echo 'This chat is already set up. New users are added by the administrator on the ⚙️ Users page.';
    exit;
}

// ---------- provjera preduvjeta ----------
$dataDir = CHAT_DATA_DIR;
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

/** @var array<int, array{label:string, ok:bool, note:string, required:bool}> $checks */
$checks = [];
$add = function (string $label, bool $ok, string $note = '', bool $required = true) use (&$checks): void {
    $checks[] = ['label' => $label, 'ok' => $ok, 'note' => $note, 'required' => $required];
};

$add('PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1', '>='), 'found ' . PHP_VERSION);
foreach ([
    'pdo_sqlite' => 'stores the messages',
    'mbstring'   => 'handles accents and emoji',
    'openssl'    => 'secures notifications',
    'curl'       => 'delivers notifications',
] as $ext => $why) {
    $add('PHP extension: ' . $ext, extension_loaded($ext), $why);
}
$add('Data folder is writable', is_dir($dataDir) && is_writable($dataDir),
    'chat/data — messages, uploads and accounts are kept here');
$add('Notification library installed', is_file(__DIR__ . '/vendor/autoload.php'),
    is_file(__DIR__ . '/vendor/autoload.php') ? '' : 'run: composer install');
$add('Served over HTTPS', chat_is_https(),
    'required for notifications; plain http is fine while testing locally', false);
$add('Voice transcription', transcription_available(),
    transcription_available()
        ? 'using ' . basename(WHISPER_MODEL)
        : 'optional — install whisper-cpp and a model for text under voice messages', false);
$add('Audio conversion (ffmpeg)', has_ffmpeg(),
    'optional — lets the server read every recording format', false);

$blocking = array_filter($checks, fn($c) => $c['required'] && !$c['ok']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$blocking) {
    $name = trim((string)($_POST['name'] ?? ''));
    $user = strtolower(trim((string)($_POST['user'] ?? '')));
    $pass = (string)($_POST['pass'] ?? '');
    if ($name === '' || $user === '') {
        $error = 'All fields are required.';
    } elseif (!preg_match(CHAT_USERNAME_RE, $user)) {
        $error = 'Username may only contain lowercase letters, digits, dot, dash and underscore (2–30 characters).';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        db()->prepare('INSERT INTO users (username, name, hash, role, created_at) VALUES (?, ?, ?, "admin", ?)')
            ->execute([$user, $name, password_hash($pass, PASSWORD_DEFAULT), time()]);

        // ključevi za notifikacije — generiraju se jednom po instalaciji
        $keyFile = CHAT_DATA_DIR . '/push-keys.json';
        if (!is_file($keyFile) && is_file(__DIR__ . '/vendor/autoload.php')) {
            try {
                require_once __DIR__ . '/vendor/autoload.php';
                $keys = Minishlink\WebPush\VAPID::createVapidKeys();
                file_put_contents($keyFile, json_encode([
                    'subject'    => 'mailto:admin@' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost'),
                    'publicKey'  => $keys['publicKey'],
                    'privateKey' => $keys['privateKey'],
                ], JSON_PRETTY_PRINT));
                @chmod($keyFile, 0600);
            } catch (Throwable $e) { /* chat radi i bez notifikacija */ }
        }

        header('Location: login.php?setup=ok');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Our Chat — Setup</title>
<?= theme_boot_script() ?>
<link rel="stylesheet" href="<?= asset("assets/style.css") ?>">
</head>
<body class="admin-page">
<div class="admin-wrap">
    <header class="admin-header">
        <h1>💬 Set up your chat</h1>
    </header>

    <section class="admin-card">
        <h2>System check</h2>
        <div class="setup-checks">
            <?php foreach ($checks as $c): ?>
            <div class="setup-check">
                <span class="setup-mark"><?= $c['ok'] ? '✅' : ($c['required'] ? '❌' : '⚠️') ?></span>
                <span class="setup-body">
                    <strong><?= htmlspecialchars($c['label']) ?></strong>
                    <?php if ($c['note']): ?><span class="setup-note"><?= htmlspecialchars($c['note']) ?></span><?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($blocking): ?>
            <p class="auth-error">Fix everything marked ❌ and reload this page. Items marked ⚠️ are optional — the chat runs without them.</p>
        <?php else: ?>
            <p class="admin-hint">Everything required is in place. Items marked ⚠️ are optional and can be added later.</p>
        <?php endif; ?>
    </section>

    <?php if (!$blocking): ?>
    <section class="admin-card">
        <h2>Your administrator account</h2>
        <p class="admin-hint" style="margin-top:0">
            You will add everyone else from inside the app. This page locks itself once the account exists.
        </p>
        <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <form method="post" autocomplete="off">
            <input name="name" placeholder="Your name (e.g. Marino)" required
                   value="<?= htmlspecialchars((string)($_POST['name'] ?? '')) ?>">
            <input name="user" placeholder="Username (e.g. marino)" autocapitalize="none" required
                   value="<?= htmlspecialchars((string)($_POST['user'] ?? '')) ?>">
            <input name="pass" type="password" placeholder="Password (min. 8 characters)" required>
            <button type="submit">Create account and finish</button>
        </form>
    </section>
    <?php endif; ?>
</div>
</body>
</html>
