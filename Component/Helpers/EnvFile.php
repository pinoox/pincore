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

namespace Pinoox\Component\Helpers;

use Pinoox\Component\Kernel\Loader;

class EnvFile
{
    public function __construct(private readonly string $path)
    {
    }

    public static function forProject(?string $basePath = null): self
    {
        $base = $basePath ?? Loader::getBasePath();
        $root = is_string($base) && $base !== '' ? rtrim(str_replace('\\', '/', $base), '/') : getcwd();

        return new self($root . '/.env');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @param array<string, scalar|null> $variables
     */
    public function setMany(array $variables): bool
    {
        $content = $this->exists() ? (string) @file_get_contents($this->path) : $this->defaultTemplate();

        foreach ($variables as $key => $value) {
            $content = $this->setLine($content, (string) $key, $this->encode($value));
        }

        $directory = dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return false;
        }

        return @file_put_contents($this->path, $content, LOCK_EX) !== false;
    }

    /**
     * @param array<string, scalar|null> $variables
     */
    public function applyToRuntime(array $variables): void
    {
        foreach ($variables as $key => $value) {
            $encoded = $this->encode($value);
            $_ENV[$key] = $encoded;
            $_SERVER[$key] = $encoded;
            putenv($key . '=' . $encoded);
        }
    }

    /**
     * All values for a key (supports duplicate lines like multiple PINOOX_LOGIN=…).
     *
     * @return list<string>
     */
    public function getAll(string $key): array
    {
        if (!$this->exists()) {
            return [];
        }

        $content = (string) @file_get_contents($this->path);
        if (preg_match_all('/^' . preg_quote($key, '/') . '=(.*)$/m', $content, $matches) < 1) {
            return [];
        }

        $values = [];
        foreach ($matches[1] as $raw) {
            $decoded = $this->decode((string) $raw);
            if ($decoded !== '') {
                $values[] = $decoded;
            }
        }

        return $values;
    }

    /**
     * Replace every line for $key with the given values (one line each).
     * Empty $values removes the key entirely.
     *
     * @param list<string> $values
     */
    public function setLines(string $key, array $values): bool
    {
        $content = $this->exists() ? (string) @file_get_contents($this->path) : $this->defaultTemplate();
        $content = (string) preg_replace(
            '/^' . preg_quote($key, '/') . '=.*\R?/m',
            '',
            $content,
        );
        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;
        $content = rtrim($content, "\r\n");

        $block = '';
        foreach ($values as $value) {
            $encoded = $this->encode($value);
            if ($encoded === '' || $encoded === '""') {
                continue;
            }
            $block .= "\n" . $key . '=' . $encoded;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return false;
        }

        $next = $content === '' ? ltrim($block, "\n") : $content . $block;
        if ($next !== '') {
            $next .= "\n";
        }

        $ok = @file_put_contents($this->path, $next, LOCK_EX) !== false;

        if ($ok) {
            $runtime = $values === [] ? '' : (string) end($values);
            $this->applyToRuntime([$key => $runtime]);
        }

        return $ok;
    }

    /**
     * Remove keys from the .env file entirely.
     *
     * @param list<string> $keys
     */
    public function removeKeys(array $keys): bool
    {
        if (!$this->exists()) {
            return true;
        }

        $content = (string) @file_get_contents($this->path);
        foreach ($keys as $key) {
            $content = (string) preg_replace(
                '/^' . preg_quote((string) $key, '/') . '=.*\R?/m',
                '',
                $content,
            );
            putenv((string) $key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;

        return @file_put_contents($this->path, rtrim($content) . "\n", LOCK_EX) !== false;
    }

    private function defaultTemplate(): string
    {
        $example = dirname($this->path) . '/.env.example';

        if (is_file($example)) {
            return (string) @file_get_contents($example);
        }

        return "# Pinoox Environment\n";
    }

    private function setLine(string $content, string $key, string $value): string
    {
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $content) === 1) {
            return (string) preg_replace($pattern, $line, $content);
        }

        $content = rtrim($content, "\r\n");

        return $content === '' ? $line . "\n" : $content . "\n" . $line . "\n";
    }

    private function encode(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if ($value === '' || preg_match('/[\s#"\']/', $value) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }

    private function decode(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote) && strlen($value) >= 2) {
            $inner = substr($value, 1, -1);
            if ($quote === '"') {
                return str_replace(['\\\\', '\\"'], ['\\', '"'], $inner);
            }

            return $inner;
        }

        return $value;
    }
}

