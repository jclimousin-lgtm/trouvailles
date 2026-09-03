<?php

declare(strict_types=1);

namespace Trouvailles\Http;

use RuntimeException;

/**
 * Implémentation réseau réelle de HttpClientInterface (cURL standard, sans
 * impersonation de navigateur/empreinte TLS) — reprise du socle juridico.
 *
 * Volontairement AUCUN contournement anti-bot (Datadome/Cloudflare) : les
 * dépôts de référence de TRV-002 (etienne-hd/lbc, vlymar1/vinted-api-kit,
 * DataKazKN/vinted-mcp-server) en utilisent (impersonation curl_cffi,
 * interception de redirection pré-challenge) — la mission interdit
 * explicitement ce type de contournement (§18). Ce client reste un cURL
 * ordinaire ; si une marketplace protégée le bloque, l'échec est remonté
 * tel quel, jamais masqué ni contourné.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(private readonly int $timeoutSeconds = 30)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Impossible d'initialiser la requête HTTP vers {$url}");
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        /** @var array<string,list<string>> $responseHeaders */
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $responseHeaders[$name][] = trim($parts[1]);
            }
            return strlen($line);
        });

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            $timeoutCodes = [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT];
            $prefix = in_array($errno, $timeoutCodes, true) ? 'Délai dépassé' : 'Requête échouée';
            throw new RuntimeException("{$prefix} vers {$url} : {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $responseBody, 'headers' => $responseHeaders];
    }
}
