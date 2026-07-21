<?php

namespace Pinoox\Component\Server\Share;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ShareWizard
{
    public const MODE_SERVE = 'serve';
    public const MODE_DEV = 'dev';
    public const MODE_GUIDE = 'guide';

    public function run(
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
        QuestionHelper $questionHelper,
        ?string $providerOption = null,
        ?string $modeOption = null,
        ?string $envProvider = null,
        bool $networkFromCli = false,
        ?string $passwordOption = null,
        ?string $expireOption = null,
    ): ShareWizardResult {
        $provider = $this->resolveProvider(
            $input,
            $output,
            $projectRoot,
            $questionHelper,
            $providerOption,
            $envProvider,
        );

        $mode = trim((string) $modeOption) !== ''
            ? $this->validateMode((string) $modeOption)
            : $this->resolveMode($input, $output, $questionHelper, null);

        if ($mode === self::MODE_GUIDE) {
            return new ShareWizardResult(provider: $provider, mode: $mode);
        }

        $network = $networkFromCli;

        if (!$network) {
            $network = $this->resolveNetwork($input, $output, $questionHelper);
        }
        $password = $passwordOption ?? $this->resolveOptionalString(
            $input,
            $output,
            $questionHelper,
            'Password-protect the public URL (leave empty to skip)',
            null,
        );
        $expire = $expireOption ?? $this->resolveOptionalString(
            $input,
            $output,
            $questionHelper,
            'Auto-stop tunnel after duration (e.g. 2h, 30m — leave empty for none)',
            null,
        );

        return new ShareWizardResult(
            provider: $provider,
            mode: $mode,
            network: $network,
            password: is_string($password) && trim($password) !== '' ? trim($password) : null,
            expire: is_string($expire) && trim($expire) !== '' ? trim($expire) : null,
        );
    }

    private function resolveProvider(
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
        QuestionHelper $questionHelper,
        ?string $cliOption,
        ?string $envDefault,
    ): string {
        if ($cliOption !== null && trim($cliOption) !== '') {
            return ShareProviderSelector::resolve($input, $output, $projectRoot, $cliOption);
        }

        if (!$input->isInteractive()) {
            return ShareProviderSelector::resolve($input, $output, $projectRoot, null, $envDefault);
        }

        return ShareProviderSelector::resolve(
            $input,
            $output,
            $projectRoot,
            null,
            $envDefault,
            $questionHelper,
        );
    }

    private function resolveMode(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        ?string $modeOption,
    ): string {
        $modeOption = strtolower(trim((string) $modeOption));

        if ($modeOption !== '') {
            return $this->validateMode($modeOption);
        }

        if (!$input->isInteractive()) {
            return self::MODE_SERVE;
        }

        $io = new SymfonyStyle($input, $output);
        $choices = [
            ['id' => self::MODE_SERVE, 'label' => 'serve', 'hint' => 'PHP development server (built/manifest assets)'],
            ['id' => self::MODE_DEV, 'label' => 'dev', 'hint' => 'PHP serve + Vite HMR (theme frontend dev)'],
            ['id' => self::MODE_GUIDE, 'label' => 'guide', 'hint' => 'Show connection guide only — no server'],
        ];

        $io->section('Share mode');
        $rows = [];

        foreach ($choices as $index => $choice) {
            $rows[] = [(string) $index, $choice['label'], $choice['hint']];
        }

        $io->table(['#', 'Mode', 'Description'], $rows);

        $ids = array_map(static fn (array $choice): string => $choice['id'], $choices);
        $default = self::MODE_SERVE;

        $question = new Question(sprintf('Select mode [%s]: ', $default), $default);
        $question->setAutocompleterValues(array_merge($ids, ['serve', 'dev', 'guide'], array_map('strval', array_keys($choices))));
        $question->setValidator(function ($answer) use ($choices, $ids): string {
            $answer = strtolower(trim((string) $answer));

            if ($answer === '') {
                return self::MODE_SERVE;
            }

            if (ctype_digit($answer)) {
                $index = (int) $answer;

                if (isset($choices[$index])) {
                    return $choices[$index]['id'];
                }
            }

            if (in_array($answer, $ids, true)) {
                return $answer;
            }

            throw new \RuntimeException(sprintf("Mode '%s' was not found.", $answer));
        });

        return (string) $questionHelper->ask($input, $output, $question);
    }

    private function resolveNetwork(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
    ): bool {
        if (!$input->isInteractive()) {
            return false;
        }

        $question = new ConfirmationQuestion('Enable LAN access (--network)? [y/N] ', false);

        return (bool) $questionHelper->ask($input, $output, $question);
    }

    private function resolveOptionalString(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        string $prompt,
        ?string $default,
    ): ?string {
        if (!$input->isInteractive()) {
            return $default;
        }

        $suffix = $default !== null && $default !== '' ? sprintf(' [%s]', $default) : '';
        $question = new Question($prompt . $suffix . ': ', $default ?? '');
        $question->setValidator(static fn ($answer): string => trim((string) $answer));

        $answer = (string) $questionHelper->ask($input, $output, $question);

        return trim($answer) === '' ? null : trim($answer);
    }

    private function validateMode(string $mode): string
    {
        $mode = match ($mode) {
            'server', 'php' => self::MODE_SERVE,
            'hmr', 'vite', 'frontend', 'fe' => self::MODE_DEV,
            'help', 'docs' => self::MODE_GUIDE,
            default => $mode,
        };

        if (!in_array($mode, [self::MODE_SERVE, self::MODE_DEV, self::MODE_GUIDE], true)) {
            throw new \InvalidArgumentException(sprintf(
                "Unknown share mode '%s'. Use serve, dev, or guide.",
                $mode,
            ));
        }

        return $mode;
    }
}
