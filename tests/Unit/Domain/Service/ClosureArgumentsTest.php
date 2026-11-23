<?php

declare(strict_types=1);

use Pollora\Hook\Domain\Service\AbstractHook;

/**
 * A hook service with no platform behind it, which only tracks what it is given.
 */
function closureCountingHook(): AbstractHook
{
    return new class extends AbstractHook
    {
        public function do(string $hook, mixed ...$args): self
        {
            return $this;
        }

        public function apply(string $hook, mixed $value, mixed ...$args): mixed
        {
            return $value;
        }
    };
}

/**
 * Register a closure that takes no argument, then forget the hook so that the closure is freed.
 */
function registerThenFreeAClosure(): void
{
    closureCountingHook()->add('pollora_freed', function (): void {});
}

beforeEach(function (): void {
    AbstractHook::clearReflectionCache();
});

it('counts the arguments of a closure that takes the object ID of a freed one', function (): void {
    // PHP hands the ID of a freed closure to the next one created: a count cached under the ID would be wrong for it.
    foreach (range(1, 5) as $_) {
        registerThenFreeAClosure();
    }

    $hook = closureCountingHook();
    $hook->add('pollora_reused', function (string $first, string $second, string $third): void {});

    expect($hook->callbacks('pollora_reused')[0]['args'])->toBe(3);
});

it('counts again the arguments of a closure that replaces a freed one with another signature', function (): void {
    $counts = [];

    foreach ([0, 2, 1, 3] as $arity) {
        $hook = closureCountingHook();
        $closure = match ($arity) {
            0 => function (): void {},
            1 => function (string $a): void {},
            2 => function (string $a, string $b): void {},
            3 => function (string $a, string $b, string $c): void {},
        };
        $hook->add('pollora_loop', $closure);
        $counts[] = $hook->callbacks('pollora_loop')[0]['args'];
        unset($hook, $closure);
    }

    expect($counts)->toBe([0, 2, 1, 3]);
});

it('keeps the count of a closure that is still alive', function (): void {
    $hook = closureCountingHook();
    $closure = function (string $a, string $b): void {};

    $hook->add('pollora_alive', $closure);
    $hook->add('pollora_alive_again', $closure);

    expect($hook->callbacks('pollora_alive')[0]['args'])->toBe(2)
        ->and($hook->callbacks('pollora_alive_again')[0]['args'])->toBe(2);
});
