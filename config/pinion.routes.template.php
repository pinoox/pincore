<?php

/**
 * Pinion route template for Pinoox apps (HMVC).
 *
 * Copy into apps/{package}/routes/api.php or private.php:
 *
 * use App\{package}\Controller\PinionController;
 *
 * [
 *     'method' => 'post',
 *     'uri' => '/pinion/init',
 *     'action' => [PinionController::class, 'init'],
 *     'flows' => ['auth'],
 * ],
 * ...
 *
 * Client baseURL: '/pinion' (relative to app API root)
 *
 * @see \Pinoox\Component\Pinion\Concerns\PinionUploadActions
 */

return [
    'init' => 'POST /pinion/init',
    'upload' => 'POST /pinion/upload',
    'complete' => 'POST /pinion/complete',
    'status' => 'GET /pinion/status/{uploadId}',
    'abort' => 'POST /pinion/abort/{uploadId}',
];
