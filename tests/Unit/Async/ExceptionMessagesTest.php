<?php

declare(strict_types=1);

use Pollora\Hook\Async\Exceptions\InvalidPayload;
use Pollora\Hook\Async\Exceptions\UnresolvableHandler;
use Pollora\Hook\Async\Exceptions\UnsupportedArgument;
use Pollora\Hook\Domain\Exceptions\CallbackAnalysisFailedException;
use Pollora\Hook\Domain\Exceptions\CallbackResolutionFailedException;
use Pollora\Hook\Domain\Exceptions\HookException;

/**
 * An exception whose code is a string, as PDOException's is.
 */
final class EdgeStringCodeException extends Exception
{
    public function __construct(string $message)
    {
        parent::__construct($message);
        $this->code = 'HY000';
    }
}

final class EdgeStringCodeReflectionException extends ReflectionException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
        $this->code = 'HY000';
    }
}

describe('Asynchronous exceptions', function (): void {
    it('says what part of the payload cannot be rebuilt', function (): void {
        expect(InvalidPayload::unreadableArgument("the enum 'X' no longer exists.")->getMessage())
            ->toBe("An argument of the asynchronous payload cannot be rebuilt: the enum 'X' no longer exists.");
    });

    it('says what cannot be carried, where, and what can', function (): void {
        expect(UnsupportedArgument::at('argument #2[items][0]', new stdClass)->getMessage())
            ->toBe('Argument #2[items][0] cannot be carried to an asynchronous action: stdClass is not supported. '
                .'Pass an ID, a scalar, an array, an enum, a date, a WordPress object or a JsonSerializable object.');
    });

    it('explains how to make a closure that cannot be serialized asynchronous', function (): void {
        expect(UnresolvableHandler::unserializableClosure('Serialization of Generator is not allowed.')->getMessage())
            ->toBe('This closure cannot be made asynchronous: Serialization of Generator is not allowed. A closure carries the object it is bound to and the variables it uses; '
                .'declare it static (static fn …) and pass IDs rather than objects, or use a class method.')
            ->and(UnresolvableHandler::unserializableClosure('No full stop')->getMessage())
            ->toStartWith('This closure cannot be made asynchronous: No full stop. A closure');
    });

    it('says why a queued closure cannot be read back', function (): void {
        expect(UnresolvableHandler::unreadableClosure('the payload is damaged.')->getMessage())
            ->toBe('A queued closure cannot be read back: the payload is damaged.');
    });
});

describe('Resolution failures', function (): void {
    it('adds how to fix a failure that comes from building the class', function (): void {
        $message = CallbackResolutionFailedException::forClass('App\\Hook', new RuntimeException('Cannot build'))->getMessage();

        expect($message)->toBe("Failed to resolve 'App\\Hook': Cannot build. Check that the class exists and can be built without arguments, or set a callback resolver (a container) that knows how to build it.");
    });

    it('adds nothing to a hook error, which already says how to fix it', function (): void {
        $previous = new class('Method missing.') extends HookException {};

        expect(CallbackResolutionFailedException::forClass('App\\Hook', $previous)->getMessage())
            ->toBe("Failed to resolve 'App\\Hook': Method missing.");
    });

    it('ends the cause with exactly one full stop', function (string $cause, string $expected): void {
        $previous = new class($cause) extends HookException {};

        expect(CallbackResolutionFailedException::forClass('App\\Hook', $previous)->getMessage())
            ->toBe("Failed to resolve 'App\\Hook': {$expected}");
    })->with([
        'no full stop' => ['Method missing', 'Method missing.'],
        'full stop' => ['Method missing.', 'Method missing.'],
        'trailing spaces' => ["Method missing  \n", 'Method missing.'],
        'empty' => ['', ''],
        'full stop then spaces' => ["Done.  \n", 'Done.'],
    ]);

    it('keeps a full stop that is not at the end', function (): void {
        $previous = new class('a.b') extends HookException {};

        expect(CallbackResolutionFailedException::forClass('X', $previous)->getMessage())->toBe("Failed to resolve 'X': a.b.");
    });

    it('keeps the code and the cause, a string code reading as a number', function (): void {
        $cause = new EdgeStringCodeException('PDO says no');

        $exception = CallbackResolutionFailedException::forClass('App\\Hook', $cause);

        expect($exception->getCode())->toBe(0)
            ->and($exception->getPrevious())->toBe($cause);
    });

    it('keeps an integer code', function (): void {
        $exception = CallbackResolutionFailedException::forClass('App\\Hook', new RuntimeException('x', 42));

        expect($exception->getCode())->toBe(42);
    });

    it('explains how to avoid detecting the arguments of a callable', function (): void {
        $exception = CallbackAnalysisFailedException::fromReflection(new ReflectionException('Class "X" does not exist.', 7));

        expect($exception->getMessage())->toBe('Failed to analyze callable: Class "X" does not exist. Check that the function or method exists and is public, '
            .'or pass the number of accepted arguments explicitly so it is not detected.')
            ->and($exception->getCode())->toBe(7);
    });

    it('ends the reflection message with one full stop', function (): void {
        expect(CallbackAnalysisFailedException::fromReflection(new ReflectionException('Method X::y() does not exist'))->getMessage())
            ->toStartWith('Failed to analyze callable: Method X::y() does not exist. Check');
    });

    it('reads the string code of a reflection error as a number', function (): void {
        $cause = new EdgeStringCodeReflectionException('odd');

        expect(CallbackAnalysisFailedException::fromReflection($cause)->getCode())->toBe(0);
    });
});
