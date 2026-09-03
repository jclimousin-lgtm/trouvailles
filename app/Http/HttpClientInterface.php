<?php

declare(strict_types=1);

namespace Trouvailles\Http;

/**
 * Abstraction HTTP minimale (reprise du socle juridico) permettant
 * d'injecter un double de test dans un client marketplace — aucun appel
 * réseau pendant les tests automatisés (voir tests/Support/FixtureHttpClient.php).
 */
interface HttpClientInterface
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, headers:array<string,list<string>>} headers en-têtes de
     *         réponse, clés en minuscules, valeurs en liste (un en-tête comme Set-Cookie peut apparaître
     *         plusieurs fois) — nécessaire à VintedClient pour récupérer un cookie de session.
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
