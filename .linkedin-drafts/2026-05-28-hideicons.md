# LinkedIn Draft — 2026-05-28 — Hide Icons (comparison + ship-velocity)

**Post type:** Free-vs-paid comparison + technical-depth flex + ship-velocity
**Best posting time:** Fri May 29 09:00–10:00 CET (next morning), or Tue Jun 2 09:00 CET
**Pair with:** 6–10s screen recording — menu bar cluttered with icons → click chevron → icons disappear off right edge → click again → icons reflow back. Alternative: side-by-side before/after screenshot of the menu bar.
**Primary link:** https://codigit.hr/apps/hideicons.html

## Posting angle

App #5 in the catalog (Native Browser, StayAwake, DockGuard, ActivityBar, Hide Icons). Continues the positioning locked in with the ActivityBar post:

- ICP-aligned (SaaS/AI founders)
- "Codigit. Built for founders." sign-off
- Hook-first, scroll-stoppy single lines, double-spaced
- Concrete numbers (5 MB RAM, 90 minutes, two NSStatusItems)
- Tech-depth signal (the spacer trick, custom NSView that silently failed) to qualify the builder credibility
- AI-tooling mention (Claude pair-programming) without being preachy about it
- Honest "wrong turn" moment — adds authenticity, separates from shipping-flex theatre
- Soft DM CTA at the end, not a sales close

The unique angle vs prior posts: this is the **comparison post** the calendar called out for week 4+. Bartender is the paid incumbent. Hidden Bar is the dated free alternative. Hide Icons is the third path — clean, native, free, no Accessibility prompt. Frames Codigit as the founder-builder who outpaces commercial tools without cutting corners.

## Why codigit.hr instead of GitHub link

- OG preview card on LinkedIn (GitHub renders as plain text)
- Branded surface with the other 4 apps + services visible
- GA4 `app_download` and `app_github_view` tracking
- Source is one click away on the product page

---

## English version

```
Bartender is paid. And it asks for Accessibility permission — the keys to your whole Mac.

Hidden Bar is free, but the UI hasn't moved since 2015.

I shipped a third option last night.

The trick:

→ Two NSStatusItems — one visible chevron, one invisible 10,000-pixel spacer
→ Click the chevron, the spacer expands and physically pushes other icons off the screen's right edge
→ Click again, the spacer collapses, icons reflow back into view
→ No private APIs. No Accessibility prompt. No system hacks.

The build:

→ Swift Package, three files, ~5 MB RAM
→ Custom app icon generated programmatically through Core Graphics — no Figma export
→ Ad-hoc codesigned .app, dropped in /Applications, auto-starts at login
→ 90 minutes pair-programming with Claude, including a wrong turn through a custom NSView that silently failed to draw in the status bar button

This is the work I do for founders at Codigit: take an existing paid tool, find the documented-API workaround, and ship a cleaner free version this evening. Not next quarter.

If you're building a SaaS or AI product and you need a builder who turns "I want X" into a working app in hours instead of weeks — DM me.

Hide Icons → https://codigit.hr/apps/hideicons.html

Codigit. Built for founders.
```

---

## Croatian version (only if posting to Croatian feed — primary feed should be English)

```
Bartender se plaća. I traži Accessibility dozvolu — ključeve cijelog tvog Maca.

Hidden Bar je besplatan, ali UI se nije pomaknuo od 2015.

Sinoć sam isporučila treću opciju.

Trik:

→ Dva NSStatusItema — jedan vidljivi chevron, jedan nevidljivi spacer od 10.000 piksela
→ Klikneš chevron, spacer se proširi i fizički gura druge ikone izvan desnog ruba ekrana
→ Klikneš opet, spacer se skupi, ikone se vrate
→ Bez private API-ja. Bez Accessibility prompta. Bez system hackova.

Build:

→ Swift Package, tri datoteke, ~5 MB RAM
→ Custom ikona generirana programski preko Core Graphics — bez Figma exporta
→ Ad-hoc potpisani .app, ide u /Applications, automatski se pokreće pri prijavi
→ 90 minuta pair-programmingom s Claudeom, uključujući krivi put kroz custom NSView koji je tiho propadao u crtanju u status bar buttonu

Ovo je rad koji radim za foundere kroz Codigit: uzmeš plaćeni alat, nađeš workaround unutar dokumentiranog API-ja, i isporučiš čistiju besplatnu verziju ovu večer. Ne sljedeći kvartal.

Ako gradiš SaaS ili AI proizvod i treba ti builder koji "želim X" pretvara u funkcionalnu aplikaciju u satima umjesto tjednima — DM.

Hide Icons → https://codigit.hr/apps/hideicons.html

Codigit. Built for founders.
```

**Char count (EN):** ~1330
**Char count (HR):** ~1380

---

## Follow-up engagement tactics

- First 60 minutes: reply to every comment. LinkedIn weights early engagement heavily for reach.
- Drop a pinned comment within 5 minutes:

  > "Genuine question — does anyone actually love Bartender enough to grant it Accessibility, or do you just live with the cluttered menu bar?"

  Frames the choice the post sets up, drives comments. Comments > likes for algorithmic reach.

- DM 3–5 specific founders/devs in your ICP with "made another tiny one, thought of you" — seeds early comments and signals the post to the algorithm.

- If post performs >2× average impressions, follow up the next week with a deep-dive technical post on **how status bar geometry actually works on macOS** — narrower audience, qualifies leads who care about native macOS work.

## Hashtags (end of post, max 3)

`#macOS #SwiftLang #BuildInPublic` — same trio as ActivityBar. Tight, ICP-aligned, no recruiter-bait tags.

## Cross-link tactic

Within 24h of posting, quote-comment your own ActivityBar post (May 25):

> "Catalog hit 5 apps today — another one for the menu bar →"

This ties Hide Icons to the ActivityBar post the algorithm is already promoting, and signals "consistent shipper" to anyone clicking through your profile.

## Don't

- Don't mention a specific Bartender price. It fluctuates, and quoting a wrong number invites a "well actually" comment that derails the thread. "Paid" is enough.
- Don't post on the same day as the cPanel deploy if the codigit.hr page isn't live yet. Verify https://codigit.hr/apps/hideicons.html loads before publishing.
- Don't edit the post in the first hour — algorithm penalty.
- Don't post the HR version the same day. Hold HR for 3–4 days later, Mon Jun 1 or Tue Jun 2 afternoon.
