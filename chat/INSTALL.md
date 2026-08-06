# Our Chat — Installation Guide

A private, self-hosted chat for a family or a small team. Messages, photos,
videos, voice messages and their transcripts stay on **your own server**.
Nothing is sent to any third-party service.

**What you get:** direct messages, groups and channels, an admin page for
accounts, photo/video/voice messages with automatic on-device transcription,
topics (threads), replies, emoji reactions, message editing, highlights,
full-text search, push notifications, dark mode, and offline reading.

---

## What you need

Only two things are strictly required:

- **PHP 8.1 or newer** with the `pdo_sqlite`, `mbstring`, `openssl` and `curl`
  extensions — that is the default on virtually every host.
- **HTTPS** — required by browsers for notifications.

No database server to set up: the chat keeps everything in a single file.

It runs on ordinary shared hosting (cPanel and similar), on a VPS, or on a
computer you leave switched on. Voice transcription is optional and needs a
machine you control (see step 5).

---

## 1. Upload the files

Copy the `chat` folder to your server, so that it is reachable at a domain or
subdomain of yours (for example `chat.yourdomain.com`).

**Point the domain at the `chat` folder itself**, not at its parent. Everything
private (`data/`, `vendor/`) is blocked from the web either way, but this keeps
the addresses tidy.

## 2. Install the dependencies

In the `chat` folder run:

```bash
composer install
```

No Composer on the server? Run it on your own computer and upload the `vendor`
folder along with the rest.

## 3. Open the address in a browser

Go to your chat address. The setup page appears and **checks the server for
you** — PHP version, extensions, whether the data folder is writable, HTTPS,
and the optional pieces. Anything marked ❌ has to be fixed; ⚠️ is optional.

Fill in your name, a username and a password, and press **Create account and
finish**. That is the whole installation: the database is created, the keys for
notifications are generated automatically, and the setup page locks itself.

## 4. Add the other people

Sign in and open **⚙️ Users**. For each person choose a username and a
*temporary* password, and send it to them — they set their own password the
first time they sign in. Deactivating an account blocks sign-in while keeping
that person's old messages in the conversations.

On phones, everyone should open the chat and use **Share → Add to Home Screen**
(iPhone) or **Install app** (Android/Chrome), then tap the **🔔 bell** to allow
notifications. On iPhone, notifications require iOS 16.4 or newer and only work
when the chat is opened from the home-screen icon.

## 5. Voice transcription (optional)

Voice messages work everywhere. To also get the spoken text written underneath
them, the server needs two things:

```bash
brew install whisper-cpp ffmpeg      # macOS
# Debian/Ubuntu: install whisper.cpp and ffmpeg from your package manager
```

Then download a model into a `whisper` folder next to `chat`:

```bash
mkdir -p whisper && curl -L -o whisper/ggml-small.bin \
  https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-small.bin
```

The app finds the binary and the model on its own and picks the most accurate
model available. `small` is a good balance; `medium` is noticeably better for
non-English languages but roughly twice as slow. Custom locations can be set
with the `WHISPER_BIN` and `WHISPER_MODEL` environment variables.

Transcription runs entirely on your server — no audio leaves it.

## 6. Keeping it running

**Shared hosting:** nothing to do. The chat is a normal PHP application.

**Your own computer or VPS:** run PHP's built-in server and have the system
keep it alive. On macOS, create
`~/Library/LaunchAgents/com.ourchat.server.plist` — replace `PHP_PATH` with the
output of `which php` and `CHAT_PATH` with the absolute path of the chat folder:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
  "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>com.ourchat.server</string>
    <key>ProgramArguments</key>
    <array>
        <string>PHP_PATH</string>
        <string>-S</string>
        <string>0.0.0.0:8080</string>
        <string>router.php</string>
    </array>
    <key>WorkingDirectory</key><string>CHAT_PATH</string>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
    <key>StandardOutPath</key><string>/tmp/chat-server.log</string>
    <key>StandardErrorPath</key><string>/tmp/chat-server.log</string>
</dict>
</plist>
```

```bash
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.ourchat.server.plist
```

On a machine that should never sleep: `sudo pmset -a sleep 0`.

For HTTPS in front of a home server, a Cloudflare Tunnel works well and needs no
open ports on your router:

```bash
brew install cloudflared
cloudflared tunnel login
cloudflared tunnel create chat
cloudflared tunnel route dns chat chat.yourdomain.com
```

Point the tunnel at `http://127.0.0.1:8080` and run it as a background service.

## Looking after it

- **Backup:** everything that matters is the `chat/data` folder — database,
  uploads, accounts and keys. Copy it regularly.
- **Upload size** is capped by PHP (`upload_max_filesize`, `post_max_size`).
  Raise both if you want to send long videos; find your `php.ini` with
  `php --ini`.
- **Moving to another server:** copy the whole folder, including `data`, and
  repeat step 2. Everyone stays signed in and no messages are lost.
- **Never expose the server directly** with router port forwarding. Use a
  tunnel or ordinary hosting.

## Troubleshooting

- The setup page tells you what is missing — start there.
- Notification problems: **👤 Settings → Notifications** shows the status per
  device and can send a test notification. Failures are logged in
  `chat/data/push.log`.
- Voice messages with no text: transcription is either not installed (check the
  setup page) or still running — it takes about a minute per message.
- After an update, reload once (Ctrl/Cmd+Shift+R, or close and reopen the app
  on a phone).
