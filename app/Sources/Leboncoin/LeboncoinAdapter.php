<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Leboncoin;

use Trouvailles\Http\HttpClientInterface;
use Trouvailles\Sources\MarketplaceAdapterInterface;
use Trouvailles\Sources\NormalizedListing;

/**
 * TRV-002 §11 — adapter Leboncoin : convertit les réponses de
 * LeboncoinClient vers NormalizedListing. Mapping de champs adapté du
 * modèle `Ad` de `etienne-hd/lbc` (`model/ad.py`, MIT) :
 *
 *   list_id → externalId, url → url, subject → title, body → description,
 *   price_cents/100 → askingPrice, brand → brand, category_id/name →
 *   category, status → condition, location{...} → location,
 *   first_publication_date → publishedAt.
 *
 * Devise toujours 'EUR' : le modèle `Ad` de référence n'expose aucun champ
 * devise (prix en centimes uniquement) — Leboncoin est une marketplace
 * mono-devise par construction, ce n'est pas l'hypothèse générique interdite
 * par §15 (qui vise à ne pas supposer EUR pour TOUTES les marketplaces).
 * `shippingPrice` et un éventuel statut particulier/pro du vendeur ne sont
 * pas confirmés par le modèle de référence inspecté : laissés `null` sauf
 * si `owner.type` est présent dans la réponse réelle (best-effort, non
 * garanti) — jamais inventés.
 *
 * `lbc-finder` (déduplication par fichier JSON local) n'est PAS repris :
 * Trouvailles déduplique via la contrainte UNIQUE(source_id, external_id)
 * déjà en base (TRV-001-C), un second mécanisme fichier serait redondant.
 */
final class LeboncoinAdapter implements MarketplaceAdapterInterface
{
    private const DEFAULT_LIMIT = 35;

    private readonly LeboncoinClient $client;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->client = new LeboncoinClient($http);
    }

    /**
     * @param array<string,mixed> $criteria text?, category?, locations?, price_min?, price_max?,
     *                                       max_pages? (défaut 1), limit? (défaut 35)
     * @return list<NormalizedListing>
     */
    public function search(array $criteria): array
    {
        $limit = (int) ($criteria['limit'] ?? self::DEFAULT_LIMIT);
        $maxPages = max(1, (int) ($criteria['max_pages'] ?? 1));

        $listings = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $raw = $this->client->searchPage($criteria, $page, $limit);
            } catch (\Throwable $e) {
                if ($page === 1) {
                    throw $e;
                }
                // Pagination interrompue (§15) : on garde ce qui a déjà été
                // collecté plutôt que de tout perdre pour l'échec d'une page.
                error_log('[trouvailles][leboncoin] pagination interrompue page ' . $page . ' : ' . $e->getMessage());
                break;
            }

            $ads = $raw['ads'] ?? [];
            if (!is_array($ads)) {
                error_log('[trouvailles][leboncoin] page ' . $page . ' : champ "ads" absent ou malformé');
                break;
            }

            foreach ($ads as $ad) {
                if (!is_array($ad)) {
                    continue;
                }
                $listing = $this->mapAd($ad);
                if ($listing !== null) {
                    $listings[] = $listing;
                }
            }

            if (count($ads) < $limit) {
                break; // dernière page (heuristique : moins de résultats que demandé)
            }
        }

        return $listings;
    }

    /** @param array<string,mixed> $ad */
    private function mapAd(array $ad): ?NormalizedListing
    {
        $externalId = isset($ad['list_id']) ? (string) $ad['list_id'] : null;
        $url = isset($ad['url']) ? (string) $ad['url'] : null;

        if ($externalId === null || $url === null) {
            error_log('[trouvailles][leboncoin] annonce ignorée : list_id ou url absent');
            return null;
        }

        $location = $ad['location'] ?? null;
        $locationLabel = null;
        if (is_array($location)) {
            $locationLabel = $location['city'] ?? $location['region_name'] ?? null;
            if ($locationLabel !== null && isset($location['zipcode'])) {
                $locationLabel .= ' (' . $location['zipcode'] . ')';
            }
        }

        $askingPrice = isset($ad['price_cents']) && is_numeric($ad['price_cents'])
            ? ((float) $ad['price_cents']) / 100
            : null;

        $owner = $ad['owner'] ?? null;
        $sellerType = is_array($owner) && isset($owner['type']) ? (string) $owner['type'] : null;

        return new NormalizedListing(
            source: 'leboncoin',
            externalId: $externalId,
            url: $url,
            title: isset($ad['subject']) ? (string) $ad['subject'] : null,
            description: isset($ad['body']) ? (string) $ad['body'] : null,
            brand: isset($ad['brand']) ? (string) $ad['brand'] : null,
            category: isset($ad['category_name']) ? (string) $ad['category_name']
                : (isset($ad['category_id']) ? (string) $ad['category_id'] : null),
            condition: isset($ad['status']) ? (string) $ad['status'] : null,
            askingPrice: $askingPrice,
            askingCurrency: $askingPrice !== null ? 'EUR' : null,
            shippingPrice: null,
            location: $locationLabel,
            sellerType: $sellerType,
            publishedAt: isset($ad['first_publication_date']) ? (string) $ad['first_publication_date'] : null,
            priceMechanism: NormalizedListing::PRICE_MECHANISM_FIXED,
        );
    }
}
