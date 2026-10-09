<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Exceptions\DriverUnavailable;

/**
 * Queues a handler when its hook fires: builds the payload, picks the driver, hands it over.
 *
 * When queuing fails (an argument that cannot travel, a driver that refuses
 * the payload), debug mode throws; otherwise the incident is reported and the
 * handler runs in place, so the work always happens.
 *
 * @internal
 */
final readonly class AsyncDispatcher
{
    public function __construct(
        private ArgumentNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, mixed>  $arguments  Hook arguments, as WordPress passed them
     */
    public function dispatch(QueuedHandler $handler, array $arguments): void
    {
        // Outside the fallback below: a condition that throws fails as it would synchronously
        $condition = $handler->options->condition();
        if ($condition instanceof \Closure && $condition(...$arguments) !== true) {
            return;
        }

        $uniqueKey = null;

        try {
            if (Async::runner()->isRunning($handler->key())) {
                return;
            }

            [$driverName, $driver] = $this->driverFor($handler);
            $normalizedArguments = $this->normalizer->normalize($arguments);

            $uniqueFor = $handler->options->uniqueFor();
            if ($uniqueFor !== null) {
                $key = UniqueLock::key($handler->hook, $handler->descriptor(), $normalizedArguments);

                if (! UniqueLock::acquire($key, $uniqueFor)) {
                    return;
                }

                $uniqueKey = $key;
            }

            $payload = new AsyncPayload(
                id: AsyncPayload::newId(),
                hook: $handler->hook,
                handler: $handler->descriptor(),
                priority: $handler->priority,
                arguments: $normalizedArguments,
                origin: self::origin(),
                captured: $this->normalizer->normalize($this->capture($handler, $arguments), 'captured value'),
                tries: $handler->options->attempts(),
                keepMissing: $handler->options->keepsMissing(),
                driver: $driverName,
                queue: $handler->options->queue(),
                asUser: $handler->options->runsAsUser(),
                backoff: $handler->options->backoffDelays(),
                uniqueKey: $uniqueKey,
            );

            $driver->dispatch($payload, $handler->options->delayInSeconds());

            // Debugging tools list what a request queued; nothing else needs it
            if (function_exists('do_action')) {
                do_action('pollora/async/dispatched', $payload, $handler->options->delayInSeconds());
            }
        } catch (\Throwable $throwable) {
            if ($uniqueKey !== null) {
                UniqueLock::release($uniqueKey);
            }

            if (Async::isDebug()) {
                throw $throwable;
            }

            Async::report($throwable, ['hook' => $handler->hook, 'handler' => $handler->label()]);

            $origin = self::origin();
            Async::runner()->call($handler->handler, $arguments, new AsyncContext(
                userId: $origin['userId'],
                blogId: $origin['blogId'],
                locale: $origin['locale'],
                hook: $handler->hook,
                dispatchedAt: new \DateTimeImmutable($origin['dispatchedAt']),
            ));
        }
    }

    /**
     * The request that fires the hook.
     *
     * @return array{userId: int, blogId: int, locale: string, dispatchedAt: string}
     */
    public static function origin(): array
    {
        return [
            'userId' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'blogId' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            'locale' => function_exists('determine_locale') ? (string) determine_locale() : 'en_US',
            'dispatchedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_RFC3339_EXTENDED),
        ];
    }

    /**
     * Run the capture callback of the registration, or the handler class's capture() method.
     *
     * @param  array<int, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function capture(QueuedHandler $handler, array $arguments): array
    {
        $capture = $handler->options->captureCallback() ?? $handler->defaultCapture;

        if (! is_callable($capture)) {
            return [];
        }

        $captured = $capture(...$arguments);

        if (! is_array($captured)) {
            throw new \UnexpectedValueException(sprintf('The capture of %s must return an array, %s returned.', $handler->hook, get_debug_type($captured)));
        }

        return $captured;
    }

    /**
     * The driver requested by the registration, or the default one, with its name.
     *
     * @return array{0: string, 1: AsyncDriver}
     *
     * @throws DriverUnavailable
     */
    private function driverFor(QueuedHandler $handler): array
    {
        $requested = $handler->options->driver();

        if ($requested !== null) {
            try {
                return [$requested, Async::driver($requested)];
            } catch (DriverUnavailable $driverUnavailable) {
                if (Async::isDebug()) {
                    throw $driverUnavailable;
                }

                Async::report($driverUnavailable, ['hook' => $handler->hook, 'handler' => $handler->label()]);
            }
        }

        $default = Async::defaultDriver();

        return [$default, Async::driver($default)];
    }
}
