# Trouvailles

Schéma SQL du modèle de données (TRV-001-C) + adapters marketplace
(TRV-002) : Leboncoin, Vinted, eBay (Browse API officielle) alimentent le
même pipeline commun (SourceManager → Adapter → NormalizedListing →
ListingPersister) vers `listings`/`price_observations`. Aucun matching
produit, calcul de valeur de marché ou moteur d'opportunité — voir
`docs/TRV-001-C.md` et `docs/TRV-002.md`.

Habillage graphique V1 (AV-UI-001) intégré : palette/typographie/composants
de `docs/brand-v1/` chargés dans `public/index.php` (voir `docs/AV-UI-001.md`).

## Structure

```
app/
├── Core/         Database.php (PDO singleton), Env.php (.env), autoload.php
├── Http/         HttpClientInterface, CurlHttpClient (aucun contournement anti-bot)
├── Sources/      NormalizedListing, MarketplaceAdapterInterface, SourceManager,
│                 Leboncoin/, Vinted/, Ebay/ (Client + Adapter par marketplace)
├── Persistence/  ListingPersister (listings + price_observations)
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
                   run_ebay_adapter.php, run_listing_persister.php,
                   Support/FixtureHttpClient.php, fixtures/*.json
docs/             TRV-001-C.md, TRV-002.md, AV-UI-001.md (rapports de mission),
                   brand-v1/ (README + usage.md du pack de charte fourni)
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

- Sous-domaine : `trouvailles.serviceproi.fr`
- Base MySQL prod : `nare8592_trouvailles`
- Identifiants (LOCAL + PROD) : `~/.config/o2switch/trouvailles-db.env`
  (hors dépôt, chmod 600), complète `~/.config/o2switch/nare8592.env`
  (FTP/cPanel génériques du compte, communs à tous les projets)
- Config prod hors docroot : `trouvailles-config-prive/db.php` (sibling
  du docroot sur le serveur, jamais dans le dépôt) — même convention que
  juridico/poesie-site/convergences
- Transfert : `scripts/deploy.sh` (lftp), voir le script pour le détail
