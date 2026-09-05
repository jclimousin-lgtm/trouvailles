<?php

declare(strict_types=1);

namespace Trouvailles\Pricing;

/**
 * TRV-004 — normalisation et similarité de titres d'annonces, pur PHP,
 * sans dépendance (aucun `vendor/`, aucun composer.json dans ce projet).
 *
 * Choix de l'indice de Jaccard sur ensembles de tokens (|A∩B|/|A∪B|)
 * plutôt qu'une distance de Levenshtein : Levenshtein compare deux
 * chaînes caractère par caractère et est donc sensible à l'ordre des
 * mots ("Canon EOS 90D DSLR Camera Body" vs "Canon EOS 90D Body Only,
 * DSLR Camera") — inadapté à des titres d'annonces où l'ordre varie
 * librement d'un vendeur à l'autre. Jaccard sur tokens capture le
 * recouvrement de mots-clés indépendamment de leur ordre, ce qui
 * correspond mieux à ce cas d'usage.
 *
 * Repli d'accents via une table statique (pas `iconv('UTF-8',
 * 'ASCII//TRANSLIT', ...)`, qui n'est invoqué nulle part ailleurs dans ce
 * projet et n'est pas garanti disponible/cohérent sur tout environnement
 * PHP — une table explicite est plus prévisible).
 *
 * Liste de mots vides volontairement orientée anglais : eBay (source
 * unique v1, `marketplace_id` par défaut `EBAY_US`) produit des titres
 * majoritairement en anglais. À revoir si une autre `marketplace_id` eBay
 * est utilisée, ou si une autre source vient s'ajouter.
 */
final class TitleNormalizer
{
    private const ACCENT_MAP = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'é' => 'e',
        'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
        'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
    ];

    /** Mots vides ignorés (bruit, aucun signal de matching). */
    private const STOP_WORDS = [
        'the', 'a', 'an', 'for', 'with', 'and', 'or', 'of', 'in', 'on', 'to', 'by',
        'new', 'used', 'genuine', 'authentic', 'original', 'excellent', 'good',
        'oem', 'lot', 'set', 'free', 'shipping', 'ship', 'fast', 'sale', 'brand',
    ];

    /**
     * @return list<string> tokens normalisés, dédupliqués, hors mots vides
     */
    public static function tokens(?string $title): array
    {
        if ($title === null || trim($title) === '') {
            return [];
        }

        $lower = mb_strtolower($title, 'UTF-8');
        $folded = strtr($lower, self::ACCENT_MAP);
        $cleaned = preg_replace('/[^a-z0-9]+/u', ' ', $folded) ?? '';
        $parts = array_filter(explode(' ', trim($cleaned)), static fn (string $p) => $p !== '');

        $tokens = [];
        foreach ($parts as $part) {
            if (in_array($part, self::STOP_WORDS, true)) {
                continue;
            }
            // Bruit : numérique pur trop court (ex. "1", "12") ou lettre isolée.
            if (ctype_digit($part) && strlen($part) <= 2) {
                continue;
            }
            if (strlen($part) === 1 && !ctype_digit($part)) {
                continue;
            }
            $tokens[$part] = true; // dédup via clé
        }

        return array_keys($tokens);
    }

    /**
     * Indice de Jaccard |A∩B|/|A∪B|, dans [0,1]. Deux ensembles vides (ou
     * l'un des deux vide) -> 0.0, jamais 1.0 par défaut (l'absence de
     * signal ne doit jamais ressembler à une correspondance parfaite).
     *
     * @param list<string> $tokensA
     * @param list<string> $tokensB
     */
    public static function jaccard(array $tokensA, array $tokensB): float
    {
        if ($tokensA === [] || $tokensB === []) {
            return 0.0;
        }

        $setA = array_flip($tokensA);
        $setB = array_flip($tokensB);

        $intersection = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $intersection;

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }
}
