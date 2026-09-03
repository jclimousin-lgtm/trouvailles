<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Vinted;

use Trouvailles\Http\HttpClientInterface;
use Trouvailles\Sources\MarketplaceAdapterInterface;
use Trouvailles\Sources\NormalizedListing;

/**
 * TRV-002 §12 — adapter Vinted : convertit les réponses de VintedClient
 * vers NormalizedListing. Mapping confirmé par l'inspection de
 * `herissondev/vinted-api-wrapper` et `vlymar1/vinted-api-kit` (`item.py`,
 * identique dans les deux, MIT) :
 *
 *   id → externalId, title → title, url → url,
 *   price.amount/price.currency_code → askingPrice/askingCurrency,
 *   brand_title → brand.
 *
 * Champs NON confirmés par le code inspecté (condition, location,
 * seller_type, description, published_at, category, shipping) : ces deux
 * bibliothèques n'extraient que les champs ci-dessus de la réponse réelle
 * de l'API — le reste peut exister côté Vinted sans être modélisé par ces
 * libs. Lus ici de façon défensive sous des noms de clés plausibles
 * (best-effort, jamais garantis) ; absents → `null`, jamais inventés.
 * `description` n'est disponible, dans les deux dépôts, que via un second
 * appel `/items/{id}/details` — non déclenché ici (un appel par résultat
 * de recherche aurait multiplié le volume de requêtes ; hors périmètre de
 * cette mission), donc laissé `null` depuis la recherche seule.
 */
final class VintedAdapter implements MarketplaceAdapterInterface
{
    private const DEFAULT_PER_PAGE = 20;

    private readonly VintedClient $client;

    public function __construct(?HttpClientInterface $http = null, string $domain = 'vinted.fr')
    {
        $this->client = new VintedClient($http, $domain);
    }

    /**
     * @param array<string,mixed> $criteria search_text?, catalog_ids?, price_from?, price_to?,
     *                                       max_pages? (défaut 1), per_page? (défaut 20)
     * @return list<NormalizedListing>
     */
    public function search(array $criteria): array
    {
        $perPage = (int) ($criteria['per_page'] ?? self::DEFAULT_PER_PAGE);
        $maxPages = max(1, (int) ($criteria['max_pages'] ?? 1));

        $listings = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $raw = $this->client->searchPage($criteria, $page, $perPage);
            } catch (\Throwable $e) {
                if ($page === 1) {
                    throw $e;
                }
                error_log('[trouvailles][vinted] pagination interrompue page ' . $page . ' : ' . $e->getMessage());
                break;
            }

            $items = $raw['items'] ?? [];
            if (!is_array($items)) {
                error_log('[trouvailles][vinted] page ' . $page . ' : champ "items" absent ou malformé');
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

            if (count($items) < $perPage) {
                break;
            }
        }

        return $listings;
    }

    /** @param array<string,mixed> $item */
    private function mapItem(array $item): ?NormalizedListing
    {
        $externalId = isset($item['id']) ? (string) $item['id'] : null;
        $rawUrl = isset($item['url']) ? (string) $item['url'] : null;

        if ($externalId === null || $rawUrl === null) {
            error_log('[trouvailles][vinted] annonce ignorée : id ou url absent');
            return null;
        }

        $url = str_starts_with($rawUrl, 'http') ? $rawUrl : ('https://www.vinted.fr' . $rawUrl);

        $price = $item['price'] ?? null;
        $askingPrice = is_array($price) && isset($price['amount']) && is_numeric($price['amount'])
            ? (float) $price['amount']
            : null;
        $askingCurrency = is_array($price) && isset($price['currency_code'])
            ? (string) $price['currency_code']
            : null;

        $user = $item['user'] ?? null;
        $sellerType = null;
        if (is_array($user) && isset($user['business'])) {
            $sellerType = $user['business'] ? 'professional' : 'private';
        }

        return new NormalizedListing(
            source: 'vinted',
            externalId: $externalId,
            url: $url,
            title: isset($item['title']) ? (string) $item['title'] : null,
            description: isset($item['description']) ? (string) $item['description'] : null,
            brand: isset($item['brand_title']) ? (string) $item['brand_title'] : null,
            category: isset($item['catalog_id']) ? (string) $item['catalog_id'] : null,
            condition: isset($item['status']) ? (string) $item['status'] : null,
            askingPrice: $askingPrice,
            askingCurrency: $askingCurrency,
            shippingPrice: null,
            location: is_array($user) && isset($user['city']) ? (string) $user['city'] : null,
            sellerType: $sellerType,
            publishedAt: isset($item['created_at']) ? (string) $item['created_at'] : null,
            priceMechanism: NormalizedListing::PRICE_MECHANISM_FIXED,
        );
    }
}
