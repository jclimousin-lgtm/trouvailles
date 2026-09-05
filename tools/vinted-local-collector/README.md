# Collecteur local Vinted — POC

Extension Chrome/Chromium Manifest V3, à charger localement, **non
publiée, non distribuée**. Fonctionne uniquement dans l'onglet réel de
l'utilisateur, avec sa session normale — aucun serveur, aucun proxy,
aucune requête réseau propre à l'extension : elle lit uniquement ce que
le navigateur a déjà chargé pour afficher la page.

Contrairement au collecteur Leboncoin (`tools/lbc-local-collector/`),
Vinted **ne bloque pas** le chargement normal d'une page de résultats
(vérifié avec un vrai navigateur, profil neuf, sans contournement —
voir `docs/TRV-005-poc-vinted-collector.md`) : seul l'appel direct à l'API
catalogue (`VintedClient`/`VintedBrowserSessionTransport`, côté PHP) est
protégé. L'extraction ici est donc purement DOM (aucun blocage rencontré
à ce niveau, aucun `__NEXT_DATA__`/`__NUXT__`/JSON-LD trouvé sur la page).

## Installation (chargement non empaqueté)

1. `chrome://extensions` (ou `edge://extensions`)
2. Activer le « Mode développeur »
3. « Charger l'extension non empaquetée » → sélectionner ce dossier
   (`tools/vinted-local-collector/`)

## Utilisation

1. Ouvrir normalement une recherche sur `https://www.vinted.fr/catalog?...`
2. Un bouton vert « Trouvailles — Collecter Vinted (POC) » apparaît en
   bas à droite de la page
3. Cliquer dessus : un fichier `trouvailles-vinted-poc-<timestamp>.json`
   est téléchargé localement (dossier de téléchargement habituel du
   navigateur)
4. Ouvrir la console développeur (F12) pour voir le détail du résultat
   (`[trouvailles-vinted-collector] Résultat de la collecte : ...`)

## Structure

```
manifest.json     déclaration MV3, aucune permission au-delà de "matches"
content.js        détection de page + extraction DOM + dédup + export + bouton
lib/normalize.js  logique pure de normalisation (partagée navigateur/tests)
tests/            tests unitaires Node natif (node:test), zéro dépendance
```

## Tests

```
node --test tools/vinted-local-collector/tests/normalize.test.js
```

L'extraction DOM (`content.js`) a en outre été vérifiée directement contre
une vraie page Vinted capturée (navigateur réel, recherche « canon eos ») :
96 cartes détectées, 96 normalisées, 0 rejetées — voir
`docs/TRV-005-poc-vinted-collector.md` pour le détail. Le bouton/export en
conditions de clic réel dans une extension chargée reste à valider par un
humain (même limite que pour le collecteur LBC).

## Ce que cette extension NE fait PAS

Aucune requête réseau propre, aucun contournement de protection
(Cloudflare/DataDome/CAPTCHA), aucune lecture/exfiltration de cookies,
aucun proxy, aucune rotation d'IP, aucun raccordement au backend
Trouvailles (`ListingPersister`, base de données) — export JSON local
uniquement. Voir `docs/TRV-005-poc-vinted-collector.md` pour le rapport complet.
