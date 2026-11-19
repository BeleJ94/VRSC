/*
 * Import contrôlé d'un référentiel : lecture du fichier Excel (.xlsx) ou CSV dans le navigateur,
 * contrôle par le serveur, puis confirmation. Le serveur revalide tout à l'import.
 */
(function ($, Core) {
  'use strict';

  var $root = $('[data-import]');
  if (!$root.length) return;

  var t = window.PM.t;
  var esc = Core.escapeHtml;
  var maxRows = Number($root.data('max-rows')) || 2000;
  var rows = [];
  var fileName = '';
  var STATUS_CLASS = { 'new': 'available', duplicate: 'neutral', invalid: 'breakdown' };

  function showError(message) {
    $root.find('[data-import-error]').text(message || '').prop('hidden', !message);
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      if (window.ExcelJS) return resolve();
      var s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = function () { reject(new Error('load')); };
      document.head.appendChild(s);
    });
  }

  function cellText(value) {
    if (value === null || value === undefined) return '';
    if (value instanceof Date) return value.toISOString().slice(0, 10);
    if (typeof value === 'object') {
      if (value.text !== undefined) return String(value.text);
      if (value.result !== undefined) return cellText(value.result);
      if (value.richText) return value.richText.map(function (p) { return p.text; }).join('');
      return '';
    }
    return String(value).trim();
  }

  /** Tableau de lignes [ [cellule, ...], ... ] → objets indexés par l'en-tête de la première ligne. */
  function toObjects(matrix) {
    var headers = (matrix.shift() || []).map(function (h) { return cellText(h); });
    return matrix.map(function (line) {
      var obj = {};
      headers.forEach(function (h, i) { if (h) obj[h] = cellText(line[i]); });
      return obj;
    }).filter(function (obj) {
      return Object.keys(obj).some(function (k) { return obj[k] !== ''; });
    });
  }

  function parseCsv(text) {
    var sep = (text.split('\n')[0].match(/;/g) || []).length >= (text.split('\n')[0].match(/,/g) || []).length ? ';' : ',';
    var out = [], line = [], cell = '', quoted = false;
    for (var i = 0; i < text.length; i++) {
      var c = text[i];
      if (quoted) {
        if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; }
        else if (c === '"') quoted = false;
        else cell += c;
      } else if (c === '"') quoted = true;
      else if (c === sep) { line.push(cell); cell = ''; }
      else if (c === '\n') { line.push(cell); out.push(line); line = []; cell = ''; }
      else if (c !== '\r') cell += c;
    }
    if (cell !== '' || line.length) { line.push(cell); out.push(line); }
    return out;
  }

  function readFile(file) {
    if (/\.csv$/i.test(file.name)) {
      return file.text().then(function (text) { return toObjects(parseCsv(text.replace(/^﻿/, ''))); });
    }
    return loadScript(window.PM.config.assets.exceljs)
      .then(function () { return file.arrayBuffer(); })
      .then(function (buffer) { return new window.ExcelJS.Workbook().xlsx.load(buffer); })
      .then(function (workbook) {
        var sheet = workbook.worksheets[0];
        var matrix = [];
        if (sheet) {
          sheet.eachRow({ includeEmpty: false }, function (row) {
            var line = [];
            for (var c = 1; c <= sheet.columnCount; c++) line.push(row.getCell(c).value);
            matrix.push(line);
          });
        }
        return toObjects(matrix);
      });
  }

  function render(preview) {
    var counts = preview.counts;
    $root.find('[data-import-summary]').text(t('js.import.summary', { 'new': counts['new'], duplicate: counts.duplicate, invalid: counts.invalid }));
    $root.find('[data-import-lines]').html(preview.lines.map(function (l) {
      return '<tr><td>' + esc(l.line) + '</td><td><strong>' + esc(l.code) + '</strong></td><td>' + esc(l.name) + '</td>'
        + '<td><span class="badge badge--' + STATUS_CLASS[l.status] + '">' + esc(t('import_status.' + l.status)) + '</span></td>'
        + '<td>' + esc((l.errors || []).join(' ')) + '</td></tr>';
    }).join(''));
    $root.find('[data-import-result]').prop('hidden', false);
    $root.find('[data-import-confirm]').prop('disabled', counts['new'] === 0);
  }

  function post(url, payload) {
    return $.ajax({ url: url, method: 'POST', contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json' });
  }

  function fail(xhr) {
    var json = xhr && xhr.responseJSON;
    var errors = json && json.errors ? Object.keys(json.errors).map(function (k) { return json.errors[k]; }).join(' ') : '';
    showError(errors || (json && json.error) || t('js.error_generic'));
  }

  $root.on('change', '[data-import-file]', function () {
    var file = this.files && this.files[0];
    showError('');
    $root.find('[data-import-result]').prop('hidden', true);
    if (!file) return;
    fileName = file.name;
    readFile(file).then(function (parsed) {
      if (!parsed.length) return showError(t('js.import.empty'));
      if (parsed.length > maxRows) return showError(t('js.import.too_many', { max: maxRows }));
      rows = parsed;
      return post($root.data('preview-url'), { rows: rows }).then(render, fail);
    }).catch(function () { showError(t('js.import.unreadable')); });
  });

  $root.on('click', '[data-import-confirm]', function () {
    var $btn = $(this).prop('disabled', true);
    post($root.data('import-url'), { rows: rows, file_name: fileName }).then(function (result) {
      Swal.fire({ icon: 'success', title: result.message, confirmButtonColor: '#1F4E79' }).then(function () {
        window.location.href = $root.data('list-url');
      });
    }, function (xhr) { $btn.prop('disabled', false); fail(xhr); });
  });
})(jQuery, PMCore);
