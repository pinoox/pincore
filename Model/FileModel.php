<?php

/**
 * ***  *  *     *  ****  ****  *    *
 *   *  *  * *   *  *  *  *  *   *  *
 * ***  *  *  *  *  *  *  *  *    *
 *      *  *   * *  *  *  *  *   *  *
 *      *  *    **  ****  ****  *    *
 *
 * @author   Pinoox
 * @link https://www.pinoox.com
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pinoox\Component\Database\Model;
use Pinoox\Component\Transport\TransportConfig;
use Pinoox\Component\Transport\TransportRuntime;
use Pinoox\Component\Transport\TransportScenario;
use Pinoox\Portal\Auth;
use Pinoox\Model\Scope\AppScope;
use Pinoox\Component\File\FileConfig;
use Pinoox\Component\File\FileDispatcher;
use Pinoox\Component\File\FileStorage;


/**
 * @property mixed $file_id
 */
class FileModel extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = Table::FILE;
    protected $primaryKey = 'file_id';
    public $incrementing = true;
    public $timestamps = true;

    protected $fillable = [
        'hash_id',
        'user_id',
        'app',
        'file_group',
        'file_realname',
        'file_name',
        'file_ext',
        'file_path',
        'file_size',
        'file_access',
        'file_disk',
        'file_metadata',
    ];

    protected $casts = [
        'file_metadata' => 'array',
    ];

    protected $hidden = [
        'app'
    ];
    protected $appends = ['file_link', 'thumb_link'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    public function getFileLinkAttribute()
    {
        return FileStorage::url($this);
    }


    public function getThumbLinkAttribute()
    {
        return FileStorage::thumbUrl($this);
    }

    public static function generateHash(?int $length = null, ?string $package = null): string
    {
        $length = FileConfig::hashLength($length, $package);
        $bytes = (int) max(1, (int) ceil($length / 2));

        return substr(bin2hex(random_bytes($bytes)), 0, $length);
    }

    public static function setPackage(string $package): void
    {
        TransportRuntime::use($package);
    }

    public static function getPackage(): string
    {
        return TransportConfig::package(TransportScenario::FILE_STORAGE);
    }

    protected static function booted(): void
    {
        self::addAppGlobalScope();

        static::creating(function ($file) {
            $file->app = $file->app ?? self::getPackage();
            $file->user_id = $file->user_id ?? Auth::id();

            if (empty($file->hash_id)) {
                $file->hash_id = self::uniqueHash(is_string($file->app ?? null) ? $file->app : null);
            }
        });

        static::saved(function ($file) {
            FileDispatcher::forgetHash($file->hash_id ?? null);
        });

        static::deleted(function ($file) {
            FileDispatcher::forgetHash($file->hash_id ?? null);
            FileStorage::delete($file);
        });
    }

    private static function uniqueHash(?string $package = null): string
    {
        for ($i = 0; $i < 16; $i++) {
            $hash = self::generateHash(null, $package);
            if (!self::withoutGlobalScopes()->where('hash_id', $hash)->exists()) {
                return $hash;
            }
        }

        return self::generateHash(null, $package);
    }

    private static function addAppGlobalScope(): void
    {
        static::addGlobalScope('app', AppScope::for(
            fn (): array => TransportConfig::scopeValues(TransportScenario::FILE_STORAGE),
        ));
    }
}
