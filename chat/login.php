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
    $users = chat_users();
    // blaga zaštita od pogađanja lozinke
    usleep(400000);
    if (isset($users[$user]) && password_verify($pass, $users[$user]['hash'])) {
        session_regenerate_id(true);
        $_SESSION['chat_user'] = $user;
        touch_activity($user);
        header('Location: index.php');
        exit;
    }
    $error = 'Pogrešno korisničko ime ili lozinka.';
}
?>
<!DOCTYPE html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Naš chat — prijava</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">💬</div>
    <h1>Naš chat</h1>
    <?php if (isset($_GET['setup'])): ?><p class="auth-ok">Računi su kreirani — prijavi se. 🎉</p><?php endif; ?>
    <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post">
        <input name="user" placeholder="Korisničko ime" autocapitalize="none" autocomplete="username" required>
        <input name="pass" type="password" placeholder="Lozinka" autocomplete="current-password" required>
        <button type="submit">Prijava</button>
    </form>
</div>
</body>
</html>
