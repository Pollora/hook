<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncDispatcher;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\QueuedHandler;
use Pollora\Hook\Domain\Contract\CallbackResolverInterface;
use Pollora\Hook\Tests\Fixtures\Async\CapturingHandler;
use Pollora\Hook\Tests\Fixtures\Async\RecordingDriver;

$GLOBALS['edge_runs'] = [];

if (! function_exists('pollora_edge_runner_fail')) {
    function pollora_edge_runner_fail(int $value): void
    {
        throw new RuntimeException('Boom');
    }
}

if (! function_exists('pollora_edge_runner_ok')) {
    function pollora_edge_runner_ok(int $value): void
    {
        $GLOBALS['edge_runs'][] = ['value' => $value, 'user' => $GLOBALS['wp_state']['user'], 'blog' => $GLOBALS['wp_state']['blog'], 'locale' => $GLOBALS['wp_state']['locale']];
    }
}

if (! function_exists('pollora_edge_runner_nested')) {
    /**
     * Runs the same registration again from inside itself, then tells whether it still counts as running.
     */
    function pollora_edge_runner_nested(int $depth): void
    {
        $payload = $GLOBALS['edge_nested_payload'];

        if ($depth === 1) {
            Async::runner()->run(edgeRunnerPayload('pollora_edge_runner_nested', [2]));
            $GLOBALS['edge_still_running'] = Async::runner()->isRunning(QueuedHandler::keyFor($payload->hook, $payload->handler, $payload->priority));
        }
    }
}

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_actions_done'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['wp_switches'] = [];
    $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 1, 'locale' => 'fr_FR', 'multisite' => false];
    $GLOBALS['edge_runs'] = [];
    $GLOBALS['edge_still_running'] = null;
    $GLOBALS['reports'] = [];

    Async::flush();
    Async::setDebug(true);
    Async::reportUsing(function (string $message, array $context): void {
        $GLOBALS['reports'][] = ['message' => $message, 'context' => $context];
    });
    $this->driver = new RecordingDriver;
    Async::extend('recording', fn (): AsyncDriver => $this->driver);
    Async::setDefaultDriver('recording');
});

afterEach(function (): void {
    Async::flush();
});

function edgeRunnerPayload(string $handler, array $arguments = [1], array $overrides = []): AsyncPayload
{
    return new AsyncPayload(...array_merge([
        'id' => AsyncPayload::newId(),
        'hook' => 'save_post',
        'handler' => $handler,
        'priority' => 10,
        'arguments' => $arguments,
        'origin' => ['userId' => 5, 'blogId' => 1, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T14:30:00+00:00'],
        'driver' => 'recording',
        'tries' => 3,
        'backoff' => [10, 60],
    ], $overrides));
}

describe('Running a payload', function (): void {
    it('counts a registration running again inside itself', function (): void {
        $GLOBALS['edge_nested_payload'] = edgeRunnerPayload('pollora_edge_runner_nested', [1]);

        Async::runner()->run($GLOBALS['edge_nested_payload']);

        expect($GLOBALS['edge_still_running'])->toBeTrue()
            ->and(Async::runner()->isRunning(QueuedHandler::keyFor('save_post', 'pollora_edge_runner_nested', 10)))->toBeFalse();
    });

    it('does not switch site when the installation is not a multisite', function (): void {
        $GLOBALS['wp_state']['blog'] = 1;

        Async::runner()->run(edgeRunnerPayload('pollora_edge_runner_ok', [1], ['origin' => ['userId' => 5, 'blogId' => 2, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T14:30:00+00:00']]));

        expect($GLOBALS['wp_switches'])->toBe([])
            ->and($GLOBALS['edge_runs'][0]['blog'])->toBe(1);
    });

    it('switches site on a multisite, and back', function (): void {
        $GLOBALS['wp_state']['multisite'] = true;

        Async::runner()->run(edgeRunnerPayload('pollora_edge_runner_ok', [1], ['origin' => ['userId' => 5, 'blogId' => 2, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T14:30:00+00:00']]));

        expect($GLOBALS['wp_switches'])->toBe([['blog', 2], ['restore_blog', 1]])
            ->and($GLOBALS['edge_runs'][0]['blog'])->toBe(2);
    });

    it('stays on the site of a multisite when it is already the right one', function (): void {
        $GLOBALS['wp_state']['multisite'] = true;

        Async::runner()->run(edgeRunnerPayload('pollora_edge_runner_ok'));

        expect($GLOBALS['wp_switches'])->toBe([]);
    });

    it('does not switch locale when it is the right one', function (): void {
        $GLOBALS['wp_state']['locale'] = 'fr_FR';

        Async::runner()->run(edgeRunnerPayload('pollora_edge_runner_ok'));

        expect($GLOBALS['wp_switches'])->toBe([]);
    });

    it('switches locale and back', function (): void {
        $GLOBALS['wp_state']['locale'] = 'en_US';

        Async::runner()->run(edgeRunnerPayload('pollora_edge_runner_ok'));

        expect($GLOBALS['wp_switches'])->toBe([['locale', 'fr_FR'], ['restore_locale', 'en_US']])
            ->and($GLOBALS['edge_runs'][0]['locale'])->toBe('fr_FR');
    });

    it('runs as the user with id 1, the first administrator', function (): void {
        $GLOBALS['wp_state']['user'] = 0;

        Async::runner()->run(edgeRunnerPayload('pollora_edge_runner_ok', [1], [
            'asUser' => true,
            'origin' => ['userId' => 1, 'blogId' => 1, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T14:30:00+00:00'],
        ]));

        expect($GLOBALS['edge_runs'][0]['user'])->toBe(1)
            ->and($GLOBALS['wp_state']['user'])->toBe(0);
    });

    it('does not run as a user without being asked to', function (): void {
        Async::runner()->run(edgeRunnerPayload('pollora_edge_runner_ok'));

        expect($GLOBALS['wp_switches'])->toBe([]);
    });

    it('does not run as an anonymous visitor', function (): void {
        Async::runner()->run(edgeRunnerPayload('pollora_edge_runner_ok', [1], [
            'asUser' => true,
            'origin' => ['userId' => 0, 'blogId' => 1, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T14:30:00+00:00'],
        ]));

        expect($GLOBALS['wp_switches'])->toBe([]);
    });
});

describe('Reporting failures', function (): void {
    it('reports a retry with the registration it concerns', function (): void {
        $payload = edgeRunnerPayload('pollora_edge_runner_fail');

        Async::runner()->run($payload);

        expect($GLOBALS['reports'])->toHaveCount(1)
            ->and($GLOBALS['reports'][0]['message'])->toBe('Attempt 1 of 3 failed, retried in 10 s: Boom')
            ->and(array_keys($GLOBALS['reports'][0]['context']))->toBe(['hook', 'handler', 'payload', 'exception'])
            ->and($GLOBALS['reports'][0]['context']['hook'])->toBe('save_post')
            ->and($GLOBALS['reports'][0]['context']['handler'])->toBe('pollora_edge_runner_fail')
            ->and($GLOBALS['reports'][0]['context']['payload'])->toBe($payload->id)
            ->and($GLOBALS['reports'][0]['context']['exception'])->toBeInstanceOf(RuntimeException::class);
    });

    it('reports the failure of a retry that cannot be queued, then the failure itself', function (): void {
        $broken = new class implements AsyncDriver
        {
            public function available(): bool
            {
                return true;
            }

            public function dispatch(AsyncPayload $payload, int $delay = 0): void
            {
                throw new RuntimeException('Queue down');
            }
        };
        Async::extend('broken', fn (): AsyncDriver => $broken);
        $payload = edgeRunnerPayload('pollora_edge_runner_fail', [1], ['driver' => 'broken']);

        Async::runner()->run($payload);

        expect($GLOBALS['reports'])->toHaveCount(2)
            ->and($GLOBALS['reports'][0]['message'])->toBe('Queue down')
            ->and($GLOBALS['reports'][0]['context'])->toHaveKeys(['hook', 'handler', 'payload', 'exception'])
            ->and(array_keys($GLOBALS['reports'][0]['context']))->toBe(['hook', 'handler', 'payload', 'exception'])
            ->and($GLOBALS['reports'][0]['context']['hook'])->toBe('save_post')
            ->and($GLOBALS['reports'][0]['context']['handler'])->toBe('pollora_edge_runner_fail')
            ->and($GLOBALS['reports'][0]['context']['payload'])->toBe($payload->id)
            ->and($GLOBALS['reports'][1]['message'])->toBe('Boom');
    });

    it('reports the final failure with the registration it concerns', function (): void {
        $payload = edgeRunnerPayload('pollora_edge_runner_fail', [1], ['tries' => 1]);

        Async::runner()->run($payload);

        expect($GLOBALS['reports'])->toHaveCount(1)
            ->and($GLOBALS['reports'][0]['message'])->toBe('Boom')
            ->and(array_keys($GLOBALS['reports'][0]['context']))->toBe(['hook', 'handler', 'payload', 'exception'])
            ->and($GLOBALS['reports'][0]['context']['hook'])->toBe('save_post')
            ->and($GLOBALS['reports'][0]['context']['handler'])->toBe('pollora_edge_runner_fail')
            ->and($GLOBALS['reports'][0]['context']['payload'])->toBe($payload->id);
    });
});

describe('Dispatching', function (): void {
    it('reports a dispatch that failed, with the hook and the handler, then runs the handler at once', function (): void {
        Async::setDebug(false);
        $broken = new class implements AsyncDriver
        {
            public function available(): bool
            {
                return true;
            }

            public function dispatch(AsyncPayload $payload, int $delay = 0): void
            {
                throw new RuntimeException('Queue down');
            }
        };
        Async::extend('broken', fn (): AsyncDriver => $broken);
        (new Action)->add('save_post', 'pollora_edge_runner_ok')->async()->via('broken');

        wp_stub_fire('save_post', 7);

        expect($GLOBALS['reports'])->toHaveCount(1)
            ->and($GLOBALS['reports'][0]['message'])->toBe('Queue down')
            ->and(array_keys($GLOBALS['reports'][0]['context']))->toBe(['hook', 'handler', 'exception'])
            ->and($GLOBALS['reports'][0]['context']['hook'])->toBe('save_post')
            ->and($GLOBALS['reports'][0]['context']['handler'])->toBe('pollora_edge_runner_ok')
            ->and($GLOBALS['edge_runs'][0]['value'])->toBe(7);
    });

    it('reports a requested driver that is unavailable, then uses the default one', function (): void {
        Async::setDebug(false);
        (new Action)->add('save_post', 'pollora_edge_runner_ok')->async()->via('missing-driver');

        wp_stub_fire('save_post', 7);

        expect($GLOBALS['reports'])->toHaveCount(1)
            ->and(array_keys($GLOBALS['reports'][0]['context']))->toBe(['hook', 'handler', 'exception'])
            ->and($GLOBALS['reports'][0]['context']['hook'])->toBe('save_post')
            ->and($GLOBALS['reports'][0]['context']['handler'])->toBe('pollora_edge_runner_ok')
            ->and($this->driver->queued)->toHaveCount(1)
            ->and($this->driver->queued[0]['payload']->driver)->toBe('recording');
    });

    it('describes the request that fires the hook', function (): void {
        $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 3, 'locale' => 'de_DE', 'multisite' => true];
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $origin = AsyncDispatcher::origin();
        $dispatchedAt = new DateTimeImmutable($origin['dispatchedAt']);

        expect($origin['userId'])->toBe(5)
            ->and($origin['blogId'])->toBe(3)
            ->and($origin['locale'])->toBe('de_DE')
            ->and($dispatchedAt->getOffset())->toBe(0)
            ->and($dispatchedAt->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp())
            ->and($dispatchedAt->getTimestamp())->toBeLessThanOrEqual(time());
    });
});

describe('Default capture of a handler class', function (): void {
    it('leaves the capture() method itself without a capture', function (): void {
        (new Action)->add('save_post', [CapturingHandler::class, 'capture'])->async();

        expect($GLOBALS['wp_actions'][0]['callback']->defaultCapture)->toBeNull();
    });

    it('uses the public capture() method of the handler class', function (): void {
        (new Action)->add('save_post', [CapturingHandler::class, 'handle'])->async();

        $capture = $GLOBALS['wp_actions'][0]['callback']->defaultCapture;
        expect($capture[0])->toBeInstanceOf(CapturingHandler::class)
            ->and($capture[1])->toBe('capture');
    });

    it('leaves a handler without a capture() method alone', function (): void {
        $class = 'EdgeNoCapture_'.str_replace('.', '', uniqid('', true));
        eval(sprintf('class %s { public function handle(int $id) {} }', $class));
        (new Action)->add('save_post', [$class, 'handle'])->async();

        expect($GLOBALS['wp_actions'][0]['callback']->defaultCapture)->toBeNull();
    });

    it('leaves a handler whose capture() method is not public alone', function (): void {
        $class = 'EdgePrivateCapture_'.str_replace('.', '', uniqid('', true));
        eval(sprintf('class %s { public function handle(int $id) {} protected function capture() { return []; } }', $class));
        (new Action)->add('save_post', [$class, 'handle'])->async();

        expect($GLOBALS['wp_actions'][0]['callback']->defaultCapture)->toBeNull();
    });

    it('has no capture for a function', function (): void {
        (new Action)->add('save_post', 'pollora_edge_runner_ok')->async();

        expect($GLOBALS['wp_actions'][0]['callback']->defaultCapture)->toBeNull();
    });
});

describe('The WordPress action adapter', function (): void {
    it('listens to the internal hook as soon as it exists', function (): void {
        new Action;

        expect(array_column($GLOBALS['wp_async_listeners'], 'hook'))->toContain(Async::HOOK);
    });

    it('listens to the internal hook again when it makes a handler asynchronous', function (): void {
        $action = new Action;
        Async::flush();
        $GLOBALS['wp_async_listeners'] = [];

        $action->add('save_post', 'pollora_edge_runner_ok')->async();

        expect(array_column($GLOBALS['wp_async_listeners'], 'hook'))->toContain(Async::HOOK);
    });

    it('builds class callbacks through the resolver it is given', function (): void {
        $class = 'EdgeResolved_'.str_replace('.', '', uniqid('', true));
        eval(sprintf('class %s { public function init() {} }', $class));
        $instance = new $class;
        $resolver = new class($instance) implements CallbackResolverInterface
        {
            public function __construct(private readonly object $instance) {}

            public function resolve(string $class): object
            {
                return $this->instance;
            }
        };

        $action = new Action;
        $action->setCallbackResolver($resolver);
        $action->add('init', $class);

        expect($action->callbacks('init')[0]['callback'][0])->toBe($instance);
    });
});
