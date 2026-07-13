<?php

namespace Pinoox\Flow;

use Pinoox\Component\Flow\Flow;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Store\Baker\ExportableManifestValue;
use Pinoox\Component\Template\Theme\ThemeContext as ThemeContextManager;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Switches the active theme context for the current request (site, panel, kids, ...).
 *
 * Register via theme_flow_aliases() in app.php and attach to routes:
 *   flows: ['theme.panel']
 */
class ThemeContextFlow extends Flow implements ExportableManifestValue
{
    /** @var array<string, self> */

    private static array $instances = [];

    public function __construct(
        ?RequestEvent $requestEvent = null,
        private readonly string $context = 'default',
    ) {
        parent::__construct($requestEvent);
    }

    public static function for(string $context): self
    {
        return self::$instances[$context] ??= new self(null, $context);
    }

    public function context(): string
    {
        return $this->context;
    }

    public function exportForPinker(): string
    {
        return '\\' . self::class . '::for(' . var_export($this->context, true) . ')';
    }

    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        $context = $properties['context'] ?? 'default';

        return self::for(is_string($context) && $context !== '' ? $context : 'default');
    }

    protected function before(Request $request): void
    {
        ThemeContextManager::activate($this->context);
    }
}

