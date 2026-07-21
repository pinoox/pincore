<?php

namespace Pinoox\Component\Server\Share;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ShareProviderSelector
{
    public const AUTO = 'auto';

    public static function resolve(
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
        ?string $cliOption = null,
        ?string $envDefault = null,
        ?QuestionHelper $questionHelper = null,
    ): string {
        $explicit = self::normalize($cliOption);

        if ($explicit === '' && is_string($envDefault)) {
            $explicit = self::normalize($envDefault);
        }

        if ($explicit !== '') {
            return self::validateId($explicit, $projectRoot, $output);
        }

        if (!$input->isInteractive() || $questionHelper === null) {
            return self::AUTO;
        }

        return self::askInteractive($input, $output, $projectRoot, $questionHelper);
    }

    /**
     * @return list<string>
     */
    public static function knownIds(string $projectRoot, OutputInterface $output): array
    {
        $registry = new ShareProviderRegistry($projectRoot, $output);

        return array_merge([self::AUTO], $registry->ids());
    }

    private static function askInteractive(
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
        QuestionHelper $questionHelper,
    ): string {
        $io = new SymfonyStyle($input, $output);
        $registry = new ShareProviderRegistry($projectRoot, $output);
        $choices = $registry->describeForMenu();

        $io->section('Share tunnel provider');
        $rows = [];

        foreach ($choices as $index => $choice) {
            $rows[] = [
                (string) $index,
                $choice['label'],
                $choice['signup'],
                $choice['status'],
                $choice['hint'],
            ];
        }

        $io->table(['#', 'Provider', 'Signup', 'Status', 'Notes'], $rows);
        $io->writeln('  <fg=gray>Signup: none = works without account · optional = better with token · required = must register first</>');

        $ids = array_map(static fn (array $choice): string => $choice['id'], $choices);
        $default = self::AUTO;

        $question = new Question(sprintf('Select provider [%s]: ', $default), $default);
        $question->setAutocompleterValues(array_merge($ids, array_map('strval', array_keys($choices))));

        $question->setValidator(function ($answer) use ($choices, $ids): string {
            $answer = strtolower(trim((string) $answer));

            if ($answer === '') {
                return self::AUTO;
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

            throw new \RuntimeException(sprintf("Provider '%s' was not found.", $answer));
        });

        $selected = (string) $questionHelper->ask($input, $output, $question);

        if ($selected === self::AUTO) {
            ShareGuideRenderer::printAutoHint($output);
        } else {
            ShareGuideRenderer::print($output, $registry->get($selected));
        }

        return $selected;
    }

    private static function validateId(string $id, string $projectRoot, OutputInterface $output): string
    {
        $id = self::normalize($id);

        if ($id === self::AUTO) {
            return self::AUTO;
        }

        $known = (new ShareProviderRegistry($projectRoot, $output))->ids();

        if (!in_array($id, $known, true)) {
            throw new \InvalidArgumentException(sprintf(
                "Unknown share provider '%s'. Use one of: auto, %s",
                $id,
                implode(', ', $known),
            ));
        }

        (new ShareProviderRegistry($projectRoot, $output))->get($id);

        return $id;
    }

    private static function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = strtolower(trim($value));

        return match ($value) {
            'localhost.run', 'localhost_run' => 'localhostrun',
            'local-tunnel', 'lt' => 'localtunnel',
            'tunnel-mole', 'tmole' => 'tunnelmole',
            'cf' => 'cloudflare',
            default => $value,
        };
    }
}
