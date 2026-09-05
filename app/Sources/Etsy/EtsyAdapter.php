<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Etsy;

use Trouvailles\Http\HttpClientInterface;
use Trouvailles\Sources\MarketplaceAdapterInterface;
use Trouvailles\Sources\NormalizedListing;

/**
 * TRV-009 — adapter Etsy : convertit les réponses d'EtsyClient
 * (`listings/active`) vers NormalizedListing. Mapping vérifié directement
 * sur le schéma `ShopListing` de la spécification OpenAPI officielle
 * d'Etsy, pas deviné.
 *
 * Champs structurellement absents de cette réponse, jamais inventés :
 * `brand` (aucun champ équivalent), `category` (seul `taxonomy_id`
 * numérique disponible, sans nom — un second appel serait nécessaire,
 * hors périmètre), `condition` (Etsy n'a pas de notion neuf/occasion —
 * `who_made`/`when_made` ne sont pas un équivalent honnête),
 * `shippingPrice` (absent de cette réponse précise), `location` et
 * `sellerType` (aucun champ équivalent pour une boutique Etsy).
 *
 * Devise potentiellement différente d'une annonce à l'autre (chaque
 * boutique Etsy fixe sa propre devise, contrairement à eBay où un seul
 * `marketplace_id` implique une devise cohérente) — `ValuationEngine`
 * gère déjà ce cas nativement (devise majoritaire retenue par produit,
 * les autres rejetées), aucune adaptation du moteur de pricing requise.
 */
final class EtsyAdapter implements MarketplaceAdapterInterface
{
    private const DEFAULT_LIMIT = 25; // défaut natif d'Etsy (pas 50 comme eBay)

    private readonly EtsyClient $client;

    public function __construct(array $config = [], ?HttpClientInterface $http = null)
    {
        $this->client = EtsyClient::fromConfig($config, $http);
    }

    /**
     * @param array<string,mixed> $criteria keywords?, min_price?, max_price?, max_pages? (défaut 1), limit? (défaut 25)
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
                error_log('[trouvailles][etsy] pagination interrompue offset ' . $offset . ' : ' . $e->getMessage());
                break;
            }

            $items = $raw['results'] ?? [];
            if (!is_array($items)) {
                error_log('[trouvailles][etsy] page offset ' . $offset . ' : champ "results" absent ou malformé');
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
        $externalId = isset($item['listing_id']) ? (string) $item['listing_id'] : null;
        $url = isset($item['url']) ? (string) $item['url'] : null;

        if ($externalId === null || $url === null) {
            error_log('[trouvailles][etsy] annonce ignorée : listing_id ou url absent');
            return null;
        }

        $price = $item['price'] ?? null;
        $askingPrice = null;
        $askingCurrency = null;
        if (is_array($price) && isset($price['amount'], $price['divisor']) && is_numeric($price['amount']) && is_numeric($price['divisor']) && (float) $price['divisor'] > 0.0) {
            $askingPrice = (float) $price['amount'] / (float) $price['divisor'];
            $askingCurrency = isset($price['currency_code']) ? (string) $price['currency_code'] : null;
        }

        return new NormalizedListing(
            source: 'etsy',
            externalId: $externalId,
            url: $url,
            title: isset($item['title']) ? (string) $item['title'] : null,
            description: isset($item['description']) ? (string) $item['description'] : null,
            brand: null, // aucun champ équivalent dans cette réponse
            category: null, // seul taxonomy_id numérique disponible, sans nom (hors périmètre)
            condition: null, // pas de notion neuf/occasion chez Etsy
            askingPrice: $askingPrice,
            askingCurrency: $askingCurrency,
            shippingPrice: null, // absent de cette réponse
            location: null, // aucun champ équivalent
            sellerType: null, // aucun champ équivalent
            publishedAt: isset($item['created_timestamp']) && is_numeric($item['created_timestamp'])
                ? self::normalizeTimestamp((int) $item['created_timestamp'])
                : null,
            priceMechanism: NormalizedListing::PRICE_MECHANISM_FIXED, // Etsy ne propose pas d'enchères
        );
    }

    /**
     * TRV-009 — created_timestamp est en epoch secondes ; converti
     * explicitement au format DATETIME MySQL dès l'écriture de ce code
     * (leçon TRV-008 : le même oubli sur eBay avait cassé la persistance
     * en conditions réelles, jamais reproduit ici).
     */
    private static function normalizeTimestamp(int $epochSeconds): ?string
    {
        try {
            return (new \DateTimeImmutable('@' . $epochSeconds))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}
