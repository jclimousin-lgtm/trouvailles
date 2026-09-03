-- TRV-001-C — liquidity_observation : stocke les observations nécessaires
-- à une future mesure de liquidité, sans en calculer le score (hors
-- périmètre — voir liquidity_score sur market_valuations, alimenté
-- ailleurs). median_time_to_disappearance : unité laissée à la charge du
-- futur moteur de liquidité, non définie ici.
--
-- currency ajoutée en plus de la liste minimale du modèle cible : median_price
-- est un montant, et §15 du modèle impose explicitement de toujours
-- conserver la devise (« ne jamais supposer EUR ») — ajout minimal
-- nécessaire pour respecter cette règle générale, signalé dans le rapport
-- de mission.

CREATE TABLE IF NOT EXISTS liquidity_observations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    observed_at DATETIME NOT NULL,
    active_count INT UNSIGNED NOT NULL DEFAULT 0,
    recent_sale_count INT UNSIGNED NOT NULL DEFAULT 0,
    median_time_to_disappearance INT UNSIGNED NULL,
    median_price DECIMAL(12,2) NULL,
    currency CHAR(3) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_liquidity_observations_product_observed (product_id, observed_at),
    CONSTRAINT fk_liquidity_observations_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_liquidity_observations_source
        FOREIGN KEY (source_id) REFERENCES sources (id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_liquidity_observations_currency_with_price
        CHECK (median_price IS NULL OR currency IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
