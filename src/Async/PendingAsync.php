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

    /** @var array<string, QueuedHandler> */
    private array $handlers = [];

    /** @var array<string, true> */
    private array $excluded = [];

    /**
     * @param  \Closure(QueuedHandler): void  $restore  Puts a hook back to synchronous execution
     */
    public function __construct(
        private readonly \Closure $restore,
    ) {}

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
