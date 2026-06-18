<?php

namespace Pinoox\Terminal\Pinion\Concerns;

use Pinoox\Pinion\Session;
use Symfony\Component\Console\Style\SymfonyStyle;

trait ManagesCliPinion
{
    protected function formatPinionProgress(Session $session): string
    {
        return sprintf(
            '%s / %s (%s%%)',
            $this->formatPinionSize($session->bytes_received),
            $this->formatPinionSize($session->size),
            number_format($session->progress(), 1),
        );
    }

    protected function formatPinionSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$power];
    }

    protected function renderPinionSession(SymfonyStyle $io, Session $session): void
    {
        $io->definitionList(
            ['ID' => $session->id],
            ['Filename' => $session->filename],
            ['Status' => $session->status],
            ['Progress' => $this->formatPinionProgress($session)],
            ['Missing chunks' => implode(', ', $session->missingIndexes()) ?: '—'],
            ['Destination' => $session->destination],
            ['Fingerprint' => $session->fingerprint ?? '—'],
            ['Created' => date('Y-m-d H:i:s', $session->created_at)],
            ['Expires' => date('Y-m-d H:i:s', $session->expires_at)],
            ['Final path' => $session->final_path ?? '—'],
        );
    }
}
