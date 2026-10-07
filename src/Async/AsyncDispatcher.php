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
        if (Async::runner()->isRunning($handler->key())) {
            return;
        }

        // Outside the fallback below: a condition that throws fails as it would synchronously
        $condition = $handler->options->condition();
        if ($condition instanceof \Closure && $condition(...$arguments) !== true) {
            return;
        }

        try {
            $payload = new AsyncPayload(
                id: AsyncPayload::newId(),
                hook: $handler->hook,
                handler: $handler->descriptor,
                priority: $handler->priority,
                arguments: $this->normalizer->normalize($arguments),
                origin: self::origin(),
                captured: $this->normalizer->normalize($this->capture($handler, $arguments), 'captured value'),
                keepMissing: $handler->options->keepsMissing(),
            );

            $this->driverFor($handler)->dispatch($payload, $handler->options->delayInSeconds());
        } catch (\Throwable $throwable) {
            if (Async::isDebug()) {
                throw $throwable;
            }

            Async::report($throwable, ['hook' => $handler->hook, 'handler' => $handler->descriptor]);

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
     * The driver requested by the registration, or the default one.
     *
     * @throws DriverUnavailable
     */
    private function driverFor(QueuedHandler $handler): AsyncDriver
    {
        $requested = $handler->options->driver();

        if ($requested === null) {
            return Async::driver();
        }

        try {
            return Async::driver($requested);
        } catch (DriverUnavailable $driverUnavailable) {
            if (Async::isDebug()) {
                throw $driverUnavailable;
            }

            Async::report($driverUnavailable, ['hook' => $handler->hook, 'handler' => $handler->descriptor]);

            return Async::driver();
        }
    }
}
