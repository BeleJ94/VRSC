/*
 * Tableaux de bord : actualisation automatique discrète (toutes les N secondes, seulement si l'onglet est visible)
 * et rapport PDF de direction d'une page (vue Exécutive).
 */
(function () {
  'use strict';
  var el = document.querySelector('[data-auto-refresh]');
  if (el) {
    var delay = Math.max(60, parseInt(el.getAttribute('data-auto-refresh'), 10) || 300) * 1000;
    var due = Date.now() + delay;
    setInterval(function () {
      if (document.visibilityState === 'visible' && Date.now() >= due) window.location.reload();
    }, 15000);
  }

  var button = document.querySelector('[data-exec-report]');
  var source = document.getElementById('exec-report');
  if (!button || !source || !window.PM || !window.PMExport) return;
  button.addEventListener('click', function () {
    var payload;
    try { payload = JSON.parse(source.textContent); } catch (e) { return; }
    button.disabled = true;
    Promise.resolve(window.PM.loadLogo())
      .then(function (logo) {
        return window.PM.loadPdfMake().then(function (pdfMake) { window.PMExport.exportExecReport(pdfMake, payload, logo); });
      })
      .catch(function () { if (window.Swal) window.Swal.fire({ icon: 'error', text: window.PM.t('js.error') }); })
      .then(function () { button.disabled = false; });
  });
})();
