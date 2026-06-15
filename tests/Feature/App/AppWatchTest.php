<?php
use Pinoox\Component\AppEvent\AppBootstrap;
use Pinoox\Component\AppEvent\AppCoreEventDispatcher;
use Pinoox\Component\AppEvent\AppEventNames;
use Pinoox\Component\AppEvent\AppRouteMatchedEvent;
use Pinoox\Component\AppEvent\AppWatchContext;
use Pinoox\Component\AppEvent\AppWatchRegistry;
use Pinoox\Component\AppEvent\AppWatchSubscriber;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Collection;
use Pinoox\Component\Router\Route;
beforeEach(function () {
    pinooxBoot();
    AppBootstrap::resetState();
});
afterEach(function () {
    AppBootstrap::resetState();
});
it('collects route watches from boot.php', function () {
    $package = appWatchPackage();
    fakeApp($package, [
        'app.php' => appWatchManifest($package),
        'boot.php' => appWatchBootFile(<<<'PHP'
$register->onRoute('ping', function (AppWatchContext $ctx): void {
    file_put_contents(__DIR__ . '/.watch-marker', $ctx->routeName() ?? '');
});
PHP),
        'routes/web.php' => appWatchWebRoute(),
    ]);
    AppBootstrap::markKernelReady();
    AppBootstrap::ensure($package, true);
    expect(AppWatchRegistry::rules())->toHaveCount(1)
        ->and(AppWatchRegistry::rules()[0]['kind'])->toBe('route')
        ->and(AppWatchRegistry::rules()[0]['match'])->toBe('ping');
    deleteFakeApp($package);
});
it('runs route watch handler after route match', function () {
    $package = appWatchPackage();
    fakeApp($package, [
        'app.php' => appWatchManifest($package),
        'boot.php' => appWatchBootFile(<<<'PHP'
$register->onRoute('ping', function (AppWatchContext $ctx): void {
    file_put_contents(__DIR__ . '/.watch-fired', $ctx->routeName() ?? 'missing');
});
PHP),
        'routes/web.php' => appWatchWebRoute(),
    ]);
    AppBootstrap::markKernelReady();
    AppBootstrap::ensure($package, true);
    $collection = new Collection();
    $route = new Route($collection, '/ping', fn () => 'pong', name: 'ping');
    $request = Request::create('http://localhost/' . $package . '/ping', 'GET');
    $request->attributes->set('_router', $route);
    inApp($package, function () use ($request, $route, $package) {
        (new AppWatchSubscriber())->onRouteMatched(new AppRouteMatchedEvent($package, $request, $route));
    });
    $marker = appPath($package) . '/.watch-fired';
    expect(is_file($marker))->toBeTrue()
        ->and(trim((string) file_get_contents($marker)))->toBe('ping');
    @unlink($marker);
    deleteFakeApp($package);
});
it('filters watches by active package when package option is set', function () {
    AppWatchRegistry::reset();
    AppWatchRegistry::add('com_plugin', [
        'kind' => 'path',
        'match' => '/any',
        'handler' => static fn () => file_put_contents(sys_get_temp_dir() . '/watch-should-not-run', 'x'),
        'package' => 'com_host_only',
    ]);
    fakeApp('com_other', ['app.php' => appWatchManifest('com_other')]);
    inApp('com_other', function () {
        $collection = new Collection();
        $route = new Route($collection, '/any', fn () => 'ok', name: 'any');
        $request = Request::create('http://localhost/any', 'GET');
        $request->attributes->set('_router', $route);
        (new AppWatchSubscriber())->onRouteMatched(new AppRouteMatchedEvent('com_other', $request, $route));
    });
    expect(is_file(sys_get_temp_dir() . '/watch-should-not-run'))->toBeFalse();
    deleteFakeApp('com_other');
});
function appWatchPackage(): string
{
    return 'com_watch_' . bin2hex(random_bytes(4));
}
function appWatchManifest(string $package): string
{
    return '<?php return ' . var_export([
        'package' => $package,
        'name' => $package,
        'enable' => true,
        'router' => ['routes' => ['routes/web.php']],
    ], true) . ';';
}
function appWatchBootFile(string $body): string
{
    return <<<PHP
<?php
use Pinoox\Component\AppEvent\AppRegister;
use Pinoox\Component\AppEvent\AppWatchContext;
return function (AppRegister \$register): void {
    {$body}
};
PHP;
}
function appWatchWebRoute(): string
{
    return <<<'PHP'
<?php
use function Pinoox\Router\get;
get('/ping', fn () => 'pong')->name('ping');
PHP;
}
