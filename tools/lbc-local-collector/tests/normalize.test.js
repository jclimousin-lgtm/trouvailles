'use strict';

// TRV-003-A — tests unitaires de lib/normalize.js. Node natif uniquement
// (node:test, node:assert) — aucune dépendance ajoutée.
// Usage : node --test tools/lbc-local-collector/tests/normalize.test.js

const test = require('node:test');
const assert = require('node:assert/strict');
const { normalizeListing, dedupeListings } = require('../lib/normalize.js');

test('normalisation d\'une annonce complète', () => {
  const raw = {
    list_id: 2891234567,
    url: 'https://www.leboncoin.fr/velos/2891234567.htm',
    subject: 'VTT Decathlon Rockrider 540',
    body: 'Très bon état, peu servi.',
    price_cents: 25000,
    brand: 'Decathlon',
    category_name: 'Vélos',
    status: 'used_good',
    location: { city: 'Lyon', zipcode: '69003' },
    owner: { type: 'private' },
    first_publication_date: '2026-08-20 10:15:00',
  };

  const result = normalizeListing(raw);

  assert.equal(result.source, 'leboncoin');
  assert.equal(result.externalId, '2891234567');
  assert.equal(result.url, raw.url);
  assert.equal(result.title, 'VTT Decathlon Rockrider 540');
  assert.equal(result.description, 'Très bon état, peu servi.');
  assert.equal(result.brand, 'Decathlon');
  assert.equal(result.category, 'Vélos');
  assert.equal(result.condition, 'used_good');
  assert.equal(result.askingPrice, 250);
  assert.equal(result.askingCurrency, 'EUR');
  assert.equal(result.shippingPrice, null);
  assert.equal(result.location, 'Lyon (69003)');
  assert.equal(result.sellerType, 'private');
  assert.equal(result.publishedAt, '2026-08-20 10:15:00');
  assert.equal(result.priceMechanism, 'fixed');
});

test('champs absents -> null, jamais inventés', () => {
  const raw = {
    list_id: 42,
    url: 'https://www.leboncoin.fr/x/42.htm',
    subject: 'Titre seul',
  };

  const result = normalizeListing(raw);

  assert.equal(result.title, 'Titre seul');
  assert.equal(result.description, null);
  assert.equal(result.brand, null);
  assert.equal(result.category, null);
  assert.equal(result.condition, null);
  assert.equal(result.askingPrice, null);
  assert.equal(result.askingCurrency, null, 'sans prix, aucune devise ne doit être inventée');
  assert.equal(result.location, null);
  assert.equal(result.sellerType, null);
  assert.equal(result.publishedAt, null);
});

test('prix/devise : price_cents converti, devise EUR uniquement si un prix existe', () => {
  const avecPrix = normalizeListing({ list_id: 1, url: 'https://x/1', price_cents: 129900 });
  assert.equal(avecPrix.askingPrice, 1299);
  assert.equal(avecPrix.askingCurrency, 'EUR');

  const sansPrix = normalizeListing({ list_id: 2, url: 'https://x/2' });
  assert.equal(sansPrix.askingPrice, null);
  assert.equal(sansPrix.askingCurrency, null);

  const prixInvalide = normalizeListing({ list_id: 3, url: 'https://x/3', price_cents: 'gratuit' });
  assert.equal(prixInvalide.askingPrice, null, 'une valeur de prix non numérique ne doit jamais être inventée/convertie au hasard');
});

test('URL absente -> annonce ignorée (retourne null)', () => {
  const result = normalizeListing({ list_id: 1, subject: 'Sans URL' });
  assert.equal(result, null);
});

test('identifiant (list_id) absent -> annonce ignorée (retourne null)', () => {
  const result = normalizeListing({ url: 'https://www.leboncoin.fr/x/1.htm', subject: 'Sans identifiant' });
  assert.equal(result, null);
});

test('entrée non-objet ou vide -> null, sans erreur', () => {
  assert.equal(normalizeListing(null), null);
  assert.equal(normalizeListing(undefined), null);
  assert.equal(normalizeListing('chaine'), null);
});

test('déduplication par (source, externalId)', () => {
  const a = normalizeListing({ list_id: 100, url: 'https://x/100-a', subject: 'Carte 1' });
  const b = normalizeListing({ list_id: 100, url: 'https://x/100-b', subject: 'Carte 1 (dupliquée)' });
  const c = normalizeListing({ list_id: 200, url: 'https://x/200', subject: 'Autre annonce' });

  const result = dedupeListings([a, b, c]);

  assert.equal(result.length, 2, 'le doublon (même source+externalId) doit être éliminé, la première occurrence conservée');
  assert.equal(result[0].title, 'Carte 1');
  assert.equal(result[1].title, 'Autre annonce');
});

test('déduplication : les entrées null (annonces déjà ignorées) sont filtrées sans planter', () => {
  const valide = normalizeListing({ list_id: 1, url: 'https://x/1', subject: 'OK' });
  const invalide = normalizeListing({ subject: 'Sans identité' }); // -> null

  const result = dedupeListings([valide, invalide, null]);

  assert.equal(result.length, 1);
  assert.equal(result[0].title, 'OK');
});
