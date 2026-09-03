-- TRV-001-C — listing_product : association listing <-> produit canonique.
-- Pas de colonne id : le modèle cible ne liste pas de champ `id` pour cette
-- table (contrairement à toutes les autres) — une seule ligne par couple
-- (listing_id, product_id), utilisée comme clé primaire composite.
--
-- match_confidence en DECIMAL(8,6), même précision que le score_d5 déjà
-- utilisé dans juridico pour un score normalisé [0,1] — borné par une
-- contrainte CHECK, sans coder les coefficients de ProductNormaliser
-- (méthodologiques, pas des constantes validées pour Trouvailles).

CREATE TABLE IF NOT EXISTS listing_products (
    listing_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    match_method ENUM(
        'gtin_exact', 'mpn_exact', 'brand_model', 'attribute_match',
        'fuzzy_match', 'vision_assisted', 'manual'
    ) NOT NULL,
    match_confidence DECIMAL(8,6) NOT NULL,
    is_variant_exact TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (listing_id, product_id),
    KEY idx_listing_products_product (product_id),
    CONSTRAINT fk_listing_products_listing
        FOREIGN KEY (listing_id) REFERENCES listings (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_listing_products_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_listing_products_confidence
        CHECK (match_confidence >= 0 AND match_confidence <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
