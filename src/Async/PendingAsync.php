<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

/**
 * Options of an asynchronous registration, returned by async().
 *
 * Every hook of the last add() call is already asynchronous when this object
 * is returned; the options are read when a hook fires, so they can be chained
 * in any order.
 *
 *     Action::add('save_post_event', SyncEventToCrm::class)->async()->delay(60);
 */
final class PendingAsync
{
    private int|\DateInterval $delay = 0;

    private ?string $driver = null;

    private bool $keepMissing = false;

    private int $tries;

    /** @var list<int> */
    private array $backoff;

    private bool $asUser;

    private ?string $queue = null;

    private ?int $uniqueFor = null;

    /** @var (\Closure(mixed...): array<string, mixed>)|null */
    private ?\Closure $capture = null;

    /** @var (\Closure(mixed...): bool)|null */
    private ?\Closure $condition = null;

    /** @var array<string, QueuedHandler> */
    private array $handlers = [];

    /** @var array<string, true> */
    private array $excluded = [];

    /**
     * @param  \Closure(QueuedHandler): void  $restore  Puts a hook back to synchronous execution
     */
    public function __construct(
        private readonly \Closure $restore,
    ) {
        $defaults = Async::defaults();
        $this->tries = $defaults['tries'];
        $this->backoff = $defaults['backoff'];
        $this->asUser = $defaults['asUser'];
    }

    /**
     * @internal
     *
     * @throws \InvalidArgumentException When $tries is lower than 1
     */
    public static function validTries(int $tries): int
    {
        if ($tries < 1) {
            throw new \InvalidArgumentException(sprintf('tries() expects at least 1 attempt, %d given.', $tries));
        }

        return $tries;
    }

    /**
     * @internal
     *
     * @param  int|array<int|string, mixed>  $seconds
     * @return list<int>
     *
     * @throws \InvalidArgumentException When a delay is negative or the list is empty
     */
    public static function validBackoff(int|array $seconds): array
    {
        $seconds = array_values((array) $seconds);

        if ($seconds === [] || array_filter($seconds, fn (mixed $delay): bool => ! is_int($delay) || $delay < 0) !== []) {
            throw new \InvalidArgumentException('backoff() expects one or more delays of 0 seconds or more.');
        }

        /** @var list<int> $seconds */
        return $seconds;
    }

    /**
     * Keep some hooks of the registration synchronous.
     *
     * @param  string|list<string>  $hooks
     *
     * @throws \InvalidArgumentException In debug mode, when a hook is not part of the registration
     */
    public function except(string|array $hooks): self
    {
        foreach ((array) $hooks as $hook) {
            if (isset($this->excluded[$hook])) {
                continue;
            }

            if (! isset($this->handlers[$hook])) {
                $this->rejectUnknownHook($hook);

                continue;
            }

            ($this->restore)($this->handlers[$hook]);
            unset($this->handlers[$hook]);
            $this->excluded[$hook] = true;
        }

        return $this;
    }

    /**
     * Minimum delay before execution.
     *
     * @param  int|\DateInterval  $delay  Seconds, or an interval
     */
    public function delay(int|\DateInterval $delay): self
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * Driver to use for this registration, instead of the default one.
     */
    public function via(string $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Run the handler with null in place of a referenced object deleted in the meantime,
     * instead of dropping it.
     */
    public function keepMissing(bool $keepMissing = true): self
    {
        $this->keepMissing = $keepMissing;

        return $this;
    }

    /**
     * Number of attempts when the handler throws: it is queued again after each
     * failure but the last, which is reported and fired as 'pollora/async/failed'.
     *
     * @throws \InvalidArgumentException When $tries is lower than 1
     */
    public function tries(int $tries): self
    {
        $this->tries = self::validTries($tries);

        return $this;
    }

    /**
     * Seconds to wait before each retry; the last value repeats. Default: 10, 60, then 300, or Async::setDefaults().
     *
     * @param  int|array<int|string, mixed>  $seconds  Validated: integers of 0 or more
     *
     * @throws \InvalidArgumentException When a delay is negative or the list is empty
     */
    public function backoff(int|array $seconds): self
    {
        $this->backoff = self::validBackoff($seconds);

        return $this;
    }

    /**
     * Run the handler as the user who fired the hook, then remove that user.
     *
     * Without it the handler runs without a current user, so a capability
     * check fails there, which is the safe default.
     */
    public function asUser(bool $asUser = true): self
    {
        $this->asUser = $asUser;

        return $this;
    }

    /**
     * Merge identical triggers (same hook, same handler, same arguments) while
     * the first one has not started running.
     *
     * @param  int  $for  Seconds after which the lock expires if it was never released
     *
     * @throws \InvalidArgumentException When $for is lower than 1
     */
    public function unique(int $for = 86400): self
    {
        if ($for < 1) {
            throw new \InvalidArgumentException(sprintf('unique() expects a lock of at least 1 second, %d given.', $for));
        }

        $this->uniqueFor = $for;

        return $this;
    }

    /**
     * Queue name: the group with Action Scheduler, the queue with a Laravel queue. WP-Cron ignores it.
     */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Record values at trigger time, read back at execution through AsyncContext::get().
     *
     * The callback runs in the original request with the hook arguments and
     * returns an array. Values must be able to travel like arguments: capture
     * an ID rather than personal data or a secret, they wait in the database.
     *
     *     ->capture(fn (int $postId, WP_Post $post) => ['status' => $post->post_status])
     *
     * @param  callable  $capture  Receives the hook arguments, returns array<string, mixed>
     */
    public function capture(callable $capture): self
    {
        $this->capture = $capture(...);

        return $this;
    }

    /**
     * Queue only when the callback, given the hook arguments, returns true.
     *
     *     ->when(fn (int $postId) => ! wp_is_post_revision($postId))
     *
     * @param  callable  $condition  Receives the hook arguments, returns bool
     */
    public function when(callable $condition): self
    {
        $this->condition = $condition(...);

        return $this;
    }

    /**
     * @internal
     */
    public function track(QueuedHandler $handler): void
    {
        $this->handlers[$handler->hook] = $handler;
    }

    /**
     * Hooks still asynchronous.
     *
     * @return list<string>
     */
    public function hooks(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * @internal
     */
    public function delayInSeconds(): int
    {
        if (is_int($this->delay)) {
            return max(0, $this->delay);
        }

        $origin = new \DateTimeImmutable('@0');

        return max(0, $origin->add($this->delay)->getTimestamp());
    }

    /**
     * @internal
     */
    public function driver(): ?string
    {
        return $this->driver;
    }

    /**
     * @internal
     */
    public function keepsMissing(): bool
    {
        return $this->keepMissing;
    }

    /**
     * @internal
     */
    public function attempts(): int
    {
        return $this->tries;
    }

    /**
     * @internal
     *
     * @return list<int>
     */
    public function backoffDelays(): array
    {
        return $this->backoff;
    }

    /**
     * @internal
     */
    public function runsAsUser(): bool
    {
        return $this->asUser;
    }

    /**
     * @internal
     *
     * @return int|null Lock duration, null when the registration is not unique
     */
    public function uniqueFor(): ?int
    {
        return $this->uniqueFor;
    }

    /**
     * @internal
     */
    public function queue(): ?string
    {
        return $this->queue;
    }

    /**
     * @internal
     *
     * @return (\Closure(mixed...): array<string, mixed>)|null
     */
    public function captureCallback(): ?\Closure
    {
        return $this->capture;
    }

    /**
     * @internal
     *
     * @return (\Closure(mixed...): bool)|null
     */
    public function condition(): ?\Closure
    {
        return $this->condition;
    }

    private function rejectUnknownHook(string $hook): void
    {
        $message = sprintf(
            "except(): '%s' is not a hook of this asynchronous registration (%s).",
            $hook,
            implode(', ', [...$this->hooks(), ...array_keys($this->excluded)]),
        );

        if (Async::isDebug()) {
            throw new \InvalidArgumentException($message);
        }

        Async::report($message);
    }
}
