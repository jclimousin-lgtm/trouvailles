<?php

declare(strict_types=1);

use Trouvailles\Http\HttpClientInterface;

/**
 * Double de test de HttpClientInterface (repris tel quel du socle
 * juridico) — aucun réseau, réponses programmées par URL exacte. Permet de
 * tester un client marketplace entièrement hors réseau à partir de
 * fixtures locales (tests/fixtures/*.json).
 */
final class FixtureHttpClient implements HttpClientInterface
{
    /** @var array<string, list<array{status:int, body:string, headers:array<string,list<string>>}>> */
    private array $responses = [];

    /** @var array<string, int> compteur d'appels par URL, pour consommer les réponses en file. */
    private array $callCount = [];

    /** @var list<string> URLs demandées, dans l'ordre. */
    public array $requestedUrls = [];

    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $requestedDetails = [];

    /**
     * Programme une réponse pour une URL. Appeler plusieurs fois pour la
     * même URL empile une file (utile pour une pagination qui poste
     * plusieurs fois vers la même URL, ex. Leboncoin) — consommée dans
     * l'ordre ; si une seule réponse est programmée, elle est réutilisée à
     * chaque appel (comportement simple, rétrocompatible).
     *
     * @param array<string,list<string>> $responseHeaders
     */
    public function respondTo(string $url, int $status, string $body, array $responseHeaders = []): void
    {
        $this->responses[$url][] = ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requestedUrls[] = $url;
        $this->requestedDetails[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if (!isset($this->responses[$url]) || $this->responses[$url] === []) {
            throw new RuntimeException("FixtureHttpClient : aucune réponse programmée pour {$url}");
        }

        $queue = $this->responses[$url];
        $index = $this->callCount[$url] ?? 0;
        $this->callCount[$url] = $index + 1;

        return $queue[$index] ?? $queue[count($queue) - 1];
    }
}
