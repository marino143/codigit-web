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
<?= theme_boot_script() ?>
<link rel="stylesheet" href="<?= asset("assets/style.css") ?>">
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
        <h2>Notifications</h2>
        <p class="admin-hint" style="margin-top:0" id="notifState">Checking…</p>
        <button type="button" id="notifEnableBtn" hidden>Turn on notifications</button>
        <button type="button" id="notifTestBtn" hidden>Send me a test notification</button>
        <p class="admin-hint" id="notifResult" hidden></p>
        <p class="admin-hint" id="notifDevices" hidden></p>
        <button type="button" id="notifForgetBtn" hidden class="danger">Getting each message twice? Remove my other devices</button>
        <p class="admin-hint">On iPhone notifications only work when the chat is opened from the
            Home Screen icon (Share → Add to Home Screen), on iOS 16.4 or newer.</p>
    </section>

    <section class="admin-card">
        <h2>Appearance</h2>
        <p class="admin-hint" style="margin-top:0">Choose how the chat looks on this device.</p>
        <div class="theme-choice">
            <button type="button" class="theme-btn" data-theme-set="auto">🌗 Auto</button>
            <button type="button" class="theme-btn" data-theme-set="light">☀️ Light</button>
            <button type="button" class="theme-btn" data-theme-set="dark">🌙 Dark</button>
        </div>
        <p class="admin-hint">“Auto” follows your phone or computer setting.</p>
    </section>

    <section class="admin-card">
        <h2>Password</h2>
        <p class="admin-hint" style="margin-top:0">Change the password you use to sign in.</p>
        <p><a class="admin-back" href="password.php">🔑 Change password ›</a></p>
    </section>
</div>
<script>
// Stanje notifikacija + testna notifikacija
(function () {
    var state = document.getElementById('notifState');
    var enableBtn = document.getElementById('notifEnableBtn');
    var testBtn = document.getElementById('notifTestBtn');
    var result = document.getElementById('notifResult');
    var VAPID = <?= json_encode(push_vapid_public_key()) ?>;

    function b64ToU8(b) {
        var pad = '='.repeat((4 - b.length % 4) % 4);
        var raw = atob((b + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, function (c) { return c.charCodeAt(0); });
    }
    function say(msg) { result.textContent = msg; result.hidden = false; }

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !window.isSecureContext) {
        state.textContent = '⚠️ This browser cannot receive notifications.'
            + (navigator.standalone === false ? ' On iPhone, add the chat to your Home Screen first.' : '');
        return;
    }

    navigator.serviceWorker.register('sw.js').then(function (reg) {
        return reg.pushManager.getSubscription().then(function (sub) {
            if (sub && Notification.permission === 'granted') {
                state.textContent = '✅ Notifications are on for this device.';
                testBtn.hidden = false;
            } else if (Notification.permission === 'denied') {
                state.textContent = '🔕 Notifications are blocked in your device settings for this app. '
                    + 'Allow them there, then reload this page.';
            } else {
                state.textContent = '🔔 Notifications are off for this device.';
                enableBtn.hidden = false;
            }

            // Popis pretplaćenih uređaja — više njih znači duple notifikacije
            var devicesEl = document.getElementById('notifDevices');
            var forgetBtn = document.getElementById('notifForgetBtn');
            function refreshDevices() {
                var q = sub ? '&endpoint=' + encodeURIComponent(sub.endpoint) : '';
                fetch('api.php?action=push_devices' + q)
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var n = (d.devices || []).length;
                        if (!n) { devicesEl.hidden = true; forgetBtn.hidden = true; return; }
                        devicesEl.textContent = n === 1
                            ? 'One device is subscribed for notifications.'
                            : n + ' devices are subscribed — you will get every message ' + n + ' times.';
                        devicesEl.hidden = false;
                        forgetBtn.hidden = !(n > 1 && sub);
                    }).catch(function () {});
            }
            if (sub) refreshDevices();

            forgetBtn.addEventListener('click', function () {
                if (!sub) return;
                var body = new URLSearchParams({ endpoint: sub.endpoint });
                fetch('api.php?action=push_forget_others', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF': <?= json_encode(csrf_token()) ?> },
                    body: body.toString(),
                }).then(function (r) { return r.json(); }).then(function (d) {
                    say('Removed ' + (d.removed || 0) + ' other device(s). Only this one will get notifications now.');
                    refreshDevices();
                }).catch(function () { say('Could not remove the other devices.'); });
            });

            enableBtn.addEventListener('click', function () {
                Notification.requestPermission().then(function (p) {
                    if (p !== 'granted') { say('Permission was not granted.'); return; }
                    return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToU8(VAPID) })
                        .then(function (s) {
                            var j = s.toJSON();
                            var body = new URLSearchParams({ endpoint: s.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth });
                            return fetch('api.php?action=push_subscribe', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF': <?= json_encode(csrf_token()) ?> },
                                body: body.toString(),
                            });
                        })
                        .then(function () {
                            state.textContent = '✅ Notifications are on for this device.';
                            enableBtn.hidden = true; testBtn.hidden = false;
                            say('Turned on. Send yourself a test to be sure.');
                        });
                }).catch(function () { say('Could not turn notifications on.'); });
            });

            testBtn.addEventListener('click', function () {
                say('Sending…');
                fetch('api.php?action=push_test', {
                    method: 'POST',
                    headers: { 'X-CSRF': <?= json_encode(csrf_token()) ?> },
                }).then(function (r) { return r.json(); }).then(function (d) {
                    say(d.ok ? 'Sent — it should arrive within a few seconds. Lock the screen or switch apps to see it.'
                             : 'Could not send (' + (d.error || 'error') + ').');
                }).catch(function () { say('Could not send the test.'); });
            });
        });
    }).catch(function () {
        state.textContent = '⚠️ Could not set up notifications on this device.';
    });
})();

// Izbor teme (po uređaju)
(function () {
    var btns = document.querySelectorAll('[data-theme-set]');
    function current() {
        try { return localStorage.getItem('theme') || 'auto'; } catch (e) { return 'auto'; }
    }
    function apply(mode) {
        if (mode === 'auto') document.documentElement.removeAttribute('data-theme');
        else document.documentElement.setAttribute('data-theme', mode);
        try { localStorage.setItem('theme', mode); } catch (e) {}
        mark();
    }
    function mark() {
        var c = current();
        btns.forEach(function (b) {
            b.classList.toggle('active', b.dataset.themeSet === c);
        });
    }
    btns.forEach(function (b) {
        b.addEventListener('click', function () { apply(b.dataset.themeSet); });
    });
    mark();
})();

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
