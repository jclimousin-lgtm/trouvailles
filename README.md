# Trouvailles

Squelette initial : structure applicative minimale, connexion PDO/MariaDB.
Aucune logique métier — la vocation fonctionnelle reste à préciser.

## Structure

```
app/
├── Core/         Database.php (PDO singleton), Env.php (.env), autoload.php
├── Models/       (vide — réservé)
├── Services/     (vide — réservé)
└── Controllers/  (vide — réservé)
config/           config/database.php (charge .env)
public/           docroot (index.php)
scripts/sql/      (vide — réservé aux futures migrations)
docs/             (vide — réservé)
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
