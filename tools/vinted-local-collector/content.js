/*
 * Collecteur local Vinted — content script du POC. Injecté uniquement sur
 * les pages de résultats Vinted (voir manifest.json, "matches"), dans
 * l'onglet réel de l'utilisateur, avec sa session normale.
 *
 * Ce script NE FAIT AUCUNE REQUÊTE RÉSEAU : il lit uniquement le DOM déjà
 * rendu par le navigateur pour afficher la page à l'utilisateur. Aucun
 * cookie n'est lu, transmis ni exfiltré.
 *
 * Structure DOM réelle vérifiée (navigateur réel, page de résultats
 * chargée normalement, aucun blocage constaté à ce niveau — voir
 * docs/TRV-005-poc-vinted-collector.md) : chaque carte de résultat a un
 * data-testid racine "product-item-id-<id>" (à distinguer explicitement
 * des cartes "closet-item-*"/"item-*" présentes ailleurs sur la même page,
 * qui appartiennent à d'autres sections — carrousels "plus de cet
 * utilisateur" — et ne sont jamais collectées ici).
 *
 * Aucune stratégie JSON structurel n'est tentée en premier recours ici
 * (contrairement au collecteur LBC) : aucun __NEXT_DATA__/__NUXT__/JSON-LD
 * n'a été trouvé sur cette page au moment de l'écriture — extraction 100%
 * DOM, volontairement minimale, aucun champ non visible n'est inventé.
 */
(function () {
  'use strict';

  var normalizeListing = self.TrouvaillesVintedNormalize.normalizeListing;
  var dedupeListings = self.TrouvaillesVintedNormalize.dedupeListings;

  var ROOT_TESTID_PATTERN = /^product-item-id-(\d+)$/;

  function isSearchPage() {
    return window.location.pathname.indexOf('/catalog') === 0;
  }

  /**
   * Extrait le titre complet à partir de la chaîne concaténée observée
   * dans l'attribut title/alt ("<Titre>, Marque: X, État: Y, P1 €, P2 €").
   * Coupe avant la DERNIÈRE occurrence de ", Marque:" si présente (un
   * titre peut lui-même contenir des virgules), sinon avant ", État:" si
   * présente, sinon retourne null plutôt que de deviner.
   * @param {string|null} raw
   * @returns {string|null}
   */
  function extractTitleFromConcatenated(raw) {
    if (typeof raw !== 'string' || raw === '') {
      return null;
    }

    var marqueIdx = raw.lastIndexOf(', Marque:');
    if (marqueIdx !== -1) {
      return raw.slice(0, marqueIdx).trim() || null;
    }

    var etatIdx = raw.lastIndexOf(', État:');
    if (etatIdx !== -1) {
      return raw.slice(0, etatIdx).trim() || null;
    }

    return null;
  }

  function textOf(el) {
    if (!el) {
      return null;
    }
    var text = (el.textContent || '').trim();
    return text === '' ? null : text;
  }

  /**
   * @returns {Array<object>} tableau d'objets bruts forme
   *   { id, url, title, brand, condition, price_text }
   */
  function extractFromDom() {
    var roots = Array.prototype.slice.call(document.querySelectorAll('[data-testid]'));
    var raw = [];
    var seenIds = {};

    for (var i = 0; i < roots.length; i++) {
      var testid = roots[i].getAttribute('data-testid') || '';
      var match = testid.match(ROOT_TESTID_PATTERN);
      if (!match) {
        continue;
      }

      var id = match[1];
      if (seenIds[id]) {
        continue; // même carte capturée deux fois (defensif, non attendu)
      }
      seenIds[id] = true;

      var card = roots[i];
      var link = card.querySelector('[data-testid$="--overlay-link"]');
      var href = link ? link.getAttribute('href') : null;

      var absoluteUrl = null;
      if (href) {
        try {
          absoluteUrl = new URL(href, window.location.origin).toString();
        } catch (e) {
          absoluteUrl = null;
        }
      }

      var concatenated = link ? link.getAttribute('title') : null;
      var titleEl = card.querySelector('[data-testid$="--description-title"]');
      var subtitleEl = card.querySelector('[data-testid$="--description-subtitle"]');
      var priceEl = card.querySelector('[data-testid$="--price-text"]');

      raw.push({
        id: id,
        url: absoluteUrl,
        title: extractTitleFromConcatenated(concatenated),
        brand: textOf(titleEl),
        condition: textOf(subtitleEl),
        price_text: textOf(priceEl),
      });
    }

    return raw;
  }

  function collect() {
    if (!isSearchPage()) {
      console.warn('[trouvailles-vinted-collector] URL non reconnue comme une recherche Vinted :', window.location.pathname);
      return null;
    }

    var rawItems = extractFromDom();
    var detectedCount = rawItems.length;
    var normalized = dedupeListings(rawItems.map(normalizeListing));

    var result = {
      source: 'vinted',
      collected_at: new Date().toISOString(),
      search_url: window.location.href,
      extraction_strategy: 'dom_only',
      count: detectedCount,
      normalized_count: normalized.length,
      listings: normalized,
    };

    console.info('[trouvailles-vinted-collector] Résultat de la collecte :', result);
    return result;
  }

  function downloadJson(data) {
    var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'trouvailles-vinted-poc-' + Date.now() + '.json';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  function injectButton() {
    if (!isSearchPage() || document.getElementById('trouvailles-vinted-collector-button')) {
      return;
    }

    var button = document.createElement('button');
    button.id = 'trouvailles-vinted-collector-button';
    button.textContent = 'Trouvailles — Collecter Vinted (POC)';
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
        button.textContent = 'Collecté : ' + result.normalized_count + ' annonce(s)';
      } else if (result) {
        button.textContent = 'Aucune annonce détectée';
      } else {
        button.textContent = 'Page non reconnue comme recherche';
      }
      setTimeout(function () { button.textContent = original; }, 5000);
    });

    document.body.appendChild(button);
  }

  injectButton();
})();
