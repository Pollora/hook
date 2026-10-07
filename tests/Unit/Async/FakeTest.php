<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncFake;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Exceptions\UnsupportedArgument;
use Pollora\Hook\Tests\Fixtures\Async\RecordingHandler;

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_cron_events'] = [];
    $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 1, 'locale' => 'fr_FR', 'multisite' => false];
    $GLOBALS['wp_objects'] = [];
    RecordingHandler::$calls = [];
    RecordingHandler::$failuresLeft = 0;

    Async::flush();
    Async::setDebug(true);
    $this->fake = Async::fake();
    $this->action = new Action;
});

afterEach(function (): void {
    Async::flush();
});

it('records queued handlers instead of queuing them, whatever the driver', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();
    $this->action->add('edit_post', [RecordingHandler::class, 'handle'])->async()->via('rabbitmq');

    wp_stub_fire('save_post', 1);
    wp_stub_fire('edit_post', 2);

    expect($this->fake)->toBeInstanceOf(AsyncFake::class)
        ->and($this->fake->dispatched())->toHaveCount(2)
        ->and($GLOBALS['wp_cron_events'])->toBe([])
        ->and(RecordingHandler::$calls)->toBe([]);
});

it('asserts on a handler by class, descriptor or payload, as in the design document', function (): void {
    $GLOBALS['wp_objects']['post'][12] = new WP_Post(12, 'publish');
    $this->action->add('save_post', [RecordingHandler::class, 'post'])->async()
        ->capture(fn (WP_Post $post): array => ['status' => $post->post_status]);

    wp_stub_fire('save_post', new WP_Post(12, 'publish'));

    Async::assertDispatched(RecordingHandler::class);
    Async::assertDispatched(RecordingHandler::class.'@post');
    Async::assertDispatched(RecordingHandler::class, fn (AsyncPayload $payload): bool => $payload->captured['status'] === 'publish');
    Async::assertDispatchedTimes(RecordingHandler::class, 1);
    Async::assertNotDispatched(RecordingHandler::class.'@handle');
});

it('fails an assertion that does not hold, with a readable message', function (Closure $assertion, string $message): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();
    wp_stub_fire('save_post', 1);

    expect($assertion)->toThrow(AssertionFailedError::class, $message);
})->with([
    'not dispatched' => [fn () => Async::assertDispatched('App\Missing'), 'The asynchronous handler [App\Missing] was not dispatched.'],
    'wrong payload' => [fn () => Async::assertDispatched(RecordingHandler::class, fn (): bool => false), 'was not dispatched with the expected payload'],
    'wrong count' => [fn () => Async::assertDispatchedTimes(RecordingHandler::class, 2), 'dispatched 1 time(s) instead of 2'],
    'dispatched' => [fn () => Async::assertNotDispatched(RecordingHandler::class), 'was dispatched'],
    'something dispatched' => [fn () => Async::assertNothingDispatched(), '1 asynchronous handler(s) were dispatched'],
]);

it('hands the delay to the callback', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->delay(90);

    wp_stub_fire('save_post', 1);

    Async::assertDispatched(RecordingHandler::class, fn (AsyncPayload $payload, int $delay): bool => $delay === 90);
});

it('matches closures', function (): void {
    Async::useClosureKey('test-signing-key');
    $this->action->add('save_post', static function (int $postId): void {})->async();

    wp_stub_fire('save_post', 1);

    Async::assertDispatched('closure');
});

it('does not record a trigger that when() skips', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->when(fn (): bool => false);

    wp_stub_fire('save_post', 1);

    Async::assertNothingDispatched();
});

it('still rejects an argument that cannot travel', function (): void {
    $this->action->add('save_post', [RecordingHandler::class, 'objects'])->async();

    expect(fn () => wp_stub_fire('save_post', new stdClass))->toThrow(UnsupportedArgument::class);
});

it('runs the recorded handlers on demand, retries included', function (): void {
    RecordingHandler::$failuresLeft = 1;
    $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async()->tries(2);
    wp_stub_fire('save_post', 4);

    $this->fake->runDispatched();

    expect(array_column(RecordingHandler::$calls, 'arguments'))->toBe([[4, 1], [4, 2]])
        ->and($this->fake->dispatched())->toBe([]);
});

it('requires fake() before asserting', function (): void {
    Async::flush();

    expect(fn () => Async::assertNothingDispatched())->toThrow(LogicException::class, 'Call Async::fake()');
});

it('is forgotten by flush()', function (): void {
    Async::flush();
    Async::setDefaultDriver('sync');
    $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

    wp_stub_fire('save_post', 1);

    expect(RecordingHandler::$calls)->toHaveCount(1);
});
