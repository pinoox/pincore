<?php

namespace Pinoox\Component\Template\Parser;

interface TemplateNameParserInterface
{
    public function parse(TemplateReferenceInterface|string $name): TemplateReferenceInterface;
}
