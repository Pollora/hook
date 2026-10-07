<?php

declare(strict_types=1);

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
        'closure' => [fn (): null => null, 'closure'],
        'first-class callable' => [pollora_test_async_handler(...), 'closure'],
        'anonymous class' => [[new class
        {
            public function handle(): void {}
        }, 'handle'], 'anonymous class'],
        'malformed array' => [[InvoiceHandler::class, 'send', 'extra'], 'cannot be made asynchronous'],
    ]);
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
