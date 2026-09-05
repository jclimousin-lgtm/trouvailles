<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Ebay;

use Trouvailles\Http\HttpClientInterface;
use Trouvailles\Sources\MarketplaceAdapterInterface;
use Trouvailles\Sources\NormalizedListing;

/**
 * TRV-002 §13 — adapter eBay : convertit les réponses d'EbayClient
 * (Browse API officielle) vers NormalizedListing. Mapping des champs
 * confirmé par la documentation officielle pour itemId/itemWebUrl ; les
 * autres noms (price.value/currency, condition, categories, seller,
 * itemLocation, shippingOptions, buyingOptions) proviennent d'une
 * connaissance stable et documentée de cette API mais n'ont PAS pu être
 * re-vérifiés champ par champ pendant cette mission (doc structurée eBay
 * inaccessible en lecture directe) — à confirmer lors de la vérification
 * réelle si des identifiants sont disponibles (voir docs/TRV-002.md).
 *
 * `buyingOptions` détermine seul `priceMechanism` (FIXED_PRICE/
 * CLASSIFIED_AD → fixed, AUCTION → auction) — c'est la seule des trois
 * marketplaces où une enchère active est possible (§9).
 */
final class EbayAdapter implements MarketplaceAdapterInterface
{
    private const DEFAULT_LIMIT = 50;

    private readonly EbayClient $client;

    public function __construct(array $config = [], ?HttpClientInterface $http = null)
    {
        $this->client = EbayClient::fromConfig($config, $http);
    }

    /**
     * @param array<string,mixed> $criteria q?, category_ids?, filter?, max_pages? (défaut 1), limit? (défaut 50)
     * @return list<NormalizedListing>
     */
    public function search(array $criteria): array
    {
        $limit = (int) ($criteria['limit'] ?? self::DEFAULT_LIMIT);
        $maxPages = max(1, (int) ($criteria['max_pages'] ?? 1));

        $listings = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $offset = $page * $limit;
            try {
                $raw = $this->client->searchPage($criteria, $limit, $offset);
            } catch (\Throwable $e) {
                if ($page === 0) {
                    throw $e;
                }
                error_log('[trouvailles][ebay] pagination interrompue offset ' . $offset . ' : ' . $e->getMessage());
                break;
            }

            $items = $raw['itemSummaries'] ?? [];
            if (!is_array($items)) {
                error_log('[trouvailles][ebay] page offset ' . $offset . ' : champ "itemSummaries" absent ou malformé');
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $listing = $this->mapItem($item);
                if ($listing !== null) {
                    $listings[] = $listing;
                }
            }

            if (count($items) < $limit) {
                break;
            }
        }

        return $listings;
    }

    /** @param array<string,mixed> $item */
    private function mapItem(array $item): ?NormalizedListing
    {
        $externalId = isset($item['itemId']) ? (string) $item['itemId'] : null;
        $url = isset($item['itemWebUrl']) ? (string) $item['itemWebUrl'] : null;

        if ($externalId === null || $url === null) {
            error_log('[trouvailles][ebay] annonce ignorée : itemId ou itemWebUrl absent');
            return null;
        }

        $price = $item['price'] ?? null;
        $askingPrice = is_array($price) && isset($price['value']) && is_numeric($price['value'])
            ? (float) $price['value']
            : null;
        $askingCurrency = is_array($price) && isset($price['currency'])
            ? (string) $price['currency']
            : null;

        $categories = $item['categories'] ?? null;
        $category = null;
        if (is_array($categories) && isset($categories[0]['categoryName'])) {
            $category = (string) $categories[0]['categoryName'];
        }

        $itemLocation = $item['itemLocation'] ?? null;
        $location = null;
        if (is_array($itemLocation)) {
            $location = implode(', ', array_filter([
                $itemLocation['city'] ?? null,
                $itemLocation['country'] ?? null,
            ]));
            $location = $location === '' ? null : $location;
        }

        $shippingOptions = $item['shippingOptions'] ?? null;
        $shippingPrice = null;
        if (is_array($shippingOptions) && isset($shippingOptions[0]['shippingCost']['value'])
            && is_numeric($shippingOptions[0]['shippingCost']['value'])) {
            $shippingPrice = (float) $shippingOptions[0]['shippingCost']['value'];
        }

        $buyingOptions = $item['buyingOptions'] ?? [];
        $priceMechanism = (is_array($buyingOptions) && in_array('AUCTION', $buyingOptions, true))
            ? NormalizedListing::PRICE_MECHANISM_AUCTION
            : NormalizedListing::PRICE_MECHANISM_FIXED;

        return new NormalizedListing(
            source: 'ebay',
            externalId: $externalId,
            url: $url,
            title: isset($item['title']) ? (string) $item['title'] : null,
            description: isset($item['shortDescription']) ? (string) $item['shortDescription'] : null,
            brand: null, // non exposé par item_summary (nécessiterait getItem détaillé, hors périmètre)
            category: $category,
            condition: isset($item['condition']) ? (string) $item['condition'] : null,
            askingPrice: $askingPrice,
            askingCurrency: $askingCurrency,
            shippingPrice: $shippingPrice,
            location: $location,
            sellerType: null, // non distingué (particulier/pro) par item_summary — jamais inventé
            publishedAt: isset($item['itemCreationDate']) ? self::normalizePublishedAt((string) $item['itemCreationDate']) : null,
            priceMechanism: $priceMechanism,
        );
    }

    /**
     * TRV-008 — eBay renvoie itemCreationDate au format ISO 8601
     * (ex. "2026-09-05T15:53:03.000Z"), incompatible avec la colonne
     * DATETIME MySQL de listings.published_at (constaté en conditions
     * réelles : rejeté par un mode SQL strict local, silencieusement
     * altéré en production sans mode strict — bug pré-existant, jamais
     * corrigé avant TRV-008 faute d'avoir persisté de vraies données
     * eBay production auparavant). Conversion explicite ; une date
     * illisible reste `null`, jamais une date inventée.
     */
    private static function normalizePublishedAt(string $iso): ?string
    {
        try {
            return (new \DateTimeImmutable($iso))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}
