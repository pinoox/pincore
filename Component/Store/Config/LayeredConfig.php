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

namespace Pinoox\Component\Store\Config;

use Pinoox\Component\Store\Config\Data\DataManager;

class LayeredConfig implements ConfigInterface
{
    private DataManager $overlay;

    public function __construct(
        private readonly ConfigInterface $base,
        array $overrides = [],
    ) {
        $this->overlay = new DataManager($overrides);
    }

    public function getBase(): ConfigInterface
    {
        return $this->base;
    }

    public function getOverlay(): DataManager
    {
        return $this->overlay;
    }

    public function get(?string $key = null, $default = null): mixed
    {
        if ($key === null || $key === '') {
            $baseAll = (array)$this->base->get();
            $overlayAll = (array)$this->overlay->get();
            return array_replace_recursive($baseAll, $overlayAll);
        }

        $overlayValue = $this->overlay->get($key);
        if ($overlayValue !== null) {
            return $overlayValue;
        }

        return $this->base->get($key, $default);
    }

    public function all(): mixed
    {
        return $this->get();
    }

    public function set(string $key, mixed $value): static
    {
        $this->overlay->set($key, $value);
        return $this;
    }

    public function remove(string $key): static
    {
        $this->overlay->remove($key);
        return $this;
    }

    public function save(): static
    {
        $this->base->save();
        return $this;
    }

    public function add(string $key, mixed $value): static
    {
        $this->overlay->add($key, $value);
        return $this;
    }

    public function getData(): mixed
    {
        return $this->get();
    }

    public function setData(mixed $data): static
    {
        $this->overlay->setData($data);
        return $this;
    }

    public function __get(string $name)
    {
        return $this->get($name);
    }

    public function __set(string $name, $value): void
    {
        $this->set($name, $value);
    }
}
