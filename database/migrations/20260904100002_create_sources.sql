-- TRV-001-C — source : une marketplace ou un fournisseur de données
-- (leboncoin, ebay, vinted...). Désactivable (colonne `active`) sans
-- suppression : aucune ligne dépendante (listings, price_observations...)
-- ne doit jamais perdre sa source pour préserver l'historique.

CREATE TABLE IF NOT EXISTS sources (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type ENUM('marketplace', 'pricing_provider', 'partner_api') NOT NULL DEFAULT 'marketplace',
    country VARCHAR(10) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sources_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
