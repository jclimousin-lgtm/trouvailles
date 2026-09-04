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

## Structure

```
app/
├── Core/         Database.php (PDO singleton), Env.php (.env), autoload.php
├── Http/         HttpClientInterface, CurlHttpClient (aucun contournement anti-bot)
├── Sources/      NormalizedListing, MarketplaceAdapterInterface, SourceManager,
│                 Leboncoin/, Ebay/ (Client + Adapter), Vinted/ (Client +
│                 BrowserSessionTransport + TransportInterface + Adapter)
├── Persistence/  ListingPersister (écriture), OpportunityRepository (lecture accueil)
├── Models/       (vide — réservé)
├── Services/     (vide — réservé)
└── Controllers/  (vide — réservé)
config/           database.php, ebay.php (charge .env)
database/
└── migrations/   fichiers .sql horodatés, suivis via schema_migrations
public/
├── index.php     page unique, habillée charte V1
├── css/          trouvailles.css (pack de charte, intact), app.css (glue de mise en page)
└── assets/       logo/, icons/, illustrations/, patterns/, ui/ (SVG, pack de charte)
tools/            migrate.php (lanceur de migrations)
tests/            TestRunner.php, assertions.php, run.php (schéma),
                   run_leboncoin_adapter.php, run_vinted_adapter.php,
                   run_vinted_transport.php, run_ebay_adapter.php,
                   run_listing_persister.php, run_opportunity_repository.php,
                   Support/FixtureHttpClient.php, fixtures/*.json
docs/             TRV-001-C.md, TRV-002.md, AV-UI-001.md, TRV-UI-002.md,
                   TRV-002-A/B-*.md (rapports de mission), brand-v1/ (charte)
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
- Transfert : `lftp` manuel (mirror du dossier `public/` vers le docroot et
  de `app/`+`config/`+`tools/`+`database/` vers `trouvailles-app-prive/`),
  aucun script conservé dans le dépôt à ce jour
