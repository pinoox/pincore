<?php

use Pinoox\Terminal\App\AppDomainCommand;
use Pinoox\Terminal\App\AppListCommand;
use Pinoox\Terminal\App\AppResolveCommand;
use Pinoox\Terminal\App\AppRouterCommand;
use Pinoox\Terminal\Cache\CacheBuildCommand;
use Pinoox\Terminal\Controller\ControllerCreateCommand;
use Pinoox\Terminal\Deps\DepsCommand;
use Pinoox\Terminal\Docs\PinDocHtmlCommand;
use Pinoox\Terminal\GraphQL\GraphQLDocsCommand;
use Pinoox\Terminal\Log\LogClearCommand;
use Pinoox\Terminal\Log\LogViewCommand;
use Pinoox\Terminal\Migrate\MigrateCommand;
use Pinoox\Terminal\Migrate\MigrateCreateCommand;
use Pinoox\Terminal\Migrate\MigrateRollbackCommand;
use Pinoox\Terminal\Migrate\MigrateStatusCommand;
use Pinoox\Terminal\Model\ModelCreateCommand;
use Pinoox\Terminal\Patch\PatchCreateCommand;
use Pinoox\Terminal\Patch\PatchRollbackCommand;
use Pinoox\Terminal\Patch\PatchRunCommand;
use Pinoox\Terminal\Patch\PatchStatusCommand;
use Pinoox\Terminal\Pincore\CacheClearCommand;
use Pinoox\Terminal\Pincore\DeleteAppCommand;
use Pinoox\Terminal\Pincore\MakeAppCommand;
use Pinoox\Terminal\Pincore\ModeShowCommand;
use Pinoox\Terminal\Pincore\ResetCommand;
use Pinoox\Terminal\Pincore\VersionCommand;
use Pinoox\Terminal\Pinker\PinkerClearCommand;
use Pinoox\Terminal\Pinker\PinkerDiffCommand;
use Pinoox\Terminal\Pinker\PinkerOverridesCommand;
use Pinoox\Terminal\Pinker\PinkerRebuildCommand;
use Pinoox\Terminal\Pinker\PinkerStatusCommand;
use Pinoox\Terminal\Pinx\PinxBuildCommand;
use Pinoox\Terminal\Pinx\PinxInfoCommand;
use Pinoox\Terminal\Pinx\PinxInstallCommand;
use Pinoox\Terminal\Pinx\PinxSignKeygenCommand;
use Pinoox\Terminal\Portal\CreatePortalCommand;
use Pinoox\Terminal\Portal\UpdatePortalCommand;
use Pinoox\Terminal\Request\FormRequestCreateCommand;
use Pinoox\Terminal\Router\RouteActionsCommand;
use Pinoox\Terminal\Schedule\ScheduleListCommand;
use Pinoox\Terminal\Schedule\ScheduleRunCommand;
use Pinoox\Terminal\Seeder\SeederCommand;
use Pinoox\Terminal\Seeder\SeederCreateCommand;
use Pinoox\Terminal\Serve\ServeCommand;
use Pinoox\Terminal\Test\TestCommand;
use Pinoox\Terminal\Test\TestCreateCommand;
use Pinoox\Terminal\Theme\ThemeFrontendCommand;
use Pinoox\Terminal\Wizard\WizardExportCommand;
use Pinoox\Terminal\Wizard\WizardInstallCommand;
use Pinoox\Terminal\Wizard\WizardListCommand;
use Pinoox\Terminal\Api\ApiDocsCommand;
use Symfony\Component\Console\Tester\CommandTester;

it('registers app management CLI commands', function () {
    $application = cliApplication([
        new AppListCommand(),
        new AppResolveCommand(),
        new AppRouterCommand(),
        new AppDomainCommand(),
    ]);

    expect($application->has('app:list'))->toBeTrue()
        ->and($application->has('app:resolve'))->toBeTrue()
        ->and($application->has('app:router'))->toBeTrue()
        ->and($application->has('app:domain'))->toBeTrue();
});

it('registers migration and patch CLI commands', function () {
    $application = cliApplication([
        new MigrateCommand(),
        new MigrateCreateCommand(),
        new MigrateRollbackCommand(),
        new MigrateStatusCommand(),
        new PatchCreateCommand(),
        new PatchRunCommand(),
        new PatchRollbackCommand(),
        new PatchStatusCommand(),
    ]);

    expect($application->has('migrate'))->toBeTrue()
        ->and($application->has('migrate:create'))->toBeTrue()
        ->and($application->has('patch:run'))->toBeTrue()
        ->and($application->has('patch:status'))->toBeTrue();
});

it('registers pinker and pincore maintenance CLI commands', function () {
    $application = cliApplication([
        new PinkerStatusCommand(),
        new PinkerDiffCommand(),
        new PinkerOverridesCommand(),
        new PinkerRebuildCommand(),
        new PinkerClearCommand(),
        new CacheClearCommand(),
        new ModeShowCommand(),
        new ResetCommand(),
        new DeleteAppCommand(),
        new MakeAppCommand(),
    ]);

    expect($application->has('pinker:status'))->toBeTrue()
        ->and($application->has('pinker:diff'))->toBeTrue()
        ->and($application->has('cache:clear'))->toBeTrue()
        ->and($application->has('mode:show'))->toBeTrue();
});

it('registers pinx packaging CLI commands', function () {
    $application = cliApplication([
        new PinxInfoCommand(),
        new PinxBuildCommand(),
        new PinxInstallCommand(),
        new PinxSignKeygenCommand(),
        new WizardListCommand(),
        new WizardInstallCommand(),
        new WizardExportCommand(),
    ]);

    expect($application->has('pinx:info'))->toBeTrue()
        ->and($application->has('pinx:build'))->toBeTrue()
        ->and($application->has('wizard:list'))->toBeTrue();
});

it('registers scaffold generator CLI commands', function () {
    $application = cliApplication([
        new ControllerCreateCommand(),
        new ModelCreateCommand(),
        new CreatePortalCommand(),
        new UpdatePortalCommand(),
        new FormRequestCreateCommand(),
        new SeederCreateCommand(),
        new TestCreateCommand(),
    ]);

    expect($application->has('controller:create'))->toBeTrue()
        ->and($application->has('model:create'))->toBeTrue()
        ->and($application->has('portal:create'))->toBeTrue()
        ->and($application->has('test:create'))->toBeTrue();
});

it('registers docs logging schedule and utility CLI commands', function () {
    $application = cliApplication([
        new ApiDocsCommand(),
        new GraphQLDocsCommand(),
        new PinDocHtmlCommand(),
        new LogViewCommand(),
        new LogClearCommand(),
        new ScheduleListCommand(),
        new ScheduleRunCommand(),
        new SeederCommand(),
        new RouteActionsCommand(),
        new ThemeFrontendCommand(),
        new CacheBuildCommand(),
        new DepsCommand(),
        new TestCommand(),
        new ServeCommand(),
    ]);

    expect($application->has('api:docs'))->toBeTrue()
        ->and($application->has('graphql:docs'))->toBeTrue()
        ->and($application->has('log:view'))->toBeTrue()
        ->and($application->has('schedule:list'))->toBeTrue()
        ->and($application->has('serve'))->toBeTrue();
});

it('prints version output via version command', function () {
    $application = cliApplication([new VersionCommand()]);
    $tester = new CommandTester($application->find('version'));

    $status = $tester->execute(['--kernel' => true], ['interactive' => false]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toContain('Kernel (pincore)');
});

it('uses optional package arguments on migrate and seeder commands', function () {
    foreach ([
        new MigrateCommand(),
        new MigrateStatusCommand(),
        new SeederCommand(),
        new PatchRunCommand(),
    ] as $command) {
        cliExpectOptionalPackageArgument($command);
    }
});
