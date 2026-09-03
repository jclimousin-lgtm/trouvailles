-- Table de suivi des migrations (mécanisme repris tel quel de juridico,
-- MIGRATION-001). Doit exister avant toute autre migration : c'est elle qui
-- permet à tools/migrate.php de savoir quels fichiers ont déjà été appliqués.

CREATE TABLE IF NOT EXISTS schema_migrations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
