<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!chat_is_configured()) {
    header('Location: setup.php');
    exit;
}
$user = require_auth_page();
$row = user_row($user);
if ((int)$row['must_change']) {
    header('Location: password.php');
    exit;
}
$admin = $row['role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#075e54">
<title>Our Chat</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/icon.png">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💬</text></svg>">
</head>
<body class="chat-page app-page"
      data-user="<?= htmlspecialchars($user) ?>"
      data-name="<?= htmlspecialchars($row['name']) ?>"
      data-admin="<?= $admin ? '1' : '0' ?>"
      data-csrf="<?= htmlspecialchars(csrf_token()) ?>"
      data-vapid="<?= htmlspecialchars(push_vapid_public_key()) ?>">

<div class="app">

    <aside class="sidebar" id="sidebar">
        <header class="sb-header">
            <div class="sb-title">💬 Our Chat</div>
            <div class="sb-actions">
                <button id="searchBtn" title="Search messages">🔍</button>
                <button id="notifBtn" title="Notifications" hidden>🔔</button>
                <button id="newBtn" title="New conversation / group">✏️</button>
                <?php if ($admin): ?><a href="admin.php" title="Users">⚙️</a><?php endif; ?>
                <a href="password.php" title="Change password">🔑</a>
                <a href="logout.php" title="Log out">⎋</a>
            </div>
        </header>
        <div class="sb-search" id="searchBar" hidden>
            <input id="searchInput" placeholder="Search messages…" autocomplete="off">
            <button id="searchClose" title="Close search">✕</button>
        </div>
        <div class="conv-list" id="convList">
            <div class="chat-loading">Loading…</div>
        </div>
    </aside>

    <section class="chatpane" id="chatpane">
        <header class="chat-header">
            <button class="back-btn" id="backBtn" title="Chats">‹</button>
            <div class="chat-avatar" id="convAvatar">💬</div>
            <div class="chat-peer">
                <div class="chat-peer-name" id="convName">Our Chat</div>
                <div class="chat-peer-status" id="peerStatus"></div>
            </div>
            <button class="info-btn" id="infoBtn" title="Members" hidden>ⓘ</button>
        </header>

        <main class="chat-messages" id="messages">
            <div class="chat-empty" id="emptyHint">Select a conversation or start a new one (✏️)</div>
        </main>

        <footer class="chat-composer" id="composer" hidden>
            <label class="attach-btn" title="Send a photo or video">
                📎<input type="file" id="fileInput" accept="image/*,video/*" multiple hidden>
            </label>
            <textarea id="input" rows="1" placeholder="Message" autocomplete="off"></textarea>
            <button class="mic-btn" id="micBtn" title="Record a voice message">🎤</button>
            <button class="send-btn" id="sendBtn" title="Send">➤</button>
        </footer>
    </section>

</div>

<div class="upload-bar" id="uploadBar" hidden>
    <div class="upload-bar-fill" id="uploadFill"></div>
    <span class="upload-bar-text" id="uploadText"></span>
</div>

<div class="lightbox" id="lightbox" hidden>
    <button class="lightbox-close" id="lightboxClose">✕</button>
    <div class="lightbox-content" id="lightboxContent"></div>
</div>

<div class="modal" id="modal" hidden>
    <div class="modal-card" id="modalCard"></div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
