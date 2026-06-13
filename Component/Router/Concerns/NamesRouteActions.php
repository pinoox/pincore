<?php

namespace Pinoox\Component\Router\Concerns;

use Pinoox\Component\Router\Action\ActionReference;

trait NamesRouteActions
{
    /**
     * Bind the route to a registered named action (Actions::HOME → @home).
     */
    public function actionName(string $name, bool $scoped = false): self
    {
        $prefix = $scoped ? ActionReference::SCOPED_PREFIX : ActionReference::GLOBAL_PREFIX;
        $this->action = $prefix . ltrim($name, '@&');

        return $this;
    }

    /**
     * Bind a named action and set the route name to the same identifier.
     */
    public function named(string $name, bool $scoped = false): self
    {
        return $this->actionName($name, $scoped)->name($name);
    }
}
