/*
 * Tableaux de bord : actualisation automatique discrète (toutes les N secondes, seulement si l'onglet est visible).
 */
(function () {
  'use strict';
  var el = document.querySelector('[data-auto-refresh]');
  if (!el) return;
  var delay = Math.max(60, parseInt(el.getAttribute('data-auto-refresh'), 10) || 300) * 1000;
  var due = Date.now() + delay;
  setInterval(function () {
    if (document.visibilityState === 'visible' && Date.now() >= due) window.location.reload();
  }, 15000);
})();
