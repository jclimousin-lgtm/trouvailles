<?php

declare(strict_types=1);

namespace Trouvailles\Persistence;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Trouvailles\Core\Database;
use Trouvailles\Sources\NormalizedListing;

/**
 * TRV-002 §8/§9 — persiste un NormalizedListing dans les tables existantes
 * de TRV-001-C (sources/listings/price_observations), sans créer aucune
 * table spécifique marketplace.
 *
 * Dédoublonnage : l'identité d'une annonce est (source_id, external_id) —
 * une même annonce récupérée plusieurs fois met à jour la même ligne
 * `listings`, jamais n'en crée une seconde (§8). Ceci NE s'applique PAS à
 * `price_observations` : chaque appel avec un prix produit une NOUVELLE
 * observation (append-only, §9 — "toute récupération de prix doit pouvoir
 * être historisée"), y compris si le prix est identique à la précédente.
 *
 * Isolation des pannes (§15) : chaque appel à persist() gère sa propre
 * transaction courte (sauf si déjà dans une transaction ouverte par
 * l'appelant, ex. harness de tests) — l'échec d'une annonce ne doit jamais
 * invalider les annonces déjà persistées avant elle dans un même lot.
 */
final class ListingPersister
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function default(): self
    {
        return new self(Database::connection());
    }

    /**
     * @return array{listing_id:int, price_observation_id:?int, created:bool}
     */
    public function persist(NormalizedListing $listing): array
    {
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($listing->askingPrice !== null && $listing->askingCurrency === null) {
                error_log(sprintf(
                    '[trouvailles][persister] prix ignoré : présent (%s) sans devise pour %s/%s',
                    (string) $listing->askingPrice,
                    $listing->source,
                    $listing->externalId
                ));
            }
            $hasUsablePrice = $listing->askingPrice !== null && $listing->askingCurrency !== null;

            $sourceId = $this->resolveSourceId($listing->source);
            [$listingId, $created] = $this->upsertListing($sourceId, $listing, $hasUsablePrice);
            $priceObservationId = $this->recordPriceObservation($listingId, $sourceId, $listing, $hasUsablePrice);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return ['listing_id' => $listingId, 'price_observation_id' => $priceObservationId, 'created' => $created];
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function resolveSourceId(string $sourceCode): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM sources WHERE code = :code');
        $stmt->execute(['code' => $sourceCode]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new InvalidArgumentException(
                "Source inconnue '{$sourceCode}' — elle doit exister dans `sources` avant toute persistance (voir TRV-001-C, seed initial)."
            );
        }

        return (int) $row['id'];
    }

    /**
     * @return array{0:int,1:bool} [listing_id, created]
     */
    private function upsertListing(int $sourceId, NormalizedListing $listing, bool $hasPrice): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM listings WHERE source_id = :source_id AND external_id = :external_id'
        );
        $stmt->execute(['source_id' => $sourceId, 'external_id' => $listing->externalId]);
        $existing = $stmt->fetch();

        if ($existing !== false) {
            $listingId = (int) $existing['id'];
            $stmt = $this->pdo->prepare(
                'UPDATE listings SET
                    url = :url,
                    title = COALESCE(:title, title),
                    description = COALESCE(:description, description),
                    brand = COALESCE(:brand, brand),
                    category = COALESCE(:category, category),
                    `condition` = COALESCE(:condition, `condition`),
                    asking_price = COALESCE(:asking_price, asking_price),
                    asking_currency = COALESCE(:asking_currency, asking_currency),
                    shipping_price = COALESCE(:shipping_price, shipping_price),
                    location = COALESCE(:location, location),
                    seller_type = COALESCE(:seller_type, seller_type),
                    published_at = COALESCE(:published_at, published_at),
                    last_seen_at = NOW(),
                    last_observed_at = IF(:has_price, NOW(), last_observed_at)
                 WHERE id = :id'
            );
            $stmt->execute($this->listingParams($listing, $hasPrice) + ['id' => $listingId]);

            return [$listingId, false];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO listings
                (source_id, external_id, url, title, description, brand, category, `condition`,
                 asking_price, asking_currency, shipping_price, location, seller_type, published_at,
                 first_seen_at, last_seen_at, last_observed_at, status)
             VALUES
                (:source_id, :external_id, :url, :title, :description, :brand, :category, :condition,
                 :asking_price, :asking_currency, :shipping_price, :location, :seller_type, :published_at,
                 NOW(), NOW(), IF(:has_price, NOW(), NULL), \'active\')'
        );
        $stmt->execute($this->listingParams($listing, $hasPrice) + [
            'source_id' => $sourceId,
            'external_id' => $listing->externalId,
        ]);

        return [(int) $this->pdo->lastInsertId(), true];
    }

    /** @return array<string,mixed> */
    private function listingParams(NormalizedListing $listing, bool $hasPrice): array
    {
        return [
            'url' => $listing->url,
            'title' => $listing->title,
            'description' => $listing->description,
            'brand' => $listing->brand,
            'category' => $listing->category,
            'condition' => $listing->condition,
            'asking_price' => $hasPrice ? $listing->askingPrice : null,
            'asking_currency' => $hasPrice ? $listing->askingCurrency : null,
            'shipping_price' => $listing->shippingPrice,
            'location' => $listing->location,
            'seller_type' => $listing->sellerType,
            'published_at' => $listing->publishedAt,
            'has_price' => $hasPrice ? 1 : 0,
        ];
    }

    /**
     * TRV-002 §9 : price_type/evidence_type dérivés de priceMechanism.
     * Aucune observation n'est créée sans prix utilisable (rien à
     * historiser, ou donnée malformée — voir persist()).
     */
    private function recordPriceObservation(int $listingId, int $sourceId, NormalizedListing $listing, bool $hasUsablePrice): ?int
    {
        if (!$hasUsablePrice) {
            return null;
        }

        [$priceType, $evidenceType] = $listing->priceMechanism === NormalizedListing::PRICE_MECHANISM_AUCTION
            ? ['auction', 'active_auction']
            : ['asking', 'active_fixed_price'];

        $stmt = $this->pdo->prepare(
            'INSERT INTO price_observations
                (listing_id, source_id, product_id, amount, currency, price_type, observed_at, `condition`, shipping_amount, evidence_type)
             VALUES
                (:listing_id, :source_id, NULL, :amount, :currency, :price_type, NOW(), :condition, :shipping_amount, :evidence_type)'
        );
        $stmt->execute([
            'listing_id' => $listingId,
            'source_id' => $sourceId,
            'amount' => $listing->askingPrice,
            'currency' => $listing->askingCurrency,
            'price_type' => $priceType,
            'condition' => $listing->condition,
            'shipping_amount' => $listing->shippingPrice,
            'evidence_type' => $evidenceType,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
