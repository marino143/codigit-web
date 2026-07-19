<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!chat_is_configured()) {
    header('Location: setup.php');
    exit;
}
$user = require_auth_page();
$partner = partner_of($user);
?>
<!DOCTYPE html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#075e54">
<title>Naš chat</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💬</text></svg>">
</head>
<body class="chat-page"
      data-user="<?= htmlspecialchars($user) ?>"
      data-partner-name="<?= htmlspecialchars(display_name($partner)) ?>"
      data-csrf="<?= htmlspecialchars(csrf_token()) ?>">

<header class="chat-header">
    <div class="chat-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr(display_name($partner), 0, 1))) ?></div>
    <div class="chat-peer">
        <div class="chat-peer-name"><?= htmlspecialchars(display_name($partner) ?: 'Partner') ?></div>
        <div class="chat-peer-status" id="peerStatus">…</div>
    </div>
    <a class="chat-logout" href="logout.php" title="Odjava">⎋</a>
</header>

<main class="chat-messages" id="messages">
    <div class="chat-loading" id="loading">Učitavam poruke…</div>
</main>

<footer class="chat-composer">
    <label class="attach-btn" title="Pošalji sliku ili video">
        📎<input type="file" id="fileInput" accept="image/*,video/*" multiple hidden>
    </label>
    <textarea id="input" rows="1" placeholder="Poruka" autocomplete="off"></textarea>
    <button class="send-btn" id="sendBtn" title="Pošalji">➤</button>
</footer>

<div class="upload-bar" id="uploadBar" hidden>
    <div class="upload-bar-fill" id="uploadFill"></div>
    <span class="upload-bar-text" id="uploadText"></span>
</div>

<div class="lightbox" id="lightbox" hidden>
    <button class="lightbox-close" id="lightboxClose">✕</button>
    <div class="lightbox-content" id="lightboxContent"></div>
</div>

<script src="assets/app.js"></script>
</body>
</html>
