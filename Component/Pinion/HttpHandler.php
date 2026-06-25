<?php



namespace Pinoox\Component\Pinion;



use Pinoox\Component\Http\Api\ApiResponse;

use Pinoox\Component\Http\JsonResponse;

use Pinoox\Component\Http\Request;

use Pinoox\Pinion\HttpHandler as PackageHttpHandler;

use Pinoox\Support\SystemConfig;



final class HttpHandler

{

    /**

     * @param array<string, mixed> $defaults

     */

    public function __construct(

        private readonly PackageHttpHandler $handler,

        private readonly array $defaults = [],

    ) {

    }



    public static function make(ProtocolManager $manager, array $defaults = []): self

    {

        return new self(PackageHttpHandler::make($manager, $defaults), $defaults);

    }



    public function init(Request $request): JsonResponse

    {

        $extensions = $request->payload('extensions', $this->defaults['extensions'] ?? []);

        $meta = StorageContext::mergeDefaults(

            (array) $request->payload('meta', []),

            $this->defaults,

        );



        return $this->respond($this->handler->init([

            'filename' => $request->payload('filename', ''),

            'size' => $request->payload('size', 0),

            'destination' => $request->payload('destination', $this->defaults['destination'] ?? ''),

            'chunk_size' => $request->payload('chunk_size'),

            'extensions' => $extensions,

            'mime' => $request->payload('mime'),

            'fingerprint' => $request->payload('fingerprint'),

            'file_hash' => $request->payload('file_hash', $request->payload('fileHash')),

            'meta' => $meta,

        ]));

    }



    public function upload(Request $request): JsonResponse

    {

        return $this->respond($this->handler->upload([

            'upload_id' => $request->payload('upload_id', $request->payload('uploadId', '')),

            'index' => $request->payload('index', -1),

            'chunk_hash' => $request->payload('chunk_hash', $request->payload('chunkHash')),

        ], $request->files->get('chunk')));

    }



    public function complete(Request $request): JsonResponse

    {

        $response = $this->handler->complete([

            'upload_id' => $request->payload('upload_id', $request->payload('uploadId', '')),

            'file_hash' => $request->payload('file_hash', $request->payload('fileHash')),

        ]);



        if (($response['success'] ?? false) && is_array($response['data'] ?? null)) {

            $response['data'] = $this->enrichCompletePayload($response['data']);

        }



        return $this->respond($response);

    }



    public function status(string $uploadId): JsonResponse

    {

        return $this->respond($this->handler->status($uploadId));

    }



    public function abort(string $uploadId): JsonResponse

    {

        return $this->respond($this->handler->abort($uploadId));

    }



    public function limits(): JsonResponse

    {

        return ApiResponse::success(

            PinionHostLimits::clientProfile(SystemConfig::path('pinion_uploads')),

            translate: false,

        );

    }



    /**

     * @param array<string, mixed> $payload

     * @return array<string, mixed>

     */

    private function enrichCompletePayload(array $payload): array

    {

        $session = is_array($payload['session'] ?? null) ? $payload['session'] : [];

        $published = is_array($session['meta']['published'] ?? null) ? $session['meta']['published'] : null;



        if ($published === null) {

            return $payload;

        }



        foreach (['file_id', 'url', 'thumb', 'storage_key', 'disk', 'package'] as $key) {

            if (array_key_exists($key, $published)) {

                $payload[$key] = $published[$key];

            }

        }



        if (isset($published['storage_key'])) {

            $payload['path'] = $published['storage_key'];

        }



        $payload['storage'] = true;



        return $payload;

    }



    /**

     * @param array<string, mixed> $payload

     */

    private function respond(array $payload): JsonResponse

    {

        if (!($payload['success'] ?? false)) {

            $error = $payload['error'] ?? [];



            return ApiResponse::error(

                (string) ($error['code'] ?? 'PINION_ERROR'),

                (string) ($error['message'] ?? 'error'),

                (array) ($error['details'] ?? []),

                (int) ($payload['status'] ?? 400),

                translate: false,

            );

        }



        return ApiResponse::success($payload['data'] ?? null, translate: false);

    }

}


