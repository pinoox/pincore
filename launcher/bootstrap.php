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

/**
 * Boot pincore without the platform launcher (standalone repo or host project via vendor).
 *
 * Host platform installs should keep using {project}/launcher/bootstrap.php.
 */

require_once __DIR__ . '/core-path.php';

require_once __DIR__ . '/requirements.php';
pinoox_check_runtime_requirements();

use Pinoox\Portal\App\AppProvider;

define('PINOOX_START', microtime(true));

require_once PINOOX_CORE_PATH . 'functions/base.php';

$loader = require PINOOX_BASE_PATH . '/vendor/autoload.php';

if ($loader instanceof Composer\Autoload\ClassLoader) {
    $loader->addPsr4('Pinoox\\', PINOOX_CORE_PATH, true);
}

\Pinoox\Component\Helpers\EnvBootstrap::load(PINOOX_BASE_PATH);
\Pinoox\Component\Helpers\CliErrorReporting::boot();

\Pinoox\Component\File::ensureStorageRootHtaccess(\Pinoox\Support\SystemConfig::path('storage'));
\Pinoox\Support\SystemConfig::ensureProjectConfigFiles();

AppProvider::boot();
