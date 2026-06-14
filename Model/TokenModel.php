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

use Pinoox\Component\Database\Model;
use Pinoox\Component\Transport\TransportConfig;
use Pinoox\Component\Transport\TransportRuntime;
use Pinoox\Component\Transport\TransportScenario;
use Pinoox\Portal\User;
use Pinoox\Model\Scope\AppScope;
use Pinoox\Portal\Url;

class TokenModel extends Model
{
    public $incrementing = true;
    public $primaryKey = 'token_id';
    protected $table = Table::TOKEN;
    public $timestamps = true;

    protected $fillable = [
        'token_key',
        'token_name',
        'token_data',
        'user_id',
        'remote_url',
        'app',
        'ip',
        'user_agent',
        'expiration_date',
    ];

    protected $casts = [
        'token_data' => 'json',
    ];

    protected $hidden = [
        'app'
    ];

    protected static function booted(): void
    {
        static::creating(function ($token) {
            $token->app = $token->app ?? self::getPackage();
            $token->user_id = $token->user_id ?? User::get('user_id');
            $token->ip = $token->ip ?? Url::clientIp();
            $token->user_agent = $token->user_agent ?? Url::userAgent();
        });

        self::addAppGlobalScope();
    }

    public static function setPackage(string $package): void
    {
        TransportRuntime::use($package);
    }

    public static function getPackage(): string
    {
        return TransportConfig::package(TransportScenario::SESSION_TOKEN);
    }

    private static function addAppGlobalScope(): void
    {
        static::addGlobalScope('app', AppScope::for(
            fn (): array => TransportConfig::scopeValues(TransportScenario::SESSION_TOKEN),
        ));
    }
}
