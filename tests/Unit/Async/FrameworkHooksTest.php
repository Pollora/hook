<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncContext;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\HandlerArguments;
use Pollora\Hook\Tests\Fixtures\Async\InjectedHandler;
use Pollora\Hook\Tests\Fixtures\Async\Mailer;
use Pollora\Hook\Tests\Fixtures\Async\OrderStatus;
use Pollora\Hook\Tests\Fixtures\Async\RecordingDriver;
use Pollora\Hook\Tests\Fixtures\Async\RecordingHandler;

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_actions_done'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['wp_objects'] = [];
    $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 1, 'locale' => 'fr_FR', 'multisite' => false];
    $GLOBALS['incidents'] = [];
    RecordingHandler::$calls = [];
    RecordingHandler::$failuresLeft = 0;
    InjectedHandler::$calls = [];

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

describe('Defaults', function (): void {
    it('starts every registration from the defaults', function (): void {
        Async::setDefaults(tries: 4, backoff: [1, 2], asUser: true);
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

        wp_stub_fire('save_post', 1);

        $payload = $this->driver->queued[0]['payload'];
        expect($payload->tries)->toBe(4)
            ->and($payload->backoff)->toBe([1, 2])
            ->and($payload->asUser)->toBeTrue();
    });

    it('lets a registration override them', function (): void {
        Async::setDefaults(tries: 4, asUser: true);
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->tries(1)->asUser(false);

        wp_stub_fire('save_post', 1);

        expect($this->driver->queued[0]['payload']->tries)->toBe(1)
            ->and($this->driver->queued[0]['payload']->asUser)->toBeFalse()
            ->and($this->driver->queued[0]['payload']->backoff)->toBe([10, 60, 300]);
    });

    it('keeps the values not given', function (): void {
        Async::setDefaults(tries: 3);
        Async::setDefaults(asUser: true);

        expect(Async::defaults())->toBe(['tries' => 3, 'backoff' => [10, 60, 300], 'asUser' => true]);
    });

    it('rejects invalid defaults', function (Closure $set, string $message): void {
        expect($set)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'no attempt' => [fn () => Async::setDefaults(tries: 0), 'at least 1 attempt'],
        'negative delay' => [fn () => Async::setDefaults(backoff: [-1]), 'one or more delays'],
    ]);

    it('is reset by flush()', function (): void {
        Async::setDefaults(tries: 9);
        Async::flush();

        expect(Async::defaults()['tries'])->toBe(1);
    });
});

describe('Parameter injection', function (): void {
    beforeEach(function (): void {
        $this->resolved = [];
        Async::injectParametersUsing(function (ReflectionParameter $parameter): object {
            $this->resolved[] = $parameter->getName();

            return new Mailer('queue');
        });
    });

    it('injects the parameters that are not hook arguments, and does not ask WordPress for them', function (): void {
        $GLOBALS['wp_objects']['post'][3] = new WP_Post(3);
        $this->action->add('save_post', [InjectedHandler::class, 'send'])->async();

        expect($GLOBALS['wp_actions'][0]['args'])->toBe(2);

        wp_stub_fire('save_post', 3, new WP_Post(3));
        $this->driver->runAll();

        expect(InjectedHandler::$calls)->toBe([['postId' => 3, 'transport' => 'queue', 'hook' => 'save_post', 'post' => 3]])
            ->and($this->resolved)->toBe(['mailer']);
    });

    it('takes WordPress objects, enums, dates and JsonSerializable objects from the hook', function (Closure $handler): void {
        $parameter = (new ReflectionFunction($handler))->getParameters()[0];

        expect(Async::isInjectable($parameter))->toBeFalse();
    })->with([
        'post' => [fn (WP_Post $post): null => null],
        'enum' => [fn (OrderStatus $status): null => null],
        'date' => [fn (DateTimeImmutable $date): null => null],
        'JsonSerializable' => [fn (JsonSerializable $value): null => null],
        'scalar' => [fn (int $id): null => null],
        'union' => [fn (Mailer|int $value): null => null],
        'unknown class' => [eval('return fn (\\App\\Missing $value) => null;')],
    ]);

    it('follows a predicate of its own when one is given', function (): void {
        Async::injectParametersUsing(fn (ReflectionParameter $parameter): string => 'injected', fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'token');
        $handler = fn (int $id, string $token): null => null;
        $context = new AsyncContext(1, 1, 'fr_FR', 'save_post', new DateTimeImmutable);

        expect(HandlerArguments::hookArgumentCount($handler, 2))->toBe(1)
            ->and(HandlerArguments::for($handler, [7], $context))->toBe([7, 'injected']);
    });

    it('injects nothing without a resolver', function (): void {
        Async::injectParametersUsing(null);
        $this->action->add('save_post', [InjectedHandler::class, 'send'])->async();

        expect($GLOBALS['wp_actions'][0]['args'])->toBe(3);
    });
});

describe('Throwing the final failure', function (): void {
    function failedAnnouncementsCount(): int
    {
        return count(array_filter($GLOBALS['wp_actions_done'], fn (array $call): bool => $call['hook'] === 'pollora/async/failed'));
    }

    it('announces the last failure, then throws it instead of reporting it', function (): void {
        RecordingHandler::$failuresLeft = 1;
        $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async();
        wp_stub_fire('save_post', 1);

        expect(fn () => Async::receive($this->driver->queued[0]['payload']->toJson(), throwOnFinalFailure: true))
            ->toThrow(RuntimeException::class, 'Temporary failure');
        expect($GLOBALS['incidents'])->toBe([])
            ->and(failedAnnouncementsCount())->toBe(1);
    });

    it('still retries while attempts are left', function (): void {
        RecordingHandler::$failuresLeft = 1;
        $this->action->add('save_post', [RecordingHandler::class, 'flaky'])->async()->tries(2);
        wp_stub_fire('save_post', 1);

        Async::receive($this->driver->queued[0]['payload']->toJson(), throwOnFinalFailure: true);

        expect($this->driver->queued)->toHaveCount(2)
            ->and(failedAnnouncementsCount())->toBe(0);
    });

    it('throws for a handler that no longer exists and for an unreadable payload', function (): void {
        $missing = (new AsyncPayload(
            id: AsyncPayload::newId(),
            hook: 'save_post',
            handler: 'App\Removed@handle',
            priority: 10,
            arguments: [],
            origin: ['userId' => 0, 'blogId' => 1, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T10:00:00+00:00'],
        ))->toJson();

        expect(fn () => Async::receive($missing, throwOnFinalFailure: true))->toThrow(RuntimeException::class, 'no longer exists')
            ->and(fn () => Async::receive('{"v":99}', throwOnFinalFailure: true))->toThrow(RuntimeException::class, 'version 99');
    });

    it('drops a handler whose referenced object was deleted, without throwing', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'post'])->async();
        wp_stub_fire('save_post', new WP_Post(12));

        Async::receive($this->driver->queued[0]['payload']->toJson(), throwOnFinalFailure: true);

        expect(RecordingHandler::$calls)->toBe([]);
    });
});
