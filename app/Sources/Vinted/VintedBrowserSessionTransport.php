<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Vinted;

use RuntimeException;
use Trouvailles\Http\CurlHttpClient;
use Trouvailles\Http\HttpClientInterface;

/**
 * TRV-002-B — transport Vinted consommant une session navigateur légitime
 * DÉJÀ établie, plutôt que d'en fabriquer une automatiquement (ce que fait
 * VintedClient::ensureSessionCookie() via un GET anonyme sur `/`).
 *
 * Ce que cette classe fait : utilise le cookie de session fourni en
 * injection pour appeler `/api/v2/catalog/items` (même endpoint, même
 * contrat de réponse que VintedClient) via un HttpClientInterface standard
 * — une requête HTTP légitime portant une session déjà valide, pas une
 * technique d'évasion.
 *
 * Ce que cette classe NE fait PAS et ne fera jamais : obtenir elle-même
 * une session (aucun GET anonyme sur `/`), contourner Cloudflare/DataDome,
 * résoudre un challenge/CAPTCHA, usurper une empreinte de navigateur,
 * tenter une méthode alternative après un refus. Voir
 * docs/TRV-002-B-vinted-browser-session.md.
 *
 * Fourniture de la session : la forme minimale retenue est une simple
 * chaîne (`$sessionCookie`, valeur littérale du header `Cookie`) — pas de
 * classe de session dédiée, pas de renouvellement automatique, pas de
 * stockage SQL (hors périmètre, voir la documentation). Une session
 * absente (`null` ou vide) ou refusée (401/403) lève une exception
 * explicite, jamais de tentative alternative.
 */
final class VintedBrowserSessionTransport implements VintedTransportInterface
{
    private const SEARCH_PATH = '/api/v2/catalog/items';

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly ?string $sessionCookie,
        ?HttpClientInterface $http = null,
        private readonly string $domain = 'vinted.fr'
    ) {
        $this->http = $http ?? new CurlHttpClient();
    }

    private function baseUrl(): string
    {
        return 'https://www.' . $this->domain;
    }

    /**
     * @param array<string,mixed> $criteria search_text?, catalog_ids?, price_from?, price_to?, order?
     * @return array<string,mixed> réponse décodée (native, non transformée) — même contrat que VintedClient
     */
    public function searchPage(array $criteria, int $page, int $perPage): array
    {
        if ($this->sessionCookie === null || trim($this->sessionCookie) === '') {
            throw new RuntimeException(
                'VintedBrowserSessionTransport : aucune session fournie — ce transport ne fabrique jamais ' .
                'de session lui-même, elle doit être établie et fournie explicitement (voir §5 de la mission ' .
                'et docs/TRV-002-B-vinted-browser-session.md).'
            );
        }

        $query = array_filter([
            'search_text' => $criteria['search_text'] ?? null,
            'catalog_ids' => $criteria['catalog_ids'] ?? null,
            'brand_ids' => $criteria['brand_ids'] ?? null,
            'price_from' => $criteria['price_from'] ?? null,
            'price_to' => $criteria['price_to'] ?? null,
            'currency' => $criteria['currency'] ?? null,
            'order' => $criteria['order'] ?? 'newest_first',
            'page' => $page,
            'per_page' => $perPage,
        ], static fn ($v) => $v !== null);

        $url = $this->baseUrl() . self::SEARCH_PATH . '?' . http_build_query($query);

        $response = $this->http->request('GET', $url, [
            'Cookie' => $this->sessionCookie,
            'Accept' => 'application/json',
        ]);

        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new RuntimeException(
                "VintedBrowserSessionTransport : session refusée (HTTP {$response['status']}) — la session " .
                'fournie n\'est plus utilisable. Aucune tentative alternative n\'est effectuée (interdit par ' .
                'la mission) ; une nouvelle session légitime doit être établie et fournie explicitement.'
            );
        }
        if ($response['status'] === 429) {
            throw new RuntimeException('VintedBrowserSessionTransport : limitation de requêtes (429) sur ' . self::SEARCH_PATH);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("VintedBrowserSessionTransport : réponse HTTP {$response['status']} inattendue");
        }
        if (trim($response['body']) === '') {
            throw new RuntimeException('VintedBrowserSessionTransport : réponse vide');
        }

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('VintedBrowserSessionTransport : réponse JSON malformée — ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('VintedBrowserSessionTransport : données catalogue invalides (réponse décodée non structurée)');
        }

        return $decoded;
    }
}
