<?php



namespace Pinoox\Portal;



use Pinoox\Component\Pinion\HttpHandler as PinionHttpHandler;

use Pinoox\Component\Pinion\Manager;

use Pinoox\Component\Source\Portal;

use Pinoox\Pinion\Builder;

use Pinoox\Pinion\Result;

use Pinoox\Pinion\Session;



/**

 * Pinion — Pinoox resumable chunked upload protocol.

 *

 * @method static Builder begin()

 * @method static Result init(string $filename, int $size, string $destination, array $extensions = [], ?int $chunkSize = null, ?string $mime = null, ?string $fingerprint = null, ?string $fileHash = null, array $meta = [])

 * @method static Result receive(string $uploadId, int $index, string $binary, ?string $chunkHash = null)

 * @method static Result complete(string $uploadId, ?string $fileHash = null)

 * @method static bool abort(string $uploadId)

 * @method static Session|null status(string $uploadId)

 * @method static list<Session> list(?string $status = null)

 * @method static int cleanExpired()

 * @method static PinionHttpHandler http(array $defaults = [])

 * @method static Manager ___()

 *

 * @see Manager

 */

class Pinion extends Portal

{

    public static function __register(): void

    {

        self::__bind(Manager::class);

    }



    public static function http(array $defaults = []): PinionHttpHandler

    {

        return PinionHttpHandler::make(self::___()->package(), $defaults);

    }



    public static function __name(): string

    {

        return 'pinion';

    }

}

