<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Async\Async;
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
 * Run the due WP-Cron events, as wp-cron.php does through the internal hook.
 */
function runCronEvents(): void
{
    foreach ($GLOBALS['wp_cron_events'] as $event) {
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

    expect($GLOBALS['wp_cron_events'])->toHaveCount(1);
    $event = $GLOBALS['wp_cron_events'][0];
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

    expect($GLOBALS['wp_cron_events'][0]['timestamp'])->toBeGreaterThanOrEqual($before + 300)
        ->and($GLOBALS['wp_cron_events'][0]['timestamp'])->toBeLessThanOrEqual(time() + 300);
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

    expect($GLOBALS['wp_cron_events'])->toHaveCount(2)
        ->and($GLOBALS['wp_cron_events'][0]['args'])->not->toBe($GLOBALS['wp_cron_events'][1]['args']);
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
