# 🔄 Radni nalog: privatna instalacija → javni demo proizvod

**Za Claudea na Marinovom Mac serveru.** Marino želi prestati koristiti ovu
instalaciju privatno i pretvoriti je u **javni demo proizvod** na
chat.codigit.io — da je može pokazivati klijentima. Postojeće privatne poruke
smiju se obrisati, ali **tek nakon uspješnog backupa**. Radi redom i ne
preskači korake.

## Korak 1 — backup (obavezan, prije svega ostalog)

1. Zaustavi PHP server servis (`launchctl bootout gui/$(id -u)/com.ourchat.server`
   ili kako se servis već zove na ovom Macu) da baza bude mirna.
2. Napravi arhivu **cijele** mape `chat/data`:
   `tar -czf ~/chat-backup-$(date +%Y%m%d-%H%M).tar.gz -C <mapa-chata> data`
3. Provjeri arhivu (`tar -tzf ... | head`) i njenu veličinu — mora sadržavati
   `chat.sqlite` i `uploads/`.
4. Reci Marinu gdje je backup i predloži mu da kopiju prebaci na drugi disk.
   **Ako backup ne uspije, STANI i javi se — ništa ne briši.**

## Korak 2 — brisanje privatnog sadržaja

Tek nakon potvrđenog backupa: obriši privatni sadržaj (`chat.sqlite`,
`uploads/`, `sessions/`, push pretplate i logove). Instalacijske ključeve
(npr. `push-keys.json`) i `data/.htaccess` možeš zadržati. Marinov i ženin
račun više ne moraju postojati — demo dobiva svoje račune (korak 3).

## Korak 3 — demo postava

1. **Admin račun:** neka Marino kroz setup/admin stranicu u pregledniku sam
   kreira svoj admin račun — ne izmišljaj mu lozinku i ne zapisuj je nigdje.
2. **Demo korisnici:** kreiraj 2–3 demo računa s javno poznatim pristupom
   (npr. `demo` / lozinka `demo-proba-123`), bez admin ovlasti.
3. **Demo sadržaj:** posij uvjerljiv ogledni razgovor na hrvatskom ili
   engleskom (par DM poruka, jedna grupa, jedan kanal, koja reakcija, tema i
   odgovor — da demo pokaže mogućnosti). Bez pravih osobnih podataka.
4. **Demo oznaka:** vidljiv, nenametljiv banner u aplikaciji: ovo je demo,
   sadržaj je javan i **briše se svake noći**.

## Korak 4 — zaštita demo instalacije

Javni demo znači da će unutra pisati nepoznati ljudi, zato:

1. **Noćni reset:** launchd job koji svake noći (npr. 04:00) vrati bazu i
   uploads na posijano demo stanje iz koraka 3 (drži "zlatnu kopiju" demo
   stanja sa strane i restauriraj je u cjelini — jednostavnije i pouzdanije
   od selektivnog brisanja).
2. **Limiti:** smanji maksimalni upload za demo (npr. 10 MB) i, ako je
   izvedivo bez većeg zahvata, ograniči broj poruka/uploada po satu — da
   netko ne napuni disk.
3. Demo korisnici ne smiju moći mijenjati lozinke demo računa niti dobiti
   admin ovlasti.
4. Sve izmjene koda (banner, reset skripta, limiti) commitaj i pushaj na
   granu kao i dosad.

## Korak 5 — ponovno paljenje i primopredaja

1. Upali server servis natrag i provjeri da `cloudflared` tunel radi —
   chat.codigit.io mora posluživati demo.
2. Prođi smoke-test: prijava demo računom, slanje poruke, upload slike,
   noćni reset pokreni jednom ručno i provjeri da vrati demo stanje.
3. Javi Marinu: gdje je backup starih poruka, demo pristupne podatke,
   i kratki sažetak što je namješteno.

## Pravila

- Redoslijed je bitan: **backup → brisanje → demo**. Nikad obrnuto.
- Ne diraj backup arhivu nakon što je napravljena.
- Marinu piši na hrvatskom.
