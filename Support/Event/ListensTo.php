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

namespace Pinoox\Support\Event;

use Attribute;

/**
 * Bind a listener class or method to an event class / dispatcher name.
 *
 *     #[ListensTo(OrderPlaced::class)]
 *     public function onPlaced(OrderPlaced $event): void {}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ListensTo
{
    /**
     * @param class-string|string $event Event class or dispatcher name
     */
    public function __construct(
        public string $event,
        public int $priority = 0,
    ) {
    }
}
