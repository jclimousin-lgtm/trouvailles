<?php

declare(strict_types=1);

namespace Trouvailles\Pricing;

use PDO;
use Trouvailles\Core\Database;

/**
 * TRV-004 — étape 3 du moteur de pricing : détecte les bonnes affaires
 * (`opportunities`) parmi les listings actifs déjà appariés à un produit
 * dont la dernière valorisation est `valid` (jamais `thin_evidence`/
 * `insufficient_evidence`, quel que soit le rabais apparent — exigence
 * explicite du mandat).
 *
 * `$minDiscount` est TOUJOURS fourni explicitement par l'appelant, jamais
 * de valeur par défaut (voir `opportunities.min_discount` en base, §14 du
 * modèle : "aucun modèle utilisateur n'existe, min_discount n'est rattaché
 * à aucune préférence stockée").
 */
final class OpportunityDetector
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function default(): self
    {
        return new self(Database::connection());
    }

    /**
     * @return array{scanned:int, created:int, skipped:int}
     */
    public function detect(float $minDiscount): array
    {
        $counts = ['scanned' => 0, 'created' => 0, 'skipped' => 0];

        $stmt = $this->pdo->query(
            "SELECT
                l.id AS listing_id,
                l.asking_price,
                l.asking_currency,
                lp.product_id,
                mv.id AS valuation_id,
                mv.value_central,
                mv.currency AS valuation_currency,
                mv.confidence_score
             FROM listings l
             JOIN listing_products lp ON lp.listing_id = l.id
             JOIN market_valuations mv ON mv.id = (
                 SELECT mv2.id FROM market_valuations mv2
                 WHERE mv2.product_id = lp.product_id
                 ORDER BY mv2.created_at DESC
                 LIMIT 1
             )
             WHERE l.status = 'active'
               AND l.asking_price IS NOT NULL
               AND l.asking_currency IS NOT NULL
               AND mv.valuation_status = 'valid'"
        );

        foreach ($stmt as $row) {
            $counts['scanned']++;

            if ($row['asking_currency'] !== $row['valuation_currency']) {
                // Devise différente entre l'annonce et la valorisation :
                // jamais de conversion inventée, on ignore ce candidat.
                $counts['skipped']++;
                continue;
            }

            if ($this->alreadyDetected((int) $row['listing_id'], (int) $row['valuation_id'])) {
                $counts['skipped']++;
                continue;
            }

            $askingPrice = (float) $row['asking_price'];
            $marketValue = (float) $row['value_central'];
            $discountPercentage = self::calculateDiscount($askingPrice, $marketValue);

            if ($discountPercentage < $minDiscount) {
                $counts['skipped']++;
                continue;
            }

            $this->createOpportunity(
                (int) $row['listing_id'],
                (int) $row['valuation_id'],
                $askingPrice,
                $marketValue,
                $discountPercentage,
                $row['confidence_score'] !== null ? (float) $row['confidence_score'] : null,
                $minDiscount
            );
            $counts['created']++;
        }

        return $counts;
    }

    /**
     * TRV-008 — lecture seule : décote actuelle (dernière valorisation
     * `valid` uniquement) pour un ensemble de listing_id donné, sans
     * jamais écrire dans `opportunities`. Utilisé par public/chasses.php
     * pour annoter les résultats d'UNE recherche précise sans dépendre de
     * l'historique d'exécutions précédentes de detect() — un seuil
     * différent utilisé hier (min_discount déjà enregistré sur une ligne
     * existante) ne doit jamais fausser l'affichage d'une recherche
     * d'aujourd'hui avec un seuil différent : ici, aucun seuil n'est
     * appliqué, seule la décote réelle est renvoyée, à l'appelant de
     * comparer à son propre seuil courant.
     *
     * Mêmes règles d'éligibilité que detect() (devise cohérente,
     * valorisation `valid` uniquement) — jamais de filtre `l.status`
     * global ici, seuls les listing_id demandés sont considérés.
     *
     * @param list<int> $listingIds
     * @return array<int, array{market_value:float, discount_percentage:float, confidence_score:?float}>
     *   indexé par listing_id — un listing absent du résultat n'a
     *   simplement aucune valorisation `valid` exploitable (jamais 0
     *   fabriqué).
     */
    public function previewForListings(array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($listingIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT
                l.id AS listing_id,
                l.asking_price,
                l.asking_currency,
                lp.product_id,
                mv.value_central,
                mv.currency AS valuation_currency,
                mv.confidence_score
             FROM listings l
             JOIN listing_products lp ON lp.listing_id = l.id
             JOIN market_valuations mv ON mv.id = (
                 SELECT mv2.id FROM market_valuations mv2
                 WHERE mv2.product_id = lp.product_id
                 ORDER BY mv2.created_at DESC
                 LIMIT 1
             )
             WHERE l.id IN ({$placeholders})
               AND l.asking_price IS NOT NULL
               AND l.asking_currency IS NOT NULL
               AND mv.valuation_status = 'valid'"
        );
        $stmt->execute($listingIds);

        $result = [];
        foreach ($stmt as $row) {
            if ($row['asking_currency'] !== $row['valuation_currency']) {
                continue; // devise différente : jamais de conversion inventée, ignoré
            }

            $marketValue = (float) $row['value_central'];
            $result[(int) $row['listing_id']] = [
                'market_value' => $marketValue,
                'discount_percentage' => self::calculateDiscount((float) $row['asking_price'], $marketValue),
                'confidence_score' => $row['confidence_score'] !== null ? (float) $row['confidence_score'] : null,
            ];
        }

        return $result;
    }

    private static function calculateDiscount(float $askingPrice, float $marketValue): float
    {
        return $marketValue > 0.0
            ? round((($marketValue - $askingPrice) / $marketValue) * 100, 2)
            : 0.0;
    }

    private function alreadyDetected(int $listingId, int $valuationId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM opportunities WHERE listing_id = :listing_id AND valuation_id = :valuation_id LIMIT 1'
        );
        $stmt->execute(['listing_id' => $listingId, 'valuation_id' => $valuationId]);

        return $stmt->fetch() !== false;
    }

    private function createOpportunity(
        int $listingId,
        int $valuationId,
        float $askingPrice,
        float $marketValue,
        float $discountPercentage,
        ?float $confidenceScore,
        float $minDiscount
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO opportunities
                (listing_id, valuation_id, asking_price, market_value, discount_percentage,
                 confidence_score, min_discount, status, detected_at)
             VALUES
                (:listing_id, :valuation_id, :asking_price, :market_value, :discount_percentage,
                 :confidence_score, :min_discount, \'detected\', NOW())'
        );
        $stmt->execute([
            'listing_id' => $listingId,
            'valuation_id' => $valuationId,
            'asking_price' => $askingPrice,
            'market_value' => $marketValue,
            'discount_percentage' => $discountPercentage,
            'confidence_score' => $confidenceScore,
            'min_discount' => $minDiscount,
        ]);
    }
}
