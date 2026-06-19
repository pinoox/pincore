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

namespace Pinoox\Component\Template\Engine;

use Exception;
use Pinoox\Component\Template\Parser\TemplateNameParserInterface;
use Pinoox\Component\Template\Parser\TemplateReferenceInterface;
use Pinoox\Component\Template\Twig\TwigFunctionLoader;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Extension\ExtensionInterface;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Pinoox\Component\Template\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\TwigFunction;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class TwigEngine implements EngineInterface
{
    private LoaderInterface $loader;
    private LoaderInterface $fileLoader;
    private ArrayLoader $arrayLoader;
    private TemplateNameParserInterface $parser;
    public Environment $template;

    /**
     * @param TemplateNameParserInterface $parser
     * @param LoaderInterface|string|list<string> $paths Absolute theme paths or custom loader
     * @param array<string, mixed> $environmentOptions Twig Environment constructor options
     */
    public function __construct(
        TemplateNameParserInterface $parser,
        LoaderInterface|string|array $paths,
        array $environmentOptions = [],
    ) {
        if ($paths instanceof LoaderInterface) {
            $this->fileLoader = $paths;
        } else {
            $this->fileLoader = new FilesystemLoader();
            foreach ($this->normalizePaths($paths) as $path) {
                $this->fileLoader->addPath($path);
            }
        }

        $this->arrayLoader = new ArrayLoader();
        $this->loader = new ChainLoader([
            $this->arrayLoader,
            $this->fileLoader
        ]);
        $this->parser = $parser;
        $this->template = new Environment($this->loader, $environmentOptions);
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
            if ($path !== '' && is_dir($path)) {
                $normalized[] = $path;
            }
        }

        return $normalized;
    }

    /**
     * Set loader
     * @param LoaderInterface $loader
     */
    public function setLoader(LoaderInterface $loader)
    {
        $this->template->setLoader($loader);
    }

    /**
     * Get Loader
     * @return LoaderInterface
     */
    public function getLoader(): LoaderInterface
    {
        return $this->template->getLoader();
    }

    /**
     * Get Loader
     * @param string $name
     * @param string $template
     * @return void
     */
    public function setTemplate(string $name, string $template): void
    {
        $this->arrayLoader->setTemplate($name, $template);
    }

    /**
     * Add Function
     *
     * @param string $name
     * @param callable $callback
     */
    public function addFunction(string $name, callable $callback): void
    {
        $this->template->addFunction(new TwigFunction($name, $callback));
    }

    /**
     * Add Extension
     *
     * @param ExtensionInterface $extension
     */
    public function addExtension(ExtensionInterface $extension): void
    {
        $this->template->addExtension($extension);
    }

    /**
     * Get all functions
     */
    public function getFunctions(): array
    {
        return $this->template->getFunctions();
    }

    /**
     * Get function
     * @param string $name
     * @return TwigFunction
     */
    public function getFunction(string $name): TwigFunction
    {
        return $this->template->getFunction($name);
    }

    /**
     * Add internal function
     *
     * @param string|array $names
     * @param string|null $namespace
     * @param string|null $replace
     */
    public function addInternalFunction(string|array $names,?string $namespace = null, ?string $replace = null): void
    {
        if (empty($names))
            return;

        if (is_array($names)) {
            foreach ($names as $key => $name) {
                $replace = !is_numeric($key) ? $name : null;
                $name = !is_numeric($key) ? $key : $name;
                $this->addInternalFunction($name, $namespace, $replace);
            }
        } else {
            try {
                $funcName = empty($replace) ? $names : $replace;

                if (!function_exists($funcName) || !is_callable($funcName)) {
                    return;
                }

                $this->addFunction($names, function () use ($funcName) {
                    return call_user_func_array($funcName, func_get_args());
                });
            } catch (\Exception) {
            }
        }

    }

    /**
     * Register a global PHP function in Twig.
     */
    public function addCallableFunction(string $name, callable $callback, array $options = []): void
    {
        $this->template->addFunction(new TwigFunction($name, $callback, $options));
    }

    /**
     * Load a PHP functions file and register only callable globals that exist at runtime.
     *
     * @param string|array $files
     */
    public function addFunctionsFile(string|array $files): void
    {
        if (is_array($files)) {
            foreach ($files as $file) {
                $this->addFunctionsFile($file);
            }

            return;
        }

        if (!is_file($files)) {
            return;
        }

        foreach (TwigFunctionLoader::registerableNames($files) as $name) {
            $this->addInternalFunction($name);
        }
    }

    /**
     * render view
     *
     * @param TemplateReferenceInterface|string $name
     * @param array $parameters
     * @return string
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function render(TemplateReferenceInterface|string $name, array $parameters = []): string
    {
        return $this->template->render($name, $parameters);
    }

    /**
     * exists view
     *
     * @param TemplateReferenceInterface|string $name
     * @return bool
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function exists(TemplateReferenceInterface|string $name): bool
    {
        try {
            $this->template->load($name);
        } catch (LoaderError $e) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(TemplateReferenceInterface|string $name): bool
    {
        $reference = $this->parser->parse($name);

        return 'twig' === $reference->get('engine');
    }
}