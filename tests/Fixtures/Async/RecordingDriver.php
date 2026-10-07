<?php

declare(strict_types=1);

namespace Pollora\Hook\Tests\Fixtures\Async;

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;

/**
 * Keeps queued payloads in memory; runAll() executes them as a driver would.
 */
final class RecordingDriver implements AsyncDriver
{
    /** @var list<array{payload: AsyncPayload, delay: int}> */
    public array $queued = [];

    public function available(): bool
    {
        return true;
    }

    public function dispatch(AsyncPayload $payload, int $delay = 0): void
    {
        $this->queued[] = ['payload' => $payload, 'delay' => $delay];
    }

    /**
     * Run every queued payload, including those queued while running (retries).
     */
    public function runAll(): void
    {
        for ($index = 0; $index < count($this->queued); $index++) {
            $this->run($index);
        }
    }

    public function run(int $index): void
    {
        Async::receive($this->queued[$index]['payload']->toJson());
    }
}
