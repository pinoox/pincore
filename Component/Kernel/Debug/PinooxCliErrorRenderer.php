<?php

namespace Pinoox\Component\Kernel\Debug;

use Pinoox\Component\Kernel\Debug\Support\ExceptionContext;
use Pinoox\Component\Kernel\Debug\Support\ExceptionHintResolver;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;

class PinooxCliErrorRenderer
{
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

        $lines = [
            '',
            $this->line('Pinoox Exception', '1;97', '41'),
            $this->line($exception->getClass(), '1;91'),
            $this->wrap($exception->getMessage() !== '' ? $exception->getMessage() : 'No exception message.', 2, '97'),
            '',
            $this->field('Location', $this->relativeLocation($throwable->getFile(), $throwable->getLine())),
            $this->field('PHP', PHP_VERSION . ' (' . PHP_SAPI . ')'),
            $this->field('Project', $this->projectDir),
        ];

        $package = (string)($context['package'] ?? '');
        if ($package !== '') {
            $lines[] = $this->field('Package', $package);
        }

        $lines[] = '';
        $lines[] = $this->section('Hints');

        foreach ($hints as $hint) {
            $lines[] = $this->bullet((string)($hint['title'] ?? 'Hint'), (string)($hint['summary'] ?? ''));

            foreach (($hint['steps'] ?? []) as $step) {
                if (is_scalar($step) && (string)$step !== '') {
                    $lines[] = '    - ' . (string)$step;
                }
            }
        }

        $lines[] = '';
        $lines[] = $this->section('Trace');

        $frames = array_slice($throwable->getTrace(), 0, 12);
        array_unshift($frames, [
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'function' => '{main}',
        ]);

        foreach ($frames as $index => $frame) {
            $file = isset($frame['file']) ? $this->relativeLocation((string)$frame['file'], (int)($frame['line'] ?? 0)) : '[internal]';
            $call = $this->frameCall($frame);
            $lines[] = sprintf('  #%02d %s %s', $index, $this->color($file, '2;37'), $call);
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
            $this->line('Command usage error', '1;97', '43'),
            $this->wrap($message, 2, '97'),
            '',
        ];

        if ($command !== null && $command !== '') {
            $lines[] = $this->field('Command', $command);
        }

        if ($extra !== null && $extra !== '') {
            $lines[] = $this->field('Unexpected', $extra);
        }

        if ($suggestions !== []) {
            $lines[] = '';
            $lines[] = $this->section('Try');
            foreach ($suggestions as $suggestion) {
                $lines[] = '  - ' . $suggestion;
            }
        }

        $lines[] = '';
        $lines[] = $this->color('Run "' . $script . ' help ' . ($command ?: 'command') . '" for command-specific usage.', '2;37');
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function scriptName(array $argv): string
    {
        $script = str_replace('\\', '/', (string)($argv[0] ?? 'php pinoox'));

        if (str_ends_with($script, '/pinoox') || $script === 'pinoox') {
            return 'php pinoox';
        }

        return basename($script) ?: 'php pinoox';
    }

    private function consoleUsageSuggestions(string $message, string $script, ?string $command, ?string $extra): array
    {
        $suggestions = [];

        if ($extra !== null && $extra !== '' && str_contains($message, 'No arguments expected')) {
            if (str_contains($extra, ':')) {
                $suggestions[] = $this->color($script . ' ' . $extra, '1;32') . ' if "' . $extra . '" is the command you wanted.';
            }

            if ($command !== null && $command !== '') {
                $suggestions[] = $this->color($script . ' ' . $command, '1;32') . ' if you wanted to run "' . $command . '" without extra arguments.';
                $suggestions[] = $this->color($script . ' help ' . $command, '1;32') . ' to see accepted options and arguments.';
            }
        }

        if ($suggestions === []) {
            $suggestions[] = $this->color($script . ' list', '1;32') . ' to list available commands.';
        }

        return $suggestions;
    }

    private function section(string $title): string
    {
        return $this->color($title, '1;96');
    }

    private function field(string $label, string $value): string
    {
        return '  ' . $this->color(str_pad($label . ':', 10), '1;36') . ' ' . $value;
    }

    private function bullet(string $title, string $summary): string
    {
        $text = '  * ' . $this->color($title, '1;93');

        return $summary !== '' ? $text . ' - ' . $summary : $text;
    }

    private function line(string $text, string $fg, ?string $bg = null): string
    {
        $code = $bg === null ? $fg : $fg . ';' . $bg;

        return $this->color(' ' . $text . ' ', $code);
    }

    private function wrap(string $text, int $indent = 0, string $color = '0'): string
    {
        $prefix = str_repeat(' ', $indent);
        $wrapped = wordwrap($text, 100 - $indent, PHP_EOL . $prefix, true);

        return $prefix . $this->color($wrapped, $color);
    }

    private function frameCall(array $frame): string
    {
        if (($frame['function'] ?? '') === '{main}') {
            return '{main}';
        }

        $class = (string)($frame['class'] ?? '');
        $type = (string)($frame['type'] ?? '');
        $function = (string)($frame['function'] ?? '');

        return $this->color($class . $type . $function . '()', '37');
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
        return rtrim(str_replace('\\', '/', (string)$path), '/');
    }

    private function color(string $text, string $code): string
    {
        if (!$this->decorated) {
            return $text;
        }

        return "\033[" . $code . 'm' . $text . "\033[0m";
    }

    private function detectColorSupport(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\' && function_exists('sapi_windows_vt100_support')) {
            $stream = defined('STDERR') ? STDERR : STDOUT;
            @sapi_windows_vt100_support($stream, true);
        }

        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }

        return function_exists('stream_isatty') && defined('STDERR') && @stream_isatty(STDERR);
    }
}
