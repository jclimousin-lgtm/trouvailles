<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Ebay;

/**
 * TRV-006 — construit la syntaxe de filtre prix native de l'API eBay
 * Browse (`price:[min..max]`, borne ouverte si un seul côté fourni).
 * Ne valide jamais min<=max (responsabilité de l'appelant, `public/chasses.php`) —
 * construit seulement à partir de ce qui est fourni, ne devine jamais une
 * borne absente.
 */
final class EbayPriceFilter
{
    public static function build(?float $min, ?float $max): ?string
    {
        if ($min === null && $max === null) {
            return null;
        }

        return match (true) {
            $min !== null && $max !== null => sprintf('price:[%s..%s]', self::format($min), self::format($max)),
            $min !== null => sprintf('price:[%s..]', self::format($min)),
            default => sprintf('price:[..%s]', self::format($max)),
        };
    }

    private static function format(float $value): string
    {
        // Pas de zéros décimaux superflus (100 plutôt que 100.00) — eBay accepte les deux, plus lisible en test/log.
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
