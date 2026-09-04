# Collecteur local LBC — POC TRV-003-A

Extension Chrome/Chromium Manifest V3, à charger localement, **non
publiée, non distribuée**. Fonctionne uniquement dans l'onglet réel de
l'utilisateur, avec sa session normale — aucun serveur, aucun proxy,
aucune requête réseau propre à l'extension : elle lit uniquement ce que
le navigateur a déjà chargé pour afficher la page.

## Installation (chargement non empaqueté)

1. `chrome://extensions` (ou `edge://extensions`)
2. Activer le « Mode développeur »
3. « Charger l'extension non empaquetée » → sélectionner ce dossier
   (`tools/lbc-local-collector/`)

## Utilisation

1. Ouvrir normalement une recherche sur `https://www.leboncoin.fr/recherche?...`
2. Un bouton vert « Trouvailles — Collecter (POC TRV-003-A) » apparaît en
   bas à droite de la page
3. Cliquer dessus : un fichier `trouvailles-lbc-poc-<timestamp>.json` est
   téléchargé localement (dossier de téléchargement habituel du navigateur)
4. Ouvrir la console développeur (F12) pour voir le détail du résultat
   (`[trouvailles-collector] Résultat de la collecte : ...`), y compris la
   stratégie d'extraction utilisée (`next_data` ou `dom_fallback`)

## Structure

```
manifest.json     déclaration MV3, aucune permission au-delà de "matches"
content.js        détection de page, extraction, dédup, export JSON, bouton
lib/normalize.js  logique pure de normalisation (partagée navigateur/tests)
tests/            tests unitaires Node natif (node:test), zéro dépendance
```

## Tests

```
node --test tools/lbc-local-collector/tests/normalize.test.js
```

## Ce que cette extension NE fait PAS

Aucune requête réseau propre, aucun contournement de protection
(Cloudflare/DataDome/CAPTCHA), aucune lecture/exfiltration de cookies,
aucun proxy, aucune rotation d'IP, aucun raccordement au backend
Trouvailles (`ListingPersister`, base de données) — export JSON local
uniquement. Voir `docs/TRV-003-A-poc-lbc-collector.md` pour le rapport
complet de la mission.
