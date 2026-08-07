<?php

use Pinoox\Component\File\FileDispatcher;

// Default prefix `/file/{hash}`. Prefer app.php → filesystem.dispatcher for custom paths.
FileDispatcher::registerRoutes();
