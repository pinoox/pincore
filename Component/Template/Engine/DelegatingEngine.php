<?php

namespace Pinoox\Component\Template\Engine;

use Pinoox\Component\Template\Parser\TemplateReferenceInterface;

class DelegatingEngine implements EngineInterface
{
    /** @var list<EngineInterface> */
    private array $engines = [];

    /**
     * @param list<EngineInterface> $engines
     */
    public function __construct(array $engines = [])
    {
        foreach ($engines as $engine) {
            $this->addEngine($engine);
        }
    }

    public function render(TemplateReferenceInterface|string $name, array $parameters = []): string
    {
        return $this->getEngine($name)->render($name, $parameters);
    }

    public function exists(TemplateReferenceInterface|string $name): bool
    {
        return $this->getEngine($name)->exists($name);
    }

    public function supports(TemplateReferenceInterface|string $name): bool
    {
        try {
            $this->getEngine($name);
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    public function addEngine(EngineInterface $engine): void
    {
        $this->engines[] = $engine;
    }

    public function getEngine(TemplateReferenceInterface|string $name): EngineInterface
    {
        foreach ($this->engines as $engine) {
            if ($engine->supports($name)) {
                return $engine;
            }
        }

        throw new \RuntimeException(sprintf('No engine is able to work with the template "%s".', $name));
    }
}
