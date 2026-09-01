(function () {
  'use strict';

  var measurementId = 'G-T97BQ3VRV1';
  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };

  window.loadAnalytics = function () {
    if (document.querySelector('script[data-codigit-analytics]')) return;
    var script = document.createElement('script');
    script.async = true;
    script.dataset.codigitAnalytics = 'true';
    script.src = 'https://www.googletagmanager.com/gtag/js?id=' + measurementId;
    document.head.appendChild(script);
    window.gtag('js', new Date());
    window.gtag('config', measurementId);
  };

  try {
    if (localStorage.getItem('cookieConsent') === 'accepted') window.loadAnalytics();
  } catch (_) {}

  if (document.getElementById('menuBtn') || !document.querySelector('nav')) return;

  var nav = document.querySelector('nav');
  var button = document.querySelector('.site-mobile-toggle');
  var menu = document.querySelector('.site-mobile-menu');
  if (!button || !menu) return;

  function setOpen(open) {
    menu.classList.toggle('open', open);
    button.setAttribute('aria-expanded', String(open));
    button.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    document.body.style.overflow = open ? 'hidden' : '';
  }

  button.addEventListener('click', function () { setOpen(!menu.classList.contains('open')); });
  menu.querySelector('.site-mobile-close').addEventListener('click', function () { setOpen(false); });
  menu.querySelectorAll('a').forEach(function (link) { link.addEventListener('click', function () { setOpen(false); }); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape') setOpen(false); });

})();
