'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const Core = require('../../public/assets/js/pm-core.js');
const Tables = require('../../public/assets/js/pm-tables.js');
const Exporter = require('../../public/assets/js/pm-export.js');
const { loadCatalogue } = require('./helpers.js');

const t = Core.createTranslator(loadCatalogue('fr'));
const columns = Tables.definitions(t, 'fr').equipment.columns;
const rows = [
  { code: 'CH-01', name: 'Chariot', status: 'available', site: 'Lyon', category: 'Frontal', hour_meter: 1520, open_work_orders: 0 },
  { code: 'TR-02', name: 'Transpalette', status: 'breakdown', site: 'Lille', category: null, hour_meter: null, open_work_orders: 2 }
];

test('tableData produit en-têtes et lignes texte', () => {
  const data = Exporter.tableData(columns, rows);
  assert.deepEqual(data.headers, ['Code', 'Nom', 'Catégorie', 'Site', 'Statut', 'Compteur horaire', 'OT ouverts']);
  assert.deepEqual(data.body[0], ['CH-01', 'Chariot', 'Frontal', 'Lyon', 'Disponible', '1 520', '0']);
  assert.deepEqual(data.body[1], ['TR-02', 'Transpalette', '', 'Lille', 'En panne', '', '2']);
});

test('document PDF de liste : paysage, en-tête répété, pagination', () => {
  const data = Exporter.tableData(columns, rows);
  const doc = Exporter.listDocDefinition('Engins', data, { subtitle: '2 lignes' });
  assert.equal(doc.pageOrientation, 'landscape');
  const table = doc.content[2].table;
  assert.equal(table.headerRows, 1);
  assert.equal(table.body.length, 3);
  assert.equal(table.body[0][0].text, 'Code');
  assert.equal(table.widths.length, 7);
  assert.equal(doc.footer(2, 5).text, '2 / 5');
  assert.equal(doc.styles.th.fillColor, '#1F4E79');
});

test('bon de travail PDF : informations et total des pièces', () => {
  const wo = {
    reference: 'OT-2026-00042', title: 'Fuite hydraulique', equipment_code: 'CH-01', equipment_name: 'Chariot', site: 'Lyon',
    type: 'corrective', priority: 'high', status: 'done', reporter: 'Sofia', reported_at: '2026-09-20 08:00:00',
    assignee: 'Sofia', due_date: '2026-09-21', completed_at: '2026-09-21 10:30:00', labor_minutes: 90,
    description: 'Fuite au vérin', resolution: 'Joint remplacé'
  };
  const parts = [
    { reference: 'JNT-1', name: 'Joint', quantity: '2.00', unit: 'u', unit_cost: '12.50' },
    { reference: 'HUI-1', name: 'Huile', quantity: '1.5', unit: 'L', unit_cost: '6.20' }
  ];
  const doc = Exporter.workOrderDocDefinition(wo, parts, t, 'fr');
  const flat = JSON.stringify(doc.content);
  assert.match(flat, /Ordre de travail OT-2026-00042/);
  assert.match(flat, /Correctif/);
  assert.match(flat, /Terminé/);
  assert.match(flat, /90 min/);
  assert.match(flat, /Joint remplacé/);
  assert.match(flat, /34,30 \$/, 'Total = 2 × 12,50 + 1,5 × 6,20');

  const withTech = JSON.stringify(Exporter.workOrderDocDefinition(wo, parts, t, 'fr', [{ name: 'Karim', job: 'mechanic', minutes: 45 }]).content);
  assert.match(withTech, /INTERVENANTS/);
  assert.match(withTech, /Mécanicien/);
  assert.match(withTech, /45 min/);

  const empty = Exporter.workOrderDocDefinition({ ...wo, labor_minutes: null, resolution: null }, [], t, 'fr');
  assert.match(JSON.stringify(empty.content), /"—"/);
});

test('classeur Excel : en-tête stylé, filtre automatique, largeurs bornées', () => {
  // Double minimal d'ExcelJS : on vérifie ce que le module configure.
  class Sheet {
    constructor() { this.rows = []; this.columnsList = []; }
    addRow(values) {
      const row = { values, font: null, fill: null, alignment: null, cells: {}, getCell(n) { return (this.cells[n] = this.cells[n] || {}); } };
      this.rows.push(row);
      values.forEach((_, i) => { this.columnsList[i] = this.columnsList[i] || { width: undefined }; });
      return row;
    }
    getRow(n) { return this.rows[n - 1]; }
    get columns() { return this.columnsList; }
  }
  class Workbook {
    constructor() { this.sheets = []; }
    addWorksheet(name, opts) { const s = new Sheet(); s.name = name; s.opts = opts; this.sheets.push(s); return s; }
  }
  const data = Exporter.tableData(columns, rows);
  data.body[0][1] = 'x'.repeat(200);
  const wb = Exporter.buildWorkbook({ Workbook }, 'Ordres de travail et engins du parc', data);
  const sheet = wb.sheets[0];

  assert.equal(sheet.name.length <= 31, true, 'Nom de feuille ≤ 31 caractères (limite Excel)');
  assert.equal(sheet.rows.length, 3);
  assert.equal(sheet.rows[0].font.bold, true);
  assert.equal(sheet.rows[0].fill.fgColor.argb, 'FF1F4E79');
  assert.deepEqual(sheet.autoFilter, { from: { row: 1, column: 1 }, to: { row: 1, column: 7 } });
  assert.equal(sheet.opts.views[0].ySplit, 1);
  assert.equal(sheet.columns[1].width, 60);
  assert.ok(sheet.columns[0].width >= 10);
});

test('planning : matrice engins × jours, totaux et libellés courts', () => {
  const payload = {
    days: [{ key: '2026-09-21', label: 'Lun 21/09' }, { key: '2026-09-22', label: 'Mar 22/09' }],
    labels: { short: { preventive: 'PRÉV', preventive_planned: 'PRÉV-OT', work_order: 'OT', contract: 'FIN PO' } },
    events: [
      { date: '2026-09-21', kind: 'preventive', site: 'KAMAO', code: 'KAM-2', equipment_name: 'Benne', title: 'Service', overdue: true },
      { date: '2026-09-22', kind: 'work_order', site: 'KAMAO', code: 'KAM-1', equipment_name: 'Pelle', title: 'Vérin', overdue: false },
      { date: '2026-09-22', kind: 'contract', site: 'CHEMAF', code: 'CHE-1', equipment_name: 'ADT', title: 'PO-1', overdue: false },
      { date: '2026-10-01', kind: 'contract', site: 'CHEMAF', code: 'CHE-9', equipment_name: 'Hors période', title: 'x', overdue: false }
    ]
  };
  const m = Exporter.planningMatrix(payload);
  assert.deepEqual(m.groups.map((g) => g.site), ['CHEMAF', 'KAMAO']);
  assert.deepEqual(m.groups[1].rows.map((r) => r.code), ['KAM-1', 'KAM-2']);
  assert.equal(m.groups[1].rows[1].cells[0][0].text, 'PRÉV !', 'Retard signalé');
  assert.deepEqual(m.dayTotals, [1, 2]);
  assert.equal(m.total, 3, 'Événement hors période ignoré');
  const doc = Exporter.planningDocDefinition({ ...payload, labels: { ...payload.labels, title: 'Planning', equipment: 'Engin', total: 'Total', legend: 'Légende', kinds: {}, overdue: 'En retard', scope: 'Tous', generated: 'édité' } }, m);
  assert.equal(doc.pageSize, 'A4');
  assert.equal(doc.content[2].table.body[0].length, 4, 'Engin + 2 jours + total');
});

test('PDF : les espaces fines (séparateur de milliers) sont remplacées par des insécables prises en charge par Roboto', () => {
  const doc = {
    content: [{ text: Core.formatMoney(120665.36, 'fr') }, { table: { body: [[Core.formatNumber(1234567, 'fr', 0)]] } }],
    footer: () => ({ text: Core.formatNumber(2500, 'fr', 0) })
  };
  const safe = Exporter.pdfSafe(doc);
  const json = JSON.stringify(safe);
  assert.ok(!/ /.test(json), 'aucune espace fine restante');
  assert.equal(safe.content[0].text, '120 665,36 $');
  assert.equal(safe.footer().text, '2 500');
  assert.ok(/ /.test(Core.formatNumber(1234, 'fr', 0)), 'affichage écran inchangé');
});

test('Excel : colonnes numériques et dates exportées en valeurs natives formatées', () => {
  const Tables = require('../../public/assets/js/pm-tables.js');
  const defs = Tables.definitions((k) => k, 'fr');
  Core.setCurrency('$');
  const data = Exporter.tableData(defs.procurement.columns,
    [{ reference: 'X', name: 'Huile', part_type: 'lubricant', site: 'KAMAO', unit: 'L', quantity: '1250.50', min_quantity: '600', monthly: '0', coverage_days: 12, level: 'low', suggested: '0', order_value: '2880.5' }], 'fr');
  const byTitle = Object.fromEntries(defs.procurement.columns.map((c, i) => [c.data, data.cells[0][i]]));
  assert.deepEqual(byTitle.quantity, { v: 1250.5, fmt: '#,##0.00" L"' });
  assert.deepEqual(byTitle.order_value, { v: 2880.5, fmt: '#,##0.00" $"' });
  assert.deepEqual(byTitle.monthly, { v: null, fmt: null }, 'zéro masqué comme à l\'écran');
  assert.equal(byTitle.reference, null, 'colonne texte inchangée');
  const wo = Exporter.tableData(defs.work_orders.columns, [{ reported_at: '2026-09-24 08:30:00', due_date: null }], 'fr');
  const reported = wo.cells[0][defs.work_orders.columns.findIndex((c) => c.data === 'reported_at')];
  assert.equal(reported.v.toISOString(), '2026-09-24T08:30:00.000Z');
  assert.equal(reported.fmt, 'dd/mm/yyyy');
});

test('export exécutif : bandeau, cartes de synthèse, ligne de total, dates à gauche', () => {
  const defs = Tables.definitions(t, 'fr');
  const rows = [
    { reference: 'DD-1', date: '2026-11-17 10:00:00', site: 'Kolwezi', party: 'Katanga Diesel', kind: 'invoice', category: 'fuel', status: 'paid', amount: '1200.50' },
    { reference: 'DD-2', date: '2026-11-18 09:00:00', site: 'Likasi', party: 'Hôtel', kind: 'expense', category: 'other', status: 'paid', amount: '99.50' }
  ];
  const data = Exporter.tableData(defs.drill_payments.columns, rows, 'fr');
  assert.equal(data.totals[6], 1300);
  assert.match(data.totalsText[6], /1\s300,00/);
  const doc = Exporter.executiveDocDefinition('Dépenses payées', data, { executive: true, logo: '<svg/>', filters: 'Kolwezi', author: 'Édité par X', count: 2 });
  assert.equal(doc.pageOrientation, 'landscape');
  assert.equal(doc.content[0].columns[0].svg, '<svg/>', 'Logo VRSC en tête');
  const flat = JSON.stringify(doc.content);
  assert.match(flat, /Kolwezi/);
  assert.match(flat, /Édité par X/);
  const table = doc.content[3].table;
  assert.equal(table.body.length, 4, 'En-tête + 2 lignes + total');
  assert.equal(table.body[3][0].text, 'Total');
  assert.match(table.body[3][6].text, /1\s300,00/);
  assert.equal(table.body[1][1].alignment, 'left', 'Date alignée à gauche');
  assert.equal(table.body[1][6].alignment, 'right', 'Montant aligné à droite');
  assert.match(doc.footer(1, 3).columns[0].text, /Document interne/);
  assert.equal(Exporter.pdfSafe({ svg: 'a b' }).svg, 'a b', 'Logo SVG non retouché');
});

test('classeur exécutif : titre, contexte, en-tête figé en ligne 4, total en formule', () => {
  class Sheet {
    constructor() { this.rows = []; this.columnsList = []; this.merged = []; }
    addRow(values) {
      const row = { values, cells: {}, getCell(n) { return (this.cells[n] = this.cells[n] || {}); } };
      this.rows.push(row);
      (values || []).forEach((_, i) => { this.columnsList[i] = this.columnsList[i] || { width: undefined }; });
      return row;
    }
    getRow(n) { return this.rows[n - 1]; }
    mergeCells(...a) { this.merged.push(a); }
    get columns() { return this.columnsList; }
  }
  class Workbook { constructor() { this.sheets = []; } addWorksheet(name, opts) { const s = new Sheet(); s.opts = opts; this.sheets.push(s); return s; } }
  const defs = Tables.definitions(t, 'fr');
  const rows = [{ reference: 'DD-1', date: '2026-11-17', site: 'K', party: 'P', kind: 'expense', category: 'fuel', status: 'paid', amount: '10' },
    { reference: 'DD-2', date: '2026-11-18', site: 'K', party: 'P', kind: 'expense', category: 'fuel', status: 'paid', amount: '5' }];
  const data = Exporter.tableData(defs.drill_payments.columns, rows, 'fr');
  const sheet = Exporter.buildExecutiveWorkbook({ Workbook }, 'Dépenses payées', data, { filters: 'Kolwezi', subtitle: '2 lignes', totalLabel: 'Total' }).sheets[0];
  assert.match(sheet.rows[0].values[0], /^VRSC — Dépenses payées/);
  assert.match(sheet.rows[1].values[0], /Kolwezi · 2 lignes/);
  assert.equal(sheet.opts.views[0].ySplit, 4);
  assert.deepEqual(sheet.autoFilter.from, { row: 4, column: 1 });
  const total = sheet.rows[sheet.rows.length - 1].values;
  assert.equal(total[0], 'Total');
  assert.deepEqual(total[6], { formula: 'SUBTOTAL(9,G5:G6)', result: 15 });
});

test('lien de ligne fourni par le serveur : {!clé} conservé tel quel, jamais une autre origine', () => {
  assert.equal(Core.fillUrl('{!link}', { link: '/ETHAN%20VRSC/purchases/12' }), '/ETHAN%20VRSC/purchases/12');
  assert.equal(Core.fillUrl('{!link}', { link: '//evil.example/x' }), '#');
  assert.equal(Core.fillUrl('{!link}', { link: 'javascript:alert(1)' }), '#');
  assert.equal(Core.fillUrl('/x/{id}', { id: 'a/b' }), '/x/a%2Fb');
});

test('rapport de direction : une page A4 paysage, indicateurs, tableaux plafonnés, logo et pied de page', () => {
  const kpi = (label, tone, delta) => ({ label, value: '1 000,00 $', delta, tone, hint: 'h', series: [1, 3, 2, 5, 4, 6] });
  const rows = (n, cols) => Array.from({ length: n }, (_, i) => Array.from({ length: cols }, (_, c) => (c === 0 ? 'L' + i : String(c))));
  const payload = {
    title: 'Rapport de direction', subtitle: 'Année · Tous les sites', generated: 'Généré par X', confidential: 'Document interne',
    kpis: [kpi('A', 'good', '+5,0 %'), kpi('B', 'bad', '-3,0 %'), kpi('C', 'neutral', null), kpi('D', 'flat', null), kpi('E', 'flat', null), kpi('F', 'good', '+1,0 %'), kpi('G', 'flat', null)],
    sites: { title: 'Sites', headers: ['Site', 'Dispo', 'Coût', 'Payé', 'Stock', 'EDB'], rows: rows(12, 6).map((r) => r.concat(['good'])) },
    top: { title: 'Top', headers: ['#', 'Engin', 'Site', 'Coût'], rows: rows(9, 4), note: 'n' },
    trend: { title: 'Évolution', headers: ['Mois', 'E', 'P', 'C'], rows: rows(6, 4) },
    alerts: { title: 'Alertes', empty: 'Aucune', rows: Array.from({ length: 10 }, (_, i) => ['Alerte ' + i, 'warn']) },
    closing: { title: 'Clôtures', done: 3, expected: 8, text: '3 sur 8' }
  };
  const doc = Exporter.execReportDocDefinition(payload, '<svg/>');
  assert.equal(doc.pageOrientation, 'landscape');
  assert.equal(doc.pageSize, 'A4');
  assert.equal(doc.content[0].columns[0].svg, '<svg/>');
  assert.equal(doc.content[2].columns.length, 6, '6 indicateurs au plus');
  const left = doc.content[3].columns[0].stack;
  assert.equal(left[1].table.body.length, 9, 'En-tête + 8 sites au plus');
  assert.equal(left[4].table.body.length, 6, 'En-tête + 5 engins au plus');
  assert.equal(left[6].stack[1].table.body.length, 7, 'En-tête + 6 mois');
  assert.equal(doc.content[3].columns[1].stack[1].stack.length, 7, '7 alertes au plus');
  assert.match(doc.footer(1, 1).columns[0].text, /Document interne · Généré par X/);
  assert.equal(doc.footer(1, 1).columns[1].text, '1 / 1');
  const flat = JSON.stringify(doc.content);
  assert.match(flat, /\+5,0 %/);
  assert.match(flat, /"color":"#B42318"/, 'Variation défavorable en rouge');
  assert.match(flat, /polyline/, 'Mini-courbes');
  // Sans logo : repli texte, aucun nœud SVG.
  assert.equal(Exporter.execReportDocDefinition(payload, null).content[0].columns[0].text, 'VRSC');
});
