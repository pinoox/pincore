<?php

use Pinoox\Component\File\FileDispatcher;
use function Pinoox\Router\get;

get(
    path: '/file/{hash}',
    action: [FileDispatcher::class, 'show'],
);

get(
    path: '/file/{hash}/thumb',
    action: [FileDispatcher::class, 'thumb'],
);
