'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const Doc = require('../../public/assets/js/pm-doc.js');

const payload = {
  reference: 'OTV-2026-00007',
  company: 'VRSC SARL',
  footer: 'pied',
  pages: [
    {
      title: 'Ordre de transfert OTV-2026-00007',
      subtitle: 'Kolwezi → Likasi',
      sections: [{ title: 'Équipement', rows: [['Numéro de parc', 'CAM-01'], ['Plaque', null]] }],
      signatures: ['Demandeur', 'Direction Logistique']
    },
    {
      title: 'Check-list technique OTV-2026-00007',
      subtitle: 'CAM-01',
      tables: [{ title: 'Points', head: ['Point', 'Constat', 'Réserve'], body: [['Freinage', 'Conforme', ''], ['Direction', 'Réserve', 'Jeu']] }],
      notes: ['Écarts : aucun'],
      signatures: ['Contrôleur']
    }
  ]
};

test('OTV et check-list : un seul document, le même numéro sur chaque page', () => {
  const def = Doc.definition(payload);
  const json = JSON.stringify(def);
  assert.equal(def.pageSize, 'A4');
  // Titre + cartouche de chaque page (2 × 2), plus le titre du PDF.
  assert.equal(json.split('OTV-2026-00007').length - 1, 5);
  const breaks = def.content.filter((node) => node.pageBreak === 'before');
  assert.equal(breaks.length, 1, 'La check-list commence sur une nouvelle page');
  assert.match(json, /Freinage/);
  assert.match(json, /Direction Logistique/);
});

test('valeur absente : tiret, jamais « null » ni « undefined »', () => {
  const json = JSON.stringify(Doc.definition(payload));
  assert.doesNotMatch(json, /"text":"null"|"text":"undefined"/);
  assert.match(json, /"text":"—"/);
  assert.doesNotThrow(() => Doc.definition({ reference: 'X', pages: [{}] }));
  assert.doesNotThrow(() => Doc.definition({}));
});

test('pied de page paginé avec la mention du document', () => {
  const footer = Doc.definition(payload).footer(2, 3);
  assert.equal(footer.columns[0].text, 'pied');
  assert.equal(footer.columns[1].text, '2 / 3');
});
