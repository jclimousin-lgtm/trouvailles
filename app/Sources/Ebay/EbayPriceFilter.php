<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Ebay;

/**
 * TRV-006/TRV-008-bugfix — construit la syntaxe de filtre prix native de
 * l'API eBay Browse (`price:[min..max]`, borne ouverte si un seul côté
 * fourni). Ne valide jamais min<=max (responsabilité de l'appelant,
 * `public/chasses.php`) — construit seulement à partir de ce qui est
 * fourni, ne devine jamais une borne absente.
 *
 * **`priceCurrency` obligatoire dans le même filtre** : vérifié en
 * conditions réelles (recherche production "nintendo switch") — sans
 * `priceCurrency:XXX` accolé, l'API eBay Browse ignore silencieusement le
 * filtre `price:[...]` (aucune erreur, mais les résultats couvrent toute
 * la fourchette de prix réelle, pas seulement l'intervalle demandé).
 * Avec `priceCurrency:EUR` ajouté, le filtre s'applique correctement.
 *
 * La devise est déduite du `marketplace_id` de configuration (fait stable
 * documenté par eBay, pas une donnée inventée) — mapping volontairement
 * limité aux deux marketplaces réellement configurables dans ce projet
 * (voir config/ebay.php) ; un marketplace non mappé retombe sur EUR
 * (cohérent avec le reste du projet, entièrement EUR par ailleurs), à
 * étendre si un jour EBAY_MARKETPLACE_ID prend une autre valeur.
 */
final class EbayPriceFilter
{
    private const MARKETPLACE_CURRENCIES = [
        'EBAY_FR' => 'EUR',
        'EBAY_US' => 'USD',
    ];

    public static function build(?float $min, ?float $max, string $marketplaceId): ?string
    {
        if ($min === null && $max === null) {
            return null;
        }

        $currency = self::MARKETPLACE_CURRENCIES[$marketplaceId] ?? 'EUR';

        $range = match (true) {
            $min !== null && $max !== null => sprintf('price:[%s..%s]', self::format($min), self::format($max)),
            $min !== null => sprintf('price:[%s..]', self::format($min)),
            default => sprintf('price:[..%s]', self::format($max)),
        };

        return $range . ',priceCurrency:' . $currency;
    }

    private static function format(float $value): string
    {
        // Pas de zéros décimaux superflus (100 plutôt que 100.00) — eBay accepte les deux, plus lisible en test/log.
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
