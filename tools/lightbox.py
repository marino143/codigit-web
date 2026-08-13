# -*- coding: utf-8 -*-
"""Ubacuje lightbox za screenshotove u stranice proizvoda (bez izmjena u markupu)."""
import sys, os

CSS = """
    /* --- Povećavanje screenshotova --- */
    .shot img { cursor: zoom-in; }
    .shot { position: relative; }
    .shot .shot-zoom {
      position: absolute; top: 10px; right: 10px; width: 30px; height: 30px;
      display: grid; place-items: center; border-radius: 8px; pointer-events: none;
      background: rgba(0,0,0,.55); color: #fff; opacity: 0; transition: opacity .15s;
      backdrop-filter: blur(4px);
    }
    .shot:hover .shot-zoom, .shot img:focus-visible + .shot-zoom { opacity: 1; }
    @media (hover: none) { .shot .shot-zoom { opacity: .9; } }

    .lb { position: fixed; inset: 0; z-index: 200; display: none; background: rgba(8,8,10,.93); }
    .lb.open { display: grid; grid-template-rows: auto 1fr auto; }
    .lb-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; }
    .lb-count { font-size: 13px; color: rgba(255,255,255,.65); font-variant-numeric: tabular-nums; }
    .lb-btn {
      appearance: none; border: 1px solid rgba(255,255,255,.22); background: rgba(255,255,255,.08);
      color: #fff; border-radius: 9px; padding: 8px 12px; font: inherit; font-size: 13px;
      font-weight: 600; cursor: pointer; line-height: 1;
    }
    .lb-btn:hover { background: rgba(255,255,255,.16); }
    .lb-btn:disabled { opacity: .35; cursor: default; }
    .lb-nav { display: flex; gap: 8px; }
    .lb-stage { overflow: auto; display: grid; place-items: center; padding: 0 16px; -webkit-overflow-scrolling: touch; }
    .lb-stage img { display: block; max-width: 100%; max-height: 100%; border-radius: 10px; cursor: zoom-in; }
    .lb.zoom .lb-stage { place-items: start; }
    .lb.zoom .lb-stage img { max-width: none; max-height: none; cursor: zoom-out; }
    .lb-cap { padding: 12px 16px 18px; color: rgba(255,255,255,.75); font-size: 13px; text-align: center; max-width: 780px; margin: 0 auto; }
    @media (max-width: 560px) { .lb-bar { padding: 10px 12px; } .lb-btn { padding: 8px 10px; } .lb-cap { font-size: 12.5px; } }
"""

JS = """
  /* Screenshotovi se otvaraju preko cijelog ekrana; na uskim ekranima su
     inače premali da bi se u njima išta pročitalo. Bez izmjena u markupu —
     nadogradnja se veže na postojeći .shots > figure.shot. */
  (function () {
    var figs = Array.prototype.slice.call(document.querySelectorAll('.shots figure.shot'));
    if (!figs.length) return;

    var T = {
      en: { open: 'Open full size', close: 'Close', prev: 'Previous', next: 'Next', zin: 'Zoom in', zout: 'Fit to screen', of: 'of' },
      hr: { open: 'Otvori u punoj veličini', close: 'Zatvori', prev: 'Prethodna', next: 'Sljedeća', zin: 'Povećaj', zout: 'Prilagodi ekranu', of: 'od' }
    };
    function tr() { return T[document.documentElement.lang] || T.en; }

    var lb = document.createElement('div');
    lb.className = 'lb';
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    lb.innerHTML =
      '<div class="lb-bar">' +
        '<span class="lb-count"></span>' +
        '<div class="lb-nav">' +
          '<button class="lb-btn" data-a="prev"></button>' +
          '<button class="lb-btn" data-a="next"></button>' +
          '<button class="lb-btn" data-a="zoom"></button>' +
          '<button class="lb-btn" data-a="close"></button>' +
        '</div>' +
      '</div>' +
      '<div class="lb-stage"><img alt="" /></div>' +
      '<p class="lb-cap"></p>';
    document.body.appendChild(lb);

    var img = lb.querySelector('.lb-stage img');
    var cap = lb.querySelector('.lb-cap');
    var count = lb.querySelector('.lb-count');
    var stage = lb.querySelector('.lb-stage');
    var btn = {};
    Array.prototype.forEach.call(lb.querySelectorAll('[data-a]'), function (b) { btn[b.dataset.a] = b; });

    var i = 0, lastFocus = null;

    function paint() {
      var f = figs[i], src = f.querySelector('img');
      var c = f.querySelector('figcaption');
      img.src = src.currentSrc || src.src;
      img.alt = src.alt || '';
      cap.textContent = c ? c.textContent : '';
      count.textContent = (i + 1) + ' ' + tr().of + ' ' + figs.length;
      btn.prev.disabled = i === 0;
      btn.next.disabled = i === figs.length - 1;
      lb.classList.remove('zoom');
      stage.scrollTop = 0; stage.scrollLeft = 0;
      labels();
    }
    function labels() {
      var t = tr(), zoomed = lb.classList.contains('zoom');
      btn.prev.textContent = '‹'; btn.prev.setAttribute('aria-label', t.prev);
      btn.next.textContent = '›'; btn.next.setAttribute('aria-label', t.next);
      btn.zoom.textContent = zoomed ? t.zout : t.zin;
      btn.close.textContent = t.close;
      lb.setAttribute('aria-label', t.open);
    }
    function open(n) {
      lastFocus = document.activeElement;
      i = n; paint();
      lb.classList.add('open');
      document.body.style.overflow = 'hidden';
      btn.close.focus();
    }
    function close() {
      lb.classList.remove('open', 'zoom');
      document.body.style.overflow = '';
      img.removeAttribute('src');
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    function go(d) { var n = i + d; if (n >= 0 && n < figs.length) { i = n; paint(); } }
    function toggleZoom() { lb.classList.toggle('zoom'); labels(); }

    figs.forEach(function (f, n) {
      var im = f.querySelector('img');
      if (!im) return;
      var badge = document.createElement('span');
      badge.className = 'shot-zoom';
      badge.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6M11 8v6M8 11h6"/></svg>';
      im.insertAdjacentElement('afterend', badge);
      im.tabIndex = 0;
      im.setAttribute('role', 'button');
      im.setAttribute('aria-label', tr().open);
      im.addEventListener('click', function () { open(n); });
      im.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(n); }
      });
    });

    btn.close.addEventListener('click', close);
    btn.prev.addEventListener('click', function () { go(-1); });
    btn.next.addEventListener('click', function () { go(1); });
    btn.zoom.addEventListener('click', toggleZoom);
    img.addEventListener('click', toggleZoom);
    lb.addEventListener('click', function (e) { if (e.target === lb || e.target === stage) close(); });
    document.addEventListener('keydown', function (e) {
      if (!lb.classList.contains('open')) return;
      if (e.key === 'Escape') close();
      else if (e.key === 'ArrowLeft') go(-1);
      else if (e.key === 'ArrowRight') go(1);
      else if (e.key === 'Tab') {
        var f = lb.querySelectorAll('.lb-btn:not(:disabled)');
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
    // jezik se mijenja bez ponovnog učitavanja — osvježi natpise
    document.querySelectorAll('.lang-btn').forEach(function (b) {
      b.addEventListener('click', function () { setTimeout(function () {
        labels();
        figs.forEach(function (f) { var im = f.querySelector('img'); if (im) im.setAttribute('aria-label', tr().open); });
        if (lb.classList.contains('open')) paint();
      }, 0); });
    });
  })();
"""

ANCHOR_CSS = ".shot figcaption { font-size: 12.5px; color: var(--muted); padding: 10px 14px; border-top: 1px solid var(--border); }"

def patch(path):
    s = open(path, encoding="utf-8").read()
    if "lb-stage" in s:
        print("  već ima lightbox:", path); return False
    assert ANCHOR_CSS in s, "CSS sidro nije nađeno u " + path
    s = s.replace(ANCHOR_CSS, ANCHOR_CSS + "\n" + CSS.rstrip(), 1)
    assert "</body>" in s
    s = s.replace("</body>", "<script>\n" + JS.strip() + "\n</script>\n</body>", 1)
    open(path, "w", encoding="utf-8").write(s)
    print("  ubačeno u:", path); return True

if __name__ == "__main__":
    root = "/Users/marinoglazarair/Documents/Co Work/codigit-web/apps"
    for f in ("claimo.html", "activitybar.html"):
        patch(os.path.join(root, f))
