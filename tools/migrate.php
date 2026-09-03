<?php

declare(strict_types=1);

/**
 * Lanceur de migrations avec suivi (mécanisme repris tel quel de juridico,
 * MIGRATION-001) — pointe sur database/migrations/ de ce dépôt, charge son
 * propre config/database.php et .env.
 *
 * Deux modes :
 *   php tools/migrate.php             — applique les migrations en attente
 *   php tools/migrate.php --bootstrap — enregistre les fichiers déjà présents
 *       comme déjà appliqués, SANS exécuter leur contenu SQL. Réservé à une
 *       base ayant déjà reçu ces migrations par un autre moyen.
 *
 * Usage exclusivement en CLI.
 *
 * Chaque fichier est exécuté en un seul appel PDO::exec() (pas de découpage
 * instruction par instruction sur ';' — un seed pourrait contenir un ';'
 * dans une chaîne de texte).
 */

const ROOT = __DIR__ . '/..';
const MIGRATIONS_DIR = ROOT . '/database/migrations';

require ROOT . '/app/Core/autoload.php';

use Trouvailles\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Ce script ne s'exécute qu'en ligne de commande (php-cli).\n";
    exit(1);
}

$bootstrap = in_array('--bootstrap', $argv, true);

$pdo = Database::connection();
$pdo->setAttribute(PDO::MYSQL_ATTR_MULTI_STATEMENTS, true);

// Auto-suffisant : la table de suivi doit exister avant même de savoir si elle
// est dans la liste des migrations à traiter (elle l'est, en tant que première
// migration horodatée — ce CREATE TABLE IF NOT EXISTS est donc sans effet lors
// de son propre passage normal, il ne sert qu'à amorcer un tout premier lancement).
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration VARCHAR(255) NOT NULL,
        checksum CHAR(64) NOT NULL,
        kind ENUM('schema', 'data') NOT NULL,
        status ENUM('success', 'failed') NOT NULL,
        error_message TEXT NULL,
        duration_ms INT UNSIGNED NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_schema_migrations_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function detecterNature(string $sql): string
{
    $premiereLigne = strtok($sql, "\n") ?: '';
    $marqueur = strtolower(trim($premiereLigne));

    if ($marqueur === '-- kind: data') {
        return 'data';
    }
    if ($marqueur === '-- kind: schema') {
        return 'schema';
    }

    $ddl = '/\b(CREATE|ALTER|DROP)\s+(TABLE|INDEX|VIEW)\b/i';
    return preg_match($ddl, $sql) === 1 ? 'schema' : 'data';
}

function migrationDejaTracee(PDO $pdo, string $nom): array|false
{
    $stmt = $pdo->prepare('SELECT * FROM schema_migrations WHERE migration = :m');
    $stmt->execute(['m' => $nom]);
    $row = $stmt->fetch();
    return $row === false ? false : $row;
}

function enregistrerResultat(
    PDO $pdo,
    string $nom,
    string $checksum,
    string $kind,
    string $status,
    ?string $erreur,
    ?int $dureeMs
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO schema_migrations (migration, checksum, kind, status, error_message, duration_ms)
         VALUES (:migration, :checksum, :kind, :status, :erreur, :duree)'
    );
    $stmt->execute([
        'migration' => $nom,
        'checksum' => $checksum,
        'kind' => $kind,
        'status' => $status,
        'erreur' => $erreur,
        'duree' => $dureeMs,
    ]);
}

$fichiers = glob(MIGRATIONS_DIR . '/*.sql') ?: [];
sort($fichiers, SORT_STRING);

if ($fichiers === []) {
    echo "Aucun fichier .sql trouvé dans " . MIGRATIONS_DIR . "\n";
    exit(1);
}

echo $bootstrap
    ? "Mode BOOTSTRAP — aucune migration ne sera exécutée, seulement enregistrée.\n\n"
    : "Mode normal — application des migrations en attente.\n\n";

foreach ($fichiers as $chemin) {
    $nom = basename($chemin);
    $sql = file_get_contents($chemin);
    if ($sql === false) {
        echo "IMPOSSIBLE DE LIRE {$nom}, arrêt.\n";
        exit(1);
    }
    $checksum = hash('sha256', $sql);
    $kind = detecterNature($sql);
    $existant = migrationDejaTracee($pdo, $nom);

    if ($existant !== false) {
        if ($existant['status'] === 'failed') {
            echo "ARRÊT : {$nom} est marquée en échec dans schema_migrations — intervention manuelle requise.\n";
            exit(1);
        }
        if ($existant['checksum'] !== $checksum) {
            echo "ARRÊT : {$nom} a déjà été appliquée mais son contenu a changé depuis (checksum différent).\n";
            exit(1);
        }
        echo "{$nom} : déjà appliquée, ignorée.\n";
        continue;
    }

    if ($bootstrap) {
        enregistrerResultat($pdo, $nom, $checksum, $kind, 'success', null, null);
        echo "{$nom} : enregistrée comme déjà appliquée ({$kind}), non exécutée.\n";
        continue;
    }

    echo "Application de {$nom} ({$kind})...\n";
    $debut = microtime(true);

    try {
        if ($kind === 'data') {
            $pdo->beginTransaction();
            $pdo->exec($sql);
            $pdo->commit();
        } else {
            // DDL : pas de transaction, MySQL/MariaDB ferait un commit implicite
            // de toute façon (InnoDB, pas de DDL transactionnelle).
            $pdo->exec($sql);
        }
        $dureeMs = (int) round((microtime(true) - $debut) * 1000);
        enregistrerResultat($pdo, $nom, $checksum, $kind, 'success', null, $dureeMs);
        echo "  OK ({$dureeMs} ms)\n";
    } catch (Throwable $e) {
        if ($kind === 'data' && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $dureeMs = (int) round((microtime(true) - $debut) * 1000);
        enregistrerResultat($pdo, $nom, $checksum, $kind, 'failed', $e->getMessage(), $dureeMs);
        echo "  ÉCHEC : " . $e->getMessage() . "\n";
        echo "ARRÊT de la chaîne de migrations.\n";
        exit(1);
    }
}

echo "\nTerminé sans erreur.\n";
exit(0);
