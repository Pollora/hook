<?php

declare(strict_types=1);

use Pollora\Hook\Async\ArgumentNormalizer;
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncContext;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Contracts\ObjectReference;
use Pollora\Hook\Async\Exceptions\UnresolvableHandler;
use Pollora\Hook\Async\Recovery;
use Pollora\Hook\Tests\Fixtures\Async\Mailer;
use Pollora\Hook\Tests\Fixtures\Async\OrderStatus;

beforeEach(function (): void {
    $GLOBALS['wp_filters'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['wp_actions'] = [];
    Async::flush();
});

afterEach(function (): void {
    Async::flush();
});

/**
 * The reflected parameter of a closure taking one typed argument.
 */
function injectionCandidate(Closure $closure): ReflectionParameter
{
    return (new ReflectionFunction($closure))->getParameters()[0];
}

function availableAsyncDriver(): AsyncDriver
{
    return new class implements AsyncDriver
    {
        public function available(): bool
        {
            return true;
        }

        public function dispatch(AsyncPayload $payload, int $delay = 0): void {}
    };
}

describe('Parameter injection', function (): void {
    it('never injects without an injector', function (): void {
        expect(Async::isInjectable(injectionCandidate(fn (Mailer $mailer): null => null)))->toBeFalse()
            ->and(Async::inject(injectionCandidate(fn (Mailer $mailer): null => null)))->toBeNull();
    });

    it('injects what the injector is given, for a class that does not travel', function (): void {
        $mailer = new Mailer;
        Async::injectParametersUsing(fn (ReflectionParameter $parameter): Mailer => $mailer);

        expect(Async::isInjectable(injectionCandidate(fn (Mailer $mailer): null => null)))->toBeTrue()
            ->and(Async::inject(injectionCandidate(fn (Mailer $mailer): null => null)))->toBe($mailer);
    });

    it('leaves a parameter that travels with the hook arguments', function (Closure $closure): void {
        Async::injectParametersUsing(fn (): object => new stdClass);

        expect(Async::isInjectable(injectionCandidate($closure)))->toBeFalse();
    })->with([
        'AsyncContext' => [fn (AsyncContext $value): null => null],
        'enum' => [fn (UnitEnum $value): null => null],
        'backed enum' => [fn (OrderStatus $value): null => null],
        'date' => [fn (DateTimeInterface $value): null => null],
        'immutable date' => [fn (DateTimeImmutable $value): null => null],
        'JsonSerializable' => [fn (JsonSerializable $value): null => null],
        'post' => [fn (WP_Post $value): null => null],
        'term' => [fn (WP_Term $value): null => null],
        'user' => [fn (WP_User $value): null => null],
        'comment' => [fn (WP_Comment $value): null => null],
        'scalar' => [fn (int $value): null => null],
        'untyped' => [fn ($value): null => null],
        'unknown class' => [eval('return fn (Missing\\Nothing\\Here $value): null => null;')],
    ]);

    it('lets the second callback decide which parameters are injected', function (): void {
        Async::injectParametersUsing(fn (): object => new stdClass, fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'wanted');

        expect(Async::isInjectable(injectionCandidate(fn (int $wanted): null => null)))->toBeTrue()
            ->and(Async::isInjectable(injectionCandidate(fn (Mailer $other): null => null)))->toBeFalse();
    });

    it('only takes a strict true from that callback', function (): void {
        Async::injectParametersUsing(fn (): object => new stdClass, eval('return fn () => "yes";'));

        expect(Async::isInjectable(injectionCandidate(fn (Mailer $mailer): null => null)))->toBeFalse();
    });

    it('stops injecting when the injector is removed', function (): void {
        Async::injectParametersUsing(fn (): object => new stdClass);
        Async::injectParametersUsing(null);

        expect(Async::isInjectable(injectionCandidate(fn (Mailer $mailer): null => null)))->toBeFalse();
    });
});

describe('Default driver from the filter', function (): void {
    it('ignores a filter returning an empty string', function (): void {
        add_filter(Async::DRIVER_FILTER, fn (): string => '');

        expect(Async::defaultDriver())->toBe('wp-cron');
    });

    it('ignores a filter returning something else than a string', function (): void {
        add_filter(Async::DRIVER_FILTER, fn (): int => 5);

        expect(Async::defaultDriver())->toBe('wp-cron');
    });

    it('builds an auto driver once while resolving it', function (): void {
        $builds = 0;
        Async::extend('action-scheduler', function () use (&$builds): AsyncDriver {
            $builds++;

            return availableAsyncDriver();
        });

        expect(Async::defaultDriver())->toBe('action-scheduler')
            ->and(Async::defaultDriver())->toBe('action-scheduler')
            ->and($builds)->toBe(1);
    });

    it('goes through the whole list of auto drivers set for it', function (): void {
        Async::setAutoDrivers([3 => 'unknown', 8 => 'sync']);

        expect(Async::defaultDriver())->toBe('sync');
    });
});

describe('Closure key', function (): void {
    it('derives the key again when it is set to null', function (): void {
        Async::useClosureKey('app-key');
        Async::useClosureKey(null);

        expect(fn () => Async::closureKey())->toThrow(UnresolvableHandler::class, 'without a signing key');
    });

    it('derives the key again when it is set to an empty string', function (): void {
        Async::useClosureKey('app-key');
        Async::useClosureKey('');

        expect(fn () => Async::closureKey())->toThrow(UnresolvableHandler::class, 'without a signing key');
    });

    it('uses the key it is given', function (): void {
        Async::useClosureKey('app-key');

        expect(Async::closureKey())->toBe('app-key');
    });
});

describe('Listening', function (): void {
    it('listens to the internal hook and the recovery, once', function (): void {
        Async::listen();
        Async::listen();

        expect($GLOBALS['wp_async_listeners'])->toBe([
            ['hook' => Async::HOOK, 'callback' => [Async::class, 'receive'], 'priority' => 10, 'args' => 1],
            ['hook' => Recovery::HOOK, 'callback' => [Recovery::class, 'run'], 'priority' => 10, 'args' => 0],
        ]);
    });

    it('keeps the events on WP-Cron whatever driver pollora/schedule has, registering the filter once', function (): void {
        Async::listen();
        Async::listen();

        expect($GLOBALS['wp_filters'])->toBe([
            ['hook' => 'pollora/schedule/protected_hooks', 'callback' => [Async::class, 'protectHooks'], 'priority' => 10, 'args' => 1],
        ])
            ->and(Async::protectHooks(['custom/hook']))->toBe(['custom/hook', Async::HOOK, Recovery::HOOK]);
    });

    it('registers the filter again after a flush', function (): void {
        Async::listen();
        Async::flush();
        $GLOBALS['wp_filters'] = [];

        Async::listen();

        expect($GLOBALS['wp_filters'])->toHaveCount(1);
    });
});

describe('Reporting', function (): void {
    it('writes an incident without context as the message alone', function (): void {
        $log = tempnam(sys_get_temp_dir(), 'pollora-hook');
        $previous = ini_set('error_log', $log);

        try {
            Async::report('Queue unavailable');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        expect(rtrim((string) file_get_contents($log)))->toEndWith('] [pollora/hook] Queue unavailable');
        unlink($log);
    });

    it('does not write what is not scalar in the context', function (): void {
        $log = tempnam(sys_get_temp_dir(), 'pollora-hook');
        $previous = ini_set('error_log', $log);

        try {
            Async::report('Queue unavailable', ['attempt' => 2, 'object' => new stdClass, 'list' => [1]]);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        expect(rtrim((string) file_get_contents($log)))->toEndWith('[pollora/hook] Queue unavailable {"attempt":2}');
        unlink($log);
    });
});

describe('Shared services', function (): void {
    it('builds the dispatcher and the runner once', function (): void {
        expect(Async::dispatcher())->toBe(Async::dispatcher())
            ->and(Async::runner())->toBe(Async::runner());
    });

    it('keeps the object references it is given', function (): void {
        $reference = new class implements ObjectReference
        {
            public function name(): string
            {
                return 'arrayobject';
            }

            public function supports(object $object): bool
            {
                return $object instanceof ArrayObject;
            }

            public function reference(object $object): array
            {
                return ['key' => 1];
            }

            public function resolve(array $reference): object
            {
                return new ArrayObject;
            }
        };
        Async::reference($reference);
        Async::reference(new class implements ObjectReference
        {
            public function name(): string
            {
                return 'never';
            }

            public function supports(object $object): bool
            {
                return false;
            }

            public function reference(object $object): array
            {
                return [];
            }

            public function resolve(array $reference): ?object
            {
                return null;
            }
        });

        $normalized = (new ReflectionMethod(Async::class, 'normalizer'))->invoke(null)->normalize([new ArrayObject]);

        expect($normalized)->toBe([['@type' => 'ref', 'kind' => 'arrayobject', 'ref' => ['key' => 1]]])
            ->and((new ReflectionMethod(Async::class, 'normalizer'))->invoke(null))->toBeInstanceOf(ArgumentNormalizer::class);
    });
});
