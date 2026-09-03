-- TRV-001-C — listing : une annonce telle que publiée par une source.
-- asking_price est EXCLUSIVEMENT le prix demandé par le vendeur, jamais une
-- valeur de marché (voir price_observation/market_valuation pour ça).
-- status n'est jamais déduit automatiquement de la disparition d'une
-- annonce (une disparition n'est pas une preuve de vente) — mis à jour
-- uniquement par la logique applicative, hors périmètre de cette mission.
--
-- FK vers sources en RESTRICT : une source ne doit jamais pouvoir être
-- supprimée tant que des annonces y sont rattachées (elle se désactive via
-- sources.active, jamais supprimée — voir §14 du modèle).

CREATE TABLE IF NOT EXISTS listings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_id INT UNSIGNED NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    title VARCHAR(500) NULL,
    description TEXT NULL,
    brand VARCHAR(255) NULL,
    category VARCHAR(255) NULL,
    `condition` VARCHAR(100) NULL,
    asking_price DECIMAL(12,2) NULL,
    asking_currency CHAR(3) NULL,
    shipping_price DECIMAL(12,2) NULL,
    location VARCHAR(255) NULL,
    seller_type VARCHAR(50) NULL,
    published_at DATETIME NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_observed_at DATETIME NULL,
    status ENUM('active', 'removed', 'expired', 'sold', 'unknown') NOT NULL DEFAULT 'unknown',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_listings_source_external (source_id, external_id),
    KEY idx_listings_status (status),
    KEY idx_listings_brand_category (brand, category),
    CONSTRAINT fk_listings_source
        FOREIGN KEY (source_id) REFERENCES sources (id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_listings_currency_with_price
        CHECK (asking_price IS NULL OR asking_currency IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
