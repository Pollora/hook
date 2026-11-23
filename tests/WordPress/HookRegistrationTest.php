<?php

declare(strict_types=1);

use Pollora\Hook\Action;
use Pollora\Hook\Filter;

/*
 * The Action and Filter facades against WordPress's own hook tables: what add_action(), remove_action(),
 * do_action() and apply_filters() do with the priority, the number of accepted arguments and the callback
 * the package resolved. The unit suite doubles these functions.
 */

final class PolloraWpintHandler
{
    /** @var list<array<int, mixed>> */
    public static array $calls = [];

    public function polloraWpintResolved(string $first, string $second = 'none'): void
    {
        self::$calls[] = func_get_args();
    }
}

beforeEach(function (): void {
    PolloraWpintHandler::$calls = [];
});

afterEach(function (): void {
    foreach (['pollora_wpint_order', 'pollora_wpint_args', 'pollora_wpint_resolved', 'pollora_wpint_one', 'pollora_wpint_two', 'pollora_wpint_removed', 'pollora_wpint_filter', 'pollora_wpint_filter_args'] as $hook) {
        remove_all_actions($hook);
        remove_all_filters($hook);
    }
});

it('runs the callbacks in the priority order WordPress sorts them, and passes only the arguments each declares', function (): void {
    $log = [];

    Action::add('pollora_wpint_order', function (string $first, string $second, string $third) use (&$log): void {
        $log[] = ['late', func_num_args(), [$first, $second, $third]];
    }, 20);
    Action::add('pollora_wpint_order', function (string $first) use (&$log): void {
        $log[] = ['early', func_num_args(), [$first]];
    }, 5);

    do_action('pollora_wpint_order', 'a', 'b', 'c');

    expect($log)->toBe([['early', 1, ['a']], ['late', 3, ['a', 'b', 'c']]])
        ->and(has_action('pollora_wpint_order'))->toBeTrue();
});

it('honours the number of arguments it is told to pass, over the one it detects', function (): void {
    $seen = [];

    Action::add('pollora_wpint_args', function (mixed ...$arguments) use (&$seen): void {
        $seen = $arguments;
    }, 10, 2);

    do_action('pollora_wpint_args', 'a', 'b', 'c');

    expect($seen)->toBe(['a', 'b']);
});

it('hooks a class on the method named after the hook, with the arguments that method declares', function (): void {
    Action::add('pollora_wpint_resolved', PolloraWpintHandler::class);

    do_action('pollora_wpint_resolved', 'x', 'y', 'z');

    expect(PolloraWpintHandler::$calls)->toBe([['x', 'y']]);
});

it('registers one callback on several hooks', function (): void {
    $count = 0;

    Action::add(['pollora_wpint_one', 'pollora_wpint_two'], function () use (&$count): void {
        $count++;
    });

    do_action('pollora_wpint_one');
    do_action('pollora_wpint_two');

    expect($count)->toBe(2);
});

it('takes a callback out of WordPress, by its callback and priority', function (): void {
    $count = 0;
    $callback = function () use (&$count): void {
        $count++;
    };
    Action::add('pollora_wpint_removed', $callback, 7);

    do_action('pollora_wpint_removed');

    expect(Action::remove('pollora_wpint_removed', $callback, 7))->not->toBeFalse()
        ->and(has_action('pollora_wpint_removed', $callback))->toBeFalse();

    do_action('pollora_wpint_removed');

    expect($count)->toBe(1);
});

it('takes the callback it registered out of WordPress when none is named', function (): void {
    $count = 0;
    Action::add('pollora_wpint_removed', function () use (&$count): void {
        $count++;
    }, 15);

    expect(Action::remove('pollora_wpint_removed'))->not->toBeFalse();

    do_action('pollora_wpint_removed');

    expect($count)->toBe(0)
        ->and(has_action('pollora_wpint_removed'))->toBeFalse();
});

it('chains filters by priority and hands each one the value the last returned', function (): void {
    Filter::add('pollora_wpint_filter', fn (string $value): string => $value.'-late', 20);
    Filter::add('pollora_wpint_filter', fn (string $value): string => $value.'-early', 5);
    Filter::add('pollora_wpint_filter_args', fn (string $value, string $context): string => $value.'-'.$context, 10, 2);

    expect(apply_filters('pollora_wpint_filter', 'v'))->toBe('v-early-late')
        ->and(apply_filters('pollora_wpint_filter_args', 'v', 'ctx', 'ignored'))->toBe('v-ctx');
});
