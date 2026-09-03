-- TRV-001-C — product_attribute : attributs extensibles d'un produit
-- canonique (clé/valeur), pour éviter une colonne SQL par caractéristique.
-- `key` est un mot réservé MySQL/MariaDB, toujours utilisé entre
-- backticks. FK vers products en CASCADE (seule table de ce schéma dans ce
-- cas) : un attribut n'a aucun sens ni valeur de traçabilité indépendamment
-- de son produit — contrairement aux observations/valorisations, qui
-- restent en RESTRICT (voir les migrations suivantes).

CREATE TABLE IF NOT EXISTS product_attributes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    `key` VARCHAR(100) NOT NULL,
    value VARCHAR(1000) NOT NULL,
    normalized_value VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_product_attributes_product_key (product_id, `key`),
    KEY idx_product_attributes_normalized_value (normalized_value),
    CONSTRAINT fk_product_attributes_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
