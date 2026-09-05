'use strict';

// Collecteur local Vinted — tests unitaires de lib/normalize.js. Node natif
// uniquement (node:test, node:assert) — aucune dépendance ajoutée.
// Usage : node --test tools/vinted-local-collector/tests/normalize.test.js

const test = require('node:test');
const assert = require('node:assert/strict');
const { normalizeListing, dedupeListings, parsePrice } = require('../lib/normalize.js');

test('normalisation d\'une annonce complète (forme brute issue du DOM)', () => {
  const raw = {
    id: '9891707666',
    url: 'https://www.vinted.fr/items/9891707666-canon-eos1000d?referrer=catalog',
    title: 'Canon EOS1000d',
    brand: 'Canon',
    condition: 'Bon état',
    price_text: '60,00 €',
  };

  const result = normalizeListing(raw);

  assert.equal(result.source, 'vinted');
  assert.equal(result.externalId, '9891707666');
  assert.equal(result.url, raw.url);
  assert.equal(result.title, 'Canon EOS1000d');
  assert.equal(result.description, null, 'jamais visible sur la grille de résultats, jamais inventé');
  assert.equal(result.brand, 'Canon');
  assert.equal(result.category, null, 'jamais visible sur la grille de résultats, jamais inventé');
  assert.equal(result.condition, 'Bon état');
  assert.equal(result.askingPrice, 60);
  assert.equal(result.askingCurrency, 'EUR');
  assert.equal(result.shippingPrice, null);
  assert.equal(result.location, null);
  assert.equal(result.sellerType, null);
  assert.equal(result.publishedAt, null);
  assert.equal(result.priceMechanism, 'fixed');
});

test('champs absents -> null, jamais inventés', () => {
  const raw = {
    id: '42',
    url: 'https://www.vinted.fr/items/42-sans-details',
    title: 'Titre seul',
  };

  const result = normalizeListing(raw);

  assert.equal(result.title, 'Titre seul');
  assert.equal(result.brand, null);
  assert.equal(result.condition, null);
  assert.equal(result.askingPrice, null);
  assert.equal(result.askingCurrency, null, 'sans texte de prix, aucune devise ne doit être inventée');
});

test('parsePrice : virgule décimale, espace insécable, symbole €', () => {
  assert.deepEqual(parsePrice('60,00 €'), { amount: 60, currency: 'EUR' });
  assert.deepEqual(parsePrice('1 299,90 €'), { amount: 1299.90, currency: 'EUR' });
});

test('parsePrice : texte vide/absent/sans montant exploitable -> null, jamais inventé', () => {
  assert.deepEqual(parsePrice(''), { amount: null, currency: null });
  assert.deepEqual(parsePrice(null), { amount: null, currency: null });
  assert.deepEqual(parsePrice(undefined), { amount: null, currency: null });
  assert.deepEqual(parsePrice('Prix sur demande'), { amount: null, currency: null });
});

test('parsePrice : montant sans symbole € reconnu -> devise null, jamais EUR par défaut', () => {
  const result = parsePrice('60,00 $');
  assert.equal(result.amount, 60);
  assert.equal(result.currency, null, 'aucun symbole € reconnu, aucune devise ne doit être inventée');
});

test('URL absente -> annonce ignorée (retourne null)', () => {
  const result = normalizeListing({ id: '1', title: 'Sans URL' });
  assert.equal(result, null);
});

test('identifiant (id) absent -> annonce ignorée (retourne null)', () => {
  const result = normalizeListing({ url: 'https://www.vinted.fr/items/1-x', title: 'Sans identifiant' });
  assert.equal(result, null);
});

test('entrée non-objet ou vide -> null, sans erreur', () => {
  assert.equal(normalizeListing(null), null);
  assert.equal(normalizeListing(undefined), null);
  assert.equal(normalizeListing('chaine'), null);
});

test('déduplication par (source, externalId)', () => {
  const a = normalizeListing({ id: '100', url: 'https://x/100-a', title: 'Carte 1' });
  const b = normalizeListing({ id: '100', url: 'https://x/100-b', title: 'Carte 1 (dupliquée)' });
  const c = normalizeListing({ id: '200', url: 'https://x/200', title: 'Autre annonce' });

  const result = dedupeListings([a, b, c]);

  assert.equal(result.length, 2, 'le doublon (même source+externalId) doit être éliminé, la première occurrence conservée');
  assert.equal(result[0].title, 'Carte 1');
  assert.equal(result[1].title, 'Autre annonce');
});

test('déduplication : les entrées null (annonces déjà ignorées) sont filtrées sans planter', () => {
  const valide = normalizeListing({ id: '1', url: 'https://x/1', title: 'OK' });
  const invalide = normalizeListing({ title: 'Sans identité' }); // -> null

  const result = dedupeListings([valide, invalide, null]);

  assert.equal(result.length, 1);
  assert.equal(result[0].title, 'OK');
});
