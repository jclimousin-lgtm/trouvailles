/*
 * Collecteur local Vinted — normalisation vers le contrat NormalizedListing
 * RÉEL du projet (app/Sources/NormalizedListing.php), cohérent avec le
 * mapping déjà utilisé par VintedAdapter::mapItem() (PHP, TRV-002) pour les
 * champs qu'il connaît — id, url, price, brand, condition — mais adapté à
 * une source DOM (grille de résultats vinted.fr/catalog) plutôt qu'à la
 * réponse JSON de l'API catalogue (bloquée pour un client automatisé,
 * voir docs/TRV-005-poc-vinted-collector.md).
 *
 * Structure DOM réelle vérifiée (pas supposée) sur une page de résultats
 * chargée normalement : chaque carte expose un data-testid
 * "product-item-id-<id>" avec, en descendants, un lien
 * "...--overlay-link" (href + attribut title concaténé
 * "<Titre>, Marque: <Marque>, État: <État>, <Prix> €, <Prix avec frais> €"),
 * et des nœuds texte isolés "...--description-title" (marque),
 * "...--description-subtitle" (état), "...--price-text" (prix demandé).
 *
 * Aucune valeur absente n'est inventée : un champ non trouvé devient
 * `null`, jamais une valeur par défaut arbitraire. Une annonce sans
 * identifiant (`id`) ou sans URL est ignorée (retourne `null`), comme
 * LeboncoinAdapter::mapAd()/VintedAdapter::mapItem().
 *
 * Format UMD volontairement minimal (aucune dépendance), même convention
 * que tools/lbc-local-collector/lib/normalize.js.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.TrouvaillesVintedNormalize = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var PRICE_MECHANISM_FIXED = 'fixed';

  /**
   * Parse un texte de prix Vinted tel qu'affiché ("60,00 €", avec espace
   * normal ou insécable) en { amount, currency }. Devise déduite du
   * symbole réellement présent dans le texte (pas une hypothèse générale
   * de marketplace) — absence de symbole reconnu -> currency null.
   * @param {string|null|undefined} priceText
   * @returns {{amount: number|null, currency: string|null}}
   */
  function parsePrice(priceText) {
    if (typeof priceText !== 'string' || priceText.trim() === '') {
      return { amount: null, currency: null };
    }

    var hasEuro = priceText.indexOf('€') !== -1; // €
    var numeric = priceText
      .replace(/ /g, ' ') // espace insécable -> espace normal
      .replace(/[^\d,.\-]/g, '') // retire tout sauf chiffres/virgule/point/signe
      .trim()
      .replace(',', '.');

    var amount = numeric === '' ? NaN : parseFloat(numeric);
    if (isNaN(amount)) {
      return { amount: null, currency: null };
    }

    return { amount: amount, currency: hasEuro ? 'EUR' : null };
  }

  /**
   * @param {object} raw Objet brut extrait du DOM (forme :
   *   { id, url, title, brand, condition, price_text })
   * @returns {object|null} NormalizedListing-compatible, ou null si
   *   l'identité minimale (id + url) est absente.
   */
  function normalizeListing(raw) {
    if (!raw || typeof raw !== 'object') {
      return null;
    }

    var externalId = raw.id !== undefined && raw.id !== null && raw.id !== ''
      ? String(raw.id)
      : null;
    var url = typeof raw.url === 'string' && raw.url !== '' ? raw.url : null;

    if (externalId === null || url === null) {
      return null;
    }

    var price = parsePrice(raw.price_text);

    return {
      source: 'vinted',
      externalId: externalId,
      url: url,
      title: typeof raw.title === 'string' && raw.title !== '' ? raw.title : null,
      description: null, // non visible sur la grille de résultats, jamais inventé
      brand: typeof raw.brand === 'string' && raw.brand !== '' ? raw.brand : null,
      category: null, // non visible sur la grille de résultats, jamais inventé
      condition: typeof raw.condition === 'string' && raw.condition !== '' ? raw.condition : null,
      askingPrice: price.amount,
      askingCurrency: price.currency,
      shippingPrice: null, // non distingué du prix affiché sur la grille, jamais inventé
      location: null, // non visible sur la grille de résultats, jamais inventé
      sellerType: null, // non visible sur la grille de résultats, jamais inventé
      publishedAt: null, // non visible sur la grille de résultats, jamais inventé
      priceMechanism: PRICE_MECHANISM_FIXED, // Vinted ne connaît pas l'enchère (cohérent avec VintedAdapter)
    };
  }

  /**
   * Déduplication minimale par (source, externalId) — même algorithme que
   * tools/lbc-local-collector/lib/normalize.js.
   * @param {Array<object|null>} listings
   * @returns {Array<object>}
   */
  function dedupeListings(listings) {
    var seen = {};
    var result = [];
    for (var i = 0; i < listings.length; i++) {
      var listing = listings[i];
      if (!listing) {
        continue;
      }
      var key = listing.source + '::' + listing.externalId;
      if (seen[key]) {
        continue;
      }
      seen[key] = true;
      result.push(listing);
    }
    return result;
  }

  return {
    normalizeListing: normalizeListing,
    dedupeListings: dedupeListings,
    parsePrice: parsePrice,
    PRICE_MECHANISM_FIXED: PRICE_MECHANISM_FIXED,
  };
});
