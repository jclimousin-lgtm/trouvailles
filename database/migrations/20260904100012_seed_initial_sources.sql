-- kind: data
-- TRV-001-C — sources nécessaires au fonctionnement initial uniquement
-- (§18 du modèle). Aucune fausse annonce, aucune donnée de valorisation
-- synthétique. type='marketplace' pour les trois (aucun pricing_provider
-- ni partner_api introduit dans cette mission).

INSERT INTO sources (code, name, type, country, active)
VALUES
    ('leboncoin', 'Leboncoin', 'marketplace', 'FR', 1),
    ('ebay', 'eBay', 'marketplace', NULL, 1),
    ('vinted', 'Vinted', 'marketplace', NULL, 1)
ON DUPLICATE KEY UPDATE code = code;
