<?php

declare(strict_types=1);

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncContext;
use Pollora\Hook\Async\AsyncFake;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\CallableDescriptor;
use Pollora\Hook\Async\Exceptions\UnresolvableHandler;
use Pollora\Hook\Async\HandlerArguments;
use Pollora\Hook\Async\PendingAsync;
use Pollora\Hook\Async\QueuedHandler;
use Pollora\Hook\Tests\Fixtures\Async\InvoiceHandler;

$GLOBALS['edge_fake_runs'] = [];

if (! function_exists('pollora_edge_recorder')) {
    function pollora_edge_recorder(int $value): void
    {
        $GLOBALS['edge_fake_runs'][] = $value;
    }
}

beforeEach(function (): void {
    $GLOBALS['edge_fake_runs'] = [];
    $GLOBALS['incidents'] = [];
    Async::flush();
    Async::setDebug(true);
    Async::reportUsing(function (string $message): void {
        $GLOBALS['incidents'][] = $message;
    });
});

afterEach(function (): void {
    Async::flush();
});

function edgeQueued(PendingAsync $pending, string $hook, string $descriptor = 'pollora_edge_recorder'): QueuedHandler
{
    $queued = new QueuedHandler($hook, 10, $descriptor, $descriptor, 1, 1, $pending);
    $pending->track($queued);

    return $queued;
}

function edgeAsyncPayload(string $handler, array $arguments = [1]): AsyncPayload
{
    return new AsyncPayload(
        id: AsyncPayload::newId(),
        hook: 'save_post',
        handler: $handler,
        priority: 10,
        arguments: $arguments,
        origin: ['userId' => 0, 'blogId' => 1, 'locale' => 'en_US', 'dispatchedAt' => '2026-10-07T14:30:00+00:00'],
    );
}

describe('PendingAsync', function (): void {
    it('renumbers the delays it is given', function (): void {
        expect(PendingAsync::validBackoff([5 => 1, 9 => 2]))->toBe([1, 2])
            ->and(PendingAsync::validBackoff(7))->toBe([7]);
    });

    it('keeps going through the hooks of except() past one already excluded', function (): void {
        $pending = new PendingAsync(fn (QueuedHandler $queued): null => null);
        edgeQueued($pending, 'a');
        edgeQueued($pending, 'b');
        $pending->except('a');

        $pending->except(['a', 'b']);

        expect($pending->hooks())->toBe([]);
    });

    it('keeps going through the hooks of except() past an unknown one', function (): void {
        Async::setDebug(false);
        $restored = [];
        $pending = new PendingAsync(function (QueuedHandler $queued) use (&$restored): void {
            $restored[] = $queued->hook;
        });
        edgeQueued($pending, 'b');

        $pending->except(['unknown', 'b']);

        expect($restored)->toBe(['b'])
            ->and($pending->hooks())->toBe([])
            ->and($GLOBALS['incidents'])->toHaveCount(1);
    });

    it('names the hooks of the registration when except() gets an unknown one', function (): void {
        $pending = new PendingAsync(fn (QueuedHandler $queued): null => null);
        edgeQueued($pending, 'a');
        edgeQueued($pending, 'b');
        edgeQueued($pending, 'c');
        $pending->except('b');

        expect(fn () => $pending->except('zz'))
            ->toThrow(InvalidArgumentException::class, "except(): 'zz' is not a hook of this asynchronous registration (a, c, b).");
    });

    it('reports an unknown hook of except() outside debug mode', function (): void {
        Async::setDebug(false);
        $pending = new PendingAsync(fn (QueuedHandler $queued): null => null);
        edgeQueued($pending, 'a');

        $pending->except('zz');

        expect($GLOBALS['incidents'])->toBe(["except(): 'zz' is not a hook of this asynchronous registration (a)."]);
    });

    it('accepts a lock of one second', function (): void {
        $pending = (new PendingAsync(fn (QueuedHandler $queued): null => null))->unique(1);

        expect($pending->uniqueFor())->toBe(1);
    });

    it('refuses a lock shorter than a second, saying how long it was', function (): void {
        expect(fn () => (new PendingAsync(fn (QueuedHandler $queued): null => null))->unique(-3))
            ->toThrow(InvalidArgumentException::class, 'unique() expects a lock of at least 1 second, -3 given.');
    });

    it('delays by seconds, never by less than none', function (int $delay, int $expected): void {
        $pending = (new PendingAsync(fn (QueuedHandler $queued): null => null))->delay($delay);

        expect($pending->delayInSeconds())->toBe($expected);
    })->with([[-5, 0], [0, 0], [1, 1], [30, 30]]);

    it('delays by an interval, never by less than none', function (DateInterval $interval, int $expected): void {
        $pending = (new PendingAsync(fn (QueuedHandler $queued): null => null))->delay($interval);

        expect($pending->delayInSeconds())->toBe($expected);
    })->with(function (): array {
        $negative = new DateInterval('PT90S');
        $negative->invert = 1;

        return [
            'ninety seconds' => [new DateInterval('PT90S'), 90],
            'none' => [new DateInterval('PT0S'), 0],
            'backwards' => [$negative, 0],
        ];
    });
});

describe('QueuedHandler', function (): void {
    it('keeps the descriptor it was given', function (): void {
        $queued = new QueuedHandler('save_post', 10, 'Preset@descriptor', 'strlen', 1, 1, new PendingAsync(fn (QueuedHandler $queued): null => null));

        expect($queued->descriptor())->toBe('Preset@descriptor')
            ->and($queued->label())->toBe('Preset@descriptor');
    });

    it('labels a closure whose descriptor is not computed yet', function (): void {
        $queued = new QueuedHandler('save_post', 10, null, static fn (): null => null, 1, 1, new PendingAsync(fn (QueuedHandler $queued): null => null));

        expect($queued->label())->toBe('closure');
    });

    it('identifies a registration by hook, handler and priority', function (): void {
        $queued = new QueuedHandler('save_post', 20, 'Handler@run', 'strlen', 1, 1, new PendingAsync(fn (QueuedHandler $queued): null => null));

        expect($queued->key())->toBe('save_post|Handler@run|20')
            ->and(QueuedHandler::keyFor('init', 'fn', 5))->toBe('init|fn|5');
    });
});

describe('AsyncFake', function (): void {
    it('lists the payloads of one handler past those of another', function (): void {
        $fake = new AsyncFake;
        $fake->dispatch(edgeAsyncPayload('Other@run'));
        $fake->dispatch(edgeAsyncPayload('Wanted@run', [2]));
        $fake->dispatch(edgeAsyncPayload('Wanted@run', [3]));

        expect(array_map(fn (AsyncPayload $payload): array => $payload->arguments, $fake->dispatched('Wanted')))->toBe([[2], [3]]);
    });

    it('filters the payloads with a callback, going on past the ones it refuses', function (): void {
        $fake = new AsyncFake;
        $fake->dispatch(edgeAsyncPayload('Wanted@run', [1]), 0);
        $fake->dispatch(edgeAsyncPayload('Wanted@run', [2]), 60);
        $fake->dispatch(edgeAsyncPayload('Wanted@run', [3]), 0);

        $delayed = $fake->dispatched('Wanted', fn (AsyncPayload $payload, int $delay): bool => $delay === 60);
        $all = $fake->dispatched(null, fn (AsyncPayload $payload, int $delay): bool => $delay === 0);

        expect(array_map(fn (AsyncPayload $payload): array => $payload->arguments, $delayed))->toBe([[2]])
            ->and(array_map(fn (AsyncPayload $payload): array => $payload->arguments, $all))->toBe([[1], [3]]);
    });

    it('matches a handler by its class name, with or without a leading backslash', function (): void {
        $fake = new AsyncFake;
        $fake->dispatch(edgeAsyncPayload('App\\Hooks\\Sync@handle'));

        expect($fake->dispatched('\\App\\Hooks\\Sync'))->toHaveCount(1)
            ->and($fake->dispatched('App\\Hooks\\Sync@handle'))->toHaveCount(1)
            ->and($fake->dispatched('App\\Hooks\\Sync'))->toHaveCount(1);
    });

    it('does not match a class whose name only starts like the handler', function (): void {
        $fake = new AsyncFake;
        $fake->dispatch(edgeAsyncPayload('App\\Hooks\\SyncExtra@handle'));

        expect($fake->dispatched('App\\Hooks\\Sync'))->toBe([]);
    });

    it('matches a closure by the name closure, and only a closure', function (): void {
        $fake = new AsyncFake;
        $fake->dispatch(edgeAsyncPayload('closure:abc:def'));
        $fake->dispatch(edgeAsyncPayload('named_function'));

        expect($fake->dispatched('closure'))->toHaveCount(1)
            ->and($fake->dispatched('named_function'))->toHaveCount(1)
            ->and($fake->dispatched('other_function'))->toBe([])
            ->and($fake->dispatched('closure:abc:def'))->toHaveCount(1);
    });

    it('does not take a named handler for a closure', function (): void {
        $fake = new AsyncFake;
        $fake->dispatch(edgeAsyncPayload('named_function'));

        expect($fake->dispatched('closure'))->toBe([]);
    });

    it('does not take a closure for another handler', function (): void {
        $fake = new AsyncFake;
        $fake->dispatch(edgeAsyncPayload('closure:abc:def'));

        expect($fake->dispatched('named_function'))->toBe([]);
    });

    it('runs the recorded handlers in the order they were dispatched', function (): void {
        $fake = new AsyncFake;
        $fake->dispatch(edgeAsyncPayload('pollora_edge_recorder', [1]));
        $fake->dispatch(edgeAsyncPayload('pollora_edge_recorder', [2]));
        $fake->dispatch(edgeAsyncPayload('pollora_edge_recorder', [3]));

        $fake->runDispatched();

        expect($GLOBALS['edge_fake_runs'])->toBe([1, 2, 3])
            ->and($fake->dispatched())->toBe([]);
    });
});

describe('HandlerArguments', function (): void {
    beforeEach(function (): void {
        $this->context = new AsyncContext(0, 1, 'en_US', 'save_post', new DateTimeImmutable('2026-10-07'));
    });

    it('never counts less than no argument, nor more than the handler takes', function (): void {
        expect(HandlerArguments::hookArgumentCount(fn (): null => null, 5))->toBe(0)
            ->and(HandlerArguments::hookArgumentCount(fn (int $a, int $b): null => null, 1))->toBe(1)
            ->and(HandlerArguments::hookArgumentCount(fn (int $a, int $b): null => null, 5))->toBe(2)
            ->and(HandlerArguments::hookArgumentCount(fn (int $a, int $b): null => null, -3))->toBe(0);
    });

    it('counts the parameters of a handler given as a pair, whatever its form', function (mixed $callback): void {
        expect(HandlerArguments::hookArgumentCount($callback, 9))->toBe(1);
    })->with([
        'object and method' => [[new InvoiceHandler, 'send']],
        'class and static method' => [[InvoiceHandler::class, 'report']],
        'class and instance method' => [[InvoiceHandler::class, 'send']],
        'Class::method string' => [InvoiceHandler::class.'::report'],
        'invokable object' => [new InvoiceHandler],
        'function name' => ['pollora_edge_recorder'],
    ]);

    it('gives the registered count for what it cannot reflect', function (mixed $callback): void {
        expect(HandlerArguments::hookArgumentCount($callback, 4))->toBe(4);
    })->with([
        'three items' => [[new InvoiceHandler, 'send', 'extra']],
        'unknown class' => [['Missing\\Handler\\Class', 'send']],
        'unknown method' => [[InvoiceHandler::class, 'missing']],
        'first item neither object nor string' => [[123, 'send']],
        'method that is not a string' => [[InvoiceHandler::class, 5]],
        'one item' => [[InvoiceHandler::class]],
        'class name alone' => [InvoiceHandler::class],
        'unknown function' => ['pollora_edge_unknown_function'],
        'unknown static method' => ['Missing\\Class::method'],
    ]);

    it('counts a handler taking a union type', function (): void {
        expect(HandlerArguments::hookArgumentCount(fn (int|string $value): null => null, 3))->toBe(1);
    });

    it('hands the hook arguments of a callable it cannot reflect as a list', function (): void {
        $callable = new class
        {
            public function __call(string $name, array $arguments): void {}
        };

        expect((new ReflectionMethod(HandlerArguments::class, 'for'))->invoke(null, [$callable, 'anything'], ['a' => 1, 3 => 2], $this->context))->toBe([1, 2]);
    });

    it('builds the arguments of a function around its context', function (): void {
        $handler = fn (int $postId, AsyncContext $context, string $status = 'draft'): null => null;

        expect(HandlerArguments::for($handler, [42], $this->context))->toBe([42, $this->context, 'draft']);
    });

    it('takes a union typed parameter for a hook argument', function (): void {
        $handler = fn (int|string $value, AsyncContext $context): null => null;

        expect(HandlerArguments::for($handler, ['x'], $this->context))->toBe(['x', $this->context]);
    });
});

describe('CallableDescriptor', function (): void {
    beforeEach(function (): void {
        $this->descriptor = new CallableDescriptor;
        Async::useClosureKey('edge-signing-key');
    });

    it('describes a named handler at once when asked to describe or defer it', function (): void {
        expect($this->descriptor->describeOrDefer('pollora_edge_recorder'))->toBe('pollora_edge_recorder')
            ->and($this->descriptor->describeOrDefer([InvoiceHandler::class, 'send']))->toBe(InvoiceHandler::class.'@send')
            ->and($this->descriptor->describeOrDefer(static fn (): null => null))->toBeNull();
    });

    it('refuses a pair whose first item is not a class', function (): void {
        expect(fn () => $this->descriptor->describe([5, 'send']))->toThrow(UnresolvableHandler::class, 'array callback cannot be made asynchronous');
    });

    it('refuses an array that is not a list', function (): void {
        expect(fn () => $this->descriptor->describe(['class' => InvoiceHandler::class, 'method' => 'send']))
            ->toThrow(UnresolvableHandler::class, 'array callback cannot be made asynchronous');
    });

    it('refuses a Class::method string with more than two parts', function (): void {
        expect(fn () => $this->descriptor->describe(InvoiceHandler::class.'::send::extra'))
            ->toThrow(UnresolvableHandler::class, 'cannot be made asynchronous');
    });

    it('drops the leading backslash of a function and of a class', function (): void {
        expect($this->descriptor->describe('\\pollora_edge_recorder'))->toBe('pollora_edge_recorder')
            ->and($this->descriptor->describe(['\\'.InvoiceHandler::class, 'send']))->toBe(InvoiceHandler::class.'@send');
    });

    it('refuses a class or a method that is not a name', function (array $pair): void {
        expect(fn () => $this->descriptor->describe($pair))->toThrow(UnresolvableHandler::class, 'cannot be made asynchronous');
    })->with([
        'bad class' => [['1bad', 'send']],
        'bad method' => [[InvoiceHandler::class, 'bad-method']],
        'namespaced method' => [[InvoiceHandler::class, 'Other\\send']],
        'both bad' => [['1bad', 'bad-method']],
    ]);

    it('refuses a descriptor that is not a name', function (string $descriptor): void {
        expect(fn () => $this->descriptor->resolve($descriptor))->toThrow(UnresolvableHandler::class, 'is not a valid handler descriptor');
    })->with([
        'bad class' => ['1bad@send'],
        'bad method' => [InvoiceHandler::class.'@bad-method'],
        'namespaced method' => [InvoiceHandler::class.'@Other\\send'],
        'bad function' => ['not a function'],
    ]);

    it('quotes the start of a closure descriptor it cannot read, at most 80 characters', function (): void {
        expect(fn () => $this->descriptor->resolve('closure:short'))->toThrow(UnresolvableHandler::class, "'closure:short' is not a valid handler descriptor.");

        $long = 'closure:'.str_repeat('z', 100);

        expect(fn () => $this->descriptor->resolve($long))->toThrow(UnresolvableHandler::class, "'".substr($long, 0, 80)."' is not a valid");

        try {
            $this->descriptor->resolve($long);
        } catch (UnresolvableHandler $exception) {
            expect($exception->getMessage())->not->toContain(substr($long, 0, 81));
        }
    });

    it('does not read a signed payload with characters foreign to base64', function (): void {
        $descriptor = $this->descriptor->describe(static fn (): int => 1);
        [, , $encoded] = explode(':', $descriptor, 3);
        $tampered = substr($encoded, 0, 10).'!'.substr($encoded, 10);
        $signed = 'closure:'.hash_hmac('sha256', $tampered, Async::closureKey()).':'.$tampered;

        expect(($this->descriptor->resolve($descriptor))())->toBe(1)
            ->and(fn () => $this->descriptor->resolve($signed))
            ->toThrow(UnresolvableHandler::class, 'the payload does not hold a serialized closure.');
    });

    it('splits a closure descriptor in three parts at most', function (): void {
        $descriptor = 'closure:'.str_repeat('a', 64).':abc:def';

        expect(fn () => $this->descriptor->resolve($descriptor))->toThrow(UnresolvableHandler::class, 'signature of a queued closure is invalid');
    });

    it('refuses a signed payload that is not valid base64', function (): void {
        $encoded = 'not!base64!';
        $descriptor = 'closure:'.hash_hmac('sha256', $encoded, Async::closureKey()).':'.$encoded;

        expect(fn () => $this->descriptor->resolve($descriptor))
            ->toThrow(UnresolvableHandler::class, 'A queued closure cannot be read back: the payload does not hold a serialized closure.');
    });
});
