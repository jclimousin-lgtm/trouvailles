<?php

declare(strict_types=1);

namespace Trouvailles\Sources\Vinted;

/**
 * TRV-002-B — contrat de transport/catalogue Vinted consommé par
 * VintedAdapter. Représente uniquement la capacité de fournir une page de
 * résultats déjà récupérée — VintedAdapter ne connaît jamais COMMENT ces
 * résultats ont été obtenus (requête HTTP directe, session navigateur
 * légitime fournie, etc.). Deux implémentations à ce jour :
 * VintedClient (HTTP direct, comportement historique) et
 * VintedBrowserSessionTransport (consomme une session fournie). Voir
 * docs/TRV-002-B-vinted-browser-session.md pour le détail.
 */
interface VintedTransportInterface
{
    /**
     * @param array<string,mixed> $criteria search_text?, catalog_ids?, price_from?, price_to?, order?
     * @return array<string,mixed> réponse décodée, doit contenir une clé 'items' (même forme que
     *         celle historiquement retournée par VintedClient::searchPage())
     */
    public function searchPage(array $criteria, int $page, int $perPage): array;
}
