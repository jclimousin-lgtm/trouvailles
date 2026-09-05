# TRV-009 — Intégration Etsy (nouvelle source marketplace)

## Contexte

Recherche de plateformes alternatives/complémentaires à eBay :

| Plateforme | Verdict |
|---|---|
| Drouot | Pas d'API publique de recherche trouvée |
| Catawiki | API réservée à la gestion des lots côté vendeur |
| Delcampe | API réservée aux comptes professionnels (sync de ventes) |
| Depop | Aucune API publique (Selling API privée, partenaires triés) |
| **Etsy** | ✅ API publique réelle, palier « Personal App » adapté à un usage personnel |

Etsy a été retenue comme deuxième source active du projet.

## Vérification technique (pas deviné)

Détails obtenus en inspectant directement la spécification OpenAPI
officielle d'Etsy (dépôt `etsy/open-api`, fichier
`_original/etsy-openapi-original.yml`, `x-generated-from:
https://www.etsy.com/openapi/generated/oas/3.0.0.json`), recoupés par une
recherche indépendante :

- **Endpoint** : `GET https://openapi.etsy.com/v3/application/listings/active`
  — recherche marketplace-wide (pas limitée à une boutique).
- **Authentification** : un seul en-tête `x-api-key: <keystring>:<shared_secret>`
  (format en vigueur depuis le 9 février 2026 — avant cette date, le
  keystring seul suffisait). **Aucun échange OAuth2** nécessaire pour
  cette recherche publique — bien plus simple que le flux
  `client_credentials` d'eBay (un seul appel HTTP par page de résultats,
  contre deux pour eBay).
- **Prix** : objet `Money` = `{amount, divisor, currency_code}`, prix réel
  = `amount / divisor` — jamais un décimal direct comme eBay.
- **Devise potentiellement différente par annonce** : chaque boutique Etsy
  fixe sa propre devise (contrairement à eBay où `marketplace_id`
  implique une devise cohérente pour toute une recherche). Vérifié que
  `ValuationEngine` gère déjà ce cas nativement (devise majoritaire
  retenue par produit, les autres rejetées) — **aucune modification du
  moteur de pricing nécessaire**.
- **Date** : `created_timestamp` en epoch secondes — converti
  explicitement en `Y-m-d H:i:s` dès l'écriture du code
  (`EtsyAdapter::normalizeTimestamp()`), pour ne pas répéter le bug
  découvert sur eBay en TRV-008 (conversion oubliée, cassait la
  persistance en conditions réelles).

## Ce que construit cette mission

```
app/Sources/Etsy/EtsyClient.php      client HTTP direct, x-api-key, pas d'OAuth
app/Sources/Etsy/EtsyAdapter.php     implements MarketplaceAdapterInterface
config/etsy.php                      charge .env (ETSY_KEYSTRING, ETSY_SHARED_SECRET)
database/migrations/20260906120000_seed_etsy_source.sql
tests/run_etsy_adapter.php           9 tests
tests/fixtures/etsy_search_page1.json
```

Aucun autre fichier existant modifié en dehors de `.env.example`/`README.md` —
`SourceManager`/`public/chasses.php`/`app/Pricing/` restent intacts.
L'intégration à l'UI de recherche (`chasses.php`) est explicitement **hors
périmètre** de cette mission (décision multi-sources à part entière —
sélecteur de source ? résultats fusionnés ? — prochaine étape si
souhaité, même logique incrémentale que TRV-002 → TRV-006 pour eBay).

## Champs jamais inventés (absents de cette réponse Etsy)

`brand` (aucun champ équivalent), `category` (seul `taxonomy_id` numérique
disponible, sans nom — un second appel serait nécessaire), `condition`
(Etsy n'a pas de notion neuf/occasion ; `who_made`/`when_made` ne sont pas
un équivalent honnête), `shippingPrice` (absent de cette réponse précise),
`location` et `sellerType` (aucun champ équivalent pour une boutique
Etsy). `priceMechanism` toujours `fixed` (pas d'enchères chez Etsy).

## Tests

```
$ php tests/run_etsy_adapter.php
# 9/9 : parsing/normalisation, un seul appel HTTP (pas d'OAuth), devises
#       mélangées, sans identifiants, item sans identifiant, conversion
#       de date epoch, divisor=0 (jamais de division par zéro), 401, 429,
#       persistance (published_at bien formé, migration seed etsy appliquée)
```

Suite complète (118 tests, 14 fichiers) rejouée — aucune régression.

## Non vérifié empiriquement (aucun identifiant Etsy disponible à ce stade)

- **Filtre `min_price`/`max_price`** : documenté comme suffisant seul par
  la spécification, contrairement à eBay où `priceCurrency` était
  silencieusement requis en plus (découvert uniquement en conditions
  réelles, TRV-008-bugfix). **À vérifier dès que possible** avec la même
  méthode : comparer les prix réellement retournés à la fourchette
  demandée, ne pas se fier à la documentation seule.
- **Format exact de `x-api-key`** selon l'ancienneté de l'app (keystring
  seul avant le 9 février 2026, `keystring:secret` depuis) — le code
  utilise systématiquement le format le plus récent ; à confirmer que la
  future app créée par l'utilisateur (donc récente) suit bien ce format.
- **Base URL** : la spécification déclare `openapi.etsy.com`, une source
  indépendante mentionne que les appels réels résolvent aussi vers
  `api.etsy.com/v3/application` — à confirmer laquelle répond
  effectivement une fois un test réel possible.

## Suivi de la demande d'app réelle (2026-09-05)

App « trouvailler » créée sur `etsy.com/developers/register` (Personal App).
Statut initial : **`Pending Personal Approval`** — clé non active tant que non
approuvée par Etsy. D'après la discussion officielle
`github.com/etsy/open-api/discussions/1607` (ouverte par le support Etsy le
12 mai 2026), le délai d'approbation d'une Personal App est très variable
(plusieurs semaines à plusieurs mois rapportés), avec des rejets parfois sans
justification ni recours. À surveiller sur `etsy.com/developers/your-apps` ;
aucun test réel possible tant que le statut n'est pas `Approved`.

## Étapes côté utilisateur pour la suite

1. Créer un compte développeur sur `https://www.etsy.com/developers/register`.
2. Créer une app en **Personal App** (pas Seller App, pas besoin de
   Commercial Access pour un usage personnel).
3. Récupérer le Keystring et le Shared Secret depuis « Your Apps ».
4. Me les transmettre (en un seul message, copié-collé — pas retapé) pour
   la vérification réelle : requête simple, filtre de prix, persistance.

## Conclusion

Etsy est intégré au même niveau de rigueur qu'eBay (TRV-002) : adapter
testé, aucune donnée inventée, prêt à être vérifié dès que de vrais
identifiants seront disponibles. Contrairement à eBay, aucun mur de
conformité (Marketplace Account Deletion) n'a été identifié pour ce
palier d'accès — à confirmer à l'usage.
