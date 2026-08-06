<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
chat_session_start();

if (!chat_is_configured()) {
    header('Location: setup.php');
    exit;
}
if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = strtolower(trim((string)($_POST['user'] ?? '')));
    $pass = (string)($_POST['pass'] ?? '');
    $ipKey = 'ip:' . client_ip();
    $userKey = 'user:' . $user;

    usleep(400000); // uspori pogađanje i kad račun nije zaključan

    if (login_locked($ipKey, $userKey)) {
        $error = 'Too many failed attempts — try again in 15 minutes.';
    } else {
        $row = user_row($user);
        if ($row !== null && (int)$row['active'] && password_verify($pass, $row['hash'])) {
            login_clear($ipKey, $userKey);
            session_regenerate_id(true);
            $_SESSION['chat_user'] = $user;
            touch_activity($user);
            note_signin($user);   // javi ako je ovo novi uređaj
            header('Location: ' . ((int)$row['must_change'] ? 'password.php' : 'index.php'));
            exit;
        }
        login_fail($ipKey, $userKey);
        $error = 'Wrong username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Our Chat — Sign in</title>
<?= theme_boot_script() ?>
<link rel="stylesheet" href="<?= asset("assets/style.css") ?>">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/icon.png">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">💬</div>
    <h1>Our Chat</h1>
    <?php if (isset($_GET['setup'])): ?><p class="auth-ok">Account created — sign in. 🎉</p><?php endif; ?>
    <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post">
        <input name="user" placeholder="Username" autocapitalize="none" autocomplete="username" required>
        <input name="pass" type="password" placeholder="Password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
</div>
</body>
</html>
