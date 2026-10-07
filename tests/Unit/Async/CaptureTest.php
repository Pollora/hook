<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncContext;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Exceptions\UnsupportedArgument;
use Pollora\Hook\Tests\Fixtures\Async\CapturingHandler;
use Pollora\Hook\Tests\Fixtures\Async\RecordingDriver;
use Pollora\Hook\Tests\Fixtures\Async\RecordingHandler;

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['wp_objects'] = [];
    $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 1, 'locale' => 'fr_FR', 'multisite' => false];
    $GLOBALS['incidents'] = [];
    RecordingHandler::$calls = [];
    CapturingHandler::$contexts = [];

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

describe('capture()', function (): void {
    it('records values at trigger time, read back at execution through the context', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
            ->capture(fn (int $postId): array => ['source' => 'admin', 'double' => $postId * 2]);

        wp_stub_fire('save_post', 21);
        $this->driver->runAll();

        $context = RecordingHandler::$calls[0]['arguments'][1];
        expect($context)->toBeInstanceOf(AsyncContext::class)
            ->and($context->get('source'))->toBe('admin')
            ->and($context->get('double'))->toBe(42);
    });

    it('keeps the trigger-time state of an object that is reloaded at execution', function (): void {
        $GLOBALS['wp_objects']['post'][12] = new WP_Post(12, 'publish');
        $this->action->add('transition', [RecordingHandler::class, 'contextFirst'])->async()
            ->capture(fn (int $postId, string $status): array => [
                'statusThen' => $GLOBALS['wp_objects']['post'][$postId]->post_status,
                'post' => $GLOBALS['wp_objects']['post'][$postId],
            ]);

        wp_stub_fire('transition', 12, 'publish');
        $GLOBALS['wp_objects']['post'][12] = new WP_Post(12, 'trash');
        $this->driver->runAll();

        $context = RecordingHandler::$calls[0]['arguments'][0];
        expect($context->get('statusThen'))->toBe('publish')
            ->and($context->get('post')->post_status)->toBe('trash');
    });

    it('runs in the original request, not at execution', function (): void {
        $GLOBALS['wp_state']['user'] = 5;
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
            ->capture(fn (): array => ['user' => get_current_user_id()]);

        wp_stub_fire('save_post', 1);
        $GLOBALS['wp_state']['user'] = 0;
        $this->driver->runAll();

        expect(RecordingHandler::$calls[0]['arguments'][1]->get('user'))->toBe(5);
    });

    it('uses the public capture() method of the handler class', function (): void {
        $this->action->add('save_post', [CapturingHandler::class, 'handle'])->async();

        wp_stub_fire('save_post', 7);
        $this->driver->runAll();

        expect(CapturingHandler::$contexts)->toBe([['method' => 'capture()', 'postId' => 7]]);
    });

    it('prefers capture() on the registration to the class method', function (): void {
        $this->action->add('save_post', [CapturingHandler::class, 'handle'])->async()
            ->capture(fn (int $postId): array => ['method' => 'registration', 'postId' => $postId]);

        wp_stub_fire('save_post', 7);
        $this->driver->runAll();

        expect(CapturingHandler::$contexts)->toBe([['method' => 'registration', 'postId' => 7]]);
    });

    it('rejects a captured value that cannot travel, naming it', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
            ->capture(fn (): array => ['request' => new stdClass]);

        expect(fn () => wp_stub_fire('save_post', 1))
            ->toThrow(UnsupportedArgument::class, "Captured value 'request' cannot be carried");
    });

    it('requires an array', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
            ->capture(fn (): string => 'publish');

        expect(fn () => wp_stub_fire('save_post', 1))
            ->toThrow(UnexpectedValueException::class, 'The capture of save_post must return an array, string returned.');
    });

    it('runs the handler in place, in production, when the capture fails', function (): void {
        Async::setDebug(false);
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
            ->capture(fn (): array => throw new RuntimeException('Session expired'));

        wp_stub_fire('save_post', 3);

        expect($this->driver->queued)->toBe([])
            ->and($GLOBALS['incidents'])->toBe(['Session expired'])
            ->and(RecordingHandler::$calls[0]['arguments'][0])->toBe(3);
    });
});

describe('when()', function (): void {
    it('queues only when the condition, given the hook arguments, holds', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
            ->when(fn (int $postId): bool => $postId !== 13);

        wp_stub_fire('save_post', 13);
        wp_stub_fire('save_post', 14);

        expect($this->driver->queued)->toHaveCount(1)
            ->and($this->driver->queued[0]['payload']->arguments)->toBe([14]);
    });

    it('requires true, not a truthy value', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
            ->when(fn (int $postId): int => $postId);

        wp_stub_fire('save_post', 1);

        expect($this->driver->queued)->toBe([]);
    });

    it('lets an exception through, as a synchronous handler would, even in production', function (): void {
        Async::setDebug(false);
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
            ->when(fn (): bool => throw new RuntimeException('Condition failed'));

        expect(fn () => wp_stub_fire('save_post', 1))->toThrow(RuntimeException::class, 'Condition failed');
        expect(RecordingHandler::$calls)->toBe([]);
    });

    it('is checked before the capture', function (): void {
        $captures = 0;
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()
            ->when(fn (): bool => false)
            ->capture(function () use (&$captures): array {
                $captures++;

                return [];
            });

        wp_stub_fire('save_post', 1);

        expect($captures)->toBe(0);
    });
});
