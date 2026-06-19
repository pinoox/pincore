<?php

namespace Pinoox\Component\Template\Parser;

class TemplateReference implements TemplateReferenceInterface
{
    /** @var array{name: ?string, engine: ?string} */
    protected array $parameters;

    public function __construct(?string $name = null, ?string $engine = null)
    {
        $this->parameters = [
            'name' => $name,
            'engine' => $engine,
        ];
    }

    public function __toString(): string
    {
        return $this->getLogicalName();
    }

    public function set(string $name, string $value): static
    {
        if (!array_key_exists($name, $this->parameters)) {
            throw new \InvalidArgumentException(sprintf('The template does not support the "%s" parameter.', $name));
        }

        $this->parameters[$name] = $value;

        return $this;
    }

    public function get(string $name): string
    {
        if (!array_key_exists($name, $this->parameters)) {
            throw new \InvalidArgumentException(sprintf('The template does not support the "%s" parameter.', $name));
        }

        return (string) $this->parameters[$name];
    }

    public function all(): array
    {
        return $this->parameters;
    }

    public function getPath(): string
    {
        return (string) $this->parameters['name'];
    }

    public function getLogicalName(): string
    {
        return (string) $this->parameters['name'];
    }
}
