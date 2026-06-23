<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Contract;

/**
 * Contract for WordPress Action hooks.
 *
 * Extends the base hook interface with action execution capability.
 */
interface Action extends HookInterface
{
    /**
     * Execute a WordPress action hook.
     *
     * @param  string  $hook  The action hook name.
     * @param  mixed  ...$args  Arguments to pass to the callbacks.
     */
    public function do(string $hook, mixed ...$args): self;
}
