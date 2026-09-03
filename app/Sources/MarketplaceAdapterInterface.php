<?php

declare(strict_types=1);

namespace Trouvailles\Sources;

/**
 * TRV-002 §6 — contrat commun minimal : une source s'interroge via
 * search(criteria) et retourne des NormalizedListing. Volontairement
 * réduit à cette seule méthode (contrairement au SourceAdapterInterface,
 * plus riche, déjà présent dans juridico — capabilities/get/normalize/
 * provenance) : la mission interdit explicitement de construire
 * maintenant le moteur complet de recherche utilisateur.
 */
interface MarketplaceAdapterInterface
{
    /**
     * @param array<string,mixed> $criteria
     * @return list<NormalizedListing>
     */
    public function search(array $criteria): array;
}
