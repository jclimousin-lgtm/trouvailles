# TRV-002-B — Support Vinted par session navigateur légitime

Mission de **découplage architectural**, faisant suite à l'audit
`TRV-002-A`. Aucun contournement anti-bot n'a été ajouté — voir la section
dédiée en fin de document.

## Pourquoi `VintedTransportInterface` existe

L'audit TRV-002-A a établi que `VintedAdapter` était déjà bien découplé
en aval (`NormalizedListing`, `SourceManager`, `ListingPersister`,
persistance SQL ne connaissent rien du transport HTTP), mais pas en
amont : `VintedAdapter` dépendait directement de la **classe concrète**
`VintedClient`, elle-même câblée en dur sur « requête HTTP anonyme +
cookie ». Aucun point d'entrée n'existait pour fournir une session
obtenue autrement.

`VintedTransportInterface` (`app/Sources/Vinted/VintedTransportInterface.php`)
introduit ce point de substitution — un contrat unique :

```php
public function searchPage(array $criteria, int $page, int $perPage): array;
```

`VintedAdapter` ne dépend désormais que de cette interface (constructeur :
`__construct(VintedTransportInterface $transport, string $domain = 'vinted.fr')`)
et ignore totalement comment les données ont été obtenues — HTTP direct,
session fournie, ou toute future implémentation.

## Différence entre `VintedClient` et `VintedBrowserSessionTransport`

| | `VintedClient` | `VintedBrowserSessionTransport` |
|---|---|---|
| Obtention de la session | Automatique : `GET /` anonyme sur la page d'accueil (`ensureSessionCookie()`), cookie mis en cache pour la durée de vie de l'instance | **Aucune** — la session (cookie) est reçue en injection au constructeur, jamais fabriquée par la classe |
| Rôle | Comportement HTTP historique, conservé tel quel comme implémentation par défaut/compatible avec l'existant (TRV-002) | Consomme une session déjà établie par un moyen légitime, externe à cette classe |
| Requêtes réseau | 2 requêtes (page d'accueil puis catalogue) | 1 seule requête (catalogue), vérifié par test (Test C) |
| Implémente | `VintedTransportInterface` | `VintedTransportInterface` |

Les deux classes appellent le **même endpoint** (`/api/v2/catalog/items`)
avec le **même contrat de réponse** (tableau décodé contenant `items`) —
`VintedAdapter::mapItem()` n'a donc pas été modifié pour cette raison
(seule la correction du domaine en dur, sans rapport avec le transport,
a été faite — voir plus bas).

## Comment une session est fournie

Forme minimale retenue, conformément au mandat (« ne pas inventer de
mécanisme de connexion Vinted ») :

```php
new VintedBrowserSessionTransport(
    ?string $sessionCookie,      // valeur littérale du header Cookie
    ?HttpClientInterface $http = null,
    string $domain = 'vinted.fr'
);
```

Pas de classe de session dédiée, pas de renouvellement automatique, pas
de stockage SQL. `$sessionCookie` doit être obtenu **par un moyen
légitime et explicite, extérieur à cette classe** (par exemple une
session de navigation normale, dont le cookie serait relevé et fourni
manuellement) — ce moyen d'obtention n'est pas défini par cette mission
et reste à la charge de l'opérateur/appelant.

## Explicitement hors périmètre

- Authentification métier, compte utilisateur Trouvailles, stockage SQL
  de session, renouvellement automatique complexe : **non implémentés**,
  hors mandat (§7 de la mission).
- Aucune nouvelle table SQL, aucune modification du schéma existant.
- Aucune sélection automatique de transport dans `VintedAdapter` (pas de
  logique `if 403 alors essayer autre chose`) — la sélection du
  transport (`VintedClient` ou `VintedBrowserSessionTransport`) se fait
  entièrement à l'assemblage, à l'extérieur de l'adapter.
- Le mécanisme concret par lequel un opérateur obtiendrait une session
  navigateur légitime (navigation manuelle, extraction du cookie, etc.)
  n'est ni conçu ni implémenté ici — seule la **capacité de consommer**
  une session déjà obtenue est fournie. Voir « Points restant bloqués »
  ci-dessous.

## Absence de contournement anti-bot — confirmation explicite

`VintedBrowserSessionTransport` :
- ne lance aucun navigateur (furtif ou non) ;
- ne contourne ni Cloudflare ni DataDome ;
- ne résout aucun CAPTCHA, n'appelle aucun service de résolution ;
- n'usurpe aucune empreinte de navigateur (pas de `curl_cffi`, pas
  d'impersonation TLS) ;
- ne tente jamais de méthode alternative après un refus (401/403) —
  vérifié par test (Test E : une seule requête part, jamais deux) ;
- utilise un `HttpClientInterface` standard (`CurlHttpClient` par défaut,
  cURL ordinaire), identique à celui déjà utilisé par `VintedClient`,
  `LeboncoinClient` et `EbayClient`.

La seule différence avec une requête HTTP « anti-bot-friendly » est
qu'elle porte un cookie de session **déjà légitimement obtenu**, au lieu
d'en fabriquer un via une requête anonyme automatisée.

## Comportement en cas de session absente/expirée/refusée

| Situation | Comportement |
|---|---|
| Session absente (`null` ou chaîne vide) | `RuntimeException` immédiate, **aucune requête réseau envoyée** (Test D) |
| Session refusée par Vinted (HTTP 401/403) | `RuntimeException` explicite (« session refusée ... n'est plus utilisable »), **aucune tentative alternative** (Test E) |
| Limitation de requêtes (HTTP 429) | `RuntimeException` explicite, comme dans `VintedClient` |
| Réponse HTTP invalide / vide | `RuntimeException` explicite |
| JSON malformé | `RuntimeException` explicite (Test F) |
| Réponse décodée mais structurellement invalide (pas un tableau) | `RuntimeException` explicite : « données catalogue invalides » (Test F) |

Dans tous les cas d'échec, `VintedAdapter::search()` conserve son
comportement existant (TRV-002) : une erreur en page 1 est propagée telle
quelle ; une erreur en page 2+ (pagination) est journalisée et interrompt
proprement la pagination sans perdre les résultats déjà collectés.

## Comment tester sans réseau

```
php tests/run_vinted_transport.php   # Tests A à F (nouveau, TRV-002-B)
php tests/run_vinted_adapter.php     # 6 tests historiques + Test G (domaine)
```

Les deux fichiers utilisent exclusivement `tests/Support/FixtureHttpClient.php`
(aucun réseau réel). `tests/run_vinted_transport.php` ajoute une classe
`FakeVintedTransport` (définie dans le fichier de test lui-même, pas dans
`app/`) implémentant `VintedTransportInterface` avec une réponse fixe,
pour prouver que `VintedAdapter` fonctionne avec n'importe quelle
implémentation du contrat, pas seulement `VintedClient`.

## Correction pré-existante (§11 du mandat)

`VintedAdapter::mapItem()` reconstruisait une URL relative avec le
domaine `vinted.fr` **en dur**, indépendamment de tout paramètre. Corrigé :
`VintedAdapter` porte désormais son propre paramètre `$domain` (défaut
`'vinted.fr'`, indépendant du domaine utilisé par le transport injecté),
utilisé uniquement pour cette reconstruction d'URL. Test dédié : « Test G »
dans `tests/run_vinted_adapter.php`.

## Points restant bloqués pour une session Vinted réellement exploitable

- **Le mécanisme concret d'obtention d'une session légitime n'est pas
  défini.** Cette mission fournit la capacité de *consommer* une session
  fournie, jamais celle de l'*obtenir* — c'est une décision explicitement
  laissée hors périmètre (§7, §15 du mandat).
- Si l'obtention réelle d'une session nécessitait une dépendance non déjà
  présente dans le projet (ex. un outil de pilotage de navigateur), cette
  mission ne l'installe pas — conformément au mandat, ce besoin est
  seulement documenté ici, pas résolu.
- Le mapping actuel (`mapItem()`) suppose que la session légitime permet
  d'atteindre `/api/v2/catalog/items` sous forme JSON. Si l'accès
  réellement possible via une session légitime n'expose ces données que
  sous forme de HTML rendu, le mapping actuel ne s'appliquerait plus et
  devrait être entièrement repensé — situation non rencontrée dans cette
  mission (aucune tentative de parsing HTML introduite, conformément au
  §6 du mandat).
