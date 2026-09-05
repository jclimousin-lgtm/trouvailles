<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Etsy;

use InvalidArgumentException;
use RuntimeException;
use Trouvailles\Http\CurlHttpClient;
use Trouvailles\Http\HttpClientInterface;

/**
 * TRV-009 — client de l'API OFFICIELLE Etsy Open API v3
 * (`GET /v3/application/listings/active`), vérifié directement sur la
 * spécification OpenAPI officielle d'Etsy (dépôt `etsy/open-api`) et
 * recoupé par une recherche indépendante — endpoint marketplace-wide
 * (pas limité à une boutique), pas deviné.
 *
 * Authentification bien plus simple qu'eBay : un seul en-tête
 * `x-api-key`, **aucun échange OAuth2** nécessaire pour cette recherche
 * publique en lecture seule. Format `keystring:shared_secret` (changement
 * du 9 février 2026 côté Etsy — avant cette date, le keystring seul
 * suffisait ; à revoir si une app plus ancienne se comporte différemment).
 *
 * `min_price`/`max_price` documentés comme suffisants seuls par la
 * spécification officielle, contrairement au filtre prix d'eBay qui
 * exigeait silencieusement `priceCurrency` en plus (découvert en
 * conditions réelles, TRV-008) — **non vérifié empiriquement faute
 * d'identifiants** au moment de l'écriture, à confirmer dès que possible
 * avec les mêmes moyens que pour eBay (comparer les prix réels retournés
 * à la fourchette demandée).
 */
final class EtsyClient
{
    private const SEARCH_URL = 'https://openapi.etsy.com/v3/application/listings/active';

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly string $keystring,
        private readonly string $sharedSecret,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? new CurlHttpClient();
    }

    /** @param array<string,mixed> $config keystring, shared_secret */
    public static function fromConfig(array $config, ?HttpClientInterface $http = null): self
    {
        return new self(
            (string) ($config['keystring'] ?? ''),
            (string) ($config['shared_secret'] ?? ''),
            $http,
        );
    }

    public function hasCredentials(): bool
    {
        return $this->keystring !== '' && $this->sharedSecret !== '';
    }

    /**
     * @param array<string,mixed> $criteria keywords?, min_price?, max_price?
     * @return array<string,mixed> réponse décodée (native, non transformée)
     */
    public function searchPage(array $criteria, int $limit = 25, int $offset = 0): array
    {
        if (!$this->hasCredentials()) {
            throw new InvalidArgumentException(
                'EtsyClient : ETSY_KEYSTRING/ETSY_SHARED_SECRET absents de la configuration — ' .
                'aucun appel possible sans ces identifiants (jamais de secret en dur).'
            );
        }

        $query = array_filter([
            'keywords' => $criteria['keywords'] ?? null,
            'min_price' => $criteria['min_price'] ?? null,
            'max_price' => $criteria['max_price'] ?? null,
            'limit' => $limit,
            'offset' => $offset,
        ], static fn ($v) => $v !== null);

        $url = self::SEARCH_URL . '?' . http_build_query($query);

        $response = $this->http->request('GET', $url, [
            'x-api-key' => $this->keystring . ':' . $this->sharedSecret,
            'Accept' => 'application/json',
        ]);

        if ($response['status'] === 401) {
            throw new RuntimeException('EtsyClient : clé API refusée (HTTP 401) — vérifier ETSY_KEYSTRING/ETSY_SHARED_SECRET');
        }
        if ($response['status'] === 429) {
            throw new RuntimeException('EtsyClient : limitation de requêtes (429) sur listings/active');
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("EtsyClient : réponse HTTP {$response['status']} inattendue — {$response['body']}");
        }
        if (trim($response['body']) === '') {
            throw new RuntimeException('EtsyClient : réponse vide');
        }

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('EtsyClient : réponse JSON malformée — ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('EtsyClient : forme de réponse inattendue (pas un objet JSON)');
        }

        return $decoded;
    }
}
