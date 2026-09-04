/*
 * TRV-003-A — normalisation vers le contrat NormalizedListing RÉEL du
 * projet (app/Sources/NormalizedListing.php), pas un second modèle
 * concurrent. Même mapping de champs que LeboncoinAdapter::mapItem()
 * (PHP, TRV-002) — list_id, subject, body, price_cents, brand,
 * category_name/category_id, status, location{city,zipcode}, owner.type,
 * first_publication_date — porté ici pour un objet brut de même forme.
 *
 * Aucune valeur absente n'est inventée : un champ non présent dans
 * l'objet brut devient `null`, jamais une valeur par défaut arbitraire.
 * Une annonce sans identifiant (`list_id`) ou sans URL est ignorée
 * (retourne `null`), exactement comme LeboncoinAdapter::mapItem().
 *
 * Format UMD volontairement minimal (aucune dépendance) : utilisable tel
 * quel comme content script (variable globale `self.TrouvaillesNormalize`)
 * et testable en Node (`require()`) sans framework de test supplémentaire.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.TrouvaillesNormalize = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var PRICE_MECHANISM_FIXED = 'fixed';

  /**
   * @param {object} raw Objet brut (forme native Leboncoin : list_id, url,
   *   subject, body, price_cents, brand, category_name|category_id,
   *   status, location{city,zipcode}, owner{type}, first_publication_date)
   * @returns {object|null} NormalizedListing-compatible, ou null si
   *   l'identité minimale (list_id + url) est absente.
   */
  function normalizeListing(raw) {
    if (!raw || typeof raw !== 'object') {
      return null;
    }

    var externalId = raw.list_id !== undefined && raw.list_id !== null && raw.list_id !== ''
      ? String(raw.list_id)
      : null;
    var url = typeof raw.url === 'string' && raw.url !== '' ? raw.url : null;

    if (externalId === null || url === null) {
      return null;
    }

    var askingPrice = typeof raw.price_cents === 'number' && !isNaN(raw.price_cents)
      ? raw.price_cents / 100
      : null;

    var location = null;
    if (raw.location && typeof raw.location === 'object') {
      var parts = [];
      if (typeof raw.location.city === 'string' && raw.location.city !== '') {
        parts.push(raw.location.city);
      }
      if (raw.location.zipcode !== undefined && raw.location.zipcode !== null && raw.location.zipcode !== '') {
        parts.push('(' + raw.location.zipcode + ')');
      }
      location = parts.length > 0 ? parts.join(' ') : null;
    }

    var sellerType = raw.owner && typeof raw.owner === 'object' && typeof raw.owner.type === 'string'
      ? raw.owner.type
      : null;

    var category = null;
    if (typeof raw.category_name === 'string' && raw.category_name !== '') {
      category = raw.category_name;
    } else if (raw.category_id !== undefined && raw.category_id !== null && raw.category_id !== '') {
      category = String(raw.category_id);
    }

    return {
      source: 'leboncoin',
      externalId: externalId,
      url: url,
      title: typeof raw.subject === 'string' && raw.subject !== '' ? raw.subject : null,
      description: typeof raw.body === 'string' && raw.body !== '' ? raw.body : null,
      brand: typeof raw.brand === 'string' && raw.brand !== '' ? raw.brand : null,
      category: category,
      condition: typeof raw.status === 'string' && raw.status !== '' ? raw.status : null,
      askingPrice: askingPrice,
      askingCurrency: askingPrice !== null ? 'EUR' : null,
      shippingPrice: null,
      location: location,
      sellerType: sellerType,
      publishedAt: typeof raw.first_publication_date === 'string' && raw.first_publication_date !== ''
        ? raw.first_publication_date
        : null,
      priceMechanism: PRICE_MECHANISM_FIXED,
    };
  }

  /**
   * Déduplication minimale par (source, externalId) — Étape 6, aucune
   * logique plus complexe à ce stade.
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
    PRICE_MECHANISM_FIXED: PRICE_MECHANISM_FIXED,
  };
});
