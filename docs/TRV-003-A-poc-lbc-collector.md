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

## Étape 9 — Test réel : **EXÉCUTÉ — RÉSULTAT : BLOQUÉ PAR LBC (DataDome)**

Mission TRV-003-A-B (2026-09-04) : le test réel a été repris et cette
fois **effectué par l'agent lui-même**, sans le transférer au maître
d'ouvrage, avec deux tentatives successives.

**Tentative 1 — extension claude-in-chrome (navigateur réel et habituel
de l'utilisateur)** : outil indisponible dans cette session — « Claude
in Chrome is turned off in your settings ». Il ne s'agit pas d'une
absence de navigateur connecté (un navigateur *est* listé comme
connecté par `list_connected_browsers`), mais d'un réglage désactivé
côté compte, que l'agent ne modifie pas lui-même (changer un réglage de
compte n'entre pas dans le périmètre d'une correction de code). Cette
voie reste donc fermée dans l'état actuel des réglages.

**Tentative 2 — navigateur réel piloté directement (Playwright +
Google Chrome installé sur la machine, `channel: 'chrome'`, fenêtre
visible, aucun mode headless, aucun argument de dissimulation
d'automatisation, profil neuf, locale `fr-FR`)** : navigation ordinaire
vers `https://www.leboncoin.fr/` (page d'accueil, avant même toute
recherche). **Dès ce premier chargement**, LBC a répondu par un défi
DataDome : « On s'assure qu'on s'adresse bien à vous, et non pas à un
robot » avec un CAPTCHA à glissière (« Faites glisser vers la droite
pour sécuriser votre accès ») et le message « vous surfez et cliquez à
une vitesse surhumaine ». Capture d'écran conservée
(`01_home.png`, session locale).

Conformément au mandat (section 3/13), **ce CAPTCHA n'a pas été
résolu, ni contourné par un autre moyen** (pas de solveur, pas de
proxy, pas de spoofing, pas de nouvelle tentative avec des paramètres
de dissimulation). Le navigateur a été fermé immédiatement après la
capture d'évidence. Aucune page `/recherche...` n'a donc été atteinte,
et le collecteur (`content.js`/`lib/normalize.js`) n'a **pas** pu être
exercé contre une vraie page de résultats.

**Conclusion factuelle** : dans les conditions testées (Chrome réel,
profil neuf, sans session/cookies préexistants, piloté par
automatisation CDP standard sans dissimulation), LBC bloque l'accès
avant même la page d'accueil. Il reste une inconnue non tranchée par ce
test : un navigateur réel avec un profil "vieilli" (cookies, historique
de navigation humaine, session utilisateur normale — précisément la
voie de la Tentative 1) pourrait obtenir un score de confiance DataDome
différent. Cette voie n'a pas pu être testée car elle dépend d'un
réglage de compte actuellement désactivé.

## Exemple

Aucune donnée réelle collectée — le test réel a été bloqué avant
d'atteindre une page de résultats (voir ci-dessus). Pas d'exemple à
présenter — en présenter un fabriqué violerait explicitement l'Étape 8
du mandat (« Un POC qui fonctionne uniquement sur des données mockées
est un échec du critère principal »). Les objets produits par les
tests unitaires (ex. `VTT Decathlon Rockrider 540`) restent des
fixtures de test, pas une collecte réelle — clairement distingués ici.

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
- **Blocage LBC confirmé** (DataDome) : **observé réellement** le
  2026-09-04 — un navigateur Chrome réel, piloté sans dissimulation
  d'automatisation et sans historique/cookies préexistants, est
  intercepté par un CAPTCHA DataDome dès la page d'accueil, avant même
  d'atteindre une page de résultats. Ce POC ne peut rien y changer par
  construction (aucun contournement n'est implémenté, conformément au
  mandat) : l'extraction elle-même (`__NEXT_DATA__`/repli DOM) reste
  **non vérifiée contre une vraie page**, quelle que soit sa qualité
  intrinsèque (8/8 tests unitaires verts sur des fixtures).

## Étape 9bis — TRV-003-A-C : test avec la session LBC existante du navigateur quotidien (2026-09-04)

**Question posée** : une session LBC humaine déjà présente dans le
navigateur quotidien de l'utilisateur permet-elle au collecteur de
récupérer de vraies annonces ?

**Tentative** : nouvel essai de l'outil claude-in-chrome (seule voie
permettant de piloter le navigateur réel de l'utilisateur, avec sa
session LBC existante, **sans en extraire les cookies**) : toujours
refusé — « Claude in Chrome is turned off in your settings ». Il ne
s'agit pas d'un navigateur non connecté (`list_connected_browsers`
liste bien un navigateur connecté), mais d'un réglage de compte
désactivé, que l'agent ne modifie pas lui-même.

**Alternatives techniques envisagées puis écartées, et pourquoi** :

- **Copier le profil Chrome réel de l'utilisateur**
  (`~/.config/google-chrome/`, confirmé présent et utilisé par un
  Chrome actuellement en cours d'exécution) vers un répertoire
  temporaire pour y lancer un navigateur piloté : **écarté** — cela
  reviendrait à extraire la base de cookies de session, explicitement
  interdit par le mandat (« ne pas extraire ou transmettre les cookies
  de session »), et exposerait en outre toutes les autres sessions
  connectées du profil (mail, banque, etc.), bien au-delà du périmètre
  LBC de cette mission.
- **Attacher un contrôleur au Chrome réel déjà lancé** via le
  protocole CDP (`--remote-debugging-port`) : **écarté** — un profil
  Chrome déjà ouvert normalement n'expose pas ce port ; l'activer
  exigerait de fermer la session en cours de l'utilisateur (tous ses
  onglets/fenêtres actuels) pour relancer le navigateur avec un
  paramètre de débogage, une action disruptive sur son usage réel non
  demandée et non autorisée dans le cadre de cette mission.

Aucune de ces deux voies ne respecte les contraintes absolues du
mandat (pas d'extraction de cookies, pas de manipulation invasive de
la session réelle de l'utilisateur). Aucune n'a donc été exécutée.

**Résultat** : `TRV-003-A-C = NOT EXECUTABLE` — le navigateur quotidien
de l'utilisateur, avec sa session LBC existante, ne peut pas être
utilisé depuis cet environnement, dans le respect des contraintes du
mandat. La cause est un réglage de compte (Claude in Chrome désactivé),
pas une limite technique de l'agent ni un blocage LBC : **DataDome n'a
pas été testé contre la session existante**, seulement contre un
profil neuf (TRV-003-A-B, résultat `BLOCKED`, voir ci-dessus).

## Conclusion

**TRV-003-A = BLOCKED** (profil neuf, testé) **/ TRV-003-A-C = NOT
EXECUTABLE** (session existante, non testable depuis cet
environnement).

Réponse à la question posée par le mandat initial — « une extension
locale exécutée dans un navigateur réel peut-elle récupérer des
annonces LBC réelles et les transformer en `NormalizedListing`
utilisables par Trouvailles, sans serveur tiers et sans contourner les
protections LBC ? » — **toujours non tranchée positivement** : le seul
test réel effectivement mené (Chrome réel, profil neuf, sans
contournement) a été bloqué par DataDome avant toute page de
résultats. Le second test demandé (session LBC existante du navigateur
quotidien) n'a pas pu être exécuté du tout, faute d'accès — pas
bloqué par LBC, simplement inaccessible depuis cet environnement tant
que ce réglage de compte reste désactivé et qu'aucune méthode
respectant les contraintes du mandat ne permet d'y accéder autrement.

Le code du collecteur (extraction, normalisation, dédup, export,
conformité au contrat `NormalizedListing`) reste complet, isolé, et
testé unitairement (8/8) — mais **son fonctionnement contre de vraies
annonces LBC demeure non démontré**, dans les deux configurations
testées à ce jour.
