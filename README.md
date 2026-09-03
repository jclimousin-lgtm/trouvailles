# Trouvailles

Squelette applicatif + schéma SQL du modèle de données (TRV-001-C) :
connexion PDO/MariaDB, migrations versionnées, persistance des entités
source/listing/product/price_observation/market_valuation/opportunity.
Aucune logique métier (scraping, matching, valorisation, notifications) —
voir `docs/TRV-001-C.md` pour le détail du périmètre.

## Structure

```
app/
├── Core/         Database.php (PDO singleton), Env.php (.env), autoload.php
├── Models/       (vide — réservé)
├── Services/     (vide — réservé)
└── Controllers/  (vide — réservé)
config/           config/database.php (charge .env)
database/
└── migrations/   fichiers .sql horodatés, suivis via schema_migrations
public/           docroot (index.php)
tools/            migrate.php (lanceur de migrations)
tests/            TestRunner.php, assertions.php, run.php
docs/             TRV-001-C.md (rapport de mission)
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
