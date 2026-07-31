<?php
/**
 * Prvo pokretanje — kreiranje administratorskog računa.
 * Ostale korisnike admin kasnije dodaje na stranici ⚙️ Users.
 * Nakon što prvi račun postoji, ova stranica se trajno zaključava.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
chat_session_start();

if (chat_is_configured()) {
    http_response_code(403);
    echo 'This chat is already set up. New users are added by the administrator on the ⚙️ Users page.';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">💬</div>
    <h1>Our Chat</h1>
    <p class="auth-sub">First run — create your administrator account.
        You will add other users later in the app (⚙️). This page locks itself afterwards.</p>
    <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post" autocomplete="off">
        <input name="name" placeholder="Name (e.g. Marino)" required value="<?= htmlspecialchars((string)($_POST['name'] ?? '')) ?>">
        <input name="user" placeholder="Username (e.g. marino)" autocapitalize="none" required value="<?= htmlspecialchars((string)($_POST['user'] ?? '')) ?>">
        <input name="pass" type="password" placeholder="Password (min. 8 characters)" required>
        <button type="submit">Create account</button>
    </form>
</div>
</body>
</html>
