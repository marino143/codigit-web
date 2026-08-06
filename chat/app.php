<?php
/**
 * Preuzimanje Android aplikacije. Traži prijavu, pa se link smije slobodno
 * slati — datoteku može skinuti samo netko tko ionako ima pristup chatu.
 * APK stoji u data/ (web nema pristup) i poslužuje se odavde.
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$user = require_auth_page();
$apk = CHAT_DATA_DIR . '/OurChat.apk';
$exists = is_file($apk);

if ($exists && isset($_GET['download'])) {
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="OurChat.apk"');
    header('Content-Length: ' . filesize($apk));
    header('Cache-Control: no-store');
    readfile($apk);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Our Chat — Android app</title>
<?= theme_boot_script() ?>
<link rel="stylesheet" href="<?= asset("assets/style.css") ?>">
</head>
<body class="admin-page">
<div class="admin-wrap">
    <header class="admin-header">
        <a href="index.php" class="admin-back">‹ Back to chat</a>
        <h1>📱 Android app</h1>
    </header>

    <section class="admin-card">
        <h2>Why use it</h2>
        <p class="admin-hint" style="margin-top:0">
            On Android phones without Google services, browser notifications never
            arrive reliably. This app talks to the server directly, so new messages
            show up as proper notifications — no Google, no app store.
        </p>

        <?php if ($exists): ?>
            <p><a class="app-download" href="app.php?download=1">⬇︎ Download OurChat.apk
                (<?= number_format(filesize($apk) / 1048576, 1) ?> MB)</a></p>
        <?php else: ?>
            <p class="auth-error">The app file is not on the server yet.</p>
        <?php endif; ?>
    </section>

    <section class="admin-card">
        <h2>How to install</h2>
        <ol class="app-steps">
            <li>Open this page <strong>on the Android phone</strong> and tap the download button.</li>
            <li>Open the downloaded file. Android will ask whether to allow installing
                apps from this source — allow it (this is normal for apps outside the store).</li>
            <li>Open <strong>Our Chat</strong>, sign in as usual, and allow notifications when asked.</li>
            <li>In Android's battery settings, set Our Chat to <strong>unrestricted</strong>,
                so the phone does not stop it in the background.</li>
        </ol>
        <p class="admin-hint">
            A quiet "Watching for new messages" line stays in the notification area —
            Android requires it for apps that listen in the background.
        </p>
    </section>

    <section class="admin-card">
        <h2>iPhone and computers</h2>
        <p class="admin-hint" style="margin-top:0">
            No app needed. On iPhone open the chat in Safari and use
            <strong>Share → Add to Home Screen</strong>; on a computer use your browser's
            <strong>Install</strong> option. Notifications work there through Apple and the browser.
        </p>
    </section>
</div>
</body>
</html>
