<?php

use Pinoox\Component\Helpers\Filesystem;

function testRemoveFile(string $path): void
{
    Filesystem::removeFile($path);
}

function testRemoveDirectory(string $dir): void
{
    Filesystem::removeDirectory($dir);
}
