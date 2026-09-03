-- TRV-001-C — price_observation : observation de prix à un instant donné.
-- Table volontairement APPEND-ONLY (voir §14 du modèle : « une observation
-- est immuable conceptuellement ») — aucune colonne updated_at, un
-- changement de prix crée une nouvelle ligne, jamais une mise à jour.
--
-- product_id est nullable : le rattachement listing -> produit canonique
-- (listing_products) peut ne pas encore exister au moment où l'observation
-- est capturée (matching asynchrone, hors périmètre de cette mission) —
-- ni le modèle ni la mission n'imposent explicitement cette nullabilité,
-- c'est une adaptation minimale nécessaire à la cohérence référentielle.
--
-- evidence_type=likely_sale ne doit JAMAIS être requalifié automatiquement
-- en completed_sale (règle impérative du modèle) — invariant applicatif,
-- rien dans ce schéma ne l'autorise ni ne l'empêche mécaniquement, mais
-- aucune logique de ce type n'est implémentée ici (hors périmètre).

CREATE TABLE IF NOT EXISTS price_observations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id INT UNSIGNED NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    price_type ENUM('asking', 'sold', 'auction') NOT NULL,
    observed_at DATETIME NOT NULL,
    `condition` VARCHAR(100) NULL,
    shipping_amount DECIMAL(12,2) NULL,
    evidence_type ENUM(
        'active_fixed_price', 'active_auction', 'completed_sale',
        'likely_sale', 'historical_observation', 'unknown'
    ) NOT NULL DEFAULT 'unknown',
    evidence_confidence DECIMAL(8,6) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_price_observations_listing_observed (listing_id, observed_at),
    KEY idx_price_observations_product_observed (product_id, observed_at),
    KEY idx_price_observations_evidence_type (evidence_type),
    CONSTRAINT fk_price_observations_listing
        FOREIGN KEY (listing_id) REFERENCES listings (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_price_observations_source
        FOREIGN KEY (source_id) REFERENCES sources (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_price_observations_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_price_observations_evidence_confidence
        CHECK (evidence_confidence IS NULL OR (evidence_confidence >= 0 AND evidence_confidence <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
