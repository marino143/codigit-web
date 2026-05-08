# LinkedIn Draft — 2026-05-08 — DockGuard (build-in-public alpha)

**Post type:** Build-in-public — "shipping the messy version"
**Best posting time:** Today (Fri) afternoon 14:30–16:00 CET, OR Monday May 11 09:00 CET
**Pair with:** Screen recording showing accidental drag-off-Dock vs DockGuard preventing it (10–12s)

## Posting angle

Native Browser and StayAwake both shipped with working installers. DockGuard is **alpha** — code is on GitHub, but there's no signed release yet. This is intentional. The post leans into that:
- Honest about it being unfinished
- Invites contributions / early testers
- Builds anticipation for v1.0 release

This kind of "ship the messy version in public" post tends to get strong engagement from devs who appreciate the transparency.

---

## English version

```
This is app #3 of 3.

Except I'm doing it wrong on purpose.

I've used a Mac for 12 years. I've accidentally dragged icons off my Dock approximately 40,000 times. Every. Single. Time. Same little "poof" animation.

So I built DockGuard.

→ One toggle in your menu bar
→ Locks Dock icons in place
→ Locks Dock size & position
→ Native Apple Silicon, under 1MB

Here's the wrong part: there's no installer yet.

The code is on GitHub, the build works, but I'm shipping it in public — alpha state, no signed .app, no .dmg, no notarization. If you can clone a repo and hit ⌘B in Xcode, you can run it today. If you can't, give me a week.

Why ship like this? Because I want feedback before I lock the design. Two of my last three apps had small interaction details I would have changed if a real user had touched them in week 1.

So if you've ever yelled at your Mac for the "drag-off-the-Dock" thing — clone, build, break it, file an issue.

GitHub: https://github.com/marino143/dockguard
Project page: https://codigit.hr/apps/dockguard.html

This wraps up the 3-app sprint. Native Browser shipped. StayAwake shipped. DockGuard is alpha. Next: a v1.0 release for DockGuard, then a stats post on what worked.

#MacOS #AppleSilicon #BuildInPublic #IndieDev #OpenSource
```

**Char count:** ~1280

---

## Croatian version

```
Ovo je app #3 od 3.

Samo što ovaj put radim namjerno krivo.

Koristim Mac 12 godina. Slučajno sam izvukao ikonu s Docka oko 40,000 puta. Svaki. Jedan. Put. Ona ista "puff" animacija.

Pa sam napravio DockGuard.

→ Jedan prekidač u menu baru
→ Zaključava ikone na Docku
→ Zaključava veličinu i poziciju Docka
→ Native Apple Silicon, ispod 1MB

A evo kriva stvar: nema instalera.

Kod je na GitHubu, build radi, ali šaljem ga u public — alpha, bez potpisanog .app-a, bez .dmg-a, bez notarizacije. Ako znaš klonirati repo i pritisnuti ⌘B u Xcodeu, možeš ga koristiti danas. Ako ne — daj mi tjedan dana.

Zašto ovako? Zato što želim feedback prije nego zaključam dizajn. Dvije od zadnje tri aplikacije imale su male interakcije koje bih promijenio da je netko stvarni testirao u prvom tjednu.

Pa ako si ikad opsovao Mac zbog "drag-off-the-Dock" stvari — kloniraj, build, razbij, otvori issue.

GitHub: https://github.com/marino143/dockguard
Stranica: https://codigit.hr/apps/dockguard.html

Ovim završava 3-app sprint. Native Browser objavljen. StayAwake objavljen. DockGuard alpha. Sljedeće: v1.0 release DockGuarda, pa stats post o tome što je radilo.

#MacOS #AppleSilicon #BuildInPublic #IndieDev #OpenSource
```

**Char count:** ~1340

---

## Posting tips

### Image / video

- **Best:** 10–12 second screen recording showing the problem first (drag icon off Dock, "poof" animation), then the fix (toggle DockGuard on, try to drag, icon snaps back). The "before/after" frame is the strongest possible visual.
- **Alternative:** Annotated screenshot of menu bar with DockGuard icon, with a tooltip caption "Dock locked".
- **Avoid:** Text-only graphics on this post. The visual demo is the whole sell — without it, the build-in-public angle feels weaker.

### Engagement bait

Within 5 minutes of posting, drop a pinned comment:

> "Honest question for fellow devs: do you ship alpha publicly, or polish in private? I keep going back and forth."

This is a deliberate dev-conversation starter that shifts focus to general indie-dev philosophy. People comment on philosophical questions much more than feature questions.

### Why "no installer" works for you here

- 1st post (Native Browser): "I shipped a polished thing"
- 2nd post (StayAwake): "I shipped a tiny thing"
- 3rd post (DockGuard): "I shipped a thing in public, raw"

This is a deliberate narrative arc. It also primes the audience to expect a v1.0 release post in 2–3 weeks — that becomes post #4 with built-in tension.

### Cross-link to existing posts

You can quote-comment your own previous Native Browser or StayAwake post within 24h with: "App #3 dropped today, but this one is different →" linking the new post. This ties the three together as a series in the algorithm.

### Don't

- Don't claim the app is finished. People will inspect the repo, find no release, and call you out.
- Don't post the HR version on the same day. Post EN today, HR Mon May 11 morning.
- Don't edit the post within the first hour.

---

## Follow-up plan

- **+7 days (May 15):** "DockGuard alpha update — 12 issues filed, 3 PRs merged, here's what's changing"
- **+21 days (May 29):** "DockGuard v1.0 with signed installer, here's the stats from 3 weeks of build-in-public"

These two follow-ups create a 4-post arc that compounds. Don't skip them — the alpha post pays off only if you actually ship the v1.0 follow-up.
