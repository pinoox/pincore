<?php

use Pinoox\Component\Database\Patch\PatchBase;
use Pinoox\Component\Helpers\ConsoleApplication as ConsoleApplicationHelper;
use Pinoox\Terminal\Patch\PatchCreateCommand;
use Pinoox\Terminal\Patch\PatchRollbackCommand;
use Pinoox\Terminal\Patch\PatchRunCommand;
use Pinoox\Terminal\Patch\PatchStatusCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

it('matches timestamped patch files by their short name', function () {
    $command = new PatchRollbackCommand();
    $method = new ReflectionMethod($command, 'matches');
    $method->setAccessible(true);

    expect($method->invoke($command, [
        'name' => '2026_06_06_085158_test',
        'class' => 'Pinoox\\Patches\\Patch@anonymous',
    ], 'test'))->toBeTrue()
        ->and($method->invoke($command, [
            'name' => '2026_06_06_085158_test',
            'class' => 'Pinoox\\Patches\\Patch@anonymous',
        ], '2026_06_06_085158_test'))->toBeTrue();
});

it('runs the new up method through the legacy run entrypoint', function () {
    if (!class_exists('PatchRunCompatibilityTestPatch')) {
        eval('class PatchRunCompatibilityTestPatch extends \Pinoox\Component\Database\Patch\PatchBase { public bool $executed = false; public function up(): void { $this->executed = true; } }');
    }

    $class = new ReflectionClass('PatchRunCompatibilityTestPatch');

    $patch = $class->newInstanceWithoutConstructor();

    $patch->run();

    expect($patch->executed)->toBeTrue();
});

it('requires interactive package selection for patch commands without package input', function () {
    foreach ([
        new PatchCreateCommand(),
        new PatchRunCommand(),
        new PatchRollbackCommand(),
        new PatchStatusCommand(),
    ] as $command) {
        $argument = $command->getDefinition()->getArgument('package');

        expect($argument->isRequired())->toBeFalse()
            ->and($argument->getDefault())->toBeNull();
    }

    $rollbackPatch = (new PatchRollbackCommand())->getDefinition()->getArgument('patch');
    expect($rollbackPatch->isRequired())->toBeFalse();
});

it('lets CLI commands select a package interactively by number', function () {
    $command = new class extends \Pinoox\Component\Terminal {
        use \Pinoox\Terminal\Concerns\SelectsPackage;

        protected function configure(): void
        {
            $this->setName('test:package-select');
            $this->addArgument('package', InputArgument::OPTIONAL);
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            parent::execute($input, $output);
            $this->resolvePackageRequired($input, $output, new SymfonyStyle($input, $output));

            return \Symfony\Component\Console\Command\Command::SUCCESS;
        }
    };

    $application = new Application();
    ConsoleApplicationHelper::addCommand($application, $command);

    $tester = new CommandTester($application->find('test:package-select'));
    $tester->setInputs(['0']);

    $status = $tester->execute([]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toContain('Available packages')
        ->and($tester->getDisplay())->toContain('platform');
});

it('matches timestamped patch files in PatchRunCommand by short name and full name', function () {
    $command = new PatchRunCommand();

    expect($command->matches([
        'name' => '2026_06_10_143000_fix_contact_status',
        'class' => 'App\\com_acme_shop\\patches\\class@anonymous',
    ], 'fix_contact_status'))->toBeTrue()
        ->and($command->matches([
            'name' => '2026_06_10_143000_fix_contact_status',
            'class' => 'App\\com_acme_shop\\patches\\class@anonymous',
        ], '2026_06_10_143000_fix_contact_status'))->toBeTrue()
        ->and($command->matches([
            'name' => '2026_06_10_143000_fix_contact_status',
            'class' => 'App\\com_acme_shop\\patches\\class@anonymous',
        ], 'different_patch'))->toBeFalse();
});

it('verifies that hasRun only considers successful patches and prevents multiple runs', function () {
    $toolkit = new \Pinoox\Component\Database\Patch\PatchToolkit();
    $toolkit->package('platform');

    (new \Pinoox\Component\Migration\Migrator('platform'))->run();

    \Pinoox\Model\HistoryModel::where('type', \Pinoox\Component\Migration\MigrationQuery::TYPE_PATCH)
        ->where('app', 'platform')
        ->where('migration', 'test_patch_unique_run')
        ->delete();

    expect($toolkit->hasRun('test_patch_unique_run'))->toBeFalse();

    // 1. Failed patch record -> hasRun must be FALSE
    $toolkit->recordFailed('test_patch_unique_run', new \Exception('Failed test'));
    expect($toolkit->hasRun('test_patch_unique_run'))->toBeFalse();

    // 2. Skipped patch record -> hasRun must be FALSE
    $toolkit->recordSkipped('test_patch_unique_run');
    expect($toolkit->hasRun('test_patch_unique_run'))->toBeFalse();

    // 3. Success patch record -> hasRun must be TRUE (strictly preventing re-run)
    $toolkit->recordSuccess('test_patch_unique_run');
    expect($toolkit->hasRun('test_patch_unique_run'))->toBeTrue();

    // Clean up after test
    \Pinoox\Model\HistoryModel::where('type', \Pinoox\Component\Migration\MigrationQuery::TYPE_PATCH)
        ->where('app', 'platform')
        ->where('migration', 'test_patch_unique_run')
        ->delete();
});

it('prevents duplicate skipped records in history', function () {
    $toolkit = new \Pinoox\Component\Database\Patch\PatchToolkit();
    $toolkit->package('platform');

    \Pinoox\Model\HistoryModel::where('type', \Pinoox\Component\Migration\MigrationQuery::TYPE_PATCH)
        ->where('app', 'platform')
        ->where('migration', 'test_patch_skip_idempotent')
        ->delete();

    $toolkit->recordSkipped('test_patch_skip_idempotent');
    $count1 = \Pinoox\Model\HistoryModel::where('type', \Pinoox\Component\Migration\MigrationQuery::TYPE_PATCH)
        ->where('app', 'platform')
        ->where('migration', 'test_patch_skip_idempotent')
        ->count();

    expect($count1)->toBe(1);

    // Calling recordSkipped again should be a no-op
    $toolkit->recordSkipped('test_patch_skip_idempotent');
    $count2 = \Pinoox\Model\HistoryModel::where('type', \Pinoox\Component\Migration\MigrationQuery::TYPE_PATCH)
        ->where('app', 'platform')
        ->where('migration', 'test_patch_skip_idempotent')
        ->count();

    expect($count2)->toBe(1);

    // Clean up
    \Pinoox\Model\HistoryModel::where('type', \Pinoox\Component\Migration\MigrationQuery::TYPE_PATCH)
        ->where('app', 'platform')
        ->where('migration', 'test_patch_skip_idempotent')
        ->delete();
});

it('allows re-running patch after rollback by deleting success record', function () {
    $toolkit = new \Pinoox\Component\Database\Patch\PatchToolkit();
    $toolkit->package('platform');

    (new \Pinoox\Component\Migration\Migrator('platform'))->run();

    \Pinoox\Model\HistoryModel::where('type', \Pinoox\Component\Migration\MigrationQuery::TYPE_PATCH)
        ->where('app', 'platform')
        ->where('migration', 'test_patch_rollback_flow')
        ->delete();

    // 1. Initial state: has not run
    expect($toolkit->hasRun('test_patch_rollback_flow'))->toBeFalse();

    // 2. Patch succeeds: recorded as success
    $toolkit->recordSuccess('test_patch_rollback_flow');
    expect($toolkit->hasRun('test_patch_rollback_flow'))->toBeTrue();

    // 3. Patch is rolled back: deleteSuccessRecord is called and rolled_back recorded
    $toolkit->deleteSuccessRecord('test_patch_rollback_flow');
    $toolkit->recordRolledBack('test_patch_rollback_flow');

    // hasRun is now FALSE again, allowing the patch to run again if needed
    expect($toolkit->hasRun('test_patch_rollback_flow'))->toBeFalse();

    // Clean up
    \Pinoox\Model\HistoryModel::where('type', \Pinoox\Component\Migration\MigrationQuery::TYPE_PATCH)
        ->where('app', 'platform')
        ->where('migration', 'test_patch_rollback_flow')
        ->delete();
});



