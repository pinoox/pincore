<?php

namespace Pinoox\Component\Template\Engine;

use Pinoox\Component\Template\Parser\TemplateReferenceInterface;

interface EngineInterface
{
    public function render(TemplateReferenceInterface|string $name, array $parameters = []): string;

    public function exists(TemplateReferenceInterface|string $name): bool;

    public function supports(TemplateReferenceInterface|string $name): bool;
}
