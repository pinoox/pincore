<?php

use Pinoox\Component\Template\Engine\TwigEngine;
use Pinoox\Component\Template\Parser\TemplateNameParser;
use Pinoox\Component\Translator\TranslationLine;

it('normalizes structured html translation entries', function () {
    expect(TranslationLine::normalize('plain'))
        ->toBe('plain')
        ->and(TranslationLine::normalize(['html' => true, 'text' => 'مقالات <span>تازه</span>']))
        ->toBe('مقالات <span>تازه</span>')
        ->and(TranslationLine::normalize(['html' => 'مقالات <span>تازه</span>']))
        ->toBe('مقالات <span>تازه</span>')
        ->and(TranslationLine::isHtml(['html' => true, 'text' => 'x']))
        ->toBeTrue()
        ->and(TranslationLine::isHtml(['html' => 'x']))
        ->toBeTrue()
        ->and(TranslationLine::isHtml('plain'))
        ->toBeFalse();
});

it('unwraps structured lines in translation_line helper', function () {
    $line = ['html' => true, 'text' => 'Articles <em>new</em>'];

    expect(translation_line($line))->toBe('Articles <em>new</em>');
});

it('escapes html in twig for t but not for t_html', function () {
    $line = 'مقالات <span>تازه</span>';
    $callback = static fn () => $line;

    $engine = new TwigEngine(new TemplateNameParser(), sys_get_temp_dir());
    $engine->addCallableFunction('t_html', $callback, ['is_safe' => ['html']]);
    $engine->addCallableFunction('t', $callback);
    $engine->setTemplate('html.twig', '{{ t_html() }}');
    $engine->setTemplate('plain.twig', '{{ t() }}');

    expect($engine->render('html.twig'))->toBe($line)
        ->and($engine->render('plain.twig'))->toBe('مقالات &lt;span&gt;تازه&lt;/span&gt;');
});
