/*
 * TRV-003-A — content script du POC. Injecté uniquement sur les pages de
 * résultats de recherche Leboncoin (voir manifest.json, "matches"), dans
 * l'onglet réel de l'utilisateur, avec sa session normale.
 *
 * Ce script NE FAIT AUCUNE REQUÊTE RÉSEAU : il lit uniquement ce que le
 * navigateur a déjà chargé pour afficher la page à l'utilisateur (le DOM
 * rendu, et le script __NEXT_DATA__ si présent — un bloc JSON standard
 * des applications Next.js, intégré à la page HTML elle-même, pas un
 * appel d'API séparé). Aucun cookie n'est lu, transmis ni exfiltré.
 *
 * Deux stratégies d'extraction, dans cet ordre :
 *   1. __NEXT_DATA__ (si présent) — recherche structurelle d'un tableau
 *      d'objets ressemblant à des annonces (clés `list_id` + `url`), sans
 *      supposer un chemin JSON fixe (non vérifiable sans accès navigateur
 *      au moment de l'écriture, voir docs/TRV-003-A-poc-lbc-collector.md).
 *   2. Repli DOM — recherche de liens dont l'URL contient un identifiant
 *      numérique, texte visible comme titre. Volontairement minimal et
 *      non garanti : les sélecteurs visuels précis de la page n'ont pas pu
 *      être vérifiés en direct (voir limites dans la documentation).
 *
 * Aucune valeur n'est inventée : un champ non trouvé reste absent, géré
 * ensuite par normalizeListing() (lib/normalize.js) exactement comme les
 * adapters PHP existants (jamais de donnée fictive pour "faire passer" le test).
 */
(function () {
  'use strict';

  var normalizeListing = self.TrouvaillesNormalize.normalizeListing;
  var dedupeListings = self.TrouvaillesNormalize.dedupeListings;

  function isSearchPage() {
    return window.location.pathname.indexOf('/recherche') === 0;
  }

  /**
   * @returns {Array<object>|null} tableau d'objets bruts forme Leboncoin
   *   (list_id, url, ...), ou null si rien d'exploitable trouvé.
   */
  function extractFromNextData() {
    var script = document.getElementById('__NEXT_DATA__');
    if (!script) {
      return null;
    }

    var data;
    try {
      data = JSON.parse(script.textContent);
    } catch (e) {
      console.warn('[trouvailles-collector] __NEXT_DATA__ présent mais JSON invalide :', e.message);
      return null;
    }

    var candidates = [];
    var seen = new Set();

    function looksLikeAdArray(node) {
      return Array.isArray(node) && node.length > 0 && node.every(function (item) {
        return item && typeof item === 'object' && 'list_id' in item && 'url' in item;
      });
    }

    function walk(node, depthLeft) {
      if (depthLeft < 0 || node === null || typeof node !== 'object' || seen.has(node)) {
        return;
      }
      seen.add(node);

      if (Array.isArray(node)) {
        if (looksLikeAdArray(node)) {
          candidates.push(node);
          return;
        }
        for (var i = 0; i < node.length; i++) {
          walk(node[i], depthLeft - 1);
        }
        return;
      }

      var keys = Object.keys(node);
      for (var k = 0; k < keys.length; k++) {
        walk(node[keys[k]], depthLeft - 1);
      }
    }

    walk(data, 12);

    if (candidates.length === 0) {
      return null;
    }

    // Heuristique simple : la liste de résultats de recherche est presque
    // toujours la plus grande collection d'objets "annonce" de la page.
    candidates.sort(function (a, b) { return b.length - a.length; });
    return candidates[0];
  }

  /**
   * Repli DOM — voir avertissement en tête de fichier. Aucune hypothèse
   * sur des classes CSS précises (trop fragiles/non vérifiées) : repose
   * uniquement sur la présence d'un identifiant numérique dans l'URL du
   * lien, motif générique observé sur les fiches Leboncoin.
   */
  function extractFromDom() {
    var anchors = Array.prototype.slice.call(document.querySelectorAll('a[href]'));
    var idPattern = /(\d{6,})(?:\.htm)?(?:[/?#]|$)/;
    var seenIds = {};
    var raw = [];

    for (var i = 0; i < anchors.length; i++) {
      var href = anchors[i].getAttribute('href') || '';
      var match = href.match(idPattern);
      if (!match) {
        continue;
      }
      var listId = match[1];
      if (seenIds[listId]) {
        continue;
      }

      var title = (anchors[i].textContent || '').trim();
      if (title === '') {
        continue; // pas de texte visible -> pas de titre à inventer, annonce ignorée
      }

      seenIds[listId] = true;
      var absoluteUrl;
      try {
        absoluteUrl = new URL(href, window.location.origin).toString();
      } catch (e) {
        continue;
      }

      raw.push({ list_id: listId, url: absoluteUrl, subject: title });
    }

    return raw;
  }

  function collect() {
    if (!isSearchPage()) {
      console.warn('[trouvailles-collector] URL non reconnue comme une recherche Leboncoin :', window.location.pathname);
      return null;
    }

    var rawAds = extractFromNextData();
    var strategy = 'next_data';
    if (!rawAds || rawAds.length === 0) {
      rawAds = extractFromDom();
      strategy = 'dom_fallback';
    }

    var detectedCount = rawAds.length;
    var normalized = dedupeListings(rawAds.map(normalizeListing));

    var result = {
      source: 'leboncoin',
      collected_at: new Date().toISOString(),
      search_url: window.location.href,
      extraction_strategy: strategy,
      count: detectedCount,
      normalized_count: normalized.length,
      listings: normalized,
    };

    console.info('[trouvailles-collector] Résultat de la collecte :', result);
    return result;
  }

  function downloadJson(data) {
    var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'trouvailles-lbc-poc-' + Date.now() + '.json';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  function injectButton() {
    if (!isSearchPage() || document.getElementById('trouvailles-collector-button')) {
      return;
    }

    var button = document.createElement('button');
    button.id = 'trouvailles-collector-button';
    button.textContent = 'Trouvailles — Collecter (POC TRV-003-A)';
    button.style.cssText = [
      'position:fixed', 'bottom:16px', 'right:16px', 'z-index:2147483647',
      'padding:10px 16px', 'background:#176B52', 'color:#fff', 'border:0',
      'border-radius:8px', 'font:600 13px system-ui,sans-serif', 'cursor:pointer',
      'box-shadow:0 4px 14px rgba(0,0,0,.25)',
    ].join(';');

    button.addEventListener('click', function () {
      var result = collect();
      var original = button.textContent;
      if (result && result.normalized_count > 0) {
        downloadJson(result);
        button.textContent = 'Collecté : ' + result.normalized_count + ' annonce(s) (' + result.extraction_strategy + ')';
      } else if (result) {
        button.textContent = 'Aucune annonce détectée (' + result.extraction_strategy + ')';
      } else {
        button.textContent = 'Page non reconnue comme recherche';
      }
      setTimeout(function () { button.textContent = original; }, 5000);
    });

    document.body.appendChild(button);
  }

  injectButton();
})();
