<?php

namespace Pinoox\Component\Store\Baker;

/**
 * Values in manifest PHP files (app.php, theme.php) that Pinker can re-export safely.
 */
interface ExportableManifestValue
{
    public function exportForPinker(): string;
}
