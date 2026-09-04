# TRV-003-A — POC Collector LBC local

## Étape 1 — Existant examiné

- **Contrat réel** : `app/Sources/NormalizedListing.php` — champs
  `source, externalId, url, title, description, brand, category,
  condition, askingPrice, askingCurrency, shippingPrice, location,
  sellerType, publishedAt, priceMechanism` (camelCase, propriétés
  `readonly`). C'est ce contrat exact qui est reproduit par le collecteur
  JS — **pas** la forme snake_case donnée à titre d'exemple illustratif
  dans le mandat de cette mission (`external_id`, `asking_price`,
  `currency`...), qui ne correspond pas au code réel.
- **Convention adapter existante** : `app/Sources/Leboncoin/LeboncoinAdapter.php`
  (`mapItem()`, TRV-002) — mapping `list_id→externalId, subject→title,
  body→description, price_cents/100→askingPrice, brand→brand,
  category_name|category_id→category, status→condition,
  location{city,zipcode}→location, owner.type→sellerType,
  first_publication_date→publishedAt`, devise toujours `'EUR'`
  (marketplace mono-devise), annonce ignorée si `list_id`/`url` absent.
  **`lib/normalize.js` reproduit exactement ce même mapping**, pour un
  objet brut de même forme — aucun second modèle concurrent créé.
- **Tests existants** : `tests/run_leboncoin_adapter.php` (PHP, TRV-002) —
  convention reprise pour les tests JS : champs absents → `null`,
  identité minimale obligatoire, aucune donnée inventée.
- **Emplacement retenu** : `tools/lbc-local-collector/`, isolé du code
  applicatif PHP (`app/`), cohérent avec `tools/migrate.php` déjà présent
  comme emplacement des outils hors pipeline de production.

Aucun fichier existant modifié — composant entièrement nouveau et isolé.

## Étape 2-7 — Composant livré

```
tools/lbc-local-collector/
├── manifest.json         MV3 minimal — un seul content_script, aucune permission
├── content.js            détection page + extraction + dédup + export + bouton
├── lib/normalize.js       logique pure (partagée navigateur/tests), UMD sans dépendance
├── tests/normalize.test.js  8 tests, node:test natif
└── README.md              installation et usage
```

**Détection** (`content.js:isSearchPage`) : URL dont le chemin commence par
`/recherche` (`content_scripts.matches` du manifest restreint déjà
l'injection à `https://www.leboncoin.fr/recherche*`).

**Extraction**, deux stratégies dans l'ordre :
1. **`__NEXT_DATA__`** (bloc JSON standard des applications Next.js,
   déjà présent dans le HTML délivré au navigateur — **pas un appel
   réseau séparé**) : recherche structurelle (jusqu'à 12 niveaux de
   profondeur) d'un tableau d'objets possédant `list_id` et `url`,
   sans supposer de chemin JSON fixe.
2. **Repli DOM** si le premier échoue : liens dont l'URL contient un
   identifiant numérique (≥ 6 chiffres), titre = texte visible du lien.
   Volontairement minimal, non garanti — voir Limites.

**Normalisation** : `lib/normalize.js` (Étape 1), dédup par
`source::externalId` (Étape 6, `dedupeListings`).

**Export** (Étape 7), déclenché par un bouton injecté en bas à droite de
la page :
```json
{
  "source": "leboncoin",
  "collected_at": "2026-...T...Z",
  "search_url": "https://www.leboncoin.fr/recherche?...",
  "extraction_strategy": "next_data",
  "count": 35,
  "normalized_count": 35,
  "listings": [ { "source": "leboncoin", "externalId": "...", ... } ]
}
```
Téléchargé via `Blob` + `<a download>` — aucune permission
`downloads` nécessaire, aucun serveur.

## Étape 9 — Tests unitaires

```
$ node --test tools/lbc-local-collector/tests/normalize.test.js
# tests 8
# pass 8
# fail 0
```

Couverture : annonce complète ; champs absents → `null` (jamais
inventés) ; prix/devise (conversion `price_cents`, devise absente sans
prix, valeur non numérique jamais convertie) ; URL absente → ignorée ;
identifiant absent → ignorée ; entrée non-objet → `null` sans erreur ;
déduplication (doublon éliminé, `null` filtrés sans planter).

## Étape 9 — Test réel : **NON EXÉCUTÉ PAR L'AGENT**

**Cause précise, signalée explicitement plutôt que devinée ou simulée** :
cette session n'a pas d'accès navigateur connecté (l'extension
claude-in-chrome a répondu « Browser extension is not connected » lors
de la tentative de connexion). Je n'ai donc **aucun moyen d'ouvrir un
onglet Chrome réel** pour effectuer moi-même le test prescrit par le
mandat (« l'utilisateur ouvre normalement une recherche LBC »).

Conformément au mandat (« Ne pas inventer de résultat si LBC bloque
effectivement le test »), **je ne fabrique aucun résultat de test réel.**
Le code est livré complet et testé unitairement ; sa validation en
conditions réelles reste entièrement à faire.

### Ce qu'il reste à faire (par l'utilisateur, ou une session avec accès navigateur)

1. Charger l'extension (`chrome://extensions`, mode développeur, charger
   non empaquetée, dossier `tools/lbc-local-collector/`).
2. Ouvrir normalement une recherche sur `www.leboncoin.fr/recherche?...`.
3. Vérifier l'apparition du bouton « Trouvailles — Collecter ».
4. Cliquer, ouvrir la console (F12), relever :
   - la stratégie utilisée (`next_data` ou `dom_fallback`) ;
   - `count` et `normalized_count` ;
   - le fichier JSON téléchargé.
5. Me communiquer ce résultat (JSON exporté, ou capture de la console)
   pour que le rapport puisse être complété honnêtement.

## Exemple

Aucune donnée réelle collectée à ce jour (test réel non exécuté). Pas
d'exemple à présenter — en présenter un fabriqué violerait explicitement
l'Étape 8 du mandat (« Un POC qui fonctionne uniquement sur des données
mockées est un échec du critère principal »). Les objets produits par les
tests unitaires (ex. `VTT Decathlon Rockrider 540`) sont des fixtures de
test, pas une collecte réelle — clairement distingués ici.

## Limites connues (dès maintenant, indépendamment du test réel)

- **Chemin JSON non vérifié** : la recherche structurelle dans
  `__NEXT_DATA__` suppose que les objets annonce y exposent au moins
  `list_id` et `url` — cohérent avec le modèle `Ad` déjà documenté dans
  `LeboncoinAdapter.php` (TRV-002), mais non re-vérifié contre la page
  réelle actuelle faute d'accès navigateur.
- **Repli DOM fragile par construction** : dépend uniquement d'un motif
  d'URL (identifiant numérique ≥ 6 chiffres) sans aucune hypothèse sur les
  classes CSS actuelles de la page — volontairement minimal pour éviter
  d'inventer des sélecteurs non vérifiés, mais donc probablement peu
  robuste si `__NEXT_DATA__` est absent ou ne contient pas les annonces.
- **Rendu dynamique** : si la page charge les annonces après coup
  (pagination infinie, chargement différé), seules les annonces déjà
  rendues au moment du clic sur le bouton seront collectées — aucun
  mécanisme d'attente/relance automatique n'a été ajouté (hors périmètre :
  « aucun système de surveillance automatique dans cette mission »).
- **Champs `brand`/`category`/`condition`/`location`/`sellerType`** :
  disponibilité réelle dans `__NEXT_DATA__` non confirmée — gérés
  défensivement (absents → `null`), comme le fait déjà
  `LeboncoinAdapter.php` pour les mêmes champs (TRV-002, non confirmés
  par les dépôts OSS inspectés à l'époque).
- **Blocage LBC éventuel** (Datadome ou autre) : non observable sans le
  test réel — si la page elle-même ne se charge pas normalement pour
  l'utilisateur (page bloquée avant même d'atteindre le DOM), aucune
  extraction n'est possible, et ce POC ne peut rien y changer par
  construction (aucun contournement n'est implémenté, conformément au
  mandat).

## Recommandation

**POC impossible à évaluer dans cette session — accès navigateur
manquant.** Le code est complet, isolé, testé unitairement (8/8), et
respecte scrupuleusement toutes les contraintes du mandat (aucune requête
réseau propre, aucun contournement, aucune donnée inventée). **Il ne peut
cependant pas être déclaré `PASS` sans le test réel décrit ci-dessus**,
qui reste à exécuter par l'utilisateur (ou dans une session avec accès
navigateur). Une fois ce test réalisé et son résultat communiqué, ce
rapport sera mis à jour avec le résultat réel avant tout passage à
TRV-003.
