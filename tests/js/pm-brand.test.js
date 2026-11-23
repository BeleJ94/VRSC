'use strict';
const test = require('node:test');
const assert = require('node:assert');
const Exporter = require('../../public/assets/js/pm-export.js');

const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';

test('ajoute le bandeau VRSC en tête sans modifier le document d’origine', () => {
  const doc = { pageOrientation: 'landscape', pageMargins: [24, 28, 24, 34], content: [{ text: 'Liste' }] };
  const out = Exporter.brandDocument(doc, SVG);
  assert.strictEqual(doc.content.length, 1);
  assert.strictEqual(out.content.length, 2);
  assert.strictEqual(out.content[0].stack[0].columns[0].svg, SVG);
  assert.strictEqual(out.content[0].stack[1].canvas[0].x2, 794);
});

test('ne double pas le logo et respecte noBrand ou l’absence de logo', () => {
  const branded = { content: [{ columns: [{ svg: SVG }] }] };
  assert.strictEqual(Exporter.brandDocument(branded, SVG), branded);
  const opt = { noBrand: true, content: [] };
  assert.strictEqual(Exporter.brandDocument(opt, SVG), opt);
  const plain = { content: [] };
  assert.strictEqual(Exporter.brandDocument(plain, null), plain);
});

test('Excel : logo posé à droite du bandeau, version blanche sur fond foncé', () => {
  const placed = [];
  const sheet = (fill) => ({
    model: { merges: ['A1:D1'] }, columnCount: 6,
    rows: { 1: { height: 24 } },
    getRow(n) { return this.rows[n]; },
    getCell() { return { fill }; },
    getColumn() { return { width: 20 }; },
    addImage(id, pos) { placed.push({ id, pos }); }
  });
  const images = [];
  const wb = {
    worksheets: [sheet(null), sheet({ fgColor: { argb: 'FF1F4E79' } })],
    addImage(img) { images.push(img); return images.length - 1; }
  };
  Exporter.brandWorkbook(wb, { color: 'C', white: 'W', ratio: 3.2 });
  assert.deepStrictEqual(images.map((i) => i.base64), ['C', 'W']);
  assert.strictEqual(wb.worksheets[0].rows[1].height, 30);
  assert.ok(placed[0].pos.tl.col > 2 && placed[0].pos.tl.col < 4, 'ancré dans la dernière colonne du bandeau (D)');
  assert.strictEqual(placed[1].id, 1);
  const none = { worksheets: [] };
  assert.strictEqual(Exporter.brandWorkbook(none, null), none);
});

test('fiche engin PDF : en-tête, indicateurs, sections et coûts selon les droits', () => {
  const block = (headers, rows) => ({ headers, rows });
  const p = {
    title: 'FICHE ENGIN', code: 'KOL-ADT-02', name: 'Tombereau', subtitle: 'Volvo', generated: 'Généré', confidential: 'Interne', filename: 'f', currency: '$',
    labels: { identity: 'Caractéristiques', kpis: 'KPI', cost_kinds: 'Coûts par nature', months: 'Mensuel', work_orders: 'OT', preventive: 'Préventif', documents: 'Documents', history: 'Historique', none: 'Aucun', total: 'Total' },
    identity: [['Code', 'KOL-ADT-02']],
    kpis: [{ label: 'Statut', value: 'Disponible', hint: '' }, { label: 'Coût', value: '10 $', hint: '' }],
    show_costs: true,
    cost_kinds: { headers: ['Nature', 'Montant', 'Part'], rows: [['Carburant', 80, 80], ['Pièces', 20, 20]], total: 100 },
    months: block(['Mois', 'Montant', 'L'], [['2026-10', 40, 10], ['2026-11', 60, 12]]),
    work_orders: block(['Réf', 'Intitulé', 'Type', 'Statut', 'Signalé', 'Clôturé', 'Coût'], [['OT-1', 'Fuite', 'Correctif', 'Ouvert', '2026-11-01', '', 12.5]]),
    preventive: block(['Intitulé', 'Périodicité', 'Prochaine', 'Actif'], []),
    documents: block(['Doc', 'Réf', 'Échéance', 'Statut'], [['Assurance', 'POL', '28/10/2026', 'Expiré', 'bad']]),
    history: block(['Date', 'De', 'Vers', 'Motif', 'Par'], [])
  };
  const doc = Exporter.equipmentReportDocDefinition(p, '<svg/>', 'fr');
  const flat = JSON.stringify(doc.content);
  assert.strictEqual(doc.content[0].columns[0].svg, '<svg/>');
  assert.match(flat, /KOL-ADT-02 — Tombereau/);
  assert.match(flat, /COÛTS PAR NATURE/);
  assert.match(flat, /OT-1/);
  assert.match(flat, /#B42318/, 'Document expiré en rouge');
  assert.strictEqual(doc.footer(1, 2).columns[1].text, '1 / 2');
  const noCost = JSON.stringify(Exporter.equipmentReportDocDefinition({ ...p, show_costs: false }, null, 'fr').content);
  assert.doesNotMatch(noCost, /COÛTS PAR NATURE/);
  assert.match(noCost, /PRÉVENTIF/);
});
