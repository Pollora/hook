<?php

declare(strict_types=1);

use Pollora\Hook\Domain\Service\AbstractHook;

/**
 * A hook service that records what is unregistered and exposes the tracking internals.
 */
final class TrackingHook extends AbstractHook
{
    /** @var list<array{hook: string, callback: mixed, priority: int}> */
    public array $removed = [];

    public function do(string $hook, mixed ...$args): self
    {
        return $this;
    }

    public function apply(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }

    public function detect(callable|array $callback): int
    {
        return $this->detectArguments($callback);
    }

    public function replace(string $hook, int $priority, callable|string|array $current, callable|string|array $replacement, int $args, callable|string|array|null $handler): void
    {
        $this->replaceTrackedCallback($hook, $priority, $current, $replacement, $args, $handler);
    }

    protected function removeHookEvent(string $hook, callable|string|array $callback, int $priority): void
    {
        $this->removed[] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
    }
}

function trackingHook(): TrackingHook
{
    return new TrackingHook;
}

/**
 * Declare a class and return its name.
 */
function declareHookClass(string $body, string $extends = ''): string
{
    $name = 'TrackingFixture_'.str_replace('.', '', uniqid('', true));
    eval(sprintf('class %s %s { %s }', $name, $extends === '' ? '' : 'extends '.$extends, $body));

    return $name;
}

beforeEach(function (): void {
    AbstractHook::clearReflectionCache();
});

describe('Removing tracked callbacks', function (): void {
    it('keeps the remaining registrations as a list once one is removed', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'first_fn');
        $hook->add('init', 'second_fn');
        $hook->add('init', 'third_fn');

        $hook->remove('init', 'first_fn');

        $callbacks = $hook->callbacks('init');
        expect(array_keys($callbacks))->toBe([0, 1])
            ->and($callbacks[0]['callback'])->toBe('second_fn')
            ->and($callbacks[1]['callback'])->toBe('third_fn');
    });

    it('removes only the registration at the requested priority', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'same_fn', 5);
        $hook->add('init', 'same_fn', 20);

        $hook->remove('init', 'same_fn', 20);

        $callbacks = $hook->callbacks('init');
        expect($callbacks)->toHaveCount(1)
            ->and($callbacks[0]['priority'])->toBe(5);
    });

    it('removes only the requested callback at a priority shared by several', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'one_fn', 10);
        $hook->add('init', 'two_fn', 10);

        $hook->remove('init', 'one_fn', 10);

        $callbacks = $hook->callbacks('init');
        expect($callbacks)->toHaveCount(1)
            ->and($callbacks[0]['callback'])->toBe('two_fn');
    });

    it('returns false when the hook it is asked to remove without a callback is unknown', function (): void {
        expect(trackingHook()->remove('nothing_here'))->toBeFalse();
    });

    it('removes the first registration of a hook asked without a callback', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'only_fn', 7);

        expect($hook->remove('init'))->toBe($hook)
            ->and($hook->removed)->toBe([['hook' => 'init', 'callback' => 'only_fn', 'priority' => 7]])
            ->and($hook->callbacks('init'))->toBeNull();
    });

    it('does not read an unknown hook', function (): void {
        set_error_handler(function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            expect(trackingHook()->callbacks('never_registered'))->toBeNull();
        } finally {
            restore_error_handler();
        }
    });

    it('tracks every callback of a hook registered several times', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'a_fn');
        $hook->add('init', 'b_fn');
        $hook->add('init', 'c_fn');

        expect($hook->callbacks('init'))->toHaveCount(3);
    });
});

describe('Looking a registration up', function (): void {
    it('does not read an unknown hook when asked for a callback', function (): void {
        set_error_handler(function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            expect(trackingHook()->exists('never_registered', 'some_fn'))->toBeFalse();
        } finally {
            restore_error_handler();
        }
    });

    it('finds a callback registered after another one at a different priority', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'early_fn', 5);
        $hook->add('init', 'late_fn', 10);

        expect($hook->exists('init', 'late_fn', 10))->toBeTrue()
            ->and($hook->exists('init', 'late_fn', 5))->toBeFalse()
            ->and($hook->exists('init', 'early_fn'))->toBeTrue()
            ->and($hook->exists('unknown', 'late_fn'))->toBeFalse();
    });

    it('finds an instance method pair at its own priority among several', function (): void {
        $class = declareHookClass('public function handle() {}');
        $hook = trackingHook();
        $hook->add('init', [$class, 'handle'], 5);
        $hook->add('init', [$class, 'handle'], 10);

        expect($hook->exists('init', [$class, 'handle'], 10))->toBeTrue()
            ->and($hook->exists('init', [$class, 'handle'], 5))->toBeTrue()
            ->and($hook->exists('init', [$class, 'handle'], 20))->toBeFalse();
    });
});

describe('Accepted arguments', function (): void {
    it('keeps the argument count asked for a callable instead of detecting it', function (): void {
        $hook = trackingHook();
        $hook->add('init', fn (int $a, int $b): int => $a + $b, 10, 1);

        expect($hook->callbacks('init')[0]['args'])->toBe(1);
    });

    it('keeps the argument count asked for a class name', function (): void {
        $class = declareHookClass('public function initHook($a, $b, $c) {}');
        $hook = trackingHook();
        $hook->add('init_hook', $class, 10, 1);

        expect($hook->callbacks('init_hook')[0]['args'])->toBe(1);
    });

    it('detects the argument count of a class name method', function (): void {
        $class = declareHookClass('public function initHook($a, $b, $c) {}');
        $hook = trackingHook();
        $hook->add('init_hook', $class);

        expect($hook->callbacks('init_hook')[0]['args'])->toBe(3);
    });

    it('keeps the argument count asked for an instance method pair', function (): void {
        $class = declareHookClass('public function handle($a, $b, $c) {}');
        $hook = trackingHook();
        $hook->add('init', [$class, 'handle'], 10, 1);

        expect($hook->callbacks('init')[0]['args'])->toBe(1);
    });

    it('detects the argument count of an instance method pair', function (): void {
        $class = declareHookClass('public function handle($a, $b, $c) {}');
        $hook = trackingHook();
        $hook->add('init', [$class, 'handle']);

        expect($hook->callbacks('init')[0]['args'])->toBe(3);
    });
});

describe('Argument detection cache', function (): void {
    it('tells the same method name apart across classes', function (): void {
        $one = new (declareHookClass('public function run($a) {}'));
        $two = new (declareHookClass('public function run($a, $b) {}'));
        $hook = trackingHook();

        expect($hook->detect([$one, 'run']))->toBe(1)
            ->and($hook->detect([$two, 'run']))->toBe(2);
    });

    it('tells the methods of one object apart', function (): void {
        $object = new (declareHookClass('public function one($a) {} public function two($a, $b) {}'));
        $hook = trackingHook();

        expect($hook->detect([$object, 'one']))->toBe(1)
            ->and($hook->detect([$object, 'two']))->toBe(2);
    });

    it('does not mistake an object for another reusing its identifier', function (): void {
        $hook = trackingHook();
        $first = new (declareHookClass('public function run($a) {}'));
        expect($hook->detect([$first, 'run']))->toBe(1);
        unset($first);

        $second = new (declareHookClass('public function run($a, $b, $c) {}'));

        expect($hook->detect([$second, 'run']))->toBe(3);
    });

    it('tells the static methods of one class apart', function (): void {
        $class = declareHookClass('public static function one($a) {} public static function two($a, $b) {}');
        $hook = trackingHook();

        expect($hook->detect([$class, 'one']))->toBe(1)
            ->and($hook->detect([$class, 'two']))->toBe(2);
    });

    it('tells the same static method name apart across classes', function (): void {
        $one = declareHookClass('public static function run($a) {}');
        $two = declareHookClass('public static function run($a, $b) {}');
        $hook = trackingHook();

        expect($hook->detect([$one, 'run']))->toBe(1)
            ->and($hook->detect([$two, 'run']))->toBe(2);
    });

    it('does not mistake one class and method for another with the same letters', function (): void {
        $base = 'KeyJoin_'.str_replace('.', '', uniqid('', true));
        eval(sprintf('class %s { public static function yz($a) {} }', $base));
        eval(sprintf('class %sy { public static function z($a, $b) {} }', $base));
        $hook = trackingHook();

        expect($hook->detect([$base, 'yz']))->toBe(1)
            ->and($hook->detect([$base.'y', 'z']))->toBe(2);
    });

    it('tells two closures apart', function (): void {
        $hook = trackingHook();
        $one = fn ($a): int => 1;
        $two = fn ($a, $b): int => 2;

        expect($hook->detect($one))->toBe(1)
            ->and($hook->detect($two))->toBe(2);
    });

    it('tells a named function from a closure', function (): void {
        $hook = trackingHook();

        expect($hook->detect('str_replace'))->toBe(4)
            ->and($hook->detect(fn (): null => null))->toBe(0);
    });
});

describe('Matching a class pair to a registration', function (): void {
    it('matches an instance of a subclass from the name of its parent', function (): void {
        $parent = declareHookClass('public function handle() {}');
        $child = declareHookClass('', $parent);
        $hook = trackingHook();
        $hook->add('init', [$child, 'handle']);

        expect($hook->exists('init', [$parent, 'handle']))->toBeTrue();

        $hook->remove('init', [$parent, 'handle']);

        expect($hook->callbacks('init'))->toBeNull()
            ->and($hook->removed[0]['callback'][0])->toBeInstanceOf($child);
    });

    it('does not match an unrelated class with the same method', function (): void {
        $registered = declareHookClass('public function handle() {}');
        $other = declareHookClass('public function handle() {}');
        $hook = trackingHook();
        $hook->add('init', [$registered, 'handle']);

        expect($hook->exists('init', [$other, 'handle']))->toBeFalse();

        $hook->remove('init', [$other, 'handle']);

        expect($hook->callbacks('init'))->toHaveCount(1)
            ->and($hook->removed[0]['callback'])->toBe([$other, 'handle']);
    });

    it('does not match another method of the same class', function (): void {
        $class = declareHookClass('public function handle() {} public function other() {}');
        $hook = trackingHook();
        $hook->add('init', [$class, 'handle']);

        expect($hook->exists('init', [$class, 'other']))->toBeFalse();
    });

    it('compares static method pairs by class name', function (): void {
        $registered = declareHookClass('public static function report() {}');
        $other = declareHookClass('public static function report() {}');
        $hook = trackingHook();
        $hook->add('init', [$registered, 'report']);

        $hook->remove('init', [$other, 'report']);
        expect($hook->callbacks('init'))->toHaveCount(1);

        $hook->remove('init', [$registered, 'report']);
        expect($hook->callbacks('init'))->toBeNull();
    });

    it('compares a closure with a class pair as different', function (): void {
        $class = declareHookClass('public static function report() {}');
        $closure = fn (): null => null;
        $hook = trackingHook();
        $hook->add('init', $closure);

        $hook->remove('init', [$class, 'report']);

        expect($hook->callbacks('init'))->toHaveCount(1);
    });

    it('compares a class pair with a function name as different', function (): void {
        $class = declareHookClass('public static function report() {}');
        $hook = trackingHook();
        $hook->add('init', [$class, 'report']);

        $hook->remove('init', 'report');

        expect($hook->callbacks('init'))->toHaveCount(1);
    });

    it('compares arrays of different sizes as different', function (): void {
        $class = declareHookClass('public static function report() {}');
        $hook = trackingHook();
        $hook->add('init', [$class, 'report']);

        $hook->remove('init', [$class, 'report', 'extra']);

        expect($hook->callbacks('init'))->toHaveCount(1);
    });

    it('keeps an object pair as it is', function (): void {
        $object = new (declareHookClass('public function handle($a) {}'));
        $hook = trackingHook();
        $hook->add('init', [$object, 'handle']);

        expect($hook->callbacks('init')[0]['callback'])->toBe([$object, 'handle'])
            ->and($hook->exists('init', [$object, 'handle']))->toBeTrue();
    });

    it('leaves a pair naming a missing method for later', function (): void {
        $class = declareHookClass('public function handle() {}');
        $hook = trackingHook();
        $hook->add('init', [$class, 'missing']);

        expect($hook->callbacks('init')[0]['callback'])->toBe([$class, 'missing'])
            ->and($hook->callbacks('init')[0]['args'])->toBe(1);
    });

    it('leaves a pair with a method that is not a string for later', function (): void {
        $class = declareHookClass('public function handle() {}');
        $hook = trackingHook();
        $hook->add('init', [$class, 5]);

        expect($hook->callbacks('init')[0]['callback'])->toBe([$class, 5]);
    });

    it('leaves a pair naming a missing class for later', function (): void {
        $hook = trackingHook();
        $hook->add('init', ['Missing\\Class\\Here', 'handle']);

        expect($hook->callbacks('init')[0]['callback'])->toBe(['Missing\\Class\\Here', 'handle']);
    });

    it('prefers the registration of the requested priority', function (): void {
        $class = declareHookClass('public function handle() {}');
        $hook = trackingHook();
        $hook->add('init', [$class, 'handle'], 5);
        $hook->add('init', [$class, 'handle'], 10);

        $hook->remove('init', [$class, 'handle'], 10);

        expect($hook->removed[0]['priority'])->toBe(10)
            ->and($hook->removed[0]['callback'][0])->toBeInstanceOf($class)
            ->and($hook->removed[0]['callback'][0])->not->toBe($hook->callbacks('init')[0]['callback'][0])
            ->and($hook->callbacks('init'))->toHaveCount(1)
            ->and($hook->callbacks('init')[0]['priority'])->toBe(5);
    });

    it('finds the instance of a pair even when a closure comes first', function (): void {
        $class = declareHookClass('public function handle() {}');
        $hook = trackingHook();
        $closure = fn (): null => null;
        $hook->add('init', $closure);
        $hook->add('init', [$class, 'handle']);

        $hook->remove('init', [$class, 'handle']);

        expect($hook->removed[0]['callback'][0])->toBeInstanceOf($class)
            ->and($hook->callbacks('init'))->toHaveCount(1)
            ->and($hook->callbacks('init')[0]['callback'])->toBe($closure);
    });
});

describe('Replacing a tracked callback', function (): void {
    it('replaces only the registration matching callback and priority', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'a_fn', 10);
        $hook->add('init', 'b_fn', 10);
        $hook->add('init', 'a_fn', 20);

        $hook->replace('init', 20, 'a_fn', 'replacement_fn', 3, null);

        $callbacks = $hook->callbacks('init');
        expect($callbacks[0]['callback'])->toBe('a_fn')
            ->and($callbacks[1]['callback'])->toBe('b_fn')
            ->and($callbacks[2]['callback'])->toBe('replacement_fn')
            ->and($callbacks[2]['args'])->toBe(3);
    });

    it('does not replace a callback registered at another priority', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'a_fn', 10);

        $hook->replace('init', 20, 'a_fn', 'replacement_fn', 3, null);

        expect($hook->callbacks('init')[0]['callback'])->toBe('a_fn');
    });

    it('does not replace another callback at the same priority', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'a_fn', 10);

        $hook->replace('init', 10, 'b_fn', 'replacement_fn', 3, null);

        expect($hook->callbacks('init')[0]['callback'])->toBe('a_fn');
    });

    it('finds the registration after one that does not match', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'first_fn', 10);
        $hook->add('init', 'target_fn', 10);

        $hook->replace('init', 10, 'target_fn', 'replacement_fn', 2, 'handler_fn');

        $callbacks = $hook->callbacks('init');
        expect($callbacks[1]['callback'])->toBe('replacement_fn')
            ->and($callbacks[1]['handler'])->toBe('handler_fn')
            ->and($callbacks[0])->not->toHaveKey('handler');
    });

    it('forgets the handler when replaced with none', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'target_fn', 10);
        $hook->replace('init', 10, 'target_fn', 'queued_fn', 1, 'handler_fn');

        $hook->replace('init', 10, 'queued_fn', 'target_fn', 1, null);

        expect($hook->callbacks('init')[0])->not->toHaveKey('handler');
    });

    it('finds a registration by the handler it stands for', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'target_fn', 10);
        $hook->replace('init', 10, 'target_fn', 'queued_fn', 1, 'handler_fn');

        $hook->remove('init', 'handler_fn', 10);

        expect($hook->removed[0]['callback'])->toBe('queued_fn')
            ->and($hook->exists('init', 'handler_fn'))->toBeFalse();
    });

    it('does not take another callback for the handler', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'target_fn', 10);
        $hook->replace('init', 10, 'target_fn', 'queued_fn', 1, 'handler_fn');

        $hook->remove('init', 'unrelated_fn', 10);

        expect($hook->removed[0]['callback'])->toBe('unrelated_fn')
            ->and($hook->callbacks('init'))->toHaveCount(1)
            ->and($hook->exists('init', 'handler_fn'))->toBeTrue()
            ->and($hook->exists('init', 'unrelated_fn'))->toBeFalse();
    });

    it('skips registrations at another priority when looking a handler up', function (): void {
        $hook = trackingHook();
        $hook->add('init', 'target_fn', 5);
        $hook->add('init', 'target_fn', 10);
        $hook->replace('init', 10, 'target_fn', 'queued_fn', 1, 'handler_fn');

        expect($hook->exists('init', 'handler_fn', 10))->toBeTrue()
            ->and($hook->exists('init', 'handler_fn', 5))->toBeFalse();
    });
});
