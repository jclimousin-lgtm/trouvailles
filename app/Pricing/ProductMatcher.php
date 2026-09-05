<?php

declare(strict_types=1);

namespace Trouvailles\Pricing;

use PDO;
use Trouvailles\Core\Database;

/**
 * TRV-004 — étape 1 du moteur de pricing : apparie chaque
 * `price_observations.product_id IS NULL` à un `products` (existant ou
 * nouvellement créé), en passant par `listing_products` (TRV-001-C).
 *
 * Matching effectué PAR LISTING, jamais par observation : l'identité
 * produit d'une annonce ne change (quasiment) jamais entre deux
 * récupérations de prix — recalculer à chaque observation serait coûteux
 * et pourrait produire des résultats incohérents dans le temps pour le
 * même listing. Un listing déjà lié (`listing_products` existant) réutilise
 * directement son `product_id`, sans jamais recalculer.
 *
 * Algorithme (nouveau listing, sans lien existant) :
 *   1. Titre absent -> aucun signal exploitable (brand est toujours null
 *      pour eBay, item_summary ne l'expose pas) -> ignoré, jamais de
 *      produit fabriqué à partir de rien.
 *   2. Filtre dur : catégories renseignées des deux côtés et différentes
 *      -> candidat écarté (jamais de match forcé à travers des catégories
 *      connues différentes). L'une des deux absente -> pas de filtre.
 *   3. Indice de Jaccard (TitleNormalizer) entre le titre du listing et
 *      `products.canonical_name` de chaque candidat restant.
 *   4. Meilleur score >= MIN_MATCH_CONFIDENCE -> rattaché au produit
 *      existant, `match_confidence` = score réel.
 *   5. Sinon -> nouveau `products` créé à partir de ce listing,
 *      `match_confidence = 1.0` : l'association est vraie par
 *      construction (le produit est *défini* par ce listing), ce n'est
 *      pas un score de similarité inventé. La faible fiabilité d'un
 *      produit à un seul spécimen s'exprime honnêtement à l'étape
 *      suivante (ValuationEngine) via `comparable_count=1` ->
 *      `insufficient_evidence`, pas ici.
 *
 * MIN_MATCH_CONFIDENCE = 0.45 : seuil heuristique documenté, non calibré
 * statistiquement (voir docs/TRV-004.md) — ajustable.
 */
final class ProductMatcher
{
    public const METHOD = 'fuzzy_match';
    public const MIN_MATCH_CONFIDENCE = 0.45;

    /** @var array<int,int> cache listing_id -> product_id résolu pendant ce run */
    private array $resolvedThisRun = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function default(): self
    {
        return new self(Database::connection());
    }

    /**
     * @return array{processed:int, matched_existing:int, created_new:int, skipped_no_signal:int, reused_listing_link:int}
     */
    public function matchPendingObservations(int $limit = 500): array
    {
        $counts = [
            'processed' => 0,
            'matched_existing' => 0,
            'created_new' => 0,
            'skipped_no_signal' => 0,
            'reused_listing_link' => 0,
        ];

        $stmt = $this->pdo->prepare(
            'SELECT id, listing_id FROM price_observations WHERE product_id IS NULL ORDER BY id LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $pending = $stmt->fetchAll();

        foreach ($pending as $row) {
            $poId = (int) $row['id'];
            $listingId = (int) $row['listing_id'];
            ++$counts['processed'];

            $this->resolveOne($poId, $listingId, $counts);
        }

        return $counts;
    }

    /** @param array{processed:int, matched_existing:int, created_new:int, skipped_no_signal:int, reused_listing_link:int} $counts */
    private function resolveOne(int $poId, int $listingId, array &$counts): void
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $existingProductId = $this->findExistingLink($listingId);
            if ($existingProductId !== null) {
                $this->attachObservation($poId, $existingProductId);
                $counts['reused_listing_link']++;
                if ($ownTransaction) {
                    $this->pdo->commit();
                }
                return;
            }

            $listing = $this->loadListing($listingId);
            if ($listing === null || $listing['title'] === null) {
                $counts['skipped_no_signal']++;
                if ($ownTransaction) {
                    $this->pdo->commit();
                }
                return;
            }

            $best = $this->findBestCandidate($listing);

            if ($best !== null && $best['score'] >= self::MIN_MATCH_CONFIDENCE) {
                $this->linkListingToProduct($listingId, (int) $best['product_id'], $best['score']);
                $this->attachObservation($poId, (int) $best['product_id']);
                $this->resolvedThisRun[$listingId] = (int) $best['product_id'];
                $counts['matched_existing']++;
            } else {
                $productId = $this->createProduct($listing);
                $this->linkListingToProduct($listingId, $productId, 1.0);
                $this->attachObservation($poId, $productId);
                $this->resolvedThisRun[$listingId] = $productId;
                $counts['created_new']++;
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function findExistingLink(int $listingId): ?int
    {
        if (isset($this->resolvedThisRun[$listingId])) {
            return $this->resolvedThisRun[$listingId];
        }

        $stmt = $this->pdo->prepare('SELECT product_id FROM listing_products WHERE listing_id = :listing_id LIMIT 1');
        $stmt->execute(['listing_id' => $listingId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $productId = (int) $row['product_id'];
        $this->resolvedThisRun[$listingId] = $productId;

        return $productId;
    }

    /** @return array{title:?string, brand:?string, category:?string}|null */
    private function loadListing(int $listingId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT title, brand, category FROM listings WHERE id = :id');
        $stmt->execute(['id' => $listingId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{title:?string, brand:?string, category:?string} $listing
     * @return array{product_id:int, score:float}|null
     */
    private function findBestCandidate(array $listing): ?array
    {
        $listingTokens = TitleNormalizer::tokens($listing['title']);
        if ($listingTokens === []) {
            return null;
        }

        $stmt = $this->pdo->query('SELECT id, category, canonical_name FROM products');
        $best = null;

        foreach ($stmt as $product) {
            if (
                $listing['category'] !== null
                && $product['category'] !== null
                && $listing['category'] !== $product['category']
            ) {
                continue; // filtre dur : catégories connues différentes, jamais forcé
            }

            $productTokens = TitleNormalizer::tokens($product['canonical_name']);
            $score = TitleNormalizer::jaccard($listingTokens, $productTokens);

            if ($best === null || $score > $best['score']) {
                $best = ['product_id' => (int) $product['id'], 'score' => $score];
            }
        }

        return $best;
    }

    /** @param array{title:?string, brand:?string, category:?string} $listing */
    private function createProduct(array $listing): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (brand, model, category, canonical_name)
             VALUES (:brand, NULL, :category, :canonical_name)'
        );
        $stmt->execute([
            'brand' => $listing['brand'],
            'category' => $listing['category'],
            'canonical_name' => $listing['title'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function linkListingToProduct(int $listingId, int $productId, float $matchConfidence): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO listing_products (listing_id, product_id, match_method, match_confidence, is_variant_exact)
             VALUES (:listing_id, :product_id, :match_method, :match_confidence, 0)'
        );
        $stmt->execute([
            'listing_id' => $listingId,
            'product_id' => $productId,
            'match_method' => self::METHOD,
            'match_confidence' => $matchConfidence,
        ]);
    }

    private function attachObservation(int $poId, int $productId): void
    {
        $stmt = $this->pdo->prepare('UPDATE price_observations SET product_id = :product_id WHERE id = :id');
        $stmt->execute(['product_id' => $productId, 'id' => $poId]);
    }
}
