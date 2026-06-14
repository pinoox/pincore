<?php

use Pinoox\Component\Token;
use Pinoox\Model\TokenModel;
use Pinoox\Terminal\Token\Concerns\ManagesCliTokens;
use Pinoox\Terminal\Token\TokenCreateCommand;
use Pinoox\Terminal\Token\TokenDeleteCommand;
use Pinoox\Terminal\Token\TokenListCommand;
use Pinoox\Terminal\Token\TokenPurgeCommand;
use Pinoox\Terminal\Token\TokenRevokeUserCommand;
use Pinoox\Terminal\Token\TokenShowCommand;
use Pinoox\Terminal\Token\TokenUpdateCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;

it('registers token CLI commands', function () {
    $application = cliApplication([
        new TokenListCommand(),
        new TokenShowCommand(),
        new TokenCreateCommand(),
        new TokenDeleteCommand(),
        new TokenUpdateCommand(),
        new TokenRevokeUserCommand(),
        new TokenPurgeCommand(),
    ]);

    expect($application->has('token:list'))->toBeTrue()
        ->and($application->has('token:show'))->toBeTrue()
        ->and($application->has('token:create'))->toBeTrue()
        ->and($application->has('token:delete'))->toBeTrue()
        ->and($application->has('token:update'))->toBeTrue()
        ->and($application->has('token:revoke-user'))->toBeTrue()
        ->and($application->has('token:purge'))->toBeTrue();
});

it('requires optional package argument on token commands', function () {
    foreach ([
        new TokenListCommand(),
        new TokenCreateCommand(),
        new TokenShowCommand(),
        new TokenDeleteCommand(),
    ] as $command) {
        $argument = $command->getDefinition()->getArgument('package');

        expect($argument->isRequired())->toBeFalse()
            ->and($argument->getDefault())->toBeNull();
    }
});

it('masks token keys for safe CLI output', function () {
    $probe = cliTraitProbe([ManagesCliTokens::class]);

    expect(cliTraitInvoke($probe, 'maskTokenKey', ''))->toBe('—')
        ->and(cliTraitInvoke($probe, 'maskTokenKey', 'short'))->toBe('*****')
        ->and(cliTraitInvoke($probe, 'maskTokenKey', 'abcdefghijklmnopqrstuvwxyz1234567890'))
        ->toBe('abcdefgh…7890');
});

it('parses token data from json or scalar input', function () {
    $probe = cliTraitProbe([ManagesCliTokens::class]);

    expect(cliTraitInvoke($probe, 'parseTokenDataInput', '{"role":"admin"}', null))
        ->toBe(['role' => 'admin'])
        ->and(cliTraitInvoke($probe, 'parseTokenDataInput', null, 'session'))
        ->toBe(['value' => 'session'])
        ->and(cliTraitInvoke($probe, 'parseTokenDataInput', null, null))
        ->toBe([]);
});

it('rejects invalid token data json', function () {
    $probe = cliTraitProbe([ManagesCliTokens::class]);

    expect(fn () => cliTraitInvoke($probe, 'parseTokenDataInput', 'not-json', null))
        ->toThrow(InvalidArgumentException::class);
});

it('labels token expiration status', function () {
    $probe = cliTraitProbe([ManagesCliTokens::class]);

    $active = new TokenModel();
    $active->expiration_date = date('Y-m-d H:i:s', time() + 3600);

    $expired = new TokenModel();
    $expired->expiration_date = date('Y-m-d H:i:s', time() - 3600);

    expect(cliTraitInvoke($probe, 'tokenStatusLabel', $active))->toBe('active')
        ->and(cliTraitInvoke($probe, 'tokenStatusLabel', $expired))->toBe('expired');
});

it('validates token lifetime options', function () {
    $probe = cliTraitProbe([ManagesCliTokens::class]);
    $command = cliTraitProbe([ManagesCliTokens::class]);
    $definition = $command->getDefinition();

    if (!$definition->hasOption('lifetime')) {
        $definition->addOption(new Symfony\Component\Console\Input\InputOption('lifetime'));
        $definition->addOption(new Symfony\Component\Console\Input\InputOption('unit'));
    }

    $input = new ArrayInput(['--lifetime' => '0', '--unit' => 'day'], $definition);

    expect(fn () => cliTraitInvoke($probe, 'applyTokenLifetime', $input))
        ->toThrow(InvalidArgumentException::class, 'positive number');

    $input = new ArrayInput(['--lifetime' => '7', '--unit' => 'week'], $definition);

    expect(fn () => cliTraitInvoke($probe, 'applyTokenLifetime', $input))
        ->toThrow(InvalidArgumentException::class, 'min, hour, or day');
});

it('prepares CLI request context with safe defaults', function () {
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

    $probe = cliTraitProbe([ManagesCliTokens::class]);
    cliTraitInvoke($probe, 'prepareCliRequestContext');

    expect($_SERVER['REMOTE_ADDR'] ?? null)->toBe('127.0.0.1')
        ->and($_SERVER['HTTP_USER_AGENT'] ?? null)->toBe('pinoox-cli');
});

it('requires token argument on show and delete commands', function () {
    foreach ([
        new TokenShowCommand(),
        new TokenDeleteCommand(),
    ] as $command) {
        $argument = $command->getDefinition()->getArgument('token');

        expect($argument)->toBeInstanceOf(InputArgument::class)
            ->and($argument->isRequired())->toBeFalse();
    }
});

it('applies default token lifetime when unset', function () {
    Token::$lifeTime = 0;

    $probe = cliTraitProbe([ManagesCliTokens::class]);
    cliTraitInvoke($probe, 'applyTokenLifetimeFromDefaults');

    expect(Token::$lifeTime)->toBeGreaterThan(0);
});
