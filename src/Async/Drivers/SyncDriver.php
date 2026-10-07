<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Drivers;

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;

/**
 * Runs the handler immediately, in the request.
 *
 * The payload still goes through its JSON form, so an argument that could not
 * travel fails here too, in development, rather than only once deployed.
 */
final class SyncDriver implements AsyncDriver
{
    public function available(): bool
    {
        return true;
    }

    public function dispatch(AsyncPayload $payload, int $delay = 0): void
    {
        Async::receive($payload->toJson());
    }
}
