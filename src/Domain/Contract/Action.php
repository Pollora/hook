<?php

declare(strict_types=1);

namespace Pollora\Hook\Domain\Contract;

use Pollora\Hook\Async\PendingAsync;

/**
 * Contract for WordPress Action hooks.
 *
 * Extends the base hook interface with action execution and asynchronous handlers.
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

    /**
     * Make the hooks of the last add() call asynchronous: when one fires, its
     * handler is queued and runs later instead.
     *
     *     $action->add('save_post_event', SyncEventToCrm::class)->async();
     *
     * @param  string|list<string>  $except  Hooks of that add() call that stay synchronous
     */
    public function async(string|array $except = []): PendingAsync;
}
