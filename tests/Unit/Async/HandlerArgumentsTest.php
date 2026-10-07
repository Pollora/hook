<?php

declare(strict_types=1);

use Pollora\Hook\Async\AsyncContext;
use Pollora\Hook\Async\HandlerArguments;

beforeEach(function (): void {
    $this->context = new AsyncContext(1, 1, 'fr_FR', 'save_post', new DateTimeImmutable);
});

it('counts the hook arguments a handler takes, its AsyncContext parameters left out', function (mixed $callback, int $registered, int $expected): void {
    expect(HandlerArguments::hookArgumentCount($callback, $registered))->toBe($expected);
})->with([
    'no context' => [fn (int $a, string $b): null => null, 2, 2],
    'context last' => [fn (int $a, AsyncContext $c): null => null, 2, 1],
    'context first' => [fn (AsyncContext $c, int $a, bool $b): null => null, 3, 2],
    'nullable context' => [fn (int $a, ?AsyncContext $c): null => null, 2, 1],
    'explicit count kept for a function not defined yet' => ['function_defined_later', 3, 3],
]);

it('puts the context where the handler asks for it and the hook arguments in order', function (): void {
    $handler = fn (int $id, AsyncContext $context, string $status): null => null;

    expect(HandlerArguments::for($handler, [7, 'publish'], $this->context))->toBe([7, $this->context, 'publish']);
});

it('stops at the arguments the hook passed', function (): void {
    $handler = fn (int $id, string $status = 'draft', ?AsyncContext $context = null): null => null;

    expect(HandlerArguments::for($handler, [7], $this->context))->toBe([7]);
});

it('hands every remaining argument to a variadic parameter', function (): void {
    $handler = fn (AsyncContext $context, mixed ...$rest): null => null;

    expect(HandlerArguments::for($handler, [1, 2, 3], $this->context))->toBe([$this->context, 1, 2, 3]);
});

it('passes the hook arguments as they are to a handler it cannot reflect', function (): void {
    expect(HandlerArguments::for('strtoupper', ['abc'], $this->context))->toBe(['abc']);
});
