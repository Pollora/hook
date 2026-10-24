<?php

declare(strict_types=1);

use Pollora\Hook\Domain\Contract\CallbackResolverInterface;
use Pollora\Hook\Domain\Exceptions\CallbackAnalysisFailed;
use Pollora\Hook\Domain\Exceptions\CallbackResolutionFailed;
use Pollora\Hook\Domain\Exceptions\HookException;
use Pollora\Hook\Domain\Exceptions\HookMethodNotFound;
use Pollora\Hook\Domain\Service\AbstractHook;

// Concrete hook exposing argument detection (domain layer, no WordPress)
class ExceptionProbeHook extends AbstractHook
{
    public function do(string $hook, mixed ...$args): self
    {
        return $this;
    }

    public function apply(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }

    public function probeArguments(callable|array $callback): int
    {
        return $this->detectArguments($callback);
    }
}

beforeEach(function (): void {
    $this->hook = new ExceptionProbeHook;
    AbstractHook::clearReflectionCache();
});

describe('Named exceptions', function (): void {
    it('says which method a class needs to handle a hook', function (): void {
        $exception = HookMethodNotFound::forMethod('App\Hooks\Sync', 'savePost');

        expect($exception)->toBeInstanceOf(HookException::class)
            ->and($exception)->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getMessage())->toStartWith("Method 'savePost' not found in class 'App\Hooks\Sync'.")
            ->and($exception->getMessage())->toContain("add a public method 'savePost()' to the class, or register a [class, method] pair");
    });

    it('chains the reason a class could not be resolved, with its code', function (): void {
        $previous = new LogicException('Container cannot build it', 42);
        $exception = CallbackResolutionFailed::forClass('App\Hooks\Sync', $previous);

        expect($exception)->toBeInstanceOf(HookException::class)
            ->and($exception)->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious())->toBe($previous)
            ->and($exception->getCode())->toBe(42)
            ->and($exception->getMessage())->toStartWith("Failed to resolve 'App\Hooks\Sync': Container cannot build it.")
            ->and($exception->getMessage())->toContain('set a callback resolver (a container) that knows how to build it');
    });

    it('chains the reflection error of a callable it cannot analyze', function (): void {
        $previous = new ReflectionException('Method Foo::bar() does not exist', 7);
        $exception = CallbackAnalysisFailed::fromReflection($previous);

        expect($exception)->toBeInstanceOf(HookException::class)
            ->and($exception)->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious())->toBe($previous)
            ->and($exception->getCode())->toBe(7)
            ->and($exception->getMessage())->toStartWith('Failed to analyze callable: Method Foo::bar() does not exist.')
            ->and($exception->getMessage())->toContain('pass the number of accepted arguments explicitly');
    });
});

describe('Thrown by the hook', function (): void {
    it('reports a class without a method named after the hook', function (): void {
        $className = 'NoHookMethod_'.uniqid();
        eval(sprintf('class %s {}', $className));

        try {
            $this->hook->add('save_post', $className);
            $this->fail('No exception was thrown.');
        } catch (CallbackResolutionFailed $exception) {
            expect($exception)->toBeInstanceOf(RuntimeException::class)
                ->and($exception->getMessage())->toStartWith(sprintf("Failed to resolve '%s': Method 'savePost' not found in class '%s'.", $className, $className))
                ->and($exception->getPrevious())->toBeInstanceOf(HookMethodNotFound::class);
        }
    });

    it('reports a class the resolver cannot build, keeping the previous exception', function (): void {
        $className = 'UnbuildableHandler_'.uniqid();
        eval(sprintf('class %s { public function handle() {} }', $className));
        $previous = new LogicException('No binding', 3);

        $this->hook->setCallbackResolver(new class($previous) implements CallbackResolverInterface
        {
            public function __construct(private readonly Throwable $error) {}

            public function resolve(string $className): object
            {
                throw $this->error;
            }
        });

        try {
            $this->hook->add('save_post', [$className, 'handle']);
            $this->fail('No exception was thrown.');
        } catch (CallbackResolutionFailed $exception) {
            expect($exception->getPrevious())->toBe($previous)
                ->and($exception->getCode())->toBe(3)
                ->and($exception->getMessage())->toStartWith(sprintf("Failed to resolve '%s': No binding.", $className));
        }
    });

    it('reports a callable whose parameters cannot be read', function (): void {
        expect(fn () => $this->hook->probeArguments([ExceptionProbeHook::class, 'missingMethod']))
            ->toThrow(CallbackAnalysisFailed::class, 'Failed to analyze callable:');
    });

    it('is still caught as a RuntimeException', function (): void {
        $className = 'StillRuntime_'.uniqid();
        eval(sprintf('class %s {}', $className));

        expect(fn () => $this->hook->add('init', $className))->toThrow(RuntimeException::class);
    });
});
