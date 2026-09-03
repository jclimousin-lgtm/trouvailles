-- TRV-001-C — opportunity : décision dérivée d'une annonce et d'une
-- valorisation. min_discount est la valeur RÉELLEMENT applicable au
-- moment de la détection (snapshot), jamais une constante — aucune valeur
-- par défaut n'est fixée ici, elle doit toujours être fournie explicitement
-- par l'appelant. Aucun modèle utilisateur n'existe dans Trouvailles à ce
-- jour ; min_discount n'est donc rattaché à aucune table de préférence
-- utilisateur (non nécessaire pour cette mission — voir §2/§20 du modèle).
--
-- status : aucune liste de valeurs n'est imposée par le modèle cible
-- (contrairement à listings.status) — VARCHAR libre, sans défaut implicite.

CREATE TABLE IF NOT EXISTS opportunities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id INT UNSIGNED NOT NULL,
    valuation_id INT UNSIGNED NOT NULL,
    asking_price DECIMAL(12,2) NOT NULL,
    market_value DECIMAL(12,2) NOT NULL,
    discount_percentage DECIMAL(6,2) NOT NULL,
    confidence_score DECIMAL(8,6) NULL,
    min_discount DECIMAL(6,2) NOT NULL,
    status VARCHAR(50) NOT NULL,
    detected_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_opportunities_listing (listing_id),
    KEY idx_opportunities_valuation (valuation_id),
    CONSTRAINT fk_opportunities_listing
        FOREIGN KEY (listing_id) REFERENCES listings (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_opportunities_valuation
        FOREIGN KEY (valuation_id) REFERENCES market_valuations (id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_opportunities_confidence
        CHECK (confidence_score IS NULL OR (confidence_score >= 0 AND confidence_score <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
