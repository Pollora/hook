<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\Drivers;

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Exceptions\AsyncException;
use Pollora\Hook\Async\PayloadStore;

/**
 * Queues through Action Scheduler, when a plugin bundling it is active.
 *
 * Action Scheduler is never required: the driver is available once its API
 * exists and its data store is initialised. The payload travels whole as the
 * action argument, and the queue name becomes the group. A payload too long
 * for Action Scheduler's arguments (8,000 characters as JSON) is kept by
 * PayloadStore and the action carries only its identifier.
 */
final class ActionSchedulerDriver implements AsyncDriver
{
    public const string DEFAULT_GROUP = 'pollora';

    /**
     * Longest JSON-encoded argument list carried whole, under Action Scheduler's 8,000.
     */
    public const int MAX_ARGUMENTS_LENGTH = 7900;

    public function available(): bool
    {
        if (! function_exists('as_enqueue_async_action') || ! function_exists('as_schedule_single_action')) {
            return false;
        }

        // Before 'action_scheduler_init', its functions exist but cannot store anything
        if (class_exists('ActionScheduler', false)) {
            return \ActionScheduler::is_initialized();
        }

        return function_exists('did_action') && did_action('action_scheduler_init') > 0;
    }

    /**
     * @throws AsyncException When Action Scheduler does not create the action
     */
    public function dispatch(AsyncPayload $payload, int $delay = 0): void
    {
        $group = $payload->queue ?? self::DEFAULT_GROUP;
        $arguments = [$payload->toJson()];
        $storedApart = strlen((string) json_encode($arguments)) > self::MAX_ARGUMENTS_LENGTH;

        if ($storedApart) {
            PayloadStore::store($payload);
            $arguments = [$payload->id];
        }

        try {
            $actionId = $delay > 0
                ? as_schedule_single_action(time() + $delay, Async::HOOK, $arguments, $group)
                : as_enqueue_async_action(Async::HOOK, $arguments, $group);
        } catch (\Throwable $throwable) {
            $actionId = 0;
            $reason = $throwable->getMessage();
        }

        if ($actionId === 0) {
            if ($storedApart) {
                PayloadStore::forget($payload->id);
            }

            throw new AsyncException(sprintf("Action Scheduler: the execution of '%s' could not be queued%s", $payload->hook, isset($reason) ? ': '.$reason : '.'));
        }

        if ($storedApart) {
            WpCronDriver::scheduleRecovery();
        }
    }
}
