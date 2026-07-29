# 💬 Naš chat — postavljanje na Mac uz Claude Code

Ovaj dokument ima dva dijela:
**1) kratke upute za Marina** (5 minuta ručnog posla), i
**2) radni nalog za Claudea** koji na Macu odradi sve ostalo.

---

## 1) Upute za Marina

### a) Instaliraj Claude Code na Mac (jednokratno)

Otvori **Terminal** i zalijepi:

```bash
curl -fsSL https://claude.ai/install.sh | bash
```

Zatim pokreni `claude` — prvi put te vodi kroz prijavu u Claude račun u pregledniku.

### b) Dohvati kod chata

Ako na tom Macu imaš git pristup GitHubu:

```bash
git clone -b claude/private-chat-local-server-qjtmc1 https://github.com/marino143/codigit-web.git ~/codigit-web
cd ~/codigit-web/chat
```

Ako nemaš (svjež Mac): na GitHubu otvori repo `marino143/codigit-web`, prebaci se
na granu `claude/private-chat-local-server-qjtmc1`, **Code → Download ZIP**,
raspakiraj, pa u Terminalu `cd` u mapu `chat`. (Claude ti kasnije može posložiti
i git, da lakše povlačiš buduće nadogradnje.)

### c) Pusti Claudea da radi

U mapi `chat` pokreni:

```bash
claude
```

i zalijepi mu:

> Pročitaj CLAUDE-SETUP.md u ovoj mapi i odradi dio "Radni nalog za Claudea".

To je sve. Claude će te pitati ono što mora (npr. želiš li autostart), a **svoje
lozinke za chat NE govoriš Claudeu** — račune kreirate sami u pregledniku, on ti
samo da link.

---

## 2) Radni nalog za Claudea

Ti si Claude Code na Marinovom Macu (MacBook Pro za probu; kasnije možda Mac
mini). Cilj: upaliti ovu privatnu chat aplikaciju (PHP + SQLite, sve u ovoj
mapi) tako da radi pouzdano i da joj Marino i supruga mogu pristupiti s
mobitela. Radi redom:

### Korak 1 — preduvjeti

1. Provjeri Homebrew (`brew --version`); ako ga nema, reci Marinu da ga
   instalira s https://brew.sh (traži admin lozinku, pa to ne radi sam).
2. `brew install php` ako `php` ne postoji. Provjeri `php -m | grep -i sqlite`
   — moraju postojati `pdo_sqlite` i `sqlite3` (Homebrew PHP ih ima po defaultu).

### Korak 2 — provjera aplikacije prije paljenja

1. `php -l` nad svim `.php` datotekama u ovoj mapi.
2. Pokreni testni server: `php -S 127.0.0.1:8080 router.php` (u pozadini).
3. Provjeri curl-om i **ne nastavljaj dok sve ne prođe**:
   - `GET /login.php` → 200 (ili redirect na `setup.php` ako računi još ne postoje)
   - `GET /data/chat.sqlite` → 403
   - `GET /data/users.json` → 403
   - `GET /media.php?id=1` → 401 (bez prijave)
4. Ugasi testni server.

### Korak 3 — trajno pokretanje

Pitaj Marina želi li **(a) ručno paljenje** (dvoklik na `start-mac.command`,
dobro za probu na MacBooku) ili **(b) autostart** (launchd — server se sam diže
nakon restarta, pravo rješenje za Mac mini).

Za varijantu (b) kreiraj `~/Library/LaunchAgents/hr.codigit.chat.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
  "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>hr.codigit.chat</string>
    <key>ProgramArguments</key>
    <array>
        <string>PHP_PUTANJA</string>
        <string>-S</string>
        <string>0.0.0.0:8080</string>
        <string>router.php</string>
    </array>
    <key>WorkingDirectory</key><string>CHAT_PUTANJA</string>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
    <key>StandardOutPath</key><string>/tmp/chat-server.log</string>
    <key>StandardErrorPath</key><string>/tmp/chat-server.log</string>
</dict>
</plist>
```

- `PHP_PUTANJA` = izlaz `which php` (Apple Silicon: `/opt/homebrew/bin/php`,
  Intel: `/usr/local/bin/php`).
- `CHAT_PUTANJA` = apsolutna putanja ove mape.
- Učitaj: `launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/hr.codigit.chat.plist`
  (ili `launchctl load` na starijim macOS-ima), pa provjeri da server odgovara.
- Za Mac mini predloži Marinu i `sudo pmset -a sleep 0` (traži admin lozinku —
  neka je upiše sam) da server nikad ne zaspi. Na MacBooku to preskoči;
  `start-mac.command` koristi `caffeinate`.

### Korak 4 — pristup s mobitela

1. Nađi IP Maca: `ipconfig getifaddr en0` (ili `en1`).
2. Ako se macOS firewall buni na dolazne veze, reci Marinu da klikne *Allow*
   (ili u System Settings → Network → Firewall doda PHP).
3. Ispiši Marinu, jasno i na hrvatskom:
   - adresu za mobitele na kućnoj Wi-Fi mreži: `http://IP:8080`
   - da prvi otvara **setup** u pregledniku i da tamo **sami** kreiraju svoja
     dva računa (nikad ne traži njihove lozinke),
   - da na iPhoneu mogu *Share → Add to Home Screen* za ikonu kao aplikacija.

### Korak 5 — pristup izvan kuće (opcionalno, pitaj)

Ponudi **Tailscale** (besplatan za osobnu upotrebu):
`brew install --cask tailscale`, Marino se prijavi u aplikaciji, instalira
Tailscale i na oba mobitela s istim računom — i chat radi svugdje preko
`http://TAILSCALE-IP-Maca:8080`, bez otvaranja ijednog porta.
**Nikad ne otvaraj portove na routeru i ne izlaži server javnom internetu.**

### Korak 6 — završna provjera i primopredaja

1. Otvori stranicu headless preglednikom ili curl-om i potvrdi da sve radi.
2. Podsjeti Marina:
   - **backup = mapa `chat/data/`** (poruke, slike, videi, računi) — predloži
     da Time Machine bude uključen,
   - limit veličine videa diktira PHP (`upload_max_filesize`); ako zatreba,
     povećaj u `php.ini` (`php --ini` pokaže gdje je),
   - za selidbu na Mac mini: ugasi server, prekopiraj cijelu mapu `chat/`,
     ponovi korake 3–5 na miniju.

### Pravila

- Ne mijenjaj kod aplikacije osim ako nešto stvarno ne radi — a tada minimalno
  i objasni Marinu što i zašto.
- Ne šalji sadržaj `data/` mape nikamo (ni u git — `data/` je u `.gitignore`).
- Ne traži i ne zapisuj ničije lozinke za chat.
- Sve poruke Marinu piši na hrvatskom.
