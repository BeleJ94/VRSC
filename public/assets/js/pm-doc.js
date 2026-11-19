/*
 * Documents métier imprimables (PDF) : ordre de transfert et sa check-list, sous le même numéro.
 * Le serveur fournit un contenu déjà traduit ; ce module pur ne fait que la mise en page pdfmake
 * (navigateur + Node pour les tests).
 *
 * Contenu attendu : { reference, company, footer, pages: [{ title, subtitle, sections: [{ title, rows: [[libellé, valeur]] }],
 *                     tables: [{ title, head: [], body: [[]] }], notes: [], signatures: [] }] }
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.PMDoc = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var ACCENT = '#1F4E79';
  var GREY = '#5B6573';
  var LINE = '#D5DBE3';
  var SOFT = '#F3F6F9';

  var boxLayout = {
    hLineWidth: function () { return 0.5; }, vLineWidth: function () { return 0.5; },
    hLineColor: function () { return LINE; }, vLineColor: function () { return LINE; },
    paddingLeft: function () { return 6; }, paddingRight: function () { return 6; },
    paddingTop: function () { return 4; }, paddingBottom: function () { return 4; }
  };

  function text(value) {
    return value === null || value === undefined || value === '' ? '—' : String(value);
  }

  function sectionTitle(title) {
    return {
      margin: [0, 10, 0, 4],
      table: { widths: ['*'], body: [[{ text: String(title).toUpperCase(), bold: true, fontSize: 8.5, color: '#FFFFFF', fillColor: ACCENT, characterSpacing: 0.6 }]] },
      layout: { hLineWidth: function () { return 0; }, vLineWidth: function () { return 0; }, paddingLeft: function () { return 6; }, paddingTop: function () { return 3; }, paddingBottom: function () { return 3; } }
    };
  }

  function section(sec) {
    return [
      sectionTitle(sec.title),
      {
        table: {
          widths: [150, '*'],
          body: (sec.rows || []).map(function (row) {
            return [{ text: text(row[0]), color: GREY, fontSize: 8.5, fillColor: SOFT }, { text: text(row[1]), fontSize: 9.5 }];
          })
        },
        layout: boxLayout
      }
    ];
  }

  function table(tab) {
    var head = (tab.head || []).map(function (h) { return { text: text(h), bold: true, fontSize: 8.5, color: ACCENT, fillColor: SOFT }; });
    var widths = (tab.head || []).map(function (h, i) { return i === 0 ? 150 : (i === 1 ? 90 : '*'); });
    return [
      sectionTitle(tab.title),
      {
        table: { headerRows: 1, widths: widths, body: [head].concat((tab.body || []).map(function (row) {
          return row.map(function (cell) { return { text: cell === null || cell === undefined ? '' : String(cell), fontSize: 9 }; });
        })) },
        layout: boxLayout
      }
    ];
  }

  function signatures(labels) {
    if (!labels || !labels.length) return [];
    return [{
      margin: [0, 16, 0, 0],
      unbreakable: true,
      table: {
        widths: labels.map(function () { return '*'; }),
        heights: [12, 46],
        body: [
          labels.map(function (label) { return { text: text(label), bold: true, fontSize: 8, color: ACCENT, fillColor: SOFT }; }),
          labels.map(function () { return { text: '' }; })
        ]
      },
      layout: boxLayout
    }];
  }

  function page(doc, p, index) {
    var content = [
      {
        columns: [
          { width: '*', stack: [
            { text: text(doc.company), fontSize: 8, bold: true, color: GREY, characterSpacing: 1.2 },
            { text: text(p.title), fontSize: 16, bold: true, color: ACCENT, margin: [0, 3, 0, 2] },
            { text: text(p.subtitle), fontSize: 10 }
          ] },
          { width: 130, table: { widths: ['*'], body: [[{ text: text(doc.reference), alignment: 'center', bold: true, fontSize: 12, color: '#FFFFFF', fillColor: ACCENT, margin: [0, 4, 0, 4] }]] }, layout: boxLayout }
        ],
        pageBreak: index > 0 ? 'before' : undefined
      },
      { canvas: [{ type: 'line', x1: 0, y1: 0, x2: 523, y2: 0, lineWidth: 2, lineColor: ACCENT }], margin: [0, 8, 0, 2] }
    ];
    (p.sections || []).forEach(function (sec) { content = content.concat(section(sec)); });
    (p.tables || []).forEach(function (tab) { content = content.concat(table(tab)); });
    (p.notes || []).forEach(function (note) { content.push({ text: text(note), margin: [0, 8, 0, 0], fontSize: 9.5 }); });
    return content.concat(signatures(p.signatures));
  }

  /** Définition pdfmake du document complet (une page logique par entrée de doc.pages). */
  function definition(doc) {
    var content = [];
    (doc.pages || []).forEach(function (p, i) { content = content.concat(page(doc, p, i)); });
    return {
      pageSize: 'A4',
      pageMargins: [36, 36, 36, 44],
      defaultStyle: { fontSize: 9.5 },
      info: { title: text(doc.reference) },
      footer: function (current, count) {
        return { columns: [
          { text: text(doc.footer), fontSize: 7, color: GREY, margin: [36, 10, 0, 0] },
          { text: current + ' / ' + count, alignment: 'right', fontSize: 7, color: GREY, margin: [0, 10, 36, 0] }
        ] };
      },
      content: content
    };
  }

  return { definition: definition };
});
