-- TRV-001-C — product : produit canonique, indépendant des marketplaces.
-- gtin/mpn volontairement nullable (une source ne fournit pas toujours ces
-- identifiants) — indexés (non uniques : aucune contrainte d'unicité sur
-- gtin/mpn n'est demandée par le modèle cible).

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    brand VARCHAR(255) NULL,
    model VARCHAR(255) NULL,
    category VARCHAR(255) NULL,
    gtin VARCHAR(14) NULL,
    mpn VARCHAR(100) NULL,
    canonical_name VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_products_gtin (gtin),
    KEY idx_products_mpn (mpn),
    KEY idx_products_brand_model (brand, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
