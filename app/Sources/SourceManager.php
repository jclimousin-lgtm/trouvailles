<?php

declare(strict_types=1);

namespace Trouvailles\Sources;

use InvalidArgumentException;

/**
 * TRV-002 §5 — registre/dispatcher minimal : associe un code source
 * (sources.code) à son adapter, et route search() vers le bon adapter.
 * Ne fait ni classement, ni filtrage, ni agrégation entre marketplaces —
 * ce serait le "moteur complet de recherche utilisateur" explicitement
 * exclu de cette mission.
 */
final class SourceManager
{
    /** @var array<string, MarketplaceAdapterInterface> */
    private array $adapters = [];

    public function register(string $sourceCode, MarketplaceAdapterInterface $adapter): void
    {
        $this->adapters[$sourceCode] = $adapter;
    }

    /**
     * @param array<string,mixed> $criteria
     * @return list<NormalizedListing>
     */
    public function search(string $sourceCode, array $criteria): array
    {
        if (!isset($this->adapters[$sourceCode])) {
            throw new InvalidArgumentException("Aucun adapter enregistré pour la source '{$sourceCode}'");
        }

        return $this->adapters[$sourceCode]->search($criteria);
    }

    public function has(string $sourceCode): bool
    {
        return isset($this->adapters[$sourceCode]);
    }
}
