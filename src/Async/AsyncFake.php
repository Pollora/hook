<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

use PHPUnit\Framework\Assert;
use Pollora\Hook\Async\Contracts\AsyncDriver;

/**
 * Records queued handlers instead of queuing them, for tests.
 *
 *     Async::fake();
 *     wp_update_post(['ID' => $event->ID, 'post_title' => 'New title']);
 *     Async::assertDispatched(SyncEventToCrm::class, fn (AsyncPayload $payload) => $payload->captured['status'] === 'publish');
 *
 * Arguments and captured values are normalized as for a real driver, so one
 * that cannot travel fails here too. A handler is matched by its class name,
 * its descriptor ('Class@method', a function name), or 'closure' for closures.
 */
final class AsyncFake implements AsyncDriver
{
    /** @var list<array{payload: AsyncPayload, delay: int}> */
    private array $dispatched = [];

    public function available(): bool
    {
        return true;
    }

    public function dispatch(AsyncPayload $payload, int $delay = 0): void
    {
        $this->dispatched[] = ['payload' => $payload, 'delay' => $delay];
    }

    /**
     * Payloads recorded for a handler, or all of them.
     *
     * @param  (callable(AsyncPayload, int): bool)|null  $callback  Receives the payload and its delay
     * @return list<AsyncPayload>
     */
    public function dispatched(?string $handler = null, ?callable $callback = null): array
    {
        $payloads = [];

        foreach ($this->dispatched as $entry) {
            if ($handler !== null && ! self::matches($entry['payload'], $handler)) {
                continue;
            }

            if ($callback !== null && $callback($entry['payload'], $entry['delay']) !== true) {
                continue;
            }

            $payloads[] = $entry['payload'];
        }

        return $payloads;
    }

    /**
     * @param  (callable(AsyncPayload, int): bool)|null  $callback
     */
    public function assertDispatched(string $handler, ?callable $callback = null): void
    {
        self::assert(
            $this->dispatched($handler, $callback) !== [],
            sprintf('The asynchronous handler [%s] was not dispatched%s.', $handler, $callback === null ? '' : ' with the expected payload'),
        );
    }

    public function assertDispatchedTimes(string $handler, int $times): void
    {
        $count = count($this->dispatched($handler));

        self::assert($count === $times, sprintf('The asynchronous handler [%s] was dispatched %d time(s) instead of %d.', $handler, $count, $times));
    }

    /**
     * @param  (callable(AsyncPayload, int): bool)|null  $callback
     */
    public function assertNotDispatched(string $handler, ?callable $callback = null): void
    {
        self::assert($this->dispatched($handler, $callback) === [], sprintf('The asynchronous handler [%s] was dispatched.', $handler));
    }

    public function assertNothingDispatched(): void
    {
        self::assert($this->dispatched === [], sprintf('%d asynchronous handler(s) were dispatched.', count($this->dispatched)));
    }

    /**
     * Run the recorded handlers, as a driver would, then forget them.
     */
    public function runDispatched(): void
    {
        while ($this->dispatched !== []) {
            $entry = array_shift($this->dispatched);
            Async::receive($entry['payload']->toJson());
        }
    }

    private static function matches(AsyncPayload $payload, string $handler): bool
    {
        $handler = ltrim($handler, '\\');

        return $payload->handler === $handler
            || str_starts_with($payload->handler, $handler.'@')
            || ($handler === 'closure' && str_starts_with($payload->handler, 'closure:'));
    }

    private static function assert(bool $condition, string $message): void
    {
        if (class_exists(Assert::class)) {
            Assert::assertTrue($condition, $message);

            return;
        }

        if (! $condition) {
            throw new \AssertionError($message);
        }
    }
}
