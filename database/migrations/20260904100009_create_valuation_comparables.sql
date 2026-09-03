-- TRV-001-C — valuation_comparable : traçabilité des observations utilisées
-- (ou explicitement écartées) par une valorisation. Les comparables
-- rejetés ne sont jamais supprimés (rejection_reason les documente) —
-- append-only, pas de updated_at.

CREATE TABLE IF NOT EXISTS valuation_comparables (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    valuation_id INT UNSIGNED NOT NULL,
    price_observation_id INT UNSIGNED NOT NULL,
    similarity_score DECIMAL(8,6) NULL,
    acceptance_status ENUM('accepted', 'rejected') NOT NULL,
    rejection_reason VARCHAR(255) NULL,
    weight DECIMAL(8,6) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_valuation_comparables_valuation (valuation_id),
    KEY idx_valuation_comparables_price_observation (price_observation_id),
    CONSTRAINT fk_valuation_comparables_valuation
        FOREIGN KEY (valuation_id) REFERENCES market_valuations (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_valuation_comparables_price_observation
        FOREIGN KEY (price_observation_id) REFERENCES price_observations (id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_valuation_comparables_similarity
        CHECK (similarity_score IS NULL OR (similarity_score >= 0 AND similarity_score <= 1)),
    CONSTRAINT chk_valuation_comparables_weight
        CHECK (weight IS NULL OR weight >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
