<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Drivers\WpCronDriver;
use Pollora\Hook\Async\Exceptions\AsyncException;
use Pollora\Hook\Tests\Fixtures\Async\RecordingHandler;

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_cron_events'] = [];
    $GLOBALS['wp_fail'] = [];
    $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 1, 'locale' => 'fr_FR', 'multisite' => false];
    $GLOBALS['incidents'] = [];
    $GLOBALS['wpdb'] = wp_stub_wpdb();
    RecordingHandler::$calls = [];

    Async::flush();
    Async::setDebug(true);
    Async::reportUsing(function (string $message): void {
        $GLOBALS['incidents'][] = $message;
    });

    (new Action)->add('save_post', [RecordingHandler::class, 'handle'])->async();
});

afterEach(function (): void {
    Async::flush();
});

/**
 * The events that run a queued handler.
 *
 * @return list<array{timestamp: int, hook: string, args: array<int, mixed>}>
 */
function executionEvents(): array
{
    return array_values(array_filter($GLOBALS['wp_cron_events'], fn (array $event): bool => $event['hook'] === Async::HOOK));
}

/**
 * Store a payload as the driver does, dispatched $age seconds ago, without its event.
 */
function storeLostPayload(int $age): string
{
    $id = AsyncPayload::newId();
    add_option(WpCronDriver::OPTION_PREFIX.$id, (new AsyncPayload(
        id: $id,
        hook: 'save_post',
        handler: RecordingHandler::class.'@handle',
        priority: 10,
        arguments: [42],
        origin: ['userId' => 0, 'blogId' => 1, 'locale' => 'fr_FR', 'dispatchedAt' => gmdate(DATE_ATOM, time() - $age)],
    ))->toJson(), '', false);

    return $id;
}

/**
 * Run the due WP-Cron events, as wp-cron.php does through the internal hooks.
 */
function runCronEvents(): void
{
    foreach (array_filter($GLOBALS['wp_cron_events'], fn (array $event): bool => $event['timestamp'] <= time()) as $event) {
        foreach ($GLOBALS['wp_async_listeners'] as $listener) {
            if ($listener['hook'] === $event['hook']) {
                call_user_func_array($listener['callback'], $event['args']);
            }
        }
    }
}

it('is the default driver', function (): void {
    expect(Async::driver())->toBeInstanceOf(WpCronDriver::class);
});

it('stores the payload in an option that is not autoloaded and schedules an event carrying only its identifier', function (): void {
    $before = time();
    wp_stub_fire('save_post', 42);

    expect(executionEvents())->toHaveCount(1);
    $event = executionEvents()[0];
    [$id] = $event['args'];
    $stored = $GLOBALS['wp_options'][WpCronDriver::OPTION_PREFIX.$id];

    expect($event['hook'])->toBe(Async::HOOK)
        ->and($event['args'])->toHaveCount(1)
        ->and(WpCronDriver::handles($id))->toBeTrue()
        ->and($event['timestamp'])->toBeGreaterThanOrEqual($before)
        ->and($stored['autoload'])->toBeFalse()
        ->and(json_decode($stored['value'], true)['arguments'])->toBe([42]);
});

it('schedules the event after the delay', function (): void {
    (new Action)->add('save_post_event', [RecordingHandler::class, 'handle'])->async()->delay(300);
    $before = time();

    wp_stub_fire('save_post_event', 1);

    expect(executionEvents()[0]['timestamp'])->toBeGreaterThanOrEqual($before + 300)
        ->and(executionEvents()[0]['timestamp'])->toBeLessThanOrEqual(time() + 300);
});

it('runs the handler when WP-Cron fires the event, then deletes the payload', function (): void {
    wp_stub_fire('save_post', 42);

    runCronEvents();

    expect(RecordingHandler::$calls)->toHaveCount(1)
        ->and(RecordingHandler::$calls[0]['arguments'][0])->toBe(42)
        ->and($GLOBALS['wp_options'])->toBe([]);
});

it('runs a payload once, even when its event fires twice', function (): void {
    wp_stub_fire('save_post', 42);

    runCronEvents();
    runCronEvents();

    expect(RecordingHandler::$calls)->toHaveCount(1)
        ->and($GLOBALS['incidents'])->toBe([]);
});

it('gives every trigger its own event, so WP-Cron discards none as a duplicate', function (): void {
    wp_stub_fire('save_post', 42);
    wp_stub_fire('save_post', 42);

    expect(executionEvents())->toHaveCount(2)
        ->and(executionEvents()[0]['args'])->not->toBe(executionEvents()[1]['args']);
});

it('ignores an identifier whose payload is gone', function (): void {
    Async::receive('00000000-0000-4000-8000-000000000000');

    expect(RecordingHandler::$calls)->toBe([])
        ->and($GLOBALS['incidents'])->toBe([]);
});

it('fails when the payload cannot be stored', function (): void {
    $GLOBALS['wp_fail'] = ['add_option'];

    expect(fn () => wp_stub_fire('save_post', 42))->toThrow(AsyncException::class, "the payload of 'save_post' could not be stored");
    expect($GLOBALS['wp_cron_events'])->toBe([]);
});

it('deletes the stored payload when the event cannot be scheduled', function (): void {
    $GLOBALS['wp_fail'] = ['wp_schedule_single_event'];

    expect(fn () => wp_stub_fire('save_post', 42))
        ->toThrow(AsyncException::class, "the execution of 'save_post' could not be scheduled: A plugin prevented the event from being scheduled.");
    expect($GLOBALS['wp_options'])->toBe([]);
});

describe('Recovery of lost events', function (): void {
    it('schedules the daily recovery task when it queues, once', function (): void {
        wp_stub_fire('save_post', 1);
        wp_stub_fire('save_post', 2);

        $recovery = array_values(array_filter($GLOBALS['wp_cron_events'], fn (array $event): bool => $event['hook'] === WpCronDriver::RECOVERY_HOOK));
        expect($recovery)->toHaveCount(1)
            ->and($recovery[0]['recurrence'])->toBe('daily');
    });

    it('schedules again a payload whose event was lost, and it then runs once', function (): void {
        $id = storeLostPayload(WpCronDriver::RECOVERY_GRACE + 60);

        expect(WpCronDriver::recover())->toBe(1)
            ->and(executionEvents())->toHaveCount(1)
            ->and(executionEvents()[0]['args'])->toBe([$id])
            ->and($GLOBALS['incidents'][0])->toContain('1 asynchronous execution(s) whose event was lost scheduled again');

        runCronEvents();
        runCronEvents();

        expect(RecordingHandler::$calls)->toHaveCount(1)
            ->and($GLOBALS['wp_options'])->toBe([]);
    });

    it('runs from its WP-Cron event', function (): void {
        storeLostPayload(WpCronDriver::RECOVERY_GRACE + 60);
        $GLOBALS['wp_cron_events'][] = ['timestamp' => time(), 'hook' => WpCronDriver::RECOVERY_HOOK, 'args' => []];

        runCronEvents();

        expect(executionEvents())->toHaveCount(1);
    });

    it('leaves a recent payload alone, its event may be about to be scheduled', function (): void {
        storeLostPayload(60);

        expect(WpCronDriver::recover())->toBe(0)
            ->and(executionEvents())->toBe([]);
    });

    it('leaves a payload whose event is still scheduled alone', function (): void {
        (new Action)->add('save_post_event', [RecordingHandler::class, 'handle'])->async()->delay(7200);
        wp_stub_fire('save_post_event', 1);
        [$id] = executionEvents()[0]['args'];
        $stored = json_decode($GLOBALS['wp_options'][WpCronDriver::OPTION_PREFIX.$id]['value'], true);
        $stored['origin']['dispatchedAt'] = gmdate(DATE_ATOM, time() - 2 * WpCronDriver::RECOVERY_GRACE);
        $GLOBALS['wp_options'][WpCronDriver::OPTION_PREFIX.$id]['value'] = json_encode($stored);

        expect(WpCronDriver::recover())->toBe(0)
            ->and(executionEvents())->toHaveCount(1);
    });

    it('deletes a stored value that is not a readable payload', function (): void {
        $id = AsyncPayload::newId();
        add_option(WpCronDriver::OPTION_PREFIX.$id, '{"v":1}', '', false);

        WpCronDriver::recover();

        expect($GLOBALS['wp_options'])->toBe([])
            ->and($GLOBALS['incidents'][0])->toContain("unreadable payload {$id} deleted");
    });

    it('ignores options that only share the prefix', function (): void {
        add_option(WpCronDriver::OPTION_PREFIX.'settings', 'kept', '', false);

        expect(WpCronDriver::recover())->toBe(0)
            ->and($GLOBALS['wp_options'])->toHaveKey(WpCronDriver::OPTION_PREFIX.'settings');
    });
});
