<?php

declare(strict_types=1);

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\CallableDescriptor;
use Pollora\Hook\Async\Exceptions\UnresolvableHandler;
use Pollora\Hook\Domain\Contract\CallbackResolverInterface;
use Pollora\Hook\Tests\Fixtures\Async\InvoiceHandler;

if (! function_exists('pollora_test_async_handler')) {
    function pollora_test_async_handler(int $postId): void {}
}

beforeEach(function (): void {
    $this->descriptor = new CallableDescriptor;
});

describe('describe()', function (): void {
    it('describes a function by its name', function (): void {
        expect($this->descriptor->describe('pollora_test_async_handler'))->toBe('pollora_test_async_handler')
            ->and($this->descriptor->describe('function_defined_later'))->toBe('function_defined_later');
    });

    it('describes a class method as Class@method, whatever form it is passed in', function (mixed $callback, string $expected): void {
        expect($this->descriptor->describe($callback))->toBe($expected);
    })->with([
        'instance' => [[new InvoiceHandler, 'send'], InvoiceHandler::class.'@send'],
        'class and instance method' => [[InvoiceHandler::class, 'send'], InvoiceHandler::class.'@send'],
        'class and static method' => [[InvoiceHandler::class, 'report'], InvoiceHandler::class.'@report'],
        'Class::method string' => [InvoiceHandler::class.'::report', InvoiceHandler::class.'@report'],
        'invokable object' => [new InvoiceHandler, InvoiceHandler::class.'@__invoke'],
    ]);

    it('rejects a handler that has no name to be resolved by later', function (mixed $callback, string $message): void {
        expect(fn () => $this->descriptor->describe($callback))->toThrow(UnresolvableHandler::class, $message);
    })->with([
        'closure without a signing key' => [static fn (): null => null, 'without a signing key'],
        'anonymous class' => [[new class
        {
            public function handle(): void {}
        }, 'handle'], 'anonymous class'],
        'malformed array' => [[InvoiceHandler::class, 'send', 'extra'], 'cannot be made asynchronous'],
    ]);
});

describe('Closures', function (): void {
    beforeEach(function (): void {
        Async::flush();
        Async::useClosureKey('test-signing-key');
    });

    afterEach(function (): void {
        Async::flush();
    });

    it('describes a first-class callable by the name it was made from', function (Closure $callable, string $expected): void {
        expect($this->descriptor->describe($callable))->toBe($expected);
    })->with([
        'function' => [pollora_test_async_handler(...), 'pollora_test_async_handler'],
        'instance method' => [(new InvoiceHandler)->send(...), InvoiceHandler::class.'@send'],
        'static method' => [InvoiceHandler::report(...), InvoiceHandler::class.'@report'],
    ]);

    it('serializes and signs a closure, then runs it back with its bound variables', function (): void {
        $prefix = 'order-';
        $descriptor = $this->descriptor->describe(fn (int $id): string => $prefix.$id);

        expect($descriptor)->toMatch('/^closure:[0-9a-f]{64}:/')
            ->and($this->descriptor->resolve($descriptor)(42))->toBe('order-42');
    });

    it('refuses a closure whose payload was altered', function (): void {
        $descriptor = $this->descriptor->describe(fn (): string => 'genuine');
        [$prefix, $signature, $encoded] = explode(':', $descriptor, 3);
        $altered = base64_encode(str_replace('genuine', 'forged!', (string) base64_decode($encoded)));

        expect(fn () => $this->descriptor->resolve("{$prefix}:{$signature}:{$altered}"))
            ->toThrow(UnresolvableHandler::class, 'signature of a queued closure is invalid');
    });

    it('refuses a closure signed with another key', function (): void {
        $descriptor = $this->descriptor->describe(fn (): string => 'genuine');
        Async::useClosureKey('rotated-key');

        expect(fn () => $this->descriptor->resolve($descriptor))
            ->toThrow(UnresolvableHandler::class, 'signature of a queued closure is invalid');
    });

    it('never unserializes anything but a closure, even correctly signed', function (): void {
        $encoded = base64_encode(serialize(new ArrayObject));
        $descriptor = 'closure:'.hash_hmac('sha256', $encoded, Async::closureKey()).':'.$encoded;

        expect(fn () => $this->descriptor->resolve($descriptor))
            ->toThrow(UnresolvableHandler::class, 'does not hold a serialized closure');
    });

    it('rejects a malformed closure descriptor', function (): void {
        expect(fn () => $this->descriptor->resolve('closure:not-a-signature:abc'))
            ->toThrow(UnresolvableHandler::class, 'not a valid handler descriptor');
    });

    it('explains why a closure cannot be serialized', function (): void {
        $rows = (static function (): Generator {
            yield 1;
        })();

        $handler = static function () use ($rows): Generator {
            return $rows;
        };

        expect(fn () => $this->descriptor->describe($handler))
            ->toThrow(UnresolvableHandler::class, 'declare it static (static fn …) and pass IDs rather than objects');
    });

    it('requires a signing key', function (): void {
        Async::useClosureKey(null);

        expect(fn () => $this->descriptor->describe(static fn (): null => null))
            ->toThrow(UnresolvableHandler::class, 'without a signing key');
    });
});

describe('resolve()', function (): void {
    it('resolves a function', function (): void {
        expect($this->descriptor->resolve('pollora_test_async_handler'))->toBe('pollora_test_async_handler');
    });

    it('resolves an instance method on a new instance', function (): void {
        $callable = $this->descriptor->resolve(InvoiceHandler::class.'@send');

        expect($callable[0])->toBeInstanceOf(InvoiceHandler::class)
            ->and($callable[1])->toBe('send');
    });

    it('builds the instance through the callback resolver', function (): void {
        $resolver = new class implements CallbackResolverInterface
        {
            public function resolve(string $className): object
            {
                return new $className('container');
            }
        };

        $callable = (new CallableDescriptor($resolver))->resolve(InvoiceHandler::class.'@send');

        expect($callable[0]->source)->toBe('container');
    });

    it('resolves a static method without instantiating', function (): void {
        expect($this->descriptor->resolve(InvoiceHandler::class.'@report'))->toBe([InvoiceHandler::class, 'report']);
    });

    it('round-trips every describable form', function (): void {
        foreach (['pollora_test_async_handler', [new InvoiceHandler, 'send'], [InvoiceHandler::class, 'report'], new InvoiceHandler] as $callback) {
            expect(is_callable($this->descriptor->resolve($this->descriptor->describe($callback))))->toBeTrue();
        }
    });

    it('fails with an explicit message when the handler no longer exists', function (string $descriptor, string $message): void {
        expect(fn () => $this->descriptor->resolve($descriptor))->toThrow(UnresolvableHandler::class, $message);
    })->with([
        'function' => ['removed_function', "'removed_function' no longer exists"],
        'class' => ['App\Removed@handle', "'App\Removed' no longer exists"],
        'method' => [InvoiceHandler::class.'@removed', 'InvoiceHandler::removed()'],
    ]);

    it('rejects a malformed descriptor', function (string $descriptor): void {
        expect(fn () => $this->descriptor->resolve($descriptor))->toThrow(UnresolvableHandler::class, 'not a valid handler descriptor');
    })->with(['system("id")', 'Foo@bar@baz', '@handle', 'Foo@']);
});
