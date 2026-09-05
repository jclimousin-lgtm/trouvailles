<?php

declare(strict_types=1);

namespace Trouvailles\Pricing;

use PDO;
use Trouvailles\Core\Database;

/**
 * TRV-004 — étape 2 du moteur de pricing : calcule une valorisation de
 * marché (`market_valuations`) pour un produit à partir de ses
 * `price_observations` comparables, avec traçabilité complète
 * (`valuation_comparables`, accepté ou rejeté avec raison).
 *
 * Comparables éligibles : uniquement `evidence_type IN
 * ('active_fixed_price', 'active_auction')` — aucune détection de vente
 * n'existe à ce jour (limite documentée : une valorisation fondée sur des
 * prix demandés, jamais vendus, tend à surestimer la vraie valeur de
 * marché ; à corriger le jour où `completed_sale`/`likely_sale` seront
 * alimentés par une autre mission).
 *
 * Fenêtre temporelle : MAX_COMPARABLE_AGE_DAYS (30 jours, heuristique
 * documentée, ajustable) — un prix affiché il y a plusieurs mois n'est
 * plus un signal de marché fiable pour un bien d'occasion.
 *
 * Un seul comparable par listing (le plus récent dans la fenêtre) : les
 * observations plus anciennes du même listing ne sont pas des points de
 * marché indépendants (même objet physique) — comptées, elles biaiseraient
 * artificiellement `comparable_count` et la dispersion.
 *
 * Devise : pas de conversion (aucun service de change disponible, en
 * inventer une serait fabriquer une donnée). La devise majoritaire parmi
 * les comparables retenus devient la devise de la valorisation ; les
 * autres sont rejetés (`currency_mismatch`), jamais convertis.
 *
 * Statistique : médiane (value_central) + P25/P75 (value_low/high), pas la
 * moyenne — robuste aux valeurs aberrantes (annonces mal catégorisées
 * passées le filtre fuzzy de ProductMatcher), contrairement à la moyenne.
 * `amount` seul, jamais fusionné avec `shipping_amount` (le schéma les
 * sépare délibérément — les fusionner inventerait une métrique non
 * demandée par le modèle).
 *
 * Seuils (heuristiques documentés, non calibrés statistiquement,
 * ajustables) : 0 comparable -> aucune ligne créée (rien à écrire) ; 1 ->
 * insufficient_evidence ; 2-4 -> thin_evidence ; >=5 -> valid.
 *
 * `liquidity_score` toujours NULL (hors périmètre v1 — voir docs/TRV-004.md).
 */
final class ValuationEngine
{
    public const METHOD_VERSION = 'trv-004-v1';
    public const MAX_COMPARABLE_AGE_DAYS = 30;
    public const MIN_COMPARABLES_VALID = 5;
    public const MIN_COMPARABLES_THIN = 2;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function default(): self
    {
        return new self(Database::connection());
    }

    /**
     * @return array{valuation_id: ?int, status: ?string, comparable_count: int}
     */
    public function valuateProduct(int $productId): array
    {
        $observations = $this->loadEligibleObservations($productId);

        if ($observations === []) {
            return ['valuation_id' => null, 'status' => null, 'comparable_count' => 0];
        }

        // Fenêtre temporelle appliquée ici (pas en SQL) : contrairement au
        // filtre evidence_type (invisible dès la requête, jamais tracé),
        // une observation hors fenêtre doit être tracée dans
        // valuation_comparables avec sa raison explicite.
        $cutoff = (new \DateTimeImmutable())->modify('-' . self::MAX_COMPARABLE_AGE_DAYS . ' days');
        $inWindow = [];
        $outOfWindow = [];
        foreach ($observations as $obs) {
            if (new \DateTimeImmutable($obs['observed_at']) < $cutoff) {
                $outOfWindow[] = $obs;
                continue;
            }
            $inWindow[] = $obs;
        }

        // Un seul comparable par listing : le plus récent dans la fenêtre.
        // $observations est trié par observed_at DESC (voir la requête),
        // donc la première occurrence par listing_id est la plus récente.
        $bestPerListing = [];
        $superseded = [];
        foreach ($inWindow as $obs) {
            $listingId = (int) $obs['listing_id'];
            if (isset($bestPerListing[$listingId])) {
                $superseded[] = $obs;
                continue;
            }
            $bestPerListing[$listingId] = $obs;
        }

        // Devise majoritaire parmi les candidats retenus par listing.
        $currencyCounts = [];
        foreach ($bestPerListing as $obs) {
            $currencyCounts[$obs['currency']] = ($currencyCounts[$obs['currency']] ?? 0) + 1;
        }
        arsort($currencyCounts);
        $majorityCurrency = array_key_first($currencyCounts);

        $accepted = [];
        $rejected = [];

        foreach ($bestPerListing as $obs) {
            if ($obs['currency'] !== $majorityCurrency) {
                $rejected[] = ['obs' => $obs, 'reason' => 'currency_mismatch'];
                continue;
            }
            $accepted[] = $obs;
        }

        foreach ($superseded as $obs) {
            $rejected[] = ['obs' => $obs, 'reason' => 'superseded_by_newer_observation_same_listing'];
        }

        foreach ($outOfWindow as $obs) {
            $rejected[] = ['obs' => $obs, 'reason' => 'outside_time_window'];
        }

        $comparableCount = count($accepted);

        if ($comparableCount === 0) {
            // Tous les comparables potentiels rejetés (ex. devise unique
            // minoritaire sans consensus) : rien à écrire, pas de zéro fabriqué.
            // Rien à tracer non plus dans valuation_comparables : son FK
            // valuation_id est NOT NULL, une ligne y est structurellement
            // impossible sans valorisation créée (limite connue, documentée).
            return ['valuation_id' => null, 'status' => null, 'comparable_count' => 0];
        }

        $amounts = array_map(static fn (array $o) => (float) $o['amount'], $accepted);
        sort($amounts);

        $valueCentral = self::percentile($amounts, 0.5);
        $valueLow = self::percentile($amounts, 0.25);
        $valueHigh = self::percentile($amounts, 0.75);

        $status = match (true) {
            $comparableCount >= self::MIN_COMPARABLES_VALID => 'valid',
            $comparableCount >= self::MIN_COMPARABLES_THIN => 'thin_evidence',
            default => 'insufficient_evidence',
        };

        $dispersion = $valueCentral > 0.0 ? min(1.0, ($valueHigh - $valueLow) / $valueCentral) : 1.0;
        $volumeRatio = min(1.0, $comparableCount / self::MIN_COMPARABLES_VALID);
        $confidenceScore = max(0.0, min(1.0, $volumeRatio * (1 - $dispersion)));
        $confidenceLabel = match (true) {
            $confidenceScore >= 0.7 => 'high',
            $confidenceScore >= 0.4 => 'medium',
            default => 'low',
        };

        $valuationId = $this->insertValuation(
            $productId,
            $valueLow,
            $valueCentral,
            $valueHigh,
            $majorityCurrency,
            $confidenceScore,
            $confidenceLabel,
            $comparableCount,
            $status
        );

        $this->recordComparables($valuationId, $accepted, $rejected);

        return ['valuation_id' => $valuationId, 'status' => $status, 'comparable_count' => $comparableCount];
    }

    /**
     * @return list<array{product_id:int, valuation_id:?int, status:?string}>
     */
    public function valuateAllProducts(): array
    {
        $stmt = $this->pdo->query(
            'SELECT DISTINCT product_id FROM price_observations WHERE product_id IS NOT NULL'
        );
        $productIds = array_map(static fn (array $r) => (int) $r['product_id'], $stmt->fetchAll());

        $results = [];
        foreach ($productIds as $productId) {
            $outcome = $this->valuateProduct($productId);
            $results[] = [
                'product_id' => $productId,
                'valuation_id' => $outcome['valuation_id'],
                'status' => $outcome['status'],
            ];
        }

        return $results;
    }

    /**
     * Filtre uniquement par evidence_type ici (invisible dès la requête,
     * jamais tracé) — la fenêtre temporelle est appliquée en PHP dans
     * valuateProduct() car, contrairement à evidence_type, une observation
     * hors fenêtre doit être tracée dans valuation_comparables (rejetée
     * avec raison), pas simplement absente de la requête.
     *
     * @return list<array{price_observation_id:int, listing_id:int, amount:string, currency:string, match_confidence:string, observed_at:string}>
     */
    private function loadEligibleObservations(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT po.id AS price_observation_id, po.listing_id, po.amount, po.currency,
                    po.observed_at, lp.match_confidence
             FROM price_observations po
             JOIN listing_products lp ON lp.listing_id = po.listing_id AND lp.product_id = po.product_id
             WHERE po.product_id = :product_id
               AND po.evidence_type IN ('active_fixed_price', 'active_auction')
             ORDER BY po.observed_at DESC"
        );
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param list<float> $sortedValues */
    private static function percentile(array $sortedValues, float $p): float
    {
        $n = count($sortedValues);
        if ($n === 1) {
            return $sortedValues[0];
        }

        $rank = $p * ($n - 1);
        $lowerIndex = (int) floor($rank);
        $upperIndex = (int) ceil($rank);
        $fraction = $rank - $lowerIndex;

        if ($lowerIndex === $upperIndex) {
            return $sortedValues[$lowerIndex];
        }

        return $sortedValues[$lowerIndex] + ($sortedValues[$upperIndex] - $sortedValues[$lowerIndex]) * $fraction;
    }

    private function insertValuation(
        int $productId,
        float $valueLow,
        float $valueCentral,
        float $valueHigh,
        string $currency,
        float $confidenceScore,
        string $confidenceLabel,
        int $comparableCount,
        string $status
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO market_valuations
                (product_id, method_version, value_low, value_central, value_high, currency,
                 confidence_score, confidence_label, comparable_count, sold_comparable_count,
                 active_comparable_count, liquidity_score, valuation_status)
             VALUES
                (:product_id, :method_version, :value_low, :value_central, :value_high, :currency,
                 :confidence_score, :confidence_label, :comparable_count, 0,
                 :active_comparable_count, NULL, :valuation_status)'
        );
        $stmt->execute([
            'product_id' => $productId,
            'method_version' => self::METHOD_VERSION,
            'value_low' => $valueLow,
            'value_central' => $valueCentral,
            'value_high' => $valueHigh,
            'currency' => $currency,
            'confidence_score' => $confidenceScore,
            'confidence_label' => $confidenceLabel,
            'comparable_count' => $comparableCount,
            'active_comparable_count' => $comparableCount,
            'valuation_status' => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<array{price_observation_id:int, listing_id:int, amount:string, currency:string, match_confidence:string}> $accepted
     * @param list<array{obs: array{price_observation_id:int, listing_id:int, amount:string, currency:string, match_confidence:string}, reason:string}> $rejected
     */
    private function recordComparables(?int $valuationId, array $accepted, array $rejected): void
    {
        if ($valuationId === null) {
            // Rien à tracer sans valorisation créée (aucun comparable accepté).
            return;
        }

        $insertAccepted = $this->pdo->prepare(
            'INSERT INTO valuation_comparables
                (valuation_id, price_observation_id, similarity_score, acceptance_status, rejection_reason, weight)
             VALUES (:valuation_id, :price_observation_id, :similarity_score, \'accepted\', NULL, NULL)'
        );
        foreach ($accepted as $obs) {
            $insertAccepted->execute([
                'valuation_id' => $valuationId,
                'price_observation_id' => $obs['price_observation_id'],
                // similarity_score = match_confidence déjà calculé par ProductMatcher
                // (réutilisation d'une donnée existante, pas une fabrication).
                'similarity_score' => $obs['match_confidence'],
            ]);
        }

        $insertRejected = $this->pdo->prepare(
            'INSERT INTO valuation_comparables
                (valuation_id, price_observation_id, similarity_score, acceptance_status, rejection_reason, weight)
             VALUES (:valuation_id, :price_observation_id, :similarity_score, \'rejected\', :reason, NULL)'
        );
        foreach ($rejected as $entry) {
            $insertRejected->execute([
                'valuation_id' => $valuationId,
                'price_observation_id' => $entry['obs']['price_observation_id'],
                'similarity_score' => $entry['obs']['match_confidence'],
                'reason' => $entry['reason'],
            ]);
        }
    }
}
