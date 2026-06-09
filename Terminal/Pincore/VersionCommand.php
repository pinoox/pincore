<?php

namespace Pinoox\Terminal\Pincore;

use Pinoox\Component\Package\Pinx\PinxVersion;
use Pinoox\Component\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'version',
    description: 'Show Pinoox platform and kernel version',
)]

class VersionCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Shows the platform distribution version from config/pinoox.config.php
and the pincore kernel version from pincore/config/pincore.config.php.

Examples:

  php pinoox version
  php pinoox version --kernel

HELP
            )
            ->addOption('kernel', 'k', InputOption::VALUE_NONE, 'Show only the pincore kernel version');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $kernelOnly = (bool) $input->getOption('kernel');

        if ($kernelOnly) {
            $this->renderVersion($output, 'Kernel (pincore)', PinxVersion::kernel());

            return Command::SUCCESS;
        }

        $this->renderVersion($output, 'Pinoox platform', PinxVersion::platform());
        $output->writeln('');
        $this->renderVersion($output, 'Kernel (pincore)', PinxVersion::kernel());

        return Command::SUCCESS;
    }

    /**
     * @param array{name: string, code: int|null} $version
     */
    private function renderVersion(OutputInterface $output, string $label, array $version): void
    {
        $name = $version['name'] !== '' ? $version['name'] : '?';
        $code = $version['code'] !== null ? (string) $version['code'] : '?';

        $output->writeln(sprintf('<info>%s</info>  <comment>%s</comment> <fg=gray>#%s</>', $label, $name, $code));
    }
}
