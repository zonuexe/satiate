<?php

declare(strict_types=1);

namespace Satiate\Audit;

/**
 * Compares the per-version "capability fingerprints" of a single package and reports which
 * security-relevant capabilities each version introduced relative to the version immediately
 * preceding it.
 *
 * A patch release that suddenly gains exec/eval/network/install-hook capability that the prior
 * version lacked is a classic supply-chain-compromise signal — the satiate version cache makes this
 * cross-build diff cheap.
 */
final class VersionCapabilityDiff
{
    /**
     * @param array<string, list<string>> $capabilitiesByVersion pretty version => capability patterns
     * @return list<array{version: string, previousVersion: string, capability: string}>
     */
    public function introduced(array $capabilitiesByVersion): array
    {
        $versions = array_keys($capabilitiesByVersion);
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        $introduced = [];

        for ($i = 1; $i < \count($versions); $i++) {
            $previous = $versions[$i - 1];
            $current = $versions[$i];

            $new = array_diff($capabilitiesByVersion[$current], $capabilitiesByVersion[$previous]);
            sort($new);

            foreach ($new as $capability) {
                $introduced[] = [
                    'version' => $current,
                    'previousVersion' => $previous,
                    'capability' => $capability,
                ];
            }
        }

        return $introduced;
    }
}
