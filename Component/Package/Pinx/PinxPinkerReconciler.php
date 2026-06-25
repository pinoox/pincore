<?php

namespace Pinoox\Component\Package\Pinx;

/**
 * Preserves Pinker runtime overrides across pinx package updates.
 */
final class PinxPinkerReconciler
{
    /** @var array<string, array{data: array<string, mixed>, remove: list<string>}>|null */
    private ?array $snapshot = null;

    public function captureForUpdate(string $package): int
    {
        $this->snapshot = PinxPinkerRegistry::snapshotOverrides($package);

        $paths = 0;

        foreach ($this->snapshot as $override) {
            $paths += count($override['data']) + count($override['remove']);
        }

        return $paths;
    }

    public function reconcile(string $package, bool $resetOverrides = false): array
    {
        $rebuilt = PinxPinkerRegistry::rebuild($package);
        $restored = 0;

        if (!$resetOverrides && $this->snapshot !== null && $this->snapshot !== []) {
            $restored = PinxPinkerRegistry::restoreOverrides($package, $this->snapshot);
        }

        return [
            'rebuilt' => $rebuilt,
            'restored' => $restored,
            'files' => count($this->snapshot ?? []),
        ];
    }

    public function hasSnapshot(): bool
    {
        return $this->snapshot !== null && $this->snapshot !== [];
    }
}
