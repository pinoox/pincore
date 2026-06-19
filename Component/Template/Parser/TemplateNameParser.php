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

namespace Pinoox\Component\Template\Parser;

use Pinoox\Component\Helpers\Str;

class TemplateNameParser implements TemplateNameParserInterface
{
    const TWIG = 'twig';
    const PHP = 'php';
    const TWIG_PHP = 'twig.php';

    /** @var list<string> Resolution priority for extensionless template names */
    const ENGINES = [
        self::TWIG_PHP,
        self::TWIG,
        self::PHP,
    ];

    public function parse(TemplateReferenceInterface|string $name): TemplateReferenceInterface
    {
        if ($name instanceof TemplateReferenceInterface) {
            return $name;
        }

        if (Str::lastHas($name, '.' . self::TWIG_PHP)) {
            $engine = self::TWIG_PHP;
        } else if (false !== $pos = strrpos($name, '.')) {
            $engine = substr($name, $pos + 1);
        } else {
            $engine = 'twig';
        }

        return new TemplateReference($name, $engine);
    }
}