'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const Core = require('../../public/assets/js/pm-core.js');
const Tables = require('../../public/assets/js/pm-tables.js');
const { loadCatalogue, sortableKeys } = require('./helpers.js');

const fr = loadCatalogue('fr');
const t = Core.createTranslator(fr);
const defs = Tables.definitions(t, 'fr');

const REPOSITORIES = {
  equipment: 'EquipmentRepository',
  documents: 'EquipmentDocumentRepository',
  work_orders: 'WorkOrderRepository',
  preventive: 'PreventivePlanRepository',
  parts: 'PartRepository',
  procurement: 'ProcurementRepository',
  personnel: 'PersonnelRepository',
  sites: 'SiteRepository',
  users: 'UserRepository',
  readings: 'MeterReadingRepository',
  activity: 'ActivityLogRepository',
  stock_statement: 'StockLedgerRepository',
  stock_journal: 'StockLedgerRepository',
  purchase_requests: 'PurchaseRepository',
  purchase_execution: 'PurchaseRepository',
  finance_summary: 'FinanceRepository',
  equipment_costs: 'EquipmentCostRepository',
  fuel_refills: 'EquipmentCostRepository',
  drill_payments: 'DashboardDrillRepository',
  drill_invoices: 'DashboardDrillRepository',
  drill_commitments: 'DashboardDrillRepository',
  drill_cost_lines: 'DashboardDrillRepository',
  drill_stock_values: 'DashboardDrillRepository'
};

test('chaque tableau a un repository et chaque colonne triable est dans sa liste blanche PHP', () => {
  assert.deepEqual(Object.keys(defs).sort(), Object.keys(REPOSITORIES).sort());
  for (const [name, def] of Object.entries(defs)) {
    const allowed = sortableKeys(REPOSITORIES[name]);
    assert.ok(allowed.length > 0, name);
    for (const col of def.columns) {
      if (col.orderable !== false) {
        assert.ok(allowed.includes(col.data), `${name}.${col.data} triable côté JS mais absent de ${REPOSITORIES[name]}::SORTABLE`);
      }
    }
    const [index] = def.order[0];
    assert.notEqual(def.columns[index].orderable, false, `${name} : tri initial sur une colonne triable`);
  }
});

test('tous les titres de colonnes sont traduits', () => {
  for (const [name, def] of Object.entries(defs)) {
    for (const col of def.columns) {
      assert.ok(col.title && !/^[a-z_]+\.[a-z0-9_.]+$/.test(col.title), `${name}.${col.data} : "${col.title}"`);
    }
  }
});

test('le rendu HTML échappe les données utilisateur', () => {
  const evil = '<script>alert(1)</script>';
  const rows = {
    equipment: { code: evil, name: evil, brand: evil, status: 'breakdown', site: evil, category: evil, project_code: evil, incomplete: 1 },
    documents: { code: evil, equipment_name: evil, plate_number: evil, site: evil, type_label: evil, issuer: evil, reference: evil, state: 'renewing', responsible: evil, exemption_reason: evil, state_label: evil, id: 1, file_url: evil, renew_url: evil },
    work_orders: { reference: evil, title: evil, equipment_code: evil, equipment_name: evil, site: evil, type: 'corrective', priority: 'high', status: 'open', assignee: evil, immobilizing: 1, overdue: 1, due_date: '2026-01-01' },
    preventive: { title: evil, equipment_code: evil, equipment_name: evil, site: evil, is_active: 0 },
    parts: { reference: evil, name: evil, unit: evil, quantity: 1, sites_below_min: 1, manufacturer: evil, brands: evil, part_type: 'tyre', is_wear_part: 1 },
    procurement: { reference: evil, name: evil, manufacturer: evil, unit: evil, site: evil, part_type: 'lubricant', quantity: 1, min_quantity: 2, monthly: 3, coverage_days: 4, level: 'low', suggested: 5, order_value: 6 },
    personnel: { first_name: evil, last_name: evil, employee_no: evil, job: 'driver', site: evil, team: evil, shift: 'night', phone: evil, equipment: evil, is_active: 1 },
    sites: { code: evil, name: evil, city: evil },
    users: { name: evil, email: evil, role: 'technician', sites: evil },
    readings: { code: evil, equipment_name: evil, site: evil, hour_meter: 1, odometer_km: null, fuel_level: 50, fuel_liters: 40, recorded_by_name: evil, note: evil },
    activity: { user_name: evil, site: evil, entity_type: 'equipment', action: 'update', summary: evil,
      before_data: JSON.stringify({ name: evil }), after_data: JSON.stringify({ name: 'ok' }) },
    stock_statement: { reference: evil, name: evil, unit: evil, site: evil, site_id: 1, qty_open: 1, receipts: 2, returns: 0, issues: 1, transfers: 0,
      adjustments: 0, qty_close: 2, min_quantity: 5, in_transit: 1, value_close: null },
    stock_journal: { moved_on: '2026-01-05', document_ref: evil, work_order: evil, part: evil, part_name: evil, site: evil, type: 'receipt', delta: 3,
      stock_after: 3, unit: evil, value: null, beneficiary: evil, user: evil, comment: evil },
    purchase_requests: { reference: evil, requested_at: '2026-01-05 10:00:00', requester: evil, department: evil, site: evil, project: evil, subject: evil,
      category: 'parts', estimated_amount: null, urgency: 'urgent', display_status: 'partial' },
    purchase_execution: { reference: evil, subject: evil, approved_at: '2026-01-05 10:00:00', executed_at: null, delay_days: null, estimated_amount: 10,
      engaged_amount: 5, received_value: 2, remaining_amount: 8, qty_remaining: 3, qty_satisfied: 1, qty_requested: 4, stage: 'delivery',
      display_status: 'partial', age_days: 12, requester: evil, site: evil, orders: evil, proofs: evil, paid_amount: 1 },
    finance_summary: { label: evil, requested: 1, approved: 1, engaged: 1, paid: 1, remaining: 0, advances: 0 },
    equipment_costs: { code: evil, name: evil, site: evil, category: evil, meter_basis: 'hours', usage_value: null, fuel: 1, parts: 2, tyres_filters: 1, lubricants: 1,
      labor: 0, external: 3, other: 0, total: 8, missing_count: 1, unit_cost: null, prev_total: 2, ytd_total: 10, fuel_ratio: 1.5, fuel_alert: 'over', meter_alert: 1 },
    fuel_refills: { refill_date: '2026-01-05', reference: evil, voucher_ref: evil, equipment: evil, equipment_name: evil, site: evil, litres: 10, amount: null,
      hour_meter: 100, odometer_km: null, meter_alert: evil, source: 'external', station: evil, user: evil },
    drill_payments: { reference: evil, date: '2026-01-05', site: evil, party: evil, kind: 'expense', category: 'other', status: 'paid', amount: 10, link: evil },
    drill_invoices: { reference: evil, date: '2026-01-05', due_date: '2026-01-01', party: evil, site: evil, amount: 10, paid: 2, unpaid: 8, link: evil },
    drill_commitments: { reference: evil, date: '2026-01-05', kind: 'order', party: evil, site: evil, category: 'fuel', status: 'issued', amount: 10, link: evil },
    drill_cost_lines: { date: '2026-01-05', equipment: evil, site: evil, category: 'fuel', label: evil, kind: 'fuel_refill', reference: evil, quantity: 2, unit: evil, amount: null, link: evil },
    drill_stock_values: { reference: evil, label: evil, site: evil, category: 'fuel', quantity: 2, unit: evil, avg_cost: null, value: 0, link: evil }
  };
  for (const [name, def] of Object.entries(defs)) {
    for (const col of def.columns) {
      const html = Tables.cellHtml(col, rows[name]);
      assert.ok(!html.includes('<script>'), `${name}.${col.data} n'échappe pas : ${html}`);
    }
  }
});

test('textes d’export lisibles (libellés traduits, dates françaises)', () => {
  const wo = defs.work_orders.columns;
  const row = { reference: 'OT-2026-00001', title: 'Fuite', equipment_code: 'CH-01', equipment_name: 'Chariot', status: 'waiting_part', priority: 'critical', type: 'preventive', reported_at: '2026-09-24 08:00:00', due_date: null };
  const text = Object.fromEntries(wo.map((c) => [c.data, Tables.cellText(c, row)]));
  assert.equal(text.status, 'Attente pièce');
  assert.equal(text.priority, 'Critique');
  assert.equal(text.type, 'Préventif');
  assert.equal(text.equipment, 'CH-01 – Chariot');
  assert.equal(text.reported_at, '24/09/2026');
  assert.equal(text.due_date, '');
  assert.equal(text.assignee, '');
});

test('journal : libellés de champs et valeurs traduits selon le type d’élément', () => {
  const wo = { entity_type: 'work_order', before_data: '{"status":"open","priority":"normal"}', after_data: '{"status":"in_progress","priority":"high","foo":1}' };
  assert.deepEqual(Tables.activityDiff(wo, t), [
    { label: 'Statut', before: fr['wo_status.open'], after: fr['wo_status.in_progress'] },
    { label: 'Priorité', before: fr['priority.normal'], after: fr['priority.high'] },
    { label: 'foo', before: '∅', after: '1' }
  ]);
  const eq = { entity_type: 'equipment', before_data: '{"status":"available"}', after_data: '{"status":"breakdown"}' };
  assert.equal(Tables.activityDiff(eq, t)[0].after, fr['status.breakdown']);
  // Création : un seul côté, valeurs vides ignorées
  assert.deepEqual(Tables.activityDiff({ entity_type: 'part', before_data: null, after_data: '{"quantity":4,"comment":null}' }, t), [
    { label: 'Quantité', value: '4' }
  ]);
  assert.deepEqual(Tables.activityDiff({ entity_type: 'user', before_data: null, after_data: null }, t), []);
});

test('journal : auteur et famille d’action', () => {
  assert.equal(Tables.actorName({ user_id: 3, user_name: 'Marc R.' }, t), 'Marc R.');
  assert.equal(Tables.actorName({ user_id: null, user_name: 'system' }, t), fr['js.log_system']);
  assert.equal(Tables.actorName({ user_id: null, user_name: null }, t), fr['js.log_anonymous']);
  assert.equal(Tables.actionTone('login_failed'), 'alert');
  assert.equal(Tables.actionTone('create'), 'add');
  assert.equal(Tables.actionTone('transition'), 'change');
});

test('diff du journal', () => {
  assert.deepEqual(Tables.diffEntries('{"status":"available"}', '{"status":"breakdown","reason":"Fuite"}'), [
    { key: 'status', before: 'available', after: 'breakdown' },
    { key: 'reason', before: '∅', after: 'Fuite' }
  ]);
  assert.deepEqual(Tables.diffEntries(null, '{"a":1}'), []);
  assert.deepEqual(Tables.diffEntries('not json', '{"a":1}'), []);
});
