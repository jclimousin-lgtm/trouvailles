<?php

declare(strict_types=1);

namespace Trouvailles\Persistence;

use PDO;
use Trouvailles\Core\Database;

/**
 * TRV-UI-002 — lecture des opportunités pour l'écran d'accueil. Aucun
 * calcul : `asking_price`, `market_value`, `discount_percentage`
 * (opportunities) et `valuation_status` (market_valuations) sont des
 * colonnes déjà présentes dans le schéma TRV-001-C, jamais recalculées ici
 * (aucun moteur de valorisation, §4 du mandat). Séparation minimale
 * lecture/rendu demandée par §21 — symétrique de ListingPersister (écriture).
 */
final class OpportunityRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function default(): self
    {
        return new self(Database::connection());
    }

    /**
     * `secondes_ecoulees` est calculé par MySQL (`TIMESTAMPDIFF`), jamais en
     * comparant `detected_at` à l'horloge PHP : PHP (UTC, voir Env par
     * défaut) et MySQL (fuseau `SYSTEM` du serveur) peuvent diverger — un
     * calcul PHP naïf a produit un écart erroné pendant les vérifications
     * de cette mission (annonce vieille de 8 min affichée « à l'instant »).
     * Les deux bornes de TIMESTAMPDIFF sont évaluées dans le même moteur/
     * fuseau, donc toujours cohérentes entre elles.
     *
     * @return list<array{
     *     opportunity_id:int, title:?string, brand:?string, url:string,
     *     asking_price:string, market_value:string, discount_percentage:string,
     *     secondes_ecoulees:int, source_code:string, source_name:string,
     *     valuation_status:string
     * }>
     */
    public function findRecent(int $limit = 12): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                o.id AS opportunity_id,
                l.title,
                l.brand,
                l.url,
                o.asking_price,
                o.market_value,
                o.discount_percentage,
                TIMESTAMPDIFF(SECOND, o.detected_at, NOW()) AS secondes_ecoulees,
                s.code AS source_code,
                s.name AS source_name,
                mv.valuation_status
             FROM opportunities o
             JOIN listings l ON l.id = o.listing_id
             JOIN sources s ON s.id = l.source_id
             JOIN market_valuations mv ON mv.id = o.valuation_id
             ORDER BY o.detected_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
