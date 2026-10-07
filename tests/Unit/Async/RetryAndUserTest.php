<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Tests\Fixtures\Async\RecordingDriver;
use Pollora\Hook\Tests\Fixtures\Async\RecordingHandler;

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_actions_done'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['wp_switches'] = [];
    $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 1, 'locale' => 'fr_FR', 'multisite' => false];
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

function failedAnnouncements(): array
{
    return array_values(array_filter($GLOBALS['wp_actions_done'], fn (array $call): bool => $call['hook'] === 'pollora/async/failed'));
}

describe('tries', function (): void {
    it('queues the handler again after a failure, with the default backoff, until it succeeds', function (): void {
        RecordingHandler::$failuresLeft = 2;
        $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async()->tries(3);

        wp_stub_fire('save_post', 8);
        $this->driver->runAll();

        expect(array_column(RecordingHandler::$calls, 'arguments'))->toBe([[8, 1], [8, 2], [8, 3]])
            ->and(array_column($this->driver->queued, 'delay'))->toBe([0, 10, 60])
            ->and(array_map(fn (array $entry): int => $entry['payload']->attempt, $this->driver->queued))->toBe([1, 2, 3])
            ->and($GLOBALS['incidents'])->toBe([
                'Attempt 1 of 3 failed, retried in 10 s: Temporary failure',
                'Attempt 2 of 3 failed, retried in 60 s: Temporary failure',
            ])
            ->and(failedAnnouncements())->toBe([]);
    });

    it('reports and announces the last failure once the attempts are used', function (): void {
        RecordingHandler::$failuresLeft = 5;
        $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async()->tries(2);

        wp_stub_fire('save_post', 8);
        $this->driver->runAll();

        expect(RecordingHandler::$calls)->toHaveCount(2)
            ->and(end($GLOBALS['incidents']))->toBe('Temporary failure')
            ->and(failedAnnouncements())->toHaveCount(1)
            ->and(failedAnnouncements()[0]['args'][0]->attempt)->toBe(2);
    });

    it('does not retry by default', function (): void {
        RecordingHandler::$failuresLeft = 1;
        $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async();

        wp_stub_fire('save_post', 8);
        $this->driver->runAll();

        expect($this->driver->queued)->toHaveCount(1)
            ->and(failedAnnouncements())->toHaveCount(1);
    });

    it('repeats the last backoff delay', function (): void {
        RecordingHandler::$failuresLeft = 3;
        $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async()->tries(4)->backoff([5, 30]);

        wp_stub_fire('save_post', 8);
        $this->driver->runAll();

        expect(array_column($this->driver->queued, 'delay'))->toBe([0, 5, 30, 30]);
    });

    it('retries through the driver that queued the handler', function (): void {
        $other = new RecordingDriver;
        Async::extend('other', fn (): AsyncDriver => $other);
        RecordingHandler::$failuresLeft = 1;
        $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async()->via('other')->tries(2);

        wp_stub_fire('save_post', 8);
        $other->runAll();

        expect($this->driver->queued)->toBe([])
            ->and($other->queued)->toHaveCount(2)
            ->and($other->queued[0]['payload']->driver)->toBe('other');
    });

    it('retries at once through the sync driver', function (): void {
        RecordingHandler::$failuresLeft = 1;
        $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async()->via('sync')->tries(2);

        wp_stub_fire('save_post', 8);

        expect(array_column(RecordingHandler::$calls, 'arguments'))->toBe([[8, 1], [8, 2]]);
    });

    it('gives up and announces the failure when the retry cannot be queued', function (): void {
        RecordingHandler::$failuresLeft = 1;
        $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async()->tries(2);
        wp_stub_fire('save_post', 8);
        Async::extend('recording', fn (): AsyncDriver => new class implements AsyncDriver
        {
            public function available(): bool
            {
                return false;
            }

            public function dispatch(AsyncPayload $payload, int $delay = 0): void {}
        });

        $this->driver->run(0);

        expect($GLOBALS['incidents'])->toBe(["The asynchronous driver 'recording' is not available in this request.", 'Temporary failure'])
            ->and(failedAnnouncements())->toHaveCount(1);
    });

    it('does not retry a handler that no longer exists', function (): void {
        Async::receive((new AsyncPayload(
            id: AsyncPayload::newId(),
            hook: 'save_post',
            handler: 'App\Removed@handle',
            priority: 10,
            arguments: [],
            origin: ['userId' => 0, 'blogId' => 1, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T10:00:00+00:00'],
            tries: 3,
            driver: 'recording',
        ))->toJson());

        expect($this->driver->queued)->toBe([])
            ->and(failedAnnouncements())->toHaveCount(1);
    });

    it('rejects invalid attempts and delays', function (Closure $configure, string $message): void {
        $pending = $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

        expect(fn () => $configure($pending))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'no attempt' => [fn ($pending) => $pending->tries(0), 'at least 1 attempt'],
        'empty backoff' => [fn ($pending) => $pending->backoff([]), 'one or more delays'],
        'negative delay' => [fn ($pending) => $pending->backoff([10, -1]), 'one or more delays'],
    ]);
});

describe('asUser', function (): void {
    it('runs as the user who fired the hook, then removes that user', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->asUser();
        wp_stub_fire('save_post', 1);
        $GLOBALS['wp_state']['user'] = 0;

        $this->driver->runAll();

        expect(RecordingHandler::$calls[0]['user'])->toBe(5)
            ->and($GLOBALS['wp_state']['user'])->toBe(0)
            ->and($GLOBALS['wp_switches'])->toBe([['user', 5], ['user', 0]]);
    });

    it('runs without a user by default', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();
        wp_stub_fire('save_post', 1);
        $GLOBALS['wp_state']['user'] = 0;

        $this->driver->runAll();

        expect(RecordingHandler::$calls[0]['user'])->toBe(0)
            ->and(RecordingHandler::$calls[0]['arguments'][1]->userId)->toBe(5)
            ->and($GLOBALS['wp_switches'])->toBe([]);
    });

    it('does not switch when nobody was logged in', function (): void {
        $GLOBALS['wp_state']['user'] = 0;
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->asUser();
        wp_stub_fire('save_post', 1);

        $this->driver->runAll();

        expect($GLOBALS['wp_switches'])->toBe([]);
    });
});

it('records the queue name and the driver in the payload', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->onQueue('integrations');

    wp_stub_fire('save_post', 1);

    expect($this->driver->queued[0]['payload']->queue)->toBe('integrations')
        ->and($this->driver->queued[0]['payload']->driver)->toBe('recording');
});
