<?php

use Pinoox\Terminal\Theme\DevCommand;

test('dev command registers and forwards local domain options', function () {
    $command = new DevCommand();

    expect($command->getDefinition()->hasOption('serve-domain'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('domain'))->toBeTrue();
});
