# 💬 Naš chat — privatni chat za dvoje

Mali privatni chat u stilu WhatsAppa, **samo za dvije osobe**. Sve poruke, slike i
videi spremaju se **isključivo na tvoj server** (SQLite baza + mapa `data/uploads/`)
— ništa ne ide u tuđi cloud.

## Što ima

- 🔐 Prijava samo za 2 računa (lozinke hashirane, sesija traje 90 dana)
- 💬 Tekstualne poruke, WhatsApp izgled (zeleni balončići, kvačice ✓✓)
- 📷 Slanje slika i 🎥 videa — sprema se na server, prikazuje u chatu s lightboxom
- 👀 "online" / "zadnje viđeno" i plave kvačice kad druga strana pročita
- 📱 Radi odlično na mobitelu — u Safariju/Chromeu "Add to Home Screen" i ponaša se kao aplikacija
- Nema instalacija i baza podataka za konfigurirati: čisti PHP + SQLite, radi na običnom cPanel hostingu

## Instalacija (cPanel hosting)

1. Kopiraj **cijelu mapu `chat/`** na server, npr. u
   `public_html/chat/` (File Manager ili FTP).
2. Provjeri da PHP ima SQLite (na cPanelu skoro uvijek ima: *Select PHP Version →
   ekstenzije `pdo_sqlite` i `sqlite3` uključene*).
3. Otvori `https://tvoja-domena.xx/chat/` u pregledniku — prvi put te vodi na
   **setup** gdje kreirate svoja dva računa (ime + korisničko ime + lozinka za svakoga).
4. Nakon toga se setup trajno zaključava. Prijavite se i — to je to. 🎉

> **Napomena za codigit.hr:** `.htaccess` u rootu trenutno preusmjerava SAV promet
> na codigit.io. Ako chat ide na taj server, dodaj iznimku PRIJE pravila za redirect:
>
> ```apache
> RewriteCond %{REQUEST_URI} !^/chat/
> ```
> (ili stavi chat na poddomenu, npr. `chat.codigit.hr`, sa svojim vlastitim rootom —
> to je najčišće rješenje.)

## Instalacija (Mac — MacBook / Mac mini)

1. Instaliraj [Homebrew](https://brew.sh) pa u Terminalu: `brew install php`
2. Dvoklikni **`start-mac.command`** u mapi `chat/` — skripta pokrene server,
   drži Mac budnim (`caffeinate`) i ispiše adrese: `http://localhost:8080` na
   Macu i `http://IP-Maca:8080` za mobitele na istoj Wi-Fi mreži.
   (Prvi put macOS zna pitati za dozvolu dolaznih veza — klikni *Allow*.)
3. Kad si zadovoljan i seliš na "pravi" server: **samo prekopiraj mapu `chat/`** —
   sve poruke, slike i računi su u `chat/data/`, ništa drugo ne treba migrirati.

> `php -S` (ugrađeni PHP server) ne čita `.htaccess`, pa `start-mac.command`
> koristi `router.php` koji jednako blokira direktan pristup `data/` mapi.

## Instalacija (kućni server — Raspberry Pi, NAS, stari laptop)

Ako "lokalni server" znači doma:

```bash
sudo apt install php php-sqlite3     # Debian/Ubuntu/Raspberry Pi OS
cd /putanja/do/chat
php -S 0.0.0.0:8080 router.php       # za probu; za stalno koristi Apache/nginx + php-fpm
```

Zatim na mobitelu otvori `http://IP-servera:8080/`.
Za pristup **izvan kuće** najjednostavnije i najsigurnije je [Tailscale](https://tailscale.com)
(besplatan za osobnu upotrebu) — instaliraš ga na server i na oba mobitela i chat
radi svugdje, bez otvaranja portova prema internetu.

## Veliki videi

Limit veličine datoteke određuje **PHP na serveru**, ne aplikacija. Ako veliki video
ne prolazi, u cPanelu otvori **MultiPHP INI Editor** i postavi:

- `upload_max_filesize` = `200M`
- `post_max_size` = `210M`
- `max_execution_time` = `300`

(Mapa `chat/.htaccess` to pokušava postaviti sama, ali ne dopuštaju svi hostinzi
`php_value` u `.htaccess`.)

## Gdje su podaci?

| Što | Gdje |
|---|---|
| Poruke | `chat/data/chat.sqlite` (SQLite baza) |
| Slike i videi | `chat/data/uploads/` |
| Računi (hashirane lozinke) | `chat/data/users.json` |

Mapa `data/` je blokirana `.htaccess`-om — datoteke se poslužuju samo kroz
`media.php` uz provjeru prijave. **Backup = kopiraj mapu `chat/data/`.**

Za reset lozinki: obriši `chat/data/users.json` i ponovo otvori chat — setup se
opet otključava (poruke i slike ostaju netaknute).

## Sigurnosne napomene

- Koristi **HTTPS** (na cPanelu: besplatni AutoSSL/Let's Encrypt) — inače lozinke i
  slike putuju nezaštićene.
- Stranica šalje `noindex` — tražilice je neće indeksirati.
- Ovo je privatan alat za dvoje; nije end-to-end enkripcija kao WhatsApp — tko ima
  pristup serveru, ima pristup porukama. Na vlastitom serveru to ste samo vas dvoje. 😊
