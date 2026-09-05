# Trouvailles

Schéma SQL du modèle de données (TRV-001-C) + adapters marketplace
(TRV-002) : Leboncoin, Vinted, eBay (Browse API officielle) alimentent le
même pipeline commun (SourceManager → Adapter → NormalizedListing →
ListingPersister) vers `listings`/`price_observations`. Aucun matching
produit, calcul de valeur de marché ou moteur d'opportunité — voir
`docs/TRV-001-C.md` et `docs/TRV-002.md`.

Habillage graphique V1 (AV-UI-001) intégré : palette/typographie/composants
de `docs/brand-v1/` chargés dans `public/index.php` (voir `docs/AV-UI-001.md`).

Écran d'accueil réel « Mes Trouvailles » (TRV-UI-002) : branché sur
`OpportunityRepository`, données réelles ou état vide honnête — jamais de
donnée fabriquée (voir `docs/TRV-UI-002.md`).

Vinted (TRV-002-A/B) : `VintedAdapter` dépend désormais de
`VintedTransportInterface` (jamais de `VintedClient` directement) —
`VintedClient` (HTTP direct, historique) et `VintedBrowserSessionTransport`
(consomme une session déjà fournie, ne fabrique jamais de session,
aucun contournement anti-bot) en sont les deux implémentations. Voir
`docs/TRV-002-A-audit-vinted-browser-session.md` et
`docs/TRV-002-B-vinted-browser-session.md`.

POC collecteur local Leboncoin (TRV-003-A) : extension Chrome MV3 isolée
dans `tools/lbc-local-collector/`, aucun raccordement au backend PHP —
détecte une recherche LBC ouverte normalement, exporte les annonces
visibles en `NormalizedListing` JSON local. Validation réelle : bloqué par
DataDome dès la page d'accueil pour un navigateur automatisé neuf (aucun
contournement tenté) — voir `docs/TRV-003-A-poc-lbc-collector.md`.

POC collecteur local Vinted (TRV-005) : même principe dans
`tools/vinted-local-collector/`, extraction 100% DOM (aucun blocage
constaté au chargement d'une page de résultats, contrairement à
Leboncoin — seul l'appel direct à l'API catalogue est protégé) ; logique
d'extraction vérifiée contre une vraie page capturée (96/96 annonces
normalisées) — voir `docs/TRV-005-poc-vinted-collector.md`.

Moteur de matching/valorisation/opportunités (TRV-004, eBay uniquement) :
`app/Pricing/` — apparie les annonces à un produit canonique, calcule une
valorisation de marché à partir des observations comparables, détecte les
bonnes affaires. Voir `docs/TRV-004.md`.

Recherche multi-critères « Chasses » (TRV-006, eBay uniquement) :
`public/chasses.php` — mot-clé (obligatoire, contrainte réelle de l'API
Browse) + fourchette de prix, résultats bruts jamais présentés comme des
opportunités. Voir `docs/TRV-006-recherche-ebay.md`.

Champ « marge minimale » sur Chasses (TRV-008) : renseigné, il déclenche
persistance + matching + valorisation + détection, et n'affiche que les
annonces de cette recherche dont la décote réelle atteint le seuil
(`OpportunityDetector::previewForListings()`, lecture seule, jamais
dépendante de l'historique d'exécutions à seuil différent). Corrige au
passage un bug pré-existant de conversion de date eBay
(`EbayAdapter::normalizePublishedAt()`). Voir `docs/TRV-008-marge-chasses.md`.

Activation production eBay (TRV-007) : `public/ebay-account-deletion.php`
implémente l'endpoint de conformité RGPD exigé par eBay avant d'activer
l'OAuth en production — sans lui, l'authentification échoue
systématiquement, même avec des identifiants exacts. Production
confirmée fonctionnelle (vraies annonces récupérées). Voir
`docs/TRV-007-ebay-production.md`.

## Structure

```
app/
├── Core/         Database.php (PDO singleton), Env.php (.env), autoload.php
├── Http/         HttpClientInterface, CurlHttpClient (aucun contournement anti-bot)
├── Sources/      NormalizedListing, MarketplaceAdapterInterface, SourceManager,
│                 Leboncoin/, Ebay/ (Client + Adapter + PriceFilter, TRV-006),
│                 Vinted/ (Client + BrowserSessionTransport + TransportInterface + Adapter)
├── Persistence/  ListingPersister (écriture), OpportunityRepository (lecture accueil)
├── Pricing/      TitleNormalizer, ProductMatcher, ValuationEngine, OpportunityDetector (TRV-004)
├── Models/       (vide — réservé)
├── Services/     (vide — réservé)
└── Controllers/  (vide — réservé)
config/           database.php, ebay.php (charge .env)
database/
└── migrations/   fichiers .sql horodatés, suivis via schema_migrations
public/
├── index.php     écran d'accueil « Mes Trouvailles », habillé charte V1
├── chasses.php   recherche multi-critères eBay (TRV-006)
├── _nav.php      navigation partagée desktop+mobile (TRV-006)
├── css/          trouvailles.css (pack de charte, intact), app.css (glue de mise en page)
└── assets/       logo/, icons/, illustrations/, patterns/, ui/ (SVG, pack de charte)
tools/            migrate.php (lanceur de migrations), pricing_engine.php (moteur TRV-004),
                   lbc-local-collector/, vinted-local-collector/ (POC navigateur, TRV-003-A/TRV-005)
tests/            TestRunner.php, assertions.php, run.php (schéma),
                   run_leboncoin_adapter.php, run_vinted_adapter.php,
                   run_vinted_transport.php, run_ebay_adapter.php,
                   run_listing_persister.php, run_opportunity_repository.php,
                   run_title_normalizer.php, run_product_matcher.php,
                   run_valuation_engine.php, run_opportunity_detector.php,
                   run_pricing_pipeline_e2e.php, run_ebay_price_filter.php,
                   Support/FixtureHttpClient.php, fixtures/*.json
docs/             TRV-001-C.md, TRV-002.md, TRV-004.md, TRV-006-recherche-ebay.md,
                   TRV-007-ebay-production.md, TRV-008-marge-chasses.md,
                   AV-UI-001.md, TRV-UI-002.md, TRV-002-A/B-*.md, TRV-003-A-*.md,
                   TRV-005-*.md (rapports de mission), brand-v1/ (charte)
```

## Migrations

```
php tools/migrate.php             # applique les migrations en attente
php tools/migrate.php --bootstrap # enregistre l'existant sans l'exécuter
php tests/run.php                 # applique les migrations + suite de tests
```

## Démarrage local

```
cp .env.example .env   # puis renseigner DB_USER/DB_PASS si besoin
php -S localhost:8905 -t public
```

Base locale MariaDB : `trouvailles`, utilisateur `trouvailles_app`
(identifiants dans `.env`, gitignored).

## Déploiement (o2switch, compte nare8592)

- Sous-domaine : `trouvailles.serviceproi.fr` — docroot = contenu de `public/`
- Base MySQL prod : `nare8592_trouvailles`
- Identifiants (LOCAL + PROD) : `~/.config/o2switch/trouvailles-db.env`
  (hors dépôt, chmod 600), complète `~/.config/o2switch/nare8592.env`
  (FTP/cPanel génériques du compte, communs à tous les projets)
- Code applicatif hors docroot : `trouvailles-app-prive/` (sibling du
  docroot sur le serveur) — mirroir de `app/`, `config/`, `tools/`,
  `database/` + `.env` prod réel ; relié au docroot via `public/app-root.php`
  (gitignored, déployé uniquement, jamais commité)
- Transfert : **SSH/SFTP** (accès activé le 2026-09-05 — nécessite d'abord
  d'autoriser l'IP sortante dans cPanel : rechercher « SSH » dans cPanel →
  outil d'autorisation IP pour le port 22 ; l'IP peut changer selon
  l'environnement, à revérifier si la connexion échoue). Utiliser
  `paramiko` (SFTP) plutôt que `lftp`/FTP classique — voir §
  « Piège FTP » ci-dessous. Aucun script de déploiement conservé dans le
  dépôt à ce jour.

### ⚠️ Piège FTP découvert le 2026-09-05 (ne pas reproduire)

`lftp mirror --reverse` avec une destination en chemin absolu commençant
par `/home/nare8592/...` a silencieusement écrit dans
`/home/nare8592/home/nare8592/...` (doublé) au lieu du chemin réel — le
client FTP interprète le chemin absolu comme relatif à la racine déjà
chrootée du compte. Résultat : les **nouveaux fichiers/dossiers** d'un
déploiement atterrissaient au mauvais endroit (invisibles pour
l'application, `ls` via FTP les montrait quand même car FTP lisait le
même chemin doublé qu'il avait écrit), tandis que les **fichiers déjà
existants et seulement modifiés** n'étaient parfois pas retransférés du
tout par la comparaison de `mirror` (constaté sur `autoload.php`,
`EbayClient.php`, `config/ebay.php` lors du déploiement TRV-004/TRV-005 —
resté silencieusement sur l'ancienne version malgré un déploiement
"réussi"). **Un déploiement FTP réussi en apparence (exit 0, `ls`
cohérent en FTP) n'est donc pas une preuve fiable** — vérifier après coup
via SSH (`find ... -exec sha256sum {} \;` côté serveur, comparé aux
sommes locales) plutôt que de se fier au résultat de `lftp`. Un dossier
résiduel `/home/nare8592/home/nare8592/trouvailles-app-prive/` datant
d'une mission antérieure (2026-09-02) suggère que ce piège n'est pas
propre à cette seule session — à nettoyer/vérifier à l'occasion.
