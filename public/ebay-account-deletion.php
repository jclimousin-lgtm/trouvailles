<?php

declare(strict_types=1);

/**
 * TRV-007 — endpoint de conformité « Marketplace Account Deletion »
 * exigé par eBay pour activer l'authentification OAuth en production
 * (même pour une application en lecture seule, client_credentials
 * uniquement, qui n'obtient jamais de jeton utilisateur eBay et ne
 * stocke donc aucune donnée personnelle d'utilisateur eBay).
 *
 * Contrat documenté par eBay (Marketplace Account Deletion Notification API) :
 *   - GET avec ?challenge_code=<token> : eBay vérifie la maîtrise de
 *     l'endpoint. Réponse attendue : JSON {"challengeResponse": <hash>},
 *     où <hash> = SHA-256 hex de la concaténation EXACTE, dans cet ordre :
 *     challenge_code + verification_token + endpoint_url (l'URL doit
 *     être identique, caractère pour caractère, à celle enregistrée côté
 *     eBay).
 *   - POST : notification réelle de suppression de compte. Trouvailles
 *     n'obtenant jamais de jeton utilisateur eBay (uniquement
 *     client_credentials, app-only, pour la recherche publique Browse),
 *     il n'existe structurellement aucune donnée utilisateur eBay à
 *     supprimer ici — accusé de réception 200 uniquement, jamais de
 *     traitement inventé au-delà de ce qui est réellement nécessaire.
 */

$racineOverride = __DIR__ . '/app-root.php';
$root = is_file($racineOverride) ? require $racineOverride : __DIR__ . '/..';
define('ROOT', $root);

require ROOT . '/app/Core/autoload.php';

$config = require ROOT . '/config/ebay.php';
$verificationToken = $config['deletion_verification_token'];
$endpointUrl = $config['deletion_endpoint_url'];

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $challengeCode = $_GET['challenge_code'] ?? null;

    if ($challengeCode === null || $verificationToken === '' || $endpointUrl === '') {
        http_response_code(400);
        echo json_encode(['error' => 'missing challenge_code or server misconfigured']);
        exit;
    }

    $hash = hash('sha256', $challengeCode . $verificationToken . $endpointUrl);
    http_response_code(200);
    echo json_encode(['challengeResponse' => $hash]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Aucune donnée utilisateur eBay n'est jamais stockée par Trouvailles
    // (pas de flux OAuth utilisateur) — rien à supprimer, accusé simple.
    error_log('[trouvailles][ebay-deletion] notification reçue, aucune donnée utilisateur eBay stockée à ce jour');
    http_response_code(200);
    echo json_encode(['acknowledged' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
