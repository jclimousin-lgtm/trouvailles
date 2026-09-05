-- kind: data
-- TRV-009 — ajoute la source Etsy (API officielle Open API v3), même
-- convention que la migration initiale (§18 du modèle) : aucune fausse
-- annonce, aucune donnée de valorisation synthétique.
-- type='marketplace', comme les trois sources existantes.

INSERT INTO sources (code, name, type, country, active)
VALUES
    ('etsy', 'Etsy', 'marketplace', NULL, 1)
ON DUPLICATE KEY UPDATE code = code;
