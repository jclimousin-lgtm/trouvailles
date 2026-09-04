# TRV-002-A — Audit du VintedAdapter pour session navigateur légitime

Mission d'audit uniquement. **Aucun fichier de production n'a été
modifié.** Toutes les affirmations ci-dessous sont sourcées sur le code
réellement présent dans le dépôt à la date de cet audit ; toute question
non tranchable par le seul code existant est signalée explicitement comme
telle plutôt que supposée.

## PARTIE 1 — Localisation de l'existant

| # | Élément | Fichier | Rôle |
|---|---|---|---|
| 1 | `VintedAdapter` | `app/Sources/Vinted/VintedAdapter.php` (namespace `Trouvailles\Sources\Vinted`, classe `final`) | Convertit les réponses de `VintedClient` en `NormalizedListing`, gère la pagination |
| 2 | Interface implémentée | `app/Sources/MarketplaceAdapterInterface.php` | Contrat unique : `search(array $criteria): array` (retourne une liste de `NormalizedListing`) |
| 3 | DTO listing | `app/Sources/NormalizedListing.php` | Classe `final`, propriétés `readonly` via promotion de constructeur, 14 champs + `priceMechanism` (ajout TRV-002, documenté dans le fichier) |
| 4 | Mécanisme HTTP | `app/Sources/Vinted/VintedClient.php` s'appuie sur `Trouvailles\Http\HttpClientInterface` (`app/Http/HttpClientInterface.php`) ; implémentation réelle par défaut : `CurlHttpClient` (`app/Http/CurlHttpClient.php`, cURL standard, aucune impersonation) | Un seul point d'injection : le constructeur de `VintedClient` (`?HttpClientInterface $http = null`), transmis par `VintedAdapter` |
| 5 | URLs/endpoints | Base : `https://www.{domain}` (`domain` = paramètre constructeur, défaut `vinted.fr`) ; `GET /` pour le cookie de session ; `GET /api/v2/catalog/items` pour la recherche (params : `search_text`, `catalog_ids`, `brand_ids`, `price_from`, `price_to`, `currency`, `order`, `page`, `per_page`) | `VintedClient::baseUrl()`, `VintedClient::SEARCH_PATH` |
| 6 | Headers/cookies/session | `VintedClient::ensureSessionCookie()` (lignes 48-79) : `GET` anonyme sur `/`, lit `response['headers']['set-cookie']` (liste), reconstruit un header `Cookie: nom=valeur; nom2=valeur2` en concaténant tous les couples `Set-Cookie` reçus. Résultat mis en cache dans la propriété d'instance `$sessionCookie` — jamais réutilisé entre deux instances, jamais rafraîchi. `User-Agent` statique `Mozilla/5.0 (compatible; TrouvaillesBot/1.0)` sur les deux requêtes | `app/Sources/Vinted/VintedClient.php:48-79` |
| 7 | Parsing réponse | `json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)` dans `VintedClient::searchPage()`, retourne le tableau décodé natif, non transformé | `app/Sources/Vinted/VintedClient.php:125-133` |
| 8 | Mapping → NormalizedListing | `VintedAdapter::mapItem(array $item): ?NormalizedListing` (lignes 91-134) — détail complet en Partie 5 | `app/Sources/Vinted/VintedAdapter.php:91-134` |
| 9 | Intégration SourceManager | **Aucune.** Recherche effectuée sur l'ensemble du dépôt (`grep -rl "SourceManager\|VintedAdapter"`) : aucun fichier de production n'instancie `VintedAdapter` et ne l'enregistre via `SourceManager::register()`. Le seul usage réel est dans les tests | — |
| 10 | Intégration ListingPersister | **Aucune en production.** Le seul lien existant est dans `tests/run_vinted_adapter.php` (bloc « persistance »), qui instancie manuellement `new ListingPersister($pdo)` puis appelle `$persister->persist($listings[0])` sur un résultat de `VintedAdapter::search()` | `tests/run_vinted_adapter.php` |
| 11 | Tests existants | `tests/run_vinted_adapter.php` — 6 tests, entièrement hors réseau via `tests/Support/FixtureHttpClient.php` et la fixture `tests/fixtures/vinted_search_page1.json` : (1) session+recherche+parsing+normalisation, (2) champs non confirmés → `null`, (3) article sans `id`/`url` ignoré, (4) absence de cookie de session levée explicitement, (5) réponse 403 remontée explicitement/jamais contournée, (6) persistance via `ListingPersister` | `tests/run_vinted_adapter.php` |

## PARTIE 2 — Comparaison avec une session navigateur légitime

**Chaîne actuelle :**
```
VintedAdapter::search()
  → VintedClient::searchPage() (construit lui-même la requête HTTP + le cookie)
    → HttpClientInterface::request() (CurlHttpClient par défaut)
```

**Chaîne envisagée :**
```
session navigateur légitime → page/session Vinted → données accessibles normalement
  → NormalizedListing (VintedAdapter::mapItem(), inchangé)
  → ListingPersister (inchangé)
```

**Découplage aval (déjà en place) :** `NormalizedListing`, `MarketplaceAdapterInterface`, `SourceManager`, `ListingPersister` et la persistance SQL ne connaissent **rien** du transport HTTP — ils manipulent exclusivement des `NormalizedListing`, produits en sortie de `VintedAdapter::search()`. Remplacer la façon dont les données brutes sont obtenues n'affecte structurellement aucun de ces éléments, **à condition que la nouvelle source de données livre une forme exploitable par `VintedAdapter::mapItem()`** (voir Partie 5 sur ce point).

**Couplage amont (le point de friction) :** `VintedClient` est câblé en dur sur le couple « requête HTTP anonyme + cookie » via `HttpClientInterface`. Ce contrat (`request(string $method, string $url, array $headers, ?string $body): array{status,body,headers}`) modélise une requête HTTP **unitaire et sans état de session/navigation** — pas de notion de page, de JavaScript exécuté, ou de session persistante gérée nativement. Une session navigateur légitime au sens de la mission est un concept de niveau supérieur (une session/page), que ce contrat ne représente pas.

`VintedAdapter` lui-même dépend directement de la **classe concrète** `VintedClient` (`private readonly VintedClient $client`, ligne 36) — pas d'une interface — ce qui empêche d'y substituer un autre mécanisme sans modifier `VintedAdapter`.

**Conclusion Partie 2 :** l'adapter est suffisamment découplé **côté aval** (rien à modifier dans NormalizedListing/SourceManager/ListingPersister/persistance SQL/pipeline aval), mais **pas côté amont** : ni `VintedClient` ni `VintedAdapter` n'offrent aujourd'hui de point de substitution pour une source de données autre qu'une requête HTTP directe via `HttpClientInterface`.

## PARTIE 3 — Architecture minimale

**Ce qui reste inchangé :** `NormalizedListing`, `MarketplaceAdapterInterface`, `SourceManager`, `ListingPersister`, le schéma SQL (`listings`/`price_observations`/`sources`), `HttpClientInterface` et `CurlHttpClient` (partagés avec `LeboncoinClient` et `EbayClient` — les modifier sortirait du périmètre Vinted), et la logique de `VintedAdapter::mapItem()` elle-même si la nouvelle source conserve la même forme de données.

**Ce qui doit être modifié :** le point d'obtention des données brutes de `VintedClient` doit devenir substituable — aujourd'hui, `ensureSessionCookie()` et `searchPage()` fabriquent eux-mêmes leur requête HTTP via `HttpClientInterface`, sans aucun point d'entrée pour fournir une session obtenue autrement. `VintedAdapter` devrait dépendre d'une abstraction plutôt que de la classe concrète `VintedClient` pour permettre cette substitution sans le modifier à chaque fois.

**Ce qui devrait être ajouté :** une interface dédiée au transport/session Vinted (le nom — `VintedTransportInterface`, `BrowserSessionInterface`, ou autre — n'est **pas déterminable avec certitude à partir du seul code existant** : c'est une décision de conception qui reste à prendre au moment de l'implémentation, pas de cet audit). Elle devrait a minima exposer une méthode équivalente à `searchPage(array $criteria, int $page, int $perPage): array` (même forme de retour que l'actuelle, pour ne rien changer à `VintedAdapter::mapItem()`).

**Où placer les nouvelles classes :** `app/Sources/Vinted/`, à côté de `VintedClient`/`VintedAdapter` — cohérent avec la structure existante (un sous-dossier par marketplace).

**Comment transmettre les résultats au VintedAdapter :** en remplaçant la dépendance actuelle de `VintedAdapter` envers la classe concrète `VintedClient` par une dépendance envers la nouvelle abstraction (injection par constructeur, même mécanisme qu'aujourd'hui pour `HttpClientInterface`).

**Comment conserver le contrat NormalizedListing :** sans changement — `VintedAdapter::mapItem()` produit `NormalizedListing` indépendamment de l'origine des données brutes, tant que leur forme reste celle d'un tableau décodé contenant une clé `items` avec les mêmes sous-champs.

**Comment gérer l'expiration d'une session : non déterminable du code existant.** `VintedClient::ensureSessionCookie()` n'a aujourd'hui aucune notion d'expiration : il obtient un cookie une fois, le met en cache pour la durée de vie de l'instance PHP (une requête HTTP), et ne le rafraîchit jamais. Un mécanisme de session réellement persistante (au sens d'une session navigateur) devrait modéliser une expiration, absente du code actuel.

**Comment distinguer une absence de résultats d'un échec de session :** le code actuel distingue déjà ces deux cas au niveau HTTP : un statut 401/403 lève une `RuntimeException` explicite (« probable protection anti-bot ... session expirée », `VintedClient.php:112-116`) ; un tableau `items` vide ou absent est traité différemment par `VintedAdapter::search()` (log + arrêt propre de la pagination, sans exception, lignes 66-70). Cette distinction devrait être préservée quel que soit le nouveau transport.

**Comment remonter les erreurs proprement :** le code actuel utilise systématiquement `RuntimeException` avec des messages explicites (pas de hiérarchie d'exceptions typées ni d'enum d'erreur) — cohérent avec `LeboncoinClient` et `EbayClient` (même style dans tout TRV-002).

## PARTIE 4 — Session

Le code actuel ne consomme, via `ensureSessionCookie()`, qu'un **cookie de session** (`Set-Cookie` → reconstruit en header `Cookie`). Pour une session navigateur légitime, seraient nécessaires a minima :

- Les **cookies de session valides** (équivalent fonctionnel de ce qu'`ensureSessionCookie()` tente d'obtenir aujourd'hui).
- Un **signal d'état de session** (valide / expirée) — absent du code actuel.
- Un **signal « challenge/protection rencontré »**, distinct d'une session simplement expirée — absent du code actuel : aujourd'hui, 401 et 403 sont traités de façon identique (un seul message, une seule exception).
- Le **contenu de la réponse catalogue** elle-même — c'est ce que `VintedClient::searchPage()` retourne déjà aujourd'hui (le tableau décodé).

**Point à signaler explicitement (demandé par la mission) :** le code existant ne contient **aucun mécanisme d'obtention de session autre que la requête HTTP anonyme automatique** (`ensureSessionCookie()`). Rien dans le code actuel ne permet ni ne prévoit :
- une initialisation manuelle de la session par un opérateur humain,
- ou l'utilisation d'un navigateur normalement piloté (sans automatisation dissimulée) dont la session serait ensuite fournie à l'adapter.

Un tel mécanisme d'obtention de session légitime devrait être conçu et ajouté de toutes pièces ; sa conception dépasse le périmètre de cet audit (qui porte sur l'analyse du code existant, pas sur la conception d'un nouveau mécanisme externe).

## PARTIE 5 — Données Vinted : mapping actuel

| Donnée | Extraite | Emplacement (`VintedAdapter.php`) | Source réponse | Transformation | Champ `NormalizedListing` |
|---|---|---|---|---|---|
| Identifiant | Oui | ligne 93 | `item['id']` | cast `string` | `externalId` |
| URL | Oui | lignes 94, 101 | `item['url']` | si ne commence pas par `http`, préfixée par `https://www.vinted.fr` **en dur** (indépendant du paramètre `$domain` du constructeur — voir Partie 7.G) | `url` |
| Titre | Oui | ligne 121 | `item['title']` | cast `string`, sinon `null` | `title` |
| Prix (montant) | Oui | lignes 103-106 | `item['price']['amount']` | vérification `is_numeric`, cast `float` | `askingPrice` |
| Devise | Oui | lignes 107-109 | `item['price']['currency_code']` | cast `string` | `askingCurrency` |
| Marque | Oui | ligne 123 | `item['brand_title']` | cast `string`, sinon `null` | `brand` |
| Catégorie | Oui, **best-effort non confirmé** | ligne 124 | `item['catalog_id']` | cast `string`, sinon `null` | `category` |
| État/condition | Oui, **best-effort non confirmé** | ligne 125 | `item['status']` | cast `string`, sinon `null` | `condition` |
| Localisation | Oui, **best-effort non confirmé** | ligne 129 | `item['user']['city']` | cast `string`, sinon `null` | `location` |
| Images | **Non extrait** | — | — | — | *(`NormalizedListing` n'a pas de champ image)* |
| Description | Tenté, **jamais rempli en pratique** | ligne 122 | `item['description']` | cast si présent ; le docblock du fichier (lignes 27-30) indique que Vinted ne fournit ce champ que via un second appel `/items/{id}/details`, jamais déclenché — reste `null` depuis une recherche seule | `description` |
| Vendeur (type) | Oui, **best-effort non confirmé** | lignes 111-115 | `item['user']['business']` (booléen) | `true` → `'professional'`, `false` → `'private'`, absent → `null` | `sellerType` |
| Date de publication | Oui, **best-effort non confirmé** | ligne 131 | `item['created_at']` | cast `string`, sinon `null` | `publishedAt` |
| Frais de port | **Non extrait** | ligne 128 (valeur en dur) | — | toujours `null` | `shippingPrice` |

*« best-effort non confirmé » = le docblock de `VintedAdapter.php` (lignes 21-26) précise explicitement que ces champs ne sont pas confirmés par le code des deux dépôts de référence inspectés (`herissondev/vinted-api-wrapper`, `vlymar1/vinted-api-kit`) — lus ici sous des noms de clés plausibles, jamais garantis.*

**Une session navigateur change-t-elle ce mapping ? Non déterminable avec certitude à partir du code existant seul.** Le mapping actuel dépend de la forme JSON retournée par l'endpoint interne `/api/v2/catalog/items`. Deux cas de figure, non tranchés par le code actuel :
- Si une session navigateur légitime aboutit à interroger cette même API JSON (ce qu'un navigateur ferait naturellement en chargeant la page catalogue), la forme de réponse serait probablement identique et ce mapping resterait valable tel quel.
- Si l'approche retenue implique de parser le HTML rendu d'une page plutôt que d'appeler cette API JSON, le mapping actuel (basé sur des clés JSON) ne s'appliquerait plus du tout et devrait être entièrement repensé.

## PARTIE 6 — Sources OSS déjà identifiées

Limité à ce qui est déjà documenté dans le code existant (aucune nouvelle recherche externe effectuée, conformément au mandat) :

- **`herissondev/vinted-api-wrapper`** (MIT) — retenu dans `VintedClient.php` (docblock) comme référence du mécanisme d'accès car « seul des trois dépôts Vinted sans contournement anti-bot actif (requêtes HTTP standard, pas d'impersonation) ».
- **`vlymar1/vinted-api-kit`** (MIT) — mapping de champs repris (`item.py`, Partie 5) ; mécanisme d'accès (impersonation TLS `curl_cffi`) explicitement écarté (docblock `VintedClient.php`).
- **`DataKazKN/vinted-mcp-server`** (MIT) — noms de paramètres de recherche repris ; aucune logique client propre (délègue à un package externe non fourni `vinted-core`) ; mécanisme d'interception pré-Cloudflare explicitement écarté.

Aucun de ces trois dépôts ne documente, dans le code déjà inspecté, de mécanisme de « session navigateur légitime » au sens de cette mission (ils s'appuient tous sur un cookie obtenu par requête HTTP directe, avec ou sans impersonation).

## PARTIE 7 — Verdict

**A. Ce qui fonctionne déjà et ne doit pas être touché**
- `NormalizedListing`, `MarketplaceAdapterInterface`, `SourceManager`, `ListingPersister`, le schéma SQL (`listings`/`price_observations`/`sources`).
- `HttpClientInterface` + `CurlHttpClient` — partagés avec `LeboncoinClient` et `EbayClient` ; les modifier dépasserait le périmètre Vinted.
- La logique de `VintedAdapter::mapItem()`, si la nouvelle source de données conserve la même forme JSON.
- Les 6 tests de `tests/run_vinted_adapter.php`, tant que le contrat de `VintedAdapter::search()` ne change pas.

**B. Ce qui empêche actuellement VintedAdapter de fonctionner avec une session navigateur**
- `VintedClient` est câblé en dur sur « `HttpClientInterface` + requête HTTP anonyme » pour obtenir son cookie — aucun point d'entrée pour injecter une session obtenue autrement.
- `VintedAdapter` dépend de la classe concrète `VintedClient` (pas d'une interface), empêchant toute substitution sans le modifier.
- Aucune notion d'expiration de session ; aucune distinction entre « session expirée » et « challenge anti-bot rencontré » (401 et 403 traités identiquement).
- Aucun mécanisme d'obtention/injection d'une session initialisée manuellement par un humain ou un navigateur normalement piloté.

**C. Modifications exactes nécessaires (architecturales — aucune implémentée dans cet audit)**
1. Introduire un point de substitution entre `VintedAdapter` et sa source de données (dépendre d'une interface plutôt que de la classe concrète `VintedClient`).
2. Découpler l'obtention du cookie/état de session de la requête HTTP interne actuelle, pour permettre de fournir une session obtenue autrement.
3. Distinguer explicitement, dans le mécanisme d'erreur, « session expirée » de « challenge anti-bot rencontré » — plus fin que le `RuntimeException` générique actuel sur 401/403.

**D. Nouveaux fichiers/classes éventuellement nécessaires**
- Une interface dédiée à la session/transport Vinted (nom exact non tranché par cet audit), dans `app/Sources/Vinted/`.
- Une implémentation de cette interface correspondant au mécanisme de session légitime retenu — sa nature exacte n'est **pas déterminable à partir du code existant** : elle dépend d'une décision, hors périmètre de cet audit, sur la façon dont cette session serait concrètement obtenue.

**E. Dépendances éventuellement nécessaires (non installées)**
**Non déterminable à partir du code existant seul.** Cela dépend entièrement du mécanisme de session légitime retenu — non défini par cette mission, qui exclut explicitement l'automatisation dissimulée (Playwright/Selenium/stealth) sans prescrire d'alternative. Si le mécanisme retenu repose sur une fourniture manuelle de cookie par un opérateur humain, aucune nouvelle dépendance n'est nécessaire.

**F. Tests à ajouter lors de l'implémentation**
- Un test vérifiant que `VintedAdapter` fonctionne correctement quand la session provient du nouveau mécanisme plutôt que de `CurlHttpClient` (nouvelle fixture correspondante).
- Un test de session expirée, distinct du test 403 déjà existant, si cette distinction est effectivement implémentée.
- Vérification de non-régression sur les 6 tests déjà présents dans `tests/run_vinted_adapter.php`.

**G. Risques ou limitations connus**
- Le mapping actuel repose sur une API JSON interne (`/api/v2/catalog/items`) ; si la session légitime implique de parser une page HTML rendue plutôt que d'appeler cette API, le mapping devra être entièrement repensé (Partie 5).
- Incohérence pré-existante, sans lien avec cette mission : l'URL d'une annonce est reconstruite avec le domaine `vinted.fr` **en dur** dans `mapItem()` (ligne 101), indépendamment du paramètre `$domain` passé au constructeur — affecterait toute annonce sur un domaine Vinted non-`.fr`.
- Le mécanisme concret d'obtention d'une « session légitime » n'est pas défini par le code existant ; cet audit ne peut pas en évaluer la faisabilité opérationnelle réelle.

**H. Estimation de complexité : moyenne**
Le découplage architectural lui-même (interface de substitution, distinction d'état de session) est de complexité faible à moyenne, le code étant déjà bien isolé (`VintedClient`/`VintedAdapter` séparés, `HttpClientInterface` déjà abstrait derrière une injection de constructeur). La complexité réelle dépend surtout du mécanisme de session légitime retenu, qui reste à définir et sort du périmètre de cet audit.
