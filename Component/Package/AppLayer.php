<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Package;

class AppLayer
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string  $path,
        private ?string $packageName,
        private array   $context = [],
    )
    {
    }

    public function getPackageName(): ?string
    {
        return $this->packageName;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    public function setPackageName(string $packageName): void
    {
        $this->packageName = $packageName;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * @return mixed
     */
    public function context(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            $resolved = [];
            foreach ($this->context as $k => $v) {
                $resolved[$k] = $this->resolveValue($v);
            }

            return $resolved;
        }

        if (!array_key_exists($key, $this->context)) {
            return $this->resolveValue($default);
        }

        return $this->resolveValue($this->context[$key]);
    }

    public function resolveContext(?string $key = null, mixed $default = null): mixed
    {
        return $this->context($key, $default);
    }

    public function rawContext(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->context;
        }

        return $this->context[$key] ?? $default;
    }

    private function resolveValue(mixed $value): mixed
    {
        if ($value instanceof \Closure || (!is_string($value) && is_callable($value))) {
            return $value();
        }

        return $value;
    }

    public function matchedBy(): ?string
    {
        $matchedBy = $this->context('matched_by');

        return is_string($matchedBy) ? $matchedBy : null;
    }

    public function host(): ?string
    {
        $host = $this->context('host');

        return is_string($host) ? $host : null;
    }

    public function subdomain(): ?string
    {
        $subdomain = $this->context('subdomain');

        return is_string($subdomain) && $subdomain !== '' ? $subdomain : null;
    }

    public function isDefaultDomain(): bool
    {
        return (bool) $this->context('is_default_domain', false);
    }

    public function isCanonicalDefault(): bool
    {
        return (bool) $this->context('is_canonical_default', false);
    }

    public function isUnresolved(): bool
    {
        return is_string($this->context('resolution'));
    }

    public function resolution(): ?string
    {
        $value = $this->context('resolution');

        return is_string($value) ? $value : null;
    }

    public function configuredPackage(): ?string
    {
        $value = $this->context('configured_package');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function configuredPath(): ?string
    {
        $value = $this->context('configured_path');

        return is_string($value) && $value !== '' ? $value : null;
    }
}

