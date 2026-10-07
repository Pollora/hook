<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Contracts;

use Pollora\Hook\Async\AsyncPayload;

/**
 * A deferred-execution mechanism: WP-Cron, Action Scheduler, a Laravel queue…
 *
 * A driver queues the payload, then hands it back to Async::receive() when it runs.
 */
interface AsyncDriver
{
    /**
     * Whether the mechanism can be used in this request.
     */
    public function available(): bool;

    /**
     * Queue the execution described by $payload.
     *
     * @param  int  $delay  Minimum delay before execution, in seconds
     */
    public function dispatch(AsyncPayload $payload, int $delay = 0): void;
}
