<?php

declare(strict_types=1);

use Pollora\Hook\Domain\Contract\CallbackResolverInterface;
use Pollora\Hook\Domain\Service\AbstractHook;

// Concrete implementation for testing (domain layer, no WordPress)
class TestableHook extends AbstractHook
{
    public function do(string $hook, mixed ...$args): self
    {
        return $this;
    }

    public function apply(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }

    // Expose protected methods for testing
    public function testResolveCallback(string $hook, callable|string|array $callback, ?int $acceptedArgs = null): array
    {
        return $this->resolveCallback($hook, $callback, $acceptedArgs);
    }
}

beforeEach(function (): void {
    $this->hook = new TestableHook;
    AbstractHook::clearReflectionCache();
});

describe('P0 — Permissive callback resolution', function (): void {
    it('stores a string callback that is not yet callable without throwing', function (): void {
        $this->hook->add('init', 'my_future_function');

        $callbacks = $this->hook->callbacks('init');
        expect($callbacks)->toBeArray()
            ->and($callbacks)->toHaveCount(1)
            ->and($callbacks[0]['callback'])->toBe('my_future_function')
            ->and($callbacks[0]['args'])->toBe(1);
    });

    it('stores an array callback with non-existent class without throwing', function (): void {
        $result = $this->hook->testResolveCallback('test', ['NonExistentClass', 'method']);

        expect($result['callable'])->toBe(['NonExistentClass', 'method'])
            ->and($result['args'])->toBe(1);
    });

    it('uses provided acceptedArgs for deferred callbacks', function (): void {
        $this->hook->add('init', 'my_future_function', 10, 3);

        $callbacks = $this->hook->callbacks('init');
        expect($callbacks[0]['args'])->toBe(3);
    });

    it('still resolves a valid closure callback normally', function (): void {
        $closure = (fn (string $a, int $b): string => $a);
        $this->hook->add('test_hook', $closure);

        $callbacks = $this->hook->callbacks('test_hook');
        expect($callbacks[0]['callback'])->toBe($closure)
            ->and($callbacks[0]['args'])->toBe(2);
    });

    it('still resolves a valid array callback normally', function (): void {
        $object = new class
        {
            public function handle(string $value): string
            {
                return $value;
            }
        };
        $this->hook->add('test_hook', $object->handle(...));

        $callbacks = $this->hook->callbacks('test_hook');
        expect($callbacks[0]['args'])->toBe(1);
    });

    it('still resolves an existing class with matching method', function (): void {
        // Create a class that has a method matching the hook name pattern
        $className = 'TestHookClass_'.uniqid();
        eval(sprintf('class %s { public function myHook($a) { return $a; } }', $className));

        $this->hook->add('my_hook', $className);

        $callbacks = $this->hook->callbacks('my_hook');
        expect($callbacks[0]['callback'])->toBeArray()
            ->and($callbacks[0]['callback'][0])->toBeInstanceOf($className)
            ->and($callbacks[0]['callback'][1])->toBe('myHook');
    });
});

describe('P1 — CallbackResolverInterface support', function (): void {
    it('uses the resolver when set to instantiate class callbacks', function (): void {
        $mockInstance = new class
        {
            public function myHook(): void {}
        };

        $resolver = Mockery::mock(CallbackResolverInterface::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->andReturn($mockInstance);

        $this->hook->setCallbackResolver($resolver);

        $className = $mockInstance::class;
        $this->hook->add('my_hook', $className);

        $callbacks = $this->hook->callbacks('my_hook');
        expect($callbacks[0]['callback'][0])->toBe($mockInstance);
    });

    it('falls back to direct instantiation when no resolver is set', function (): void {
        $className = 'DirectInstantiationTest_'.uniqid();
        eval(sprintf('class %s { public function myHook() {} }', $className));

        // No resolver set
        $this->hook->add('my_hook', $className);

        $callbacks = $this->hook->callbacks('my_hook');
        expect($callbacks[0]['callback'][0])->toBeInstanceOf($className);
    });

    it('throws RuntimeException when class method does not exist', function (): void {
        $className = 'NoMethodClass_'.uniqid();
        eval(sprintf('class %s {}', $className));

        expect(fn () => $this->hook->add('some_hook', $className))
            ->toThrow(RuntimeException::class);
    });
});

describe('P2 — Remove hooks not registered through Pollora', function (): void {
    it('calls removeHookEvent even when hook is not in internal tracking', function (): void {
        // Use a testable subclass that tracks removeHookEvent calls
        $hook = new class extends AbstractHook
        {
            public array $removedEvents = [];

            public function do(string $hook, mixed ...$args): self
            {
                return $this;
            }

            public function apply(string $hook, mixed $value, mixed ...$args): mixed
            {
                return $value;
            }

            protected function removeHookEvent(string $hook, callable|string|array $callback, int $priority): void
            {
                $this->removedEvents[] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
            }
        };

        // Remove a hook that was never added through this class (simulates WooCommerce core hooks)
        $hook->remove('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);

        expect($hook->removedEvents)->toHaveCount(1)
            ->and($hook->removedEvents[0]['hook'])->toBe('woocommerce_after_single_product_summary')
            ->and($hook->removedEvents[0]['callback'])->toBe('woocommerce_output_related_products')
            ->and($hook->removedEvents[0]['priority'])->toBe(20);
    });

    it('also cleans internal tracking when hook was registered through Pollora', function (): void {
        $hook = new class extends AbstractHook
        {
            public array $removedEvents = [];

            public function do(string $hook, mixed ...$args): self
            {
                return $this;
            }

            public function apply(string $hook, mixed $value, mixed ...$args): mixed
            {
                return $value;
            }

            protected function removeHookEvent(string $hook, callable|string|array $callback, int $priority): void
            {
                $this->removedEvents[] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
            }
        };

        // Add a hook through Pollora, then remove it
        $closure = fn (): null => null;
        $hook->add('my_hook', $closure, 15);

        expect($hook->callbacks('my_hook'))->toHaveCount(1);

        $hook->remove('my_hook', $closure, 15);

        expect($hook->removedEvents)->toHaveCount(1)
            ->and($hook->callbacks('my_hook'))->toBeNull();
    });
});
