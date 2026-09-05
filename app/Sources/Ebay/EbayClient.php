<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Ebay;

use InvalidArgumentException;
use RuntimeException;
use Trouvailles\Http\CurlHttpClient;
use Trouvailles\Http\HttpClientInterface;

/**
 * TRV-002 §13 — client de l'API OFFICIELLE eBay Browse API (priorité
 * imposée par la mission — aucun scraping HTML pour les annonces actives).
 * Flux OAuth2 client_credentials et endpoint de recherche conformes à la
 * documentation officielle developer.ebay.com. `data-scrape/ebay-price-
 * scraper` (dépôt OSS complémentaire imposé) a été inspecté mais ne
 * contient aucune logique spécifique à eBay exploitable (voir docs/TRV-002.md) —
 * rien n'en a été repris.
 */
final class EbayClient
{
    private const TOKEN_URL_PRODUCTION = 'https://api.ebay.com/identity/v1/oauth2/token';
    private const SEARCH_URL_PRODUCTION = 'https://api.ebay.com/buy/browse/v1/item_summary/search';
    private const TOKEN_URL_SANDBOX = 'https://api.sandbox.ebay.com/identity/v1/oauth2/token';
    private const SEARCH_URL_SANDBOX = 'https://api.sandbox.ebay.com/buy/browse/v1/item_summary/search';
    private const DEFAULT_SCOPE = 'https://api.ebay.com/oauth/api_scope';

    private readonly HttpClientInterface $http;
    private ?string $accessToken = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $marketplaceId = 'EBAY_US',
        ?HttpClientInterface $http = null,
        private readonly bool $sandbox = false,
    ) {
        $this->http = $http ?? new CurlHttpClient();
    }

    /** @param array<string,mixed> $config client_id, client_secret, marketplace_id?, sandbox? */
    public static function fromConfig(array $config, ?HttpClientInterface $http = null): self
    {
        return new self(
            (string) ($config['client_id'] ?? ''),
            (string) ($config['client_secret'] ?? ''),
            (string) ($config['marketplace_id'] ?? 'EBAY_US'),
            $http,
            (bool) ($config['sandbox'] ?? false),
        );
    }

    private function tokenUrl(): string
    {
        return $this->sandbox ? self::TOKEN_URL_SANDBOX : self::TOKEN_URL_PRODUCTION;
    }

    private function searchUrl(): string
    {
        return $this->sandbox ? self::SEARCH_URL_SANDBOX : self::SEARCH_URL_PRODUCTION;
    }

    public function hasCredentials(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    private function ensureToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        if (!$this->hasCredentials()) {
            throw new InvalidArgumentException(
                'EbayClient : EBAY_CLIENT_ID/EBAY_CLIENT_SECRET absents de la configuration — ' .
                'aucun appel à l\'API Browse n\'est possible sans ces identifiants (voir §14 : jamais de secret en dur).'
            );
        }

        $response = $this->http->request(
            'POST',
            $this->tokenUrl(),
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            http_build_query([
                'grant_type' => 'client_credentials',
                'scope' => self::DEFAULT_SCOPE,
            ])
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("EbayClient : échec d'authentification OAuth (HTTP {$response['status']}) — {$response['body']}");
        }

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('EbayClient : réponse OAuth JSON malformée — ' . $e->getMessage());
        }

        if (!is_array($decoded) || !isset($decoded['access_token'])) {
            throw new RuntimeException('EbayClient : réponse OAuth sans access_token');
        }

        $this->accessToken = (string) $decoded['access_token'];

        return $this->accessToken;
    }

    /**
     * @param array<string,mixed> $criteria q?, category_ids?, filter?
     * @return array<string,mixed> réponse décodée (native, non transformée)
     */
    public function searchPage(array $criteria, int $limit = 50, int $offset = 0): array
    {
        $token = $this->ensureToken();

        $query = array_filter([
            'q' => $criteria['q'] ?? null,
            'category_ids' => $criteria['category_ids'] ?? null,
            'filter' => $criteria['filter'] ?? null,
            'limit' => $limit,
            'offset' => $offset,
        ], static fn ($v) => $v !== null);

        $url = $this->searchUrl() . '?' . http_build_query($query);

        $response = $this->http->request('GET', $url, [
            'Authorization' => "Bearer {$token}",
            'X-EBAY-C-MARKETPLACE-ID' => $this->marketplaceId,
            'Accept' => 'application/json',
        ]);

        if ($response['status'] === 429) {
            throw new RuntimeException('EbayClient : limitation de requêtes (429) sur item_summary/search');
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("EbayClient : réponse HTTP {$response['status']} inattendue — {$response['body']}");
        }
        if (trim($response['body']) === '') {
            throw new RuntimeException('EbayClient : réponse vide');
        }

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('EbayClient : réponse JSON malformée — ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('EbayClient : forme de réponse inattendue (pas un objet JSON)');
        }

        return $decoded;
    }
}
