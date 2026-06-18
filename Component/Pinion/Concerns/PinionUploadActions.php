<?php

namespace Pinoox\Component\Pinion\Concerns;

use Pinoox\Component\Http\Request;
use Pinoox\Component\Pinion\HttpHandler;
use Pinoox\Portal\Pinion;

/**
 * Drop-in Pinion HTTP actions for app controllers (HMVC).
 *
 * Implement pinionDefaults() with destination, extensions, storage mode, etc.
 */
trait PinionUploadActions
{
    /**
     * @return array<string, mixed>
     */
    abstract protected function pinionDefaults(): array;

    protected function pinionHandler(): HttpHandler
    {
        return Pinion::http($this->pinionDefaults());
    }

    public function init(Request $request)
    {
        return $this->pinionHandler()->init($request);
    }

    public function upload(Request $request)
    {
        return $this->pinionHandler()->upload($request);
    }

    public function complete(Request $request)
    {
        return $this->pinionHandler()->complete($request);
    }

    public function status(string $uploadId)
    {
        return $this->pinionHandler()->status($uploadId);
    }

    public function abort(string $uploadId)
    {
        return $this->pinionHandler()->abort($uploadId);
    }
}
