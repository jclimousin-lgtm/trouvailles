<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Vinted;

use RuntimeException;
use Trouvailles\Http\CurlHttpClient;
use Trouvailles\Http\HttpClientInterface;

/**
 * TRV-002 §12 — client HTTP Vinted, adapté de `herissondev/vinted-api-wrapper`
 * (MIT) : c'est le seul des trois dépôts de référence qui n'implémente PAS
 * de contournement anti-bot actif (pas d'impersonation TLS, requêtes HTTP
 * standard) — retenu comme base pour cette raison, conformément à
 * l'interdiction §18. `vlymar1/vinted-api-kit` (impersonation `curl_cffi`)
 * et `DataKazKN/vinted-mcp-server` (interception de redirection pré-
 * Cloudflare) ne sont PAS reproduits pour leur mécanisme d'accès — leurs
 * noms de paramètres de recherche (identiques dans les trois dépôts) sont
 * en revanche repris tels quels.
 *
 * Auth : un cookie de session (JWT `access_token_web`) est nécessaire à
 * l'API catalogue — obtenu par une requête GET anonyme et standard vers la
 * page d'accueil (aucune impersonation), comme le fait
 * `vinted-api-wrapper`. Aucune clé API à configurer.
 *
 * Si la protection anti-bot de Vinted bloque malgré tout ce client
 * standard, l'échec est remonté tel quel (voir VintedAdapter et le rapport
 * de vérification réelle), jamais contourné.
 *
 * TRV-002-B : implémente VintedTransportInterface — comportement HTTP
 * historique inchangé, conservé comme implémentation par défaut/compatible
 * avec l'existant. Voir VintedBrowserSessionTransport pour l'alternative
 * consommant une session déjà fournie.
 */
final class VintedClient implements VintedTransportInterface
{
    private const SEARCH_PATH = '/api/v2/catalog/items';

    private readonly HttpClientInterface $http;
    private ?string $sessionCookie = null;

    public function __construct(?HttpClientInterface $http = null, private readonly string $domain = 'vinted.fr')
    {
        $this->http = $http ?? new CurlHttpClient();
    }

    private function baseUrl(): string
    {
        return 'https://www.' . $this->domain;
    }

    private function ensureSessionCookie(): string
    {
        if ($this->sessionCookie !== null) {
            return $this->sessionCookie;
        }

        $response = $this->http->request('GET', $this->baseUrl() . '/', [
            'User-Agent' => 'Mozilla/5.0 (compatible; TrouvaillesBot/1.0)',
            'Accept' => 'text/html',
        ]);

        if ($response['status'] < 200 || $response['status'] >= 400) {
            throw new RuntimeException("VintedClient : impossible d'établir une session (HTTP {$response['status']})");
        }

        $setCookies = $response['headers']['set-cookie'] ?? [];
        if ($setCookies === []) {
            throw new RuntimeException('VintedClient : aucun cookie de session reçu de la page d\'accueil');
        }

        $pairs = [];
        foreach ($setCookies as $setCookie) {
            $nameValue = explode(';', $setCookie, 2)[0];
            if (str_contains($nameValue, '=')) {
                $pairs[] = trim($nameValue);
            }
        }

        $this->sessionCookie = implode('; ', $pairs);

        return $this->sessionCookie;
    }

    /**
     * @param array<string,mixed> $criteria search_text?, catalog_ids?, price_from?, price_to?, order?
     * @return array<string,mixed> réponse décodée (native, non transformée)
     */
    public function searchPage(array $criteria, int $page = 1, int $perPage = 20): array
    {
        $cookie = $this->ensureSessionCookie();

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
            'Cookie' => $cookie,
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (compatible; TrouvaillesBot/1.0)',
        ]);

        if ($response['status'] === 429) {
            throw new RuntimeException('VintedClient : limitation de requêtes (429) sur ' . self::SEARCH_PATH);
        }
        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new RuntimeException(
                "VintedClient : accès refusé ({$response['status']}) — probable protection anti-bot " .
                "(Cloudflare/session expirée). Ce client ne tente aucun contournement (interdit par la mission)."
            );
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException("VintedClient : réponse HTTP {$response['status']} inattendue");
        }
        if (trim($response['body']) === '') {
            throw new RuntimeException('VintedClient : réponse vide');
        }

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('VintedClient : réponse JSON malformée — ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('VintedClient : forme de réponse inattendue (pas un objet JSON)');
        }

        return $decoded;
    }
}
