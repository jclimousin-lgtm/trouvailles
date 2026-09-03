<?php

declare(strict_types=1);

namespace Trouvailles\Sources;

/**
 * TRV-002 §7 — format interne commun produit par chaque adapter
 * marketplace, avant toute persistance. Les données absentes restent
 * `null` : aucun adapter n'invente une valeur non fournie par sa source.
 *
 * `priceMechanism` est un ajout minimal au-delà de la liste littérale du
 * §7 : §9 impose de distinguer une annonce à prix fixe (price_type=asking,
 * evidence_type=active_fixed_price) d'une enchère active (price_type=
 * auction, evidence_type=active_auction) — seul eBay peut produire
 * 'auction' (buyingOptions), Leboncoin/Vinted sont toujours 'fixed'. Sans
 * ce champ, ListingPersister n'aurait aucun moyen de choisir price_type/
 * evidence_type sans deviner. Signalé dans le rapport de mission.
 */
final class NormalizedListing
{
    public const PRICE_MECHANISM_FIXED = 'fixed';
    public const PRICE_MECHANISM_AUCTION = 'auction';

    public function __construct(
        public readonly string $source,
        public readonly string $externalId,
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $brand = null,
        public readonly ?string $category = null,
        public readonly ?string $condition = null,
        public readonly ?float $askingPrice = null,
        public readonly ?string $askingCurrency = null,
        public readonly ?float $shippingPrice = null,
        public readonly ?string $location = null,
        public readonly ?string $sellerType = null,
        public readonly ?string $publishedAt = null,
        public readonly string $priceMechanism = self::PRICE_MECHANISM_FIXED,
    ) {
    }
}
