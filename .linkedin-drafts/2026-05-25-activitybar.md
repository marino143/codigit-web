# LinkedIn Draft — 2026-05-25 — ActivityBar (launch + repositioning post)

**Post type:** Capability flex + ship-velocity + AI-assisted workflow
**Best posting time:** Today (Mon) 17:00–19:00 CET, or Tue 09:00 CET
**Pair with:** Screenshot of the menu bar showing `📊 CPU 13% RAM 87% SSD 70%`, or a 5s screen recording showing the menu bar number live-updating
**Primary link:** https://codigit.hr/apps/activitybar.html (NOT raw GitHub — codigit.hr gets the OG card, tracks analytics, and routes through to the source)

## Posting angle

First post written under the new positioning agreed with the LinkedIn consultant on 2026-05-25:
- ICP-aligned (early-stage SaaS / AI founders)
- "Built for founders" tagline close (sets up repeatable brand sign-off)
- Hook-first structure with short, scroll-stoppy single lines, double-spaced
- Concrete numbers (45 MB, 150 lines, "hour") for credibility over generic claims
- Tech depth flag (AppKit, Mach syscalls, ad-hoc codesign) to signal real builder, not vibe coder
- AI-tooling mention (Cursor + Claude) — current founder bait, signals "on the wave"
- Soft DM CTA, not a hard sell
- Codigit close that doubles as a repeatable brand tagline

## Why codigit.hr instead of GitHub link

- LinkedIn renders an OG image preview card for codigit.hr — GitHub gets a plain text snippet
- Sends visitors to a branded surface where they can also see services + other apps
- GA4 tracks `app_download` and `app_github_view` events from the site so we can measure conversion
- The product page links straight to GitHub — anyone who wants source is one click away

---

## English version

```
All the macOS menu bar monitors want to do 50 things.

I just wanted three numbers.

So I built it. Native Swift. ~45 MB RAM. Lives at the top of my screen and never leaves.

The build:

→ One Swift file, ~150 lines (AppKit + a couple of Mach syscalls for CPU/RAM)
→ Custom app icon, generated programmatically — shipping with a placeholder is a vibe killer
→ Ad-hoc codesigned .app, dropped in /Applications, auto-starts at login
→ Cursor + Claude for pairing. Described the spec, iterated three times, shipped in under an hour

This is the work I do for founders at Codigit: take a half-formed idea and ship something real, this week. Not next quarter.

If you're building a SaaS or AI product and you need a builder who can go from "I want X" to a working app in hours instead of weeks — DM me.

ActivityBar → https://codigit.hr/apps/activitybar.html

Codigit. Built for founders.
```

---

## Croatian version (only if posting to Croatian feed — primary feed should be English)

```
Svi macOS menu bar monitori žele raditi 50 stvari.

Ja sam htjela samo tri broja.

Pa sam je sama izradila. Nativni Swift. ~45 MB RAM. Stoji u vrhu ekrana i ne odlazi.

Build:

→ Jedna Swift datoteka, ~150 linija (AppKit + par Mach syscallova za CPU/RAM)
→ Custom ikona generirana programski — shipping s placeholderom je vibe killer
→ Ad-hoc potpisani .app, ide u /Applications, automatski se pokreće pri prijavi
→ Cursor + Claude kao pair. Opisala spec, iterirala tri puta, gotovo za manje od sat vremena

Ovo je rad koji radim za foundere kroz Codigit: uzmeš pola-ideje i isporučiš nešto stvarno, ovaj tjedan. Ne sljedeći kvartal.

Ako gradiš SaaS ili AI proizvod i treba ti builder koji ide od "želim X" do funkcionalne aplikacije u satima umjesto tjednima — DM.

ActivityBar → https://codigit.hr/apps/activitybar.html

Codigit. Built for founders.
```

---

## Follow-up engagement tactics

- First 60 minutes: reply to every comment (LinkedIn weights early engagement heavily for reach)
- DM 3–5 specific people in the SaaS/AI founder ICP from your network with a "made this, thought you'd find it useful" — generates seed comments
- If post performs well, follow up Wednesday with a deep-dive post on the `host_statistics` syscall (developer-targeted, narrower audience but qualifies leads)

## Hashtags (optional, max 3, end of post)

`#macOS #SwiftLang #BuildInPublic` — keep it tight. Avoid #LinkedInTips, #PersonalBranding (would attract recruiters and other consultants, the inverse of our ICP).
