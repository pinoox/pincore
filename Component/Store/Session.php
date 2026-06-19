<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Store;

use Pinoox\Support\SystemConfig;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\Session as SessionSymfony;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

class Session extends SessionSymfony
{
    public function __construct(
        ?SessionStorageInterface $storage = null,
        ?AttributeBagInterface $attributes = null,
        ?FlashBagInterface $flashes = null,
        ?callable $usageReporter = null,
    ) {
        parent::__construct(
            $storage ?? new NativeSessionStorage(self::nativeStorageOptions()),
            $attributes,
            $flashes,
            $usageReporter,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function nativeStorageOptions(): array
    {
        if (!defined('PINOOX_BASE_PATH')) {
            return [];
        }

        try {
            $config = SystemConfig::get('session');
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($config)) {
            return [];
        }

        $options = [];

        if (isset($config['lifetime'])) {
            $seconds = max(0, (int) $config['lifetime']) * 60;
            $options['cookie_lifetime'] = $seconds;
            $options['gc_maxlifetime'] = $seconds;
        }

        if (!empty($config['cookie'])) {
            $options['name'] = (string) $config['cookie'];
        }

        foreach ([
            'path' => 'cookie_path',
            'domain' => 'cookie_domain',
            'secure' => 'cookie_secure',
            'http_only' => 'cookie_httponly',
            'same_site' => 'cookie_samesite',
        ] as $from => $to) {
            if (array_key_exists($from, $config) && $config[$from] !== null && $config[$from] !== '') {
                $options[$to] = $config[$from];
            }
        }

        return $options;
    }
}
