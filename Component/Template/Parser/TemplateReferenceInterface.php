<?php

namespace Pinoox\Component\Template\Parser;

interface TemplateReferenceInterface
{
    public function set(string $name, string $value): static;

    public function get(string $name): string;

    public function all(): array;

    public function getPath(): string;

    public function getLogicalName(): string;
}
