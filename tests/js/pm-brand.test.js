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
