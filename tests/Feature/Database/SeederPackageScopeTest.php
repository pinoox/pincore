<?php

use Pinoox\Component\Database\Seeder\SeederRunner;
use Pinoox\Component\Package\AppLayer;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Component\Transport\TransportRuntime;
use Pinoox\Model\UserModel;
use Pinoox\Portal\App\App;
use Pinoox\Support\PackageContext;

beforeEach(function () {
    PackageContext::use(null);
    TransportRuntime::clear();
    unset($GLOBALS['pinoox_seeder_scope']);
});

afterEach(function () {
    PackageContext::use(null);
    TransportRuntime::clear();
    unset($GLOBALS['pinoox_seeder_scope']);
    AppTestKit::deleteFakeApp('com_seed_guest');
    AppTestKit::deleteFakeApp('com_seed_host');
    AppTestKit::cleanupTransientArtifacts(false);
});

it('seeds user-table package from the seeder app, not the host default route app', function () {
    $host = 'com_seed_host';
    $guest = 'com_seed_guest';

    AppTestKit::fakeApp($host);
    AppTestKit::fakeApp($guest, [
        'database/seeders/AdminUserSeeder.php' => <<<'PHP'
<?php

use Pinoox\Component\Database\Seeder\SeederBase;
use Pinoox\Component\Transport\TransportRuntime;
use Pinoox\Model\UserModel;
use Pinoox\Portal\App\App;
use Pinoox\Support\PackageContext;

return new class extends SeederBase
{
    public function run(): void
    {
        $GLOBALS['pinoox_seeder_scope'] = [
            'context' => PackageContext::runtime(),
            'transport' => TransportRuntime::active(),
            'user' => UserModel::getPackage(),
            'app' => App::package(),
        ];
    }
};
PHP,
    ]);

    App::setLayer(new AppLayer('/', $host));

    $count = (new SeederRunner())->run('AdminUserSeeder', $guest);

    expect($count)->toBe(1)
        ->and($GLOBALS['pinoox_seeder_scope']['context'])->toBe($guest)
        ->and($GLOBALS['pinoox_seeder_scope']['transport'])->toBe($guest)
        ->and($GLOBALS['pinoox_seeder_scope']['user'])->toBe($guest)
        ->and($GLOBALS['pinoox_seeder_scope']['app'])->toBe($host);
});

it('restores outer package context after loading seeders', function () {
    $guest = 'com_seed_guest';
    AppTestKit::fakeApp($guest, [
        'database/seeders/AdminUserSeeder.php' => <<<'PHP'
<?php

use Pinoox\Component\Database\Seeder\SeederBase;

return new class extends SeederBase
{
    public function run(): void
    {
    }
};
PHP,
    ]);

    PackageContext::use('com_outer');

    (new SeederRunner())->run('AdminUserSeeder', $guest);

    expect(PackageContext::runtime())->toBe('com_outer');
});
