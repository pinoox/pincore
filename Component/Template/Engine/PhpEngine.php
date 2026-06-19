<?php

namespace Pinoox\Component\Template\Engine;

use Pinoox\Component\Template\Parser\TemplateNameParserInterface;
use Pinoox\Component\Template\Parser\TemplateReferenceInterface;

class PhpEngine implements EngineInterface
{
    /** @var list<string> */
    private array $paths;

    private TemplateNameParserInterface $parser;

    /**
     * @param string|list<string> $paths Absolute theme directories (child first)
     */
    public function __construct(TemplateNameParserInterface $parser, string|array $paths)
    {
        $this->parser = $parser;
        $this->paths = $this->normalizePaths($paths);
    }

    public function render(TemplateReferenceInterface|string $name, array $parameters = []): string
    {
        $file = $this->resolvePath($name);

        if ($file === null) {
            throw new \RuntimeException(sprintf('The template "%s" cannot be rendered.', $this->parser->parse($name)));
        }

        extract($parameters, EXTR_SKIP);

        ob_start();

        try {
            include $file;
        } finally {
            $content = ob_get_clean();
        }

        return $content === false ? '' : $content;
    }

    public function exists(TemplateReferenceInterface|string $name): bool
    {
        return $this->resolvePath($name) !== null;
    }

    public function supports(TemplateReferenceInterface|string $name): bool
    {
        $reference = $this->parser->parse($name);

        return $reference->get('engine') === 'php';
    }

    private function resolvePath(TemplateReferenceInterface|string $name): ?string
    {
        $reference = $this->parser->parse($name);
        $logical = $reference->getLogicalName();

        if ($logical === '') {
            return null;
        }

        if (!str_ends_with($logical, '.php')) {
            $logical .= '.php';
        }

        foreach ($this->paths as $path) {
            $file = $path . '/' . $logical;

            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param string|list<string> $paths
     * @return list<string>
     */
    private function normalizePaths(string|array $paths): array
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $normalized = [];

        foreach ($paths as $path) {
            $path = rtrim(str_replace('\\', '/', (string) $path), '/');

            if ($path !== '') {
                $normalized[] = $path;
            }
        }

        return $normalized;
    }
}
