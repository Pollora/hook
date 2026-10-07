<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Drivers\ActionSchedulerDriver;
use Pollora\Hook\Async\Drivers\WpCronDriver;
use Pollora\Hook\Async\Exceptions\AsyncException;
use Pollora\Hook\Async\PayloadStore;
use Pollora\Hook\Tests\Fixtures\Async\RecordingHandler;

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_cron_events'] = [];
    $GLOBALS['wp_fail'] = [];
    $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 1, 'locale' => 'fr_FR', 'multisite' => false];
    $GLOBALS['wpdb'] = wp_stub_wpdb();
    $GLOBALS['as_actions'] = [];
    $GLOBALS['as_fail'] = false;
    $GLOBALS['as_initialized'] = true;
    $GLOBALS['incidents'] = [];
    RecordingHandler::$calls = [];
    RecordingHandler::$failuresLeft = 0;

    Async::flush();
    Async::setDebug(true);
    Async::reportUsing(function (string $message): void {
        $GLOBALS['incidents'][] = $message;
    });

    $this->action = new Action;
});

afterEach(function (): void {
    $GLOBALS['as_initialized'] = false;
    Async::flush();
});

/**
 * Run the queued Action Scheduler actions, as its queue runner does.
 */
function runScheduledActions(): void
{
    for ($index = 0; $index < count($GLOBALS['as_actions']); $index++) {
        $action = $GLOBALS['as_actions'][$index];
        if ($action['timestamp'] <= time()) {
            Async::receive(...$action['args']);
        }
    }
}

it('is the default driver once Action Scheduler is initialised', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

    wp_stub_fire('save_post', 42);

    expect($GLOBALS['as_actions'])->toHaveCount(1)
        ->and($GLOBALS['wp_cron_events'])->toBe([])
        ->and(AsyncPayload::fromJson($GLOBALS['as_actions'][0]['args'][0])->driver)->toBe('action-scheduler');
});

it('falls back to WP-Cron while Action Scheduler is not initialised yet', function (): void {
    $GLOBALS['as_initialized'] = false;
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

    wp_stub_fire('save_post', 42);

    expect($GLOBALS['as_actions'])->toBe([])
        ->and(array_column($GLOBALS['wp_cron_events'], 'hook'))->toContain(Async::HOOK);
});

it('carries the payload whole in the action, in the pollora group by default', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

    wp_stub_fire('save_post', 42);

    $action = $GLOBALS['as_actions'][0];
    expect($action['hook'])->toBe(Async::HOOK)
        ->and($action['group'])->toBe(ActionSchedulerDriver::DEFAULT_GROUP)
        ->and(AsyncPayload::fromJson($action['args'][0])->arguments)->toBe([42])
        ->and($GLOBALS['wp_options'])->toBe([]);
});

it('uses the queue name as the group', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->onQueue('integrations');

    wp_stub_fire('save_post', 42);

    expect($GLOBALS['as_actions'][0]['group'])->toBe('integrations');
});

it('schedules a delayed action for later', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->delay(120);
    $before = time();

    wp_stub_fire('save_post', 42);

    expect($GLOBALS['as_actions'][0]['timestamp'])->toBeGreaterThanOrEqual($before + 120);
});

it('runs the handler when Action Scheduler runs the action', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();
    wp_stub_fire('save_post', 42);

    runScheduledActions();

    expect(RecordingHandler::$calls[0]['arguments'][0])->toBe(42);
});

it('keeps a payload too long for Action Scheduler apart and runs it once', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
        ->capture(fn (): array => ['report' => str_repeat('x', 9000)]);

    wp_stub_fire('save_post', 42);
    [$id] = $GLOBALS['as_actions'][0]['args'];

    expect(PayloadStore::handles($id))->toBeTrue()
        ->and($GLOBALS['wp_options'][PayloadStore::OPTION_PREFIX.$id]['autoload'])->toBeFalse()
        ->and(wp_next_scheduled(WpCronDriver::RECOVERY_HOOK))->not->toBeFalse();

    runScheduledActions();
    runScheduledActions();

    expect(RecordingHandler::$calls)->toHaveCount(1)
        ->and(strlen(RecordingHandler::$calls[0]['arguments'][1]->get('report')))->toBe(9000)
        ->and($GLOBALS['wp_options'])->toBe([]);
});

it('leaves a payload kept apart alone during recovery while its action is pending', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->delay(7200)
        ->capture(fn (): array => ['report' => str_repeat('x', 9000)]);
    wp_stub_fire('save_post', 42);
    [$id] = $GLOBALS['as_actions'][0]['args'];
    $option = PayloadStore::OPTION_PREFIX.$id;
    $stored = json_decode($GLOBALS['wp_options'][$option]['value'], true);
    $stored['origin']['dispatchedAt'] = gmdate(DATE_ATOM, time() - 2 * WpCronDriver::RECOVERY_GRACE);
    $GLOBALS['wp_options'][$option]['value'] = json_encode($stored);

    expect(WpCronDriver::recover())->toBe(0);
});

it('retries through Action Scheduler', function (): void {
    RecordingHandler::$failuresLeft = 1;
    $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async()->tries(2)->backoff(0);
    wp_stub_fire('save_post', 42);

    runScheduledActions();

    expect($GLOBALS['as_actions'])->toHaveCount(2)
        ->and(array_column(RecordingHandler::$calls, 'arguments'))->toBe([[42, 1], [42, 2]]);
});

it('fails, and forgets the payload kept apart, when Action Scheduler refuses the action', function (): void {
    $GLOBALS['as_fail'] = true;
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
        ->capture(fn (): array => ['report' => str_repeat('x', 9000)]);

    expect(fn () => wp_stub_fire('save_post', 42))
        ->toThrow(AsyncException::class, "Action Scheduler: the execution of 'save_post' could not be queued: ActionScheduler_Action::\$args too long.");
    expect($GLOBALS['wp_options'])->toBe([]);
});

it('is unavailable without Action Scheduler', function (): void {
    $GLOBALS['as_initialized'] = false;

    expect((new ActionSchedulerDriver)->available())->toBeFalse();
});
