<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Leboncoin;

use RuntimeException;
use Trouvailles\Http\CurlHttpClient;
use Trouvailles\Http\HttpClientInterface;

/**
 * TRV-002 §11 — client HTTP Leboncoin, logique de requête/pagination
 * adaptée de `etienne-hd/lbc` (MIT, Copyright (c) 2025 Étienne Hodé) :
 * base URL, endpoint et paramètres de recherche repris de son
 * `client.py`/`model/ad.py` — AUCUNE ligne de code Python copiée
 * (langage différent), seule la structure de requête/réponse est portée.
 *
 * Contournement anti-bot NON reproduit : `lbc` utilise `curl_cffi` avec
 * impersonation de navigateur pour contourner Datadome, explicitement
 * interdit par la mission (§18). Ce client utilise un cURL standard — si
 * Datadome bloque la requête, l'échec est remonté tel quel (voir
 * LeboncoinAdapter et le rapport de vérification réelle), jamais contourné.
 */
final class LeboncoinClient
{
    private const BASE_URL = 'https://api.leboncoin.fr/';
    private const SEARCH_PATH = 'finder/search';

    private readonly HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null, private readonly string $baseUrl = self::BASE_URL)
    {
        $this->http = $http ?? new CurlHttpClient();
    }

    /**
     * @param array<string,mixed> $criteria text?, category?, locations?, price_min?, price_max?
     * @return array<string,mixed> réponse décodée (native, non transformée)
     */
    public function searchPage(array $criteria, int $page = 1, int $limit = 35): array
    {
        $payload = [
            'text' => $criteria['text'] ?? '',
            'filters' => array_filter([
                'category' => isset($criteria['category']) ? ['id' => (string) $criteria['category']] : null,
                'location' => isset($criteria['locations']) ? ['locations' => $criteria['locations']] : null,
                'price' => (isset($criteria['price_min']) || isset($criteria['price_max']))
                    ? array_values(array_filter([$criteria['price_min'] ?? null, $criteria['price_max'] ?? null], static fn ($v) => $v !== null))
                    : null,
            ], static fn ($v) => $v !== null),
            'sort_by' => $criteria['sort'] ?? 'relevance',
            'sort_order' => 'desc',
            'limit' => $limit,
            'limit_alu' => 0,
            'page' => $page,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('LeboncoinClient : impossible d\'encoder les critères de recherche en JSON');
        }

        $response = $this->http->request('POST', $this->baseUrl . self::SEARCH_PATH, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body);

        if ($response['status'] === 429) {
            throw new RuntimeException('LeboncoinClient : limitation de requêtes (429) sur ' . self::SEARCH_PATH);
        }
        if ($response['status'] === 403) {
            throw new RuntimeException(
                'LeboncoinClient : accès refusé (403) — probable protection Datadome. ' .
                'Ce client ne tente aucun contournement (interdit par la mission), échec remonté tel quel.'
            );
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("LeboncoinClient : réponse HTTP {$response['status']} inattendue");
        }
        if (trim($response['body']) === '') {
            throw new RuntimeException('LeboncoinClient : réponse vide');
        }

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('LeboncoinClient : réponse JSON malformée — ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('LeboncoinClient : forme de réponse inattendue (pas un objet JSON)');
        }

        return $decoded;
    }
}
