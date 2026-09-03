-- TRV-001-C — market_valuation : estimation de valeur de marché pour un
-- produit, produite par le moteur de valorisation (hors périmètre de cette
-- mission — aucune logique de calcul n'est implémentée ici).
-- Conserve explicitement low/central/high (jamais un prix unique) et
-- method_version. Table append-only comme price_observation : pas de
-- updated_at, une nouvelle exécution du moteur crée une nouvelle ligne.

CREATE TABLE IF NOT EXISTS market_valuations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    method_version VARCHAR(50) NOT NULL,
    value_low DECIMAL(12,2) NOT NULL,
    value_central DECIMAL(12,2) NOT NULL,
    value_high DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    confidence_score DECIMAL(8,6) NULL,
    confidence_label VARCHAR(50) NULL,
    comparable_count INT UNSIGNED NOT NULL DEFAULT 0,
    sold_comparable_count INT UNSIGNED NOT NULL DEFAULT 0,
    active_comparable_count INT UNSIGNED NOT NULL DEFAULT 0,
    liquidity_score DECIMAL(8,6) NULL,
    valuation_status ENUM('valid', 'thin_evidence', 'insufficient_evidence') NOT NULL,
    PRIMARY KEY (id),
    KEY idx_market_valuations_product_created (product_id, created_at),
    CONSTRAINT fk_market_valuations_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_market_valuations_values
        CHECK (value_low <= value_central AND value_central <= value_high),
    CONSTRAINT chk_market_valuations_confidence
        CHECK (confidence_score IS NULL OR (confidence_score >= 0 AND confidence_score <= 1)),
    CONSTRAINT chk_market_valuations_liquidity
        CHECK (liquidity_score IS NULL OR (liquidity_score >= 0 AND liquidity_score <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
