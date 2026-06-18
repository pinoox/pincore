<?php

use Pinoox\Component\Template\Engine\TwigEngine;
use Pinoox\Component\Template\Parser\TemplateNameParser;
use Pinoox\Component\Template\TemplateHelper;
use Pinoox\Component\Template\Twig\AppFunctionFiles;
use Pinoox\Component\Template\Twig\TwigFunctionLoader;
use Pinoox\Component\Test\AppTestKit;

beforeEach(function () {
    AppTestKit::boot();
});

afterEach(function () {
    twigFunctionLoaderCleanup();
    AppTestKit::deleteFakeApp('com_test_twig_funcs');
});

it('registers only callable functions after loading a guarded functions file', function () {
    $file = twigFunctionLoaderTempFile(<<<'PHP'
<?php
if (!function_exists('twig_loader_alpha')) {
    function twig_loader_alpha(): string
    {
        return 'alpha';
    }
}

function twig_loader_beta(): string
{
    return 'beta';
}
PHP);

    expect(TwigFunctionLoader::registerableNames($file))
        ->toEqual(['twig_loader_alpha', 'twig_loader_beta']);

    $engine = new TwigEngine(new TemplateNameParser(), sys_get_temp_dir());
    $engine->addFunctionsFile($file);
    $engine->setTemplate('demo.twig', '{{ twig_loader_alpha() }}-{{ twig_loader_beta() }}');

    expect($engine->render('demo.twig'))->toBe('alpha-beta');
});

it('skips function names that are not defined at runtime', function () {
    function twig_loader_existing(): string
    {
        return 'exists';
    }

    $file = twigFunctionLoaderTempFile(<<<'PHP'
<?php
if (!function_exists('twig_loader_existing')) {
    function twig_loader_existing(): string
    {
        return 'shadow';
    }
}

function twig_loader_new_gamma(): string
{
    return 'gamma';
}
PHP);

    expect(TwigFunctionLoader::registerableNames($file))
        ->toEqual(['twig_loader_existing', 'twig_loader_new_gamma']);
});

it('resolves app function files from loader and conventional paths', function () {
    $package = 'com_test_twig_funcs';

    AppTestKit::fakeApp($package, [
        'app.php' => "<?php\n\nreturn ['package' => '{$package}', 'loader' => ['@func' => 'func.php'], 'enable' => true];\n",
        'func.php' => "<?php\n\nfunction twig_loader_from_func(): string { return 'func'; }\n",
        'functions.php' => "<?php\n\nfunction twig_loader_from_functions(): string { return 'functions'; }\n",
    ]);

    $dir = AppTestKit::path($package);

    AppTestKit::inApp($package, function () use ($package, $dir) {
        expect(AppFunctionFiles::resolve($package))->toEqual([
            $dir . '/func.php',
            $dir . '/functions.php',
        ]);
    });
});

it('supports th and head_html helpers for twig-safe html output', function () {
    head_html('<meta name="x" content="1">');
    footer_html('<script>window.__FOOTER__=1</script>');

    expect(head_html())->toContain('<meta name="x"')
        ->and(footer_html())->toContain('__FOOTER__');

    TemplateHelper::reset();

    expect(head_html())->toBe('')
        ->and(footer_html())->toBe('');
});

function twigFunctionLoaderCleanup(): void
{
    TemplateHelper::reset();
}

function twigFunctionLoaderTempFile(string $contents): string
{
    $dir = sys_get_temp_dir() . '/pinoox-twig-func-' . uniqid('', true);
    mkdir($dir, 0777, true);
    $file = $dir . '/functions.php';
    file_put_contents($file, $contents);

    return $file;
}
