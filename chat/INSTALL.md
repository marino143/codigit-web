# Our Chat — Installation Guide

A private, self-hosted chat for a small team or family. Everything — messages,
photos, videos, voice messages and their transcripts — stays on **your own Mac**.
Nothing is sent to any cloud service.

**Features:** direct messages, groups, channels, an admin panel for managing
users, photo/video/voice messages, automatic **on-device** voice transcription
(Whisper), full-text search (including inside voice messages), push
notifications on phones, and remote access via Tailscale — without opening any
router ports.

---

## What you need

- A Mac that stays on (a Mac mini is ideal). Apple Silicon or Intel.
- Admin access to that Mac.
- ~1 GB of free disk space (PHP + Whisper model), plus room for your media.
- iPhones with iOS 16.4+ (or Android phones) for the mobile experience.
- A free [Tailscale](https://tailscale.com) account for access from outside
  your home/office network and for push notifications (they require HTTPS).

Everything below is typed into **Terminal** on the server Mac.

---

## 1. Install the tools

Install [Homebrew](https://brew.sh) if you don't have it, then:

```bash
brew install php composer whisper-cpp node
```

Raise PHP's upload limit so large videos can be sent (find your `php.ini` with
`php --ini`, usually `/opt/homebrew/etc/php/<version>/php.ini`):

```
upload_max_filesize = 200M
post_max_size = 210M
```

## 2. Get the code

Place the project folder (containing `chat/`, provided by your developer or
cloned from the repository) somewhere permanent, e.g. `~/ourchat`. Then:

```bash
cd ~/ourchat/chat
composer install
```

## 3. Download the transcription model (one-time, ~470 MB)

```bash
mkdir -p ~/ourchat/whisper
curl -L -o ~/ourchat/whisper/ggml-small.bin \
  https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-small.bin
```

The `small` model handles most languages well. On a slow Mac you can use the
3× faster (but less accurate) `ggml-base.bin` instead — download it the same
way and change `WHISPER_MODEL` in `chat/lib.php`.

## 4. Generate push-notification keys (one-time)

```bash
cd ~/ourchat/chat
php -r 'require "vendor/autoload.php";
$k = Minishlink\WebPush\VAPID::createVapidKeys();
file_put_contents("data/push-keys.json", json_encode([
  "subject" => "mailto:YOUR-EMAIL@example.com",
  "publicKey" => $k["publicKey"], "privateKey" => $k["privateKey"]]));
chmod("data/push-keys.json", 0600);
echo "Push keys created\n";'
```

(Replace the e-mail with your own — it is only used as a technical contact
field in the push protocol.)

## 5. Start the chat server automatically (launchd)

Create `~/Library/LaunchAgents/com.ourchat.server.plist` — replace
`PHP_PATH` with the output of `which php`, and `CHAT_PATH` with your absolute
chat folder path (e.g. `/Users/john/ourchat/chat`):

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

Load it:

```bash
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.ourchat.server.plist
```

Check: `http://localhost:8080` in a browser should show the setup page.
The server now starts on every boot and restarts itself if it ever crashes.

On a Mac mini, also prevent sleep: `sudo pmset -a sleep 0`.

## 6. First account

Open `http://localhost:8080` and create **your administrator account**. The
setup page locks itself afterwards. You then add every other user from the
**⚙️ Users** page inside the app: you choose their username and a *temporary*
password; they must set their own password the first time they sign in.

## 7. HTTPS + remote access (Tailscale)

Push notifications require HTTPS, and Tailscale gives you that plus secure
access from anywhere — with no router configuration.

1. `brew install --cask tailscale-app` (or install Tailscale from the App
   Store), open it and sign in.
2. Get a certificate for this Mac (find your machine name with
   `tailscale status`; HTTPS certificates must be enabled in the Tailscale
   admin console → DNS, they are by default on new accounts):

   ```bash
   mkdir -p ~/ourchat/tls && cd ~/ourchat/tls
   tailscale cert your-machine.your-tailnet.ts.net
   ```

3. The project includes a small HTTPS proxy (`chat-https-proxy.js`, one level
   above `chat/`). It finds the certificate automatically. Create
   `~/Library/LaunchAgents/com.ourchat.https.plist` — replace `NODE_PATH`
   (output of `which node`) and `PROJECT_PATH` (e.g. `/Users/john/ourchat`):

   ```xml
   <?xml version="1.0" encoding="UTF-8"?>
   <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
     "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
   <plist version="1.0">
   <dict>
       <key>Label</key><string>com.ourchat.https</string>
       <key>ProgramArguments</key>
       <array>
           <string>NODE_PATH</string>
           <string>PROJECT_PATH/chat-https-proxy.js</string>
       </array>
       <key>RunAtLoad</key><true/>
       <key>KeepAlive</key><true/>
       <key>StandardOutPath</key><string>/tmp/chat-https-proxy.log</string>
       <key>StandardErrorPath</key><string>/tmp/chat-https-proxy.log</string>
   </dict>
   </plist>
   ```

   ```bash
   launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.ourchat.https.plist
   ```

   Your chat is now at **`https://your-machine.your-tailnet.ts.net:8443`**.

   > Alternative: on recent macOS versions `tailscale serve --bg
   > http://127.0.0.1:8080` does the same job on port 443 without the Node
   > proxy. If `tailscale serve status` shows your config after running it,
   > you can skip the proxy entirely and use the URL it prints.

4. Certificates last 90 days. Auto-renew them weekly with
   `~/Library/LaunchAgents/com.ourchat.cert.plist` (replace `PROJECT_PATH`);
   the proxy picks up renewed certificates automatically, no restart needed:

   ```xml
   <?xml version="1.0" encoding="UTF-8"?>
   <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
     "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
   <plist version="1.0">
   <dict>
       <key>Label</key><string>com.ourchat.cert</string>
       <key>ProgramArguments</key>
       <array>
           <string>/bin/bash</string>
           <string>PROJECT_PATH/renew-cert.sh</string>
       </array>
       <key>StartCalendarInterval</key>
       <dict>
           <key>Weekday</key><integer>1</integer>
           <key>Hour</key><integer>4</integer>
           <key>Minute</key><integer>30</integer>
       </dict>
   </dict>
   </plist>
   ```

   ```bash
   launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.ourchat.cert.plist
   ```

## 8. Phones

On every phone:

1. Install **Tailscale** from the App Store / Play Store and sign in with the
   **same Tailscale account** (or invite family/team members to your tailnet).
2. Open `https://your-machine.your-tailnet.ts.net:8443` in Safari (iPhone) or
   Chrome (Android) and sign in.
3. iPhone: **Share → Add to Home Screen**. Android: **Install app** from the
   browser menu. Open the chat from that icon.
4. Tap the **🔔 bell** at the top of the chat list and allow notifications.

That's it — messages now arrive as real push notifications anywhere, as long
as Tailscale is enabled on the phone.

## Maintenance

- **Backup:** everything that matters is in `chat/data/` (database, media,
  accounts) — include it in Time Machine or copy it elsewhere regularly.
- **Voice transcription** runs in the background; the text appears under the
  voice message typically within a minute. If a transcript never appears,
  check that `whisper-cli` and the model file exist (paths in `chat/lib.php`).
- **Moving to another Mac:** stop the server, copy the whole project folder,
  repeat steps 1, 5 and 7 on the new machine.
- **Never expose the server directly to the internet** (no router port
  forwarding). Tailscale is the safe way to get remote access.

## Troubleshooting

- Server log: `/tmp/chat-server.log` · HTTPS proxy log: `/tmp/chat-https-proxy.log`
- Restart the server: `launchctl kickstart -k gui/$(id -u)/com.ourchat.server`
- "Database is locked" or odd behaviour after a crash: just restart the
  server; SQLite recovers on the next start.
- Phones can't connect from outside: check that Tailscale is connected on
  both the Mac and the phone (`tailscale status`).
