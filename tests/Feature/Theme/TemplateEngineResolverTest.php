<?php

use Pinoox\Component\Template\Engine\PhpEngine;
use Pinoox\Component\Template\Engine\PhpTwigEngine;
use Pinoox\Component\Template\Engine\TwigEngine;
use Pinoox\Component\Template\Parser\TemplateNameParser;
use Pinoox\Component\Template\TemplateEngineResolver;
use Pinoox\Component\Template\View;

function templateEngineResolverExists(array $files): callable
{
    return static function (string $name) use ($files): bool {
        return in_array($name, $files, true);
    };
}

it('prefers twig over php for extensionless template names', function () {
    $exists = templateEngineResolverExists(['home.twig', 'home.php']);

    expect(TemplateEngineResolver::resolve('home', $exists))->toBe('home.twig');
});

it('prefers twig.php over twig and php', function () {
    $exists = templateEngineResolverExists(['home.twig.php', 'home.twig', 'home.php']);

    expect(TemplateEngineResolver::resolve('home', $exists))->toBe('home.twig.php');
});

it('redirects explicit legacy php requests to twig when both exist', function () {
    $exists = templateEngineResolverExists(['home.twig', 'home.php']);

    expect(TemplateEngineResolver::resolve('home.php', $exists))->toBe('home.twig');
});

it('keeps explicit twig.php and twig template names', function () {
    $exists = templateEngineResolverExists(['pages/show.twig.php', 'pages/show.twig', 'pages/show.php']);

    expect(TemplateEngineResolver::resolve('pages/show.twig.php', $exists))->toBe('pages/show.twig.php')
        ->and(TemplateEngineResolver::resolve('pages/show.twig', $exists))->toBe('pages/show.twig');
});

it('detects shadowed templates for debug warnings', function () {
    $exists = templateEngineResolverExists(['home.twig', 'home.php']);

    expect(TemplateEngineResolver::shadowedTemplates('home', $exists))
        ->toEqual(['home.twig', 'home.php']);
});

it('renders twig templates through View when legacy php also exists', function () {
    $themeDir = sys_get_temp_dir() . '/pinoox_theme_engine_' . uniqid('', true);
    mkdir($themeDir, 0777, true);

    file_put_contents($themeDir . '/hello.php', '<?php echo "legacy-php";');
    file_put_contents($themeDir . '/hello.twig', 'twig-template');

    $view = new View($themeDir, '');

    expect($view->render('hello'))->toBe('twig-template')
        ->and($view->render('hello.php'))->toBe('twig-template');

    @unlink($themeDir . '/hello.php');
    @unlink($themeDir . '/hello.twig');
    @rmdir($themeDir);
});

it('uses delegating engine order twig.php then twig then php', function () {
    $parser = new TemplateNameParser();
    $paths = [sys_get_temp_dir()];

    $php = new PhpEngine($parser, $paths);
    $twig = new TwigEngine($parser, $paths);
    $phpTwig = new PhpTwigEngine($parser, $php, $twig);

    expect($phpTwig->supports('layout.twig.php'))->toBeTrue()
        ->and($twig->supports('layout.twig'))->toBeTrue()
        ->and($php->supports('layout.php'))->toBeTrue()
        ->and($twig->supports('layout.php'))->toBeFalse();
});
