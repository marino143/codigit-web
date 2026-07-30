<?php
/**
 * Upravljanje korisnicima — samo za admina.
 * Admin kreira račune s privremenom lozinkom; korisnik je mora promijeniti
 * pri prvoj prijavi (lozinke se nigdje ne spremaju u čitljivom obliku).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$me = require_auth_page();
if (!is_admin($me)) {
    http_response_code(403);
    exit('Only the administrator can access this page.');
}

$pdo = db();
$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        http_response_code(403);
        exit('Invalid CSRF token — refresh the page and try again.');
    }
    $do = (string)($_POST['do'] ?? '');
    $target = strtolower(trim((string)($_POST['user'] ?? '')));

    if ($do === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $pass = (string)($_POST['pass'] ?? '');
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'member';
        if ($name === '' || $target === '') {
            $error = 'Name and username are required.';
        } elseif (!preg_match(CHAT_USERNAME_RE, $target)) {
            $error = 'Username may only contain lowercase letters, digits, dot, dash and underscore (2–30 characters).';
        } elseif (user_row($target) !== null) {
            $error = "User \"$target\" already exists.";
        } elseif (strlen($pass) < 8) {
            $error = 'The temporary password must be at least 8 characters long.';
        } else {
            $pdo->prepare('INSERT INTO users (username, name, hash, role, must_change, created_at)
                VALUES (?, ?, ?, ?, 1, ?)')
                ->execute([$target, $name, password_hash($pass, PASSWORD_DEFAULT), $role, time()]);
            $ok = "Account \"$target\" created. Give the person their username and temporary password — they will have to set their own on first sign-in.";
        }
    } elseif ($do === 'resetpass') {
        $pass = (string)($_POST['pass'] ?? '');
        if (user_row($target) === null) {
            $error = 'Unknown user.';
        } elseif (strlen($pass) < 8) {
            $error = 'The temporary password must be at least 8 characters long.';
        } else {
            $pdo->prepare('UPDATE users SET hash = ?, must_change = 1 WHERE username = ?')
                ->execute([password_hash($pass, PASSWORD_DEFAULT), $target]);
            $ok = "Password for \"$target\" has been reset — they must set a new one on next sign-in.";
        }
    } elseif ($do === 'toggle') {
        if ($target === $me) {
            $error = 'You cannot deactivate your own account.';
        } elseif (user_row($target) === null) {
            $error = 'Unknown user.';
        } else {
            $pdo->prepare('UPDATE users SET active = 1 - active WHERE username = ?')->execute([$target]);
            $ok = "Account status for \"$target\" has been changed.";
        }
    }
}

$users = $pdo->query('SELECT * FROM users ORDER BY created_at')->fetchAll();
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Our Chat — Users</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="admin-page">
<div class="admin-wrap">
    <header class="admin-header">
        <a href="index.php" class="admin-back">‹ Back to chat</a>
        <h1>⚙️ Users</h1>
    </header>

    <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <?php if ($ok): ?><p class="auth-ok"><?= htmlspecialchars($ok) ?></p><?php endif; ?>

    <section class="admin-card">
        <h2>New user</h2>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="do" value="create">
            <input name="name" placeholder="Name (e.g. Ana)" required>
            <input name="user" placeholder="Username (e.g. ana)" autocapitalize="none" required>
            <input name="pass" type="text" placeholder="Temporary password (min. 8 characters)" required>
            <label class="admin-check"><input type="checkbox" name="role" value="admin"> This user is also an administrator</label>
            <button type="submit">Create account</button>
        </form>
        <p class="admin-hint">Send the person their username and temporary password — they set their own password on first sign-in.</p>
    </section>

    <section class="admin-card">
        <h2>Existing users</h2>
        <?php foreach ($users as $u): ?>
        <div class="admin-user<?= (int)$u['active'] ? '' : ' inactive' ?>">
            <div class="admin-user-main">
                <strong><?= htmlspecialchars($u['name']) ?></strong>
                <span class="admin-user-tag">@<?= htmlspecialchars($u['username']) ?></span>
                <?php if ($u['role'] === 'admin'): ?><span class="admin-badge">admin</span><?php endif; ?>
                <?php if (!(int)$u['active']): ?><span class="admin-badge off">deactivated</span><?php endif; ?>
                <?php if ((int)$u['must_change']): ?><span class="admin-badge warn">awaiting new password</span><?php endif; ?>
            </div>
            <div class="admin-user-actions">
                <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="do" value="resetpass">
                    <input type="hidden" name="user" value="<?= htmlspecialchars($u['username']) ?>">
                    <input name="pass" type="text" placeholder="New temporary password">
                    <button type="submit">Reset password</button>
                </form>
                <?php if ($u['username'] !== $me): ?>
                <form method="post" class="inline"
                      onsubmit="return confirm('<?= (int)$u['active'] ? 'Deactivate' : 'Activate' ?> account @<?= htmlspecialchars($u['username']) ?>?');">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="do" value="toggle">
                    <input type="hidden" name="user" value="<?= htmlspecialchars($u['username']) ?>">
                    <button type="submit" class="danger"><?= (int)$u['active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <p class="admin-hint">A deactivated user cannot sign in; their old messages stay in the conversations.</p>
    </section>
</div>
</body>
</html>
