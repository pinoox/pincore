<?php

use Pinoox\Component\AppEvent\AppBootstrap;
use Pinoox\Component\Event\Event;
use Pinoox\Portal\Event as EventPortal;

beforeEach(function () {
    pinooxBoot();
    AppBootstrap::resetState();
});

afterEach(function () {
    AppBootstrap::resetState();
    EventPortal::dontFake();
});

it('auto-discovers a Listener handle method without boot.php', function () {
    $package = 'com_evt_auto_' . bin2hex(random_bytes(3));
    $eventClass = 'App\\' . $package . '\\Event\\OrderPlaced';
    $listenerClass = 'App\\' . $package . '\\Listener\\OrderListener';

    fakeApp($package, [
        'app.php' => appEventDxManifest($package),
        'Event/OrderPlaced.php' => appEventDxEventFile($package),
        'Listener/OrderListener.php' => appEventDxHandleListenerFile($package),
    ]);

    AppBootstrap::markKernelReady();
    AppBootstrap::ensure($package, true);

    expect(class_exists($eventClass))->toBeTrue()
        ->and(class_exists($listenerClass))->toBeTrue();

    $listenerClass::$seen = null;
    $eventClass::dispatch(7);

    expect($listenerClass::$seen)->toBe(7);

    deleteFakeApp($package);
});

it('auto-discovers #[ListensTo] methods', function () {
    $package = 'com_evt_attr_' . bin2hex(random_bytes(3));
    $eventClass = 'App\\' . $package . '\\Event\\OrderPlaced';
    $listenerClass = 'App\\' . $package . '\\Listener\\OrderListener';

    fakeApp($package, [
        'app.php' => appEventDxManifest($package),
        'Event/OrderPlaced.php' => appEventDxEventFile($package, 'shop.order.placed'),
        'Listener/OrderListener.php' => appEventDxAttributeListenerFile($package),
    ]);

    AppBootstrap::markKernelReady();
    AppBootstrap::ensure($package, true);

    $listenerClass::$seen = null;
    $eventClass::dispatch(11);

    expect($eventClass::eventName())->toBe('shop.order.placed')
        ->and($listenerClass::$seen)->toBe(11);

    deleteFakeApp($package);
});

it('skips auto-discovery when events is false', function () {
    $package = 'com_evt_off_' . bin2hex(random_bytes(3));
    $eventClass = 'App\\' . $package . '\\Event\\OrderPlaced';
    $listenerClass = 'App\\' . $package . '\\Listener\\OrderListener';

    fakeApp($package, [
        'app.php' => appEventDxManifest($package, ['events' => false]),
        'Event/OrderPlaced.php' => appEventDxEventFile($package),
        'Listener/OrderListener.php' => appEventDxHandleListenerFile($package),
    ]);

    AppBootstrap::markKernelReady();
    AppBootstrap::ensure($package, true);

    require_once appPath($package) . '/Event/OrderPlaced.php';
    require_once appPath($package) . '/Listener/OrderListener.php';

    $listenerClass::$seen = null;
    $eventClass::dispatch(3);

    expect($listenerClass::$seen)->toBeNull();

    deleteFakeApp($package);
});

it('registers explicit listen map from app.php', function () {
    $package = 'com_evt_map_' . bin2hex(random_bytes(3));
    $eventClass = 'App\\' . $package . '\\Event\\OrderPlaced';
    $listenerClass = 'App\\' . $package . '\\Listener\\OrderListener';

    fakeApp($package, [
        'app.php' => appEventDxManifest($package, [
            'events' => [
                'discover' => false,
                'listen' => [
                    $eventClass => [$listenerClass],
                ],
            ],
        ]),
        'Event/OrderPlaced.php' => appEventDxEventFile($package),
        'Listener/OrderListener.php' => appEventDxHandleListenerFile($package),
    ]);

    AppBootstrap::markKernelReady();
    AppBootstrap::ensure($package, true);

    $listenerClass::$seen = null;
    $eventClass::dispatch(21);

    expect($listenerClass::$seen)->toBe(21);

    deleteFakeApp($package);
});

it('auto-discovers handle* methods from the event type hint', function () {
    $package = 'com_evt_handle_' . bin2hex(random_bytes(3));
    $eventClass = 'App\\' . $package . '\\Event\\OrderPlaced';
    $listenerClass = 'App\\' . $package . '\\Listener\\OrderListener';

    fakeApp($package, [
        'app.php' => appEventDxManifest($package),
        'Event/OrderPlaced.php' => appEventDxEventFile($package),
        'Listener/OrderListener.php' => appEventDxPrefixedHandleListenerFile($package),
    ]);

    AppBootstrap::markKernelReady();
    AppBootstrap::ensure($package, true);

    $listenerClass::$seen = null;
    $eventClass::dispatch(13);

    expect($listenerClass::$seen)->toBe(13);

    deleteFakeApp($package);
});

it('lets boot.php add extra listeners on top of discovery', function () {
    $package = 'com_evt_boot_' . bin2hex(random_bytes(3));
    $eventClass = 'App\\' . $package . '\\Event\\OrderPlaced';
    $listenerClass = 'App\\' . $package . '\\Listener\\OrderListener';

    fakeApp($package, [
        'app.php' => appEventDxManifest($package),
        'Event/OrderPlaced.php' => appEventDxEventFile($package),
        'Listener/OrderListener.php' => appEventDxHandleListenerFile($package),
        'boot.php' => appEventDxBootListenFile($package),
    ]);

    AppBootstrap::markKernelReady();
    AppBootstrap::ensure($package, true);

    $listenerClass::$seen = null;
    $eventClass::dispatch(5);

    expect($listenerClass::$seen)->toBe(5)
        ->and(appEventDxBootMarker($package))->toBe('5');

    deleteFakeApp($package);
});

it('resolves Event::listen with class names', function () {
    $handled = false;

    EventPortal::listen(AppEventDxPortalEvent::class, function (AppEventDxPortalEvent $event) use (&$handled) {
        $handled = $event->value === 9;
    });

    AppEventDxPortalEvent::dispatch(9);

    expect(AppEventDxPortalEvent::eventName())->toBe(AppEventDxPortalEvent::class)
        ->and($handled)->toBeTrue();
});

/**
 * @param array<string, mixed> $extra
 */
function appEventDxManifest(string $package, array $extra = []): string
{
    $config = array_replace([
        'package' => $package,
        'name' => $package,
        'enable' => true,
        'router' => ['routes' => []],
    ], $extra);

    return '<?php return ' . var_export($config, true) . ';';
}

function appEventDxEventFile(string $package, ?string $eventName = null): string
{
    $line = $eventName === null
        ? ''
        : "    public static \$eventName = '{$eventName}';\n";

    return <<<PHP
<?php

namespace App\\{$package}\\Event;

use Pinoox\\Component\\Event\\Event;

class OrderPlaced extends Event
{
{$line}    public function __construct(public readonly int \$id) {}
}
PHP;
}

function appEventDxHandleListenerFile(string $package): string
{
    return <<<PHP
<?php

namespace App\\{$package}\\Listener;

use App\\{$package}\\Event\\OrderPlaced;

class OrderListener
{
    public static ?int \$seen = null;

    public function handle(OrderPlaced \$event): void
    {
        self::\$seen = \$event->id;
    }
}
PHP;
}

function appEventDxAttributeListenerFile(string $package): string
{
    return <<<PHP
<?php

namespace App\\{$package}\\Listener;

use App\\{$package}\\Event\\OrderPlaced;
use Pinoox\\Support\\Event\\ListensTo;

class OrderListener
{
    public static ?int \$seen = null;

    #[ListensTo(OrderPlaced::class)]
    public function onPlaced(OrderPlaced \$event): void
    {
        self::\$seen = \$event->id;
    }
}
PHP;
}

function appEventDxPrefixedHandleListenerFile(string $package): string
{
    return <<<PHP
<?php

namespace App\\{$package}\\Listener;

use App\\{$package}\\Event\\OrderPlaced;

class OrderListener
{
    public static ?int \$seen = null;

    public function handleOrderPlaced(OrderPlaced \$event): void
    {
        self::\$seen = \$event->id;
    }
}
PHP;
}

function appEventDxBootListenFile(string $package): string
{
    return <<<PHP
<?php

use Pinoox\\Component\\AppEvent\\AppRegister;

return function (AppRegister \$register): void {
    \$register->listen(
        App\\{$package}\\Event\\OrderPlaced::class,
        function (\$event): void {
            file_put_contents(__DIR__ . '/.boot-event', (string) \$event->id);
        },
    );
};
PHP;
}

function appEventDxBootMarker(string $package): ?string
{
    $file = appPath($package) . '/.boot-event';

    return is_file($file) ? trim((string) file_get_contents($file)) : null;
}

class AppEventDxPortalEvent extends Event
{
    public function __construct(public readonly int $value)
    {
    }
}
