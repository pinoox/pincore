<?php

namespace Pinoox\Component\Kernel\Debug;

use Pinoox\Component\Kernel\Debug\Support\ExceptionContext;
use Pinoox\Component\Kernel\Debug\Support\ExceptionHintResolver;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;

class PinooxCliErrorRenderer
{
    private const DEFAULT_WIDTH = 72;

    private string $projectDir;
    private bool $decorated;

    public function __construct(?string $projectDir = null, ?bool $decorated = null)
    {
        $this->projectDir = $this->normalizePath($projectDir ?? (defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : getcwd()));
        $this->decorated = $decorated ?? $this->detectColorSupport();
    }

    public function render(\Throwable $throwable): string
    {
        if ($throwable instanceof ConsoleExceptionInterface) {
            return $this->renderConsoleUsageError($throwable);
        }

        $exception = FlattenException::createFromThrowable($throwable);
        $context = ExceptionContext::collect($exception);
        $hints = ExceptionHintResolver::resolve($exception, $context);
        $class = $exception->getClass();

        $lines = [
            '',
            $this->banner('Pinoox Exception', '1;97', '41'),
            $this->rule(),
            $this->shortClass($class),
            $this->dim('  ' . $class),
            '',
            $this->wrap($exception->getMessage() !== '' ? $exception->getMessage() : 'No exception message.'),
            '',
            $this->rule('-'),
            $this->field('Location', $this->relativeLocation($throwable->getFile(), $throwable->getLine()), '1;97'),
            $this->field('PHP', PHP_VERSION . ' (' . PHP_SAPI . ')', '2;37'),
            $this->field('Project', $this->projectDir, '2;37'),
        ];

        $package = (string) ($context['package'] ?? '');
        if ($package !== '') {
            $lines[] = $this->field('Package', $package, '1;95');
        }

        $lines[] = '';
        $lines[] = $this->section('Hints');
        $lines[] = '';

        foreach ($hints as $hint) {
            $lines[] = $this->bullet((string) ($hint['title'] ?? 'Hint'), (string) ($hint['summary'] ?? ''));

            foreach (($hint['steps'] ?? []) as $step) {
                if (is_scalar($step) && (string) $step !== '') {
                    $lines[] = '    ' . $this->color('>', '1;96') . ' ' . (string) $step;
                }
            }
        }

        $lines[] = '';
        $lines[] = $this->section('Trace');
        $lines[] = '';

        $frames = array_slice($throwable->getTrace(), 0, 12);
        array_unshift($frames, [
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'function' => '{main}',
        ]);

        foreach ($frames as $index => $frame) {
            $file = isset($frame['file'])
                ? $this->relativeLocation((string) $frame['file'], (int) ($frame['line'] ?? 0))
                : '[internal]';
            $call = $this->frameCall($frame);
            $lines[] = sprintf(
                '  %s %s %s',
                $this->color(sprintf('#%02d', $index), '1;90'),
                $this->color($file, '2;37'),
                $call,
            );
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function renderConsoleUsageError(ConsoleExceptionInterface $throwable): string
    {
        $message = $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Invalid command usage.';
        $argv = array_values(array_map('strval', $_SERVER['argv'] ?? []));
        $script = $this->scriptName($argv);
        $command = $argv[1] ?? null;
        $extra = $argv[2] ?? null;
        $suggestions = $this->consoleUsageSuggestions($message, $script, $command, $extra);

        $lines = [
            '',
            $this->banner('Command usage error', '1;97', '43'),
            $this->rule(),
            $this->wrap($message),
            '',
        ];

        if ($command !== null && $command !== '') {
            $lines[] = $this->field('Command', $command);
        }

        if ($extra !== null && $extra !== '') {
            $lines[] = $this->field('Unexpected', $extra, '1;93');
        }

        if ($suggestions !== []) {
            $lines[] = '';
            $lines[] = $this->section('Try');
            $lines[] = '';

            foreach ($suggestions as $suggestion) {
                $lines[] = '  ' . $this->color('>', '1;96') . ' ' . $suggestion;
            }
        }

        $lines[] = '';
        $lines[] = $this->dim('Run "' . $script . ' help ' . ($command ?: 'command') . '" for command-specific usage.');
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function banner(string $title, string $fg, string $bg): string
    {
        $label = ' ' . $title . ' ';
        $width = max(mb_strlen($label) + 4, self::DEFAULT_WIDTH);
        $line = str_repeat(' ', max(0, (int) floor(($width - mb_strlen($label)) / 2))) . $label;
        $line = str_pad($line, $width, ' ');

        return $this->color($line, $fg, $bg);
    }

    private function rule(string $char = '-'): string
    {
        return $this->color(str_repeat($char, self::DEFAULT_WIDTH), '2;37');
    }

    private function shortClass(string $class): string
    {
        $short = $class;
        if (str_contains($class, '\\')) {
            $short = substr($class, strrpos($class, '\\') + 1);
        }

        return $this->color($short, '1;91');
    }

    private function dim(string $text): string
    {
        return $this->color($text, '2;37');
    }

    private function scriptName(array $argv): string
    {
        $script = str_replace('\\', '/', (string) ($argv[0] ?? 'php pinoox'));

        if (str_ends_with($script, '/pinoox') || $script === 'pinoox') {
            return 'php pinoox';
        }

        if (str_ends_with($script, '/pincore') || $script === 'pincore') {
            return 'php pincore';
        }

        return basename($script) ?: 'php pinoox';
    }

    private function consoleUsageSuggestions(string $message, string $script, ?string $command, ?string $extra): array
    {
        $suggestions = [];

        if ($extra !== null && $extra !== '' && str_contains($message, 'No arguments expected')) {
            if (str_contains($extra, ':')) {
                $suggestions[] = $this->color($script . ' ' . $extra, '1;32') . $this->dim(' - run this command instead');
            }

            if ($command !== null && $command !== '') {
                $suggestions[] = $this->color($script . ' ' . $command, '1;32') . $this->dim(' - without extra arguments');
                $suggestions[] = $this->color($script . ' help ' . $command, '1;32') . $this->dim(' - accepted options');
            }
        }

        if ($suggestions === []) {
            $suggestions[] = $this->color($script . ' list', '1;32') . $this->dim(' - all commands');
        }

        return $suggestions;
    }

    private function section(string $title): string
    {
        return $this->color($title, '1;96');
    }

    private function field(string $label, string $value, string $valueColor = '97'): string
    {
        return '  '
            . $this->color(str_pad($label . ':', 10), '1;36')
            . ' '
            . $this->color($value, $valueColor);
    }

    private function bullet(string $title, string $summary): string
    {
        $text = '  ' . $this->color('*', '1;91') . ' ' . $this->color($title, '1;93');

        return $summary !== '' ? $text . $this->color(' - ', '2;37') . $summary : $text;
    }

    private function wrap(string $text, int $indent = 2): string
    {
        $prefix = str_repeat(' ', $indent);
        $wrapped = wordwrap($text, self::DEFAULT_WIDTH - $indent, PHP_EOL . $prefix, true);

        return $prefix . $this->color($wrapped, '97');
    }

    private function frameCall(array $frame): string
    {
        if (($frame['function'] ?? '') === '{main}') {
            return $this->color('{main}', '1;37');
        }

        $class = (string) ($frame['class'] ?? '');
        $type = (string) ($frame['type'] ?? '');
        $function = (string) ($frame['function'] ?? '');

        return $this->color($class . $type . $function . '()', '1;37');
    }

    private function relativeLocation(string $file, int $line): string
    {
        $file = $this->normalizePath($file);

        if ($this->projectDir !== '' && str_starts_with($file, $this->projectDir . '/')) {
            $file = substr($file, strlen($this->projectDir) + 1);
        }

        return $line > 0 ? $file . ':' . $line : $file;
    }

    private function normalizePath(string|false|null $path): string
    {
        return rtrim(str_replace('\\', '/', (string) $path), '/');
    }

    private function color(string $text, string $code, ?string $background = null): string
    {
        if (!$this->decorated) {
            return $text;
        }

        $style = $background === null ? $code : $code . ';' . $background;

        return "\033[" . $style . 'm' . $text . "\033[0m";
    }

    private function detectColorSupport(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }

        if (DIRECTORY_SEPARATOR === '\\' && function_exists('sapi_windows_vt100_support')) {
            foreach ([STDOUT, STDERR] as $stream) {
                if (is_resource($stream)) {
                    @sapi_windows_vt100_support($stream, true);
                }
            }
        }

        return function_exists('stream_isatty') && defined('STDERR') && @stream_isatty(STDERR);
    }
}
