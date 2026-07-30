<?php
/**
 * Promjena vlastite lozinke. Obavezna kod prve prijave s privremenom lozinkom.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$user = require_auth_page();
$row = user_row($user);
$forced = (int)$row['must_change'] === 1;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Invalid CSRF token — refresh the page and try again.';
    } else {
        $old = (string)($_POST['old'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $new2 = (string)($_POST['new2'] ?? '');
        if (!password_verify($old, $row['hash'])) {
            $error = $forced ? 'Wrong temporary password.' : 'Wrong current password.';
        } elseif (strlen($new) < 8) {
            $error = 'The new password must be at least 8 characters long.';
        } elseif ($new !== $new2) {
            $error = 'The new passwords do not match.';
        } else {
            db()->prepare('UPDATE users SET hash = ?, must_change = 0 WHERE username = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user]);
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Our Chat — Password</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">🔑</div>
    <h1><?= $forced ? 'Set your password' : 'Change password' ?></h1>
    <?php if ($forced): ?>
        <p class="auth-sub">You signed in with a temporary password — choose your own before continuing.</p>
    <?php endif; ?>
    <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input name="old" type="password" placeholder="<?= $forced ? 'Temporary password' : 'Current password' ?>" autocomplete="current-password" required>
        <input name="new" type="password" placeholder="New password (min. 8 characters)" autocomplete="new-password" required>
        <input name="new2" type="password" placeholder="Repeat new password" autocomplete="new-password" required>
        <button type="submit">Save password</button>
    </form>
    <?php if (!$forced): ?><p class="auth-sub" style="margin-top:12px"><a href="index.php">‹ Back to chat</a></p><?php endif; ?>
</div>
</body>
</html>
