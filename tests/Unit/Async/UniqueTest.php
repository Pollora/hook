<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Drivers\WpCronDriver;
use Pollora\Hook\Async\Exceptions\AsyncException;
use Pollora\Hook\Async\UniqueLock;
use Pollora\Hook\Tests\Fixtures\Async\RecordingDriver;
use Pollora\Hook\Tests\Fixtures\Async\RecordingHandler;

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_cron_events'] = [];
    $GLOBALS['wp_fail'] = [];
    $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 1, 'locale' => 'fr_FR', 'multisite' => false];
    $GLOBALS['wpdb'] = wp_stub_wpdb();
    $GLOBALS['incidents'] = [];
    RecordingHandler::$calls = [];
    RecordingHandler::$failuresLeft = 0;

    Async::flush();
    Async::setDebug(true);
    Async::reportUsing(function (string $message): void {
        $GLOBALS['incidents'][] = $message;
    });
    $this->driver = new RecordingDriver;
    Async::extend('recording', fn (): AsyncDriver => $this->driver);
    Async::setDefaultDriver('recording');

    $this->action = new Action;
});

afterEach(function (): void {
    Async::flush();
});

function uniqueLocks(): array
{
    return array_filter($GLOBALS['wp_options'], fn (string $name): bool => str_starts_with($name, UniqueLock::OPTION_PREFIX), ARRAY_FILTER_USE_KEY);
}

it('merges identical triggers while the first one has not run', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->unique();

    wp_stub_fire('save_post', 42);
    wp_stub_fire('save_post', 42);
    wp_stub_fire('save_post', 43);

    expect(array_map(fn (array $entry): array => $entry['payload']->arguments, $this->driver->queued))->toBe([[42], [43]]);
});

it('stores the lock in an option that is not autoloaded, expiring after the given time', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->unique(for: 600);

    wp_stub_fire('save_post', 42);

    $lock = array_values(uniqueLocks())[0];
    expect(uniqueLocks())->toHaveCount(1)
        ->and($lock['autoload'])->toBeFalse()
        ->and($lock['value'])->toBeGreaterThanOrEqual(time() + 599)
        ->and($lock['value'])->toBeLessThanOrEqual(time() + 600)
        ->and(UniqueLock::OPTION_PREFIX.$this->driver->queued[0]['payload']->uniqueKey)->toBe(array_key_first(uniqueLocks()));
});

it('queues again once the first trigger starts running', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->unique();
    wp_stub_fire('save_post', 42);

    $this->driver->run(0);
    wp_stub_fire('save_post', 42);

    expect($this->driver->queued)->toHaveCount(2)
        ->and(RecordingHandler::$calls)->toHaveCount(1);
});

it('takes over an expired lock', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->unique(for: 60);
    wp_stub_fire('save_post', 42);
    $name = array_key_first(uniqueLocks());
    $GLOBALS['wp_options'][$name]['value'] = time() - 1;

    wp_stub_fire('save_post', 42);

    expect($this->driver->queued)->toHaveCount(2);
});

it('tells triggers apart by hook and by handler', function (): void {
    $this->action->add(['save_post', 'edit_post'], [RecordingHandler::class, 'handle'])->async()->unique();
    $this->action->add('save_post', [RecordingHandler::class, 'post'])->async()->unique();

    wp_stub_fire('save_post', 42);
    wp_stub_fire('edit_post', 42);
    wp_stub_fire('save_post', 42);

    expect(array_map(fn (array $entry): string => $entry['payload']->hook.' '.$entry['payload']->handler, $this->driver->queued))->toBe([
        'save_post '.RecordingHandler::class.'@handle',
        'save_post '.RecordingHandler::class.'@post',
        'edit_post '.RecordingHandler::class.'@handle',
    ]);
});

it('lets a retry run without releasing the lock of a newer trigger', function (): void {
    RecordingHandler::$failuresLeft = 1;
    $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async()->unique()->tries(2);
    wp_stub_fire('save_post', 42);
    $this->driver->run(0);
    wp_stub_fire('save_post', 42);

    $this->driver->run(1);
    wp_stub_fire('save_post', 42);

    expect(array_map(fn (array $entry): int => $entry['payload']->attempt, $this->driver->queued))->toBe([1, 2, 1])
        ->and(uniqueLocks())->toHaveCount(1);
});

it('releases the lock when the trigger cannot be queued', function (): void {
    Async::setDebug(false);
    Async::setDefaultDriver(null);
    $GLOBALS['wp_fail'] = ['wp_schedule_single_event'];
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->unique();

    wp_stub_fire('save_post', 42);

    expect(uniqueLocks())->toBe([])
        ->and(RecordingHandler::$calls)->toHaveCount(1);
});

it('runs every trigger through the sync driver, the lock being released at once', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->via('sync')->unique();

    wp_stub_fire('save_post', 42);
    wp_stub_fire('save_post', 42);

    expect(RecordingHandler::$calls)->toHaveCount(2);
});

it('fails, rather than merging, when the lock cannot be stored', function (): void {
    $GLOBALS['wp_fail'] = ['add_option'];

    expect(fn () => UniqueLock::acquire('key', 60))->toThrow(AsyncException::class, 'could not be stored');
});

it('rejects a lock shorter than a second', function (): void {
    expect(fn () => $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->unique(for: 0))
        ->toThrow(InvalidArgumentException::class, 'at least 1 second');
});

it('deletes expired locks during the daily maintenance', function (): void {
    add_option(UniqueLock::OPTION_PREFIX.'expired', time() - 10, '', false);
    add_option(UniqueLock::OPTION_PREFIX.'active', time() + 600, '', false);

    WpCronDriver::recover();

    expect(array_keys(uniqueLocks()))->toBe([UniqueLock::OPTION_PREFIX.'active']);
});

it('reads a payload without a unique key', function (): void {
    $data = json_decode((new AsyncPayload(
        id: AsyncPayload::newId(),
        hook: 'save_post',
        handler: 'pollora_test',
        priority: 10,
        arguments: [],
        origin: ['userId' => 0, 'blogId' => 1, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T10:00:00+00:00'],
    ))->toJson(), true);
    unset($data['uniqueKey']);

    expect(AsyncPayload::fromJson(json_encode($data))->uniqueKey)->toBeNull();
});
