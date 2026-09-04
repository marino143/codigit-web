# 🔒 Radni nalog: privatna instanca za Marina i suprugu (uz postojeći demo)

**Za Claudea na Marinovom Mac serveru.** Demo na chat.codigit.io ostaje kakav
jest. Uz njega treba podignuti **drugu, potpuno odvojenu, privatnu instancu**
chata — samo za Marina i suprugu. Njene poruke i fotografije spremaju se
direktno na ovaj Mac i **ne smiju biti dostupne s javnog interneta**.

## Korak 1 — nova instanca

1. Kreiraj mapu `~/our-chat-private` i u nju kopiraj **kod** chata (sadržaj
   mape `chat/` iz repoza, bez `data/`). Neka ostane git checkout iste grane,
   da se nadogradnje povlače s `git pull`.
2. Pitaj Marina: želi li **vratiti stare poruke** iz backupa
   `~/chat-backup-20260812-1603.tar.gz` ili krenuti od nule?
   - Ako vraća: raspakiraj `data/` iz arhive u `~/our-chat-private/data`
     (arhivu samu ne diraj). Provjeri da unutra nema demo tragova
     (`demo.json`, demo računi) — backup je od prije dema pa ne bi smjelo.
   - Ako od nule: ništa — setup u pregledniku kreira račune (Marino admin,
     supruga normalan račun; lozinke ne smiješ znati ti).
3. Launchd servis `hr.codigit.chat-private` — isti obrazac kao postojeći,
   ali na **127.0.0.1:8081** i s `WorkingDirectory` u novoj mapi.

## Korak 2 — privatni pristup (bez javnog interneta)

Privatna instanca NE dobiva cloudflared/javni DNS. Umjesto toga **Tailscale**:

1. `brew install --cask tailscale`; Marino se prijavi u aplikaciji (osobni
   račun je besplatan). Na oba mobitela instaliraju Tailscale s istim računom.
2. HTTPS unutar tailneta (treba za push notifikacije i "Add to Home Screen"):
   `tailscale serve --bg https+insecure://... ` — točnije, usmjeri
   `tailscale serve` na `http://127.0.0.1:8081` tako da instanca bude na
   `https://<ime-maca>.<tailnet>.ts.net`. Certifikat rješava Tailscale sam.
3. Provjeri s Marinovog mobitela da adresa radi i da push notifikacije prolaze.
4. Demo (8080, javni tunel) i privatna instanca (8081, samo tailnet) ne smiju
   dijeliti ništa osim koda: odvojene `data/` mape, odvojeni servisi.

## Korak 3 — smoke test i primopredaja

1. Prijava, poruka, upload fotografije; provjeri da je fotografija odmah
   fizički u `~/our-chat-private/data/uploads/`.
2. S javnog interneta (bez Tailscalea) privatna adresa ne smije biti dostupna.
3. Javi Marinu: privatnu adresu, kako on i supruga dodaju ikonu na home
   screen, i podsjetnik da backup (Time Machine ili kopija
   `~/our-chat-private/data`) sada pokriva i privatnu instancu.

## Napomene

- Nikakav "cache" na Netlifyju ili drugoj trećoj strani — mobiteli imaju
  vlastiti offline cache aplikacije, a sve datoteke žive samo na ovom Macu.
- Bot iz demo instance NE ide u privatnu instancu osim ako Marino izričito
  ne zatraži.
- Marinu piši na hrvatskom.
