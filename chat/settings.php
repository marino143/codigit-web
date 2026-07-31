<?php
/**
 * Osobne postavke korisnika — trenutno vremenska zona.
 * Zona se prikazuje sugovornicima da vide koliko je kod tebe sati.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$user = require_auth_page();
$row = user_row($user);

$ok = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Invalid CSRF token — refresh the page and try again.';
    } else {
        $tz = trim((string)($_POST['timezone'] ?? ''));
        if ($tz !== '' && !valid_timezone($tz)) {
            $error = 'Unknown time zone.';
        } else {
            db()->prepare('UPDATE users SET timezone = ? WHERE username = ?')->execute([$tz, $user]);
            $row['timezone'] = $tz;
            $ok = $tz === '' ? 'Time zone cleared.' : 'Time zone saved.';
        }
    }
}

$current = (string)($row['timezone'] ?? '');
$nowLabel = valid_timezone($current)
    ? (new DateTimeImmutable('now', new DateTimeZone($current)))->format('H:i')
    : '';

// Zone grupirane po regiji (Europe/…, America/… itd.)
$zones = [];
foreach (DateTimeZone::listIdentifiers() as $z) {
    $region = str_contains($z, '/') ? explode('/', $z)[0] : 'Other';
    $zones[$region][] = $z;
}
ksort($zones);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Our Chat — Settings</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="admin-page">
<div class="admin-wrap">
    <header class="admin-header">
        <a href="index.php" class="admin-back">‹ Back to chat</a>
        <h1>👤 Settings</h1>
    </header>

    <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <?php if ($ok): ?><p class="auth-ok"><?= htmlspecialchars($ok) ?></p><?php endif; ?>

    <section class="admin-card">
        <h2>Time zone</h2>
        <p class="admin-hint" style="margin-top:0">
            Pick your time zone so the people you chat with can see what time it is where you are.
            <?php if ($nowLabel): ?><br><strong>Right now it is <?= htmlspecialchars($nowLabel) ?> for you.</strong><?php endif; ?>
        </p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <select name="timezone" class="settings-select">
                <option value="">— not set —</option>
                <?php foreach ($zones as $region => $list): ?>
                <optgroup label="<?= htmlspecialchars($region) ?>">
                    <?php foreach ($list as $z): ?>
                    <option value="<?= htmlspecialchars($z) ?>"<?= $z === $current ? ' selected' : '' ?>>
                        <?= htmlspecialchars($z) ?>
                    </option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
            <button type="submit">Save time zone</button>
        </form>
        <p class="admin-hint" id="tzHint" hidden></p>
    </section>

    <section class="admin-card">
        <h2>Password</h2>
        <p class="admin-hint" style="margin-top:0">Change the password you use to sign in.</p>
        <p><a class="admin-back" href="password.php">🔑 Change password ›</a></p>
    </section>
</div>
<script>
// Ponudi zonu koju javlja preglednik ako korisnik još nema postavljenu
(function () {
    var sel = document.querySelector('select[name="timezone"]');
    var hint = document.getElementById('tzHint');
    if (!sel || !hint || sel.value) return;
    try {
        var guess = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (!guess) return;
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === guess) {
                sel.selectedIndex = i;
                hint.textContent = 'Detected from your device: ' + guess + ' — press Save to confirm.';
                hint.hidden = false;
                return;
            }
        }
    } catch (e) {}
})();
</script>
</body>
</html>
