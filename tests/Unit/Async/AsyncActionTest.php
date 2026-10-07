<?php

declare(strict_types=1);

use Pollora\Hook\Action as ActionFacade;
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncContext;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Drivers\WpCronDriver;
use Pollora\Hook\Async\Exceptions\DriverUnavailable;
use Pollora\Hook\Async\Exceptions\UnresolvableHandler;
use Pollora\Hook\Async\Exceptions\UnsupportedArgument;
use Pollora\Hook\Async\PendingAsync;
use Pollora\Hook\Async\QueuedHandler;
use Pollora\Hook\Domain\Contract\CallbackResolverInterface;
use Pollora\Hook\Tests\Fixtures\Async\RecordingHandler;

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_actions_removed'] = [];
    $GLOBALS['wp_actions_done'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['wp_objects'] = [];
    $GLOBALS['wp_switches'] = [];
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_cron_events'] = [];
    $GLOBALS['wp_fail'] = [];
    $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 1, 'locale' => 'fr_FR', 'multisite' => false];
    $GLOBALS['incidents'] = [];
    RecordingHandler::$calls = [];

    Async::flush();
    Async::setDebug(true);
    Async::reportUsing(function (string $message, array $context): void {
        $GLOBALS['incidents'][] = $message;
    });

    $this->driver = new class implements AsyncDriver
    {
        /** @var list<array{payload: AsyncPayload, delay: int}> */
        public array $queued = [];

        public function available(): bool
        {
            return true;
        }

        public function dispatch(AsyncPayload $payload, int $delay = 0): void
        {
            $this->queued[] = ['payload' => $payload, 'delay' => $delay];
        }

        public function runAll(): void
        {
            foreach ($this->queued as $entry) {
                Async::receive($entry['payload']->toJson());
            }
        }
    };
    Async::extend('recording', fn (): AsyncDriver => $this->driver);

    $this->action = new Action;
});

afterEach(function (): void {
    Async::flush();
});

describe('Registration', function (): void {
    it('replaces the callback by a queuing callback, at the same priority', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'], 20)->async();

        expect($GLOBALS['wp_actions'])->toHaveCount(1)
            ->and($GLOBALS['wp_actions'][0]['callback'])->toBeInstanceOf(QueuedHandler::class)
            ->and($GLOBALS['wp_actions'][0]['priority'])->toBe(20);
    });

    it('takes from WordPress only the hook arguments, not the AsyncContext parameter', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

        expect($GLOBALS['wp_actions'][0]['args'])->toBe(1);
    });

    it('returns the options of the registration', function (): void {
        $pending = $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

        expect($pending)->toBeInstanceOf(PendingAsync::class)
            ->and($pending->hooks())->toBe(['save_post']);
    });

    it('applies to every hook of the last add() call, each with its own method', function (): void {
        $this->action->add(['save_post_event', 'save_post_page'], RecordingHandler::class)->async()->via('recording');

        wp_stub_fire('save_post_event', 1);
        wp_stub_fire('save_post_page', 2);

        expect(array_column(array_column($this->driver->queued, 'payload'), 'handler'))
            ->toBe([RecordingHandler::class.'@savePostEvent', RecordingHandler::class.'@savePostPage']);
    });

    it('requires an add() call right before', function (): void {
        expect(fn () => $this->action->async())->toThrow(LogicException::class, 'async() must directly follow add()');

        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

        expect(fn () => $this->action->async())->toThrow(LogicException::class);
    });

    it('rejects a closure at registration and leaves the registration untouched', function (): void {
        $closure = function (int $postId): void {};

        expect(fn () => $this->action->add('save_post', $closure)->async())->toThrow(UnresolvableHandler::class, 'closure');

        expect($GLOBALS['wp_actions'])->toHaveCount(1)
            ->and($GLOBALS['wp_actions'][0]['callback'])->toBe($closure);
    });

    it('is chained from the static facade', function (): void {
        expect(ActionFacade::add('save_post', [RecordingHandler::class, 'handle']))->toBeInstanceOf(Action::class);
    });

    it('listens to the internal hooks once, whatever the number of services', function (): void {
        new Action;
        new Action;

        expect(array_map(fn (array $listener): array => [$listener['hook'], $listener['callback']], $GLOBALS['wp_async_listeners']))->toBe([
            [Async::HOOK, [Async::class, 'receive']],
            [WpCronDriver::RECOVERY_HOOK, [WpCronDriver::class, 'recover']],
        ]);
    });
});

describe('Removal and lookup', function (): void {
    it('finds and removes an asynchronous handler by its own callback', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

        expect($this->action->exists('save_post', [RecordingHandler::class, 'handle']))->toBeTrue();

        $this->action->remove('save_post', [RecordingHandler::class, 'handle']);

        expect($GLOBALS['wp_actions'])->toBe([])
            ->and($this->action->callbacks('save_post'))->toBeNull();
    });
});

describe('except', function (): void {
    it('keeps the listed hooks synchronous', function (string $form): void {
        $hooks = ['save_post_event', 'save_post_page'];
        $form === 'parameter'
            ? $this->action->add($hooks, RecordingHandler::class)->async(except: 'save_post_page')->via('recording')
            : $this->action->add($hooks, RecordingHandler::class)->async()->except(['save_post_page'])->via('recording');

        wp_stub_fire('save_post_event', 1);
        wp_stub_fire('save_post_page', 2);

        expect($this->driver->queued)->toHaveCount(1)
            ->and($this->driver->queued[0]['payload']->hook)->toBe('save_post_event')
            ->and(RecordingHandler::$calls)->toHaveCount(1)
            ->and(RecordingHandler::$calls[0]['method'])->toBe('savePostPage')
            ->and($this->action->callbacks('save_post_page')[0])->not->toHaveKey('handler');
    })->with(['parameter', 'method']);

    it('rejects a hook the registration does not have, in debug mode', function (): void {
        $pending = $this->action->add(['save_post_event', 'save_post_page'], RecordingHandler::class)->async();

        expect(fn () => $pending->except('save_post_pgae'))
            ->toThrow(InvalidArgumentException::class, "'save_post_pgae' is not a hook of this asynchronous registration (save_post_event, save_post_page)");
    });

    it('reports a hook the registration does not have, in production', function (): void {
        Async::setDebug(false);

        $this->action->add('save_post_event', RecordingHandler::class)->async(except: 'save_post_pgae');

        expect($GLOBALS['incidents'][0])->toContain("'save_post_pgae' is not a hook");
    });

    it('accepts a hook excluded twice', function (): void {
        $this->action->add(['save_post_event', 'save_post_page'], RecordingHandler::class)
            ->async(except: 'save_post_page')
            ->except('save_post_page');

        expect($GLOBALS['incidents'])->toBe([]);
    });
});

describe('Dispatch', function (): void {
    it('queues a payload carrying the handler, the arguments and the request', function (): void {
        $GLOBALS['wp_objects']['post'][12] = new WP_Post(12);
        $this->action->add('save_post', [RecordingHandler::class, 'post'])->async()->via('recording');

        wp_stub_fire('save_post', new WP_Post(12));

        $payload = $this->driver->queued[0]['payload'];
        expect(RecordingHandler::$calls)->toBe([])
            ->and($payload->hook)->toBe('save_post')
            ->and($payload->handler)->toBe(RecordingHandler::class.'@post')
            ->and($payload->priority)->toBe(10)
            ->and($payload->arguments)->toBe([['@type' => 'ref', 'kind' => 'wp', 'ref' => ['type' => 'post', 'id' => 12]]])
            ->and($payload->origin['userId'])->toBe(5)
            ->and($payload->origin['blogId'])->toBe(1)
            ->and($payload->origin['locale'])->toBe('fr_FR');
    });

    it('hands the driver the delay, in seconds', function (int|DateInterval $delay, int $seconds): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->via('recording')->delay($delay);

        wp_stub_fire('save_post', 1);

        expect($this->driver->queued[0]['delay'])->toBe($seconds);
    })->with([
        'seconds' => [60, 60],
        'interval' => [new DateInterval('PT1H30M'), 5400],
    ]);

    it('rejects an argument that cannot travel, in debug mode', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->via('recording');

        expect(fn () => wp_stub_fire('save_post', new stdClass))->toThrow(UnsupportedArgument::class);
    });

    it('runs the handler in place and reports, in production, when an argument cannot travel', function (): void {
        Async::setDebug(false);
        $this->action->add('save_post', [RecordingHandler::class, 'objects'])->async()->via('recording');
        $notTransportable = new stdClass;

        wp_stub_fire('save_post', $notTransportable);

        expect($this->driver->queued)->toBe([])
            ->and($GLOBALS['incidents'])->toHaveCount(1)
            ->and($GLOBALS['incidents'][0])->toContain('cannot be carried')
            ->and(RecordingHandler::$calls[0]['arguments'][0])->toBe($notTransportable);
    });

    it('rejects an unknown driver, in debug mode', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->via('rabbitmq');

        expect(fn () => wp_stub_fire('save_post', 1))->toThrow(DriverUnavailable::class, "'rabbitmq' is not registered");
    });

    it('falls back to the default driver and reports, in production, when the requested one is unknown', function (): void {
        Async::setDebug(false);
        Async::extend(Async::DEFAULT_DRIVER, fn (): AsyncDriver => $this->driver);
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->via('rabbitmq');

        wp_stub_fire('save_post', 1);

        expect($this->driver->queued)->toHaveCount(1)
            ->and($GLOBALS['incidents'][0])->toContain("'rabbitmq' is not registered");
    });

    it('runs the handler in place and reports, in production, when the driver cannot queue', function (): void {
        Async::setDebug(false);
        $GLOBALS['wp_fail'] = ['add_option'];
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async();

        wp_stub_fire('save_post', 7);

        expect(RecordingHandler::$calls)->toHaveCount(1)
            ->and(RecordingHandler::$calls[0]['arguments'][0])->toBe(7)
            ->and(RecordingHandler::$calls[0]['arguments'][1])->toBeInstanceOf(AsyncContext::class)
            ->and($GLOBALS['incidents'][0])->toContain("WP-Cron: the payload of 'save_post' could not be stored");
    });
});

describe('Execution', function (): void {
    it('runs the handler with the hook arguments and the context, through the sync driver', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->via('sync');

        wp_stub_fire('save_post', 42);

        [$postId, $context] = RecordingHandler::$calls[0]['arguments'];
        expect($postId)->toBe(42)
            ->and($context)->toBeInstanceOf(AsyncContext::class)
            ->and($context->userId)->toBe(5)
            ->and($context->hook)->toBe('save_post')
            ->and($context->attempt)->toBe(1);
    });

    it('places the context wherever the handler asks for it', function (): void {
        $this->action->add('transition', [RecordingHandler::class, 'contextFirst'])->async()->via('sync');

        wp_stub_fire('transition', 3, 'publish');

        $arguments = RecordingHandler::$calls[0]['arguments'];
        expect($arguments[0])->toBeInstanceOf(AsyncContext::class)
            ->and(array_slice($arguments, 1))->toBe([3, 'publish']);
    });

    it('builds the handler on a new instance, through the callback resolver', function (): void {
        $this->action->setCallbackResolver(new class implements CallbackResolverInterface
        {
            public function resolve(string $className): object
            {
                return new $className('container');
            }
        });
        $this->action->add('sync_source', [RecordingHandler::class, 'source'])->async()->via('sync');

        wp_stub_fire('sync_source');

        expect(RecordingHandler::$calls[0]['arguments'])->toBe(['container']);
    });

    it('reloads a referenced object in its state at execution time', function (): void {
        $GLOBALS['wp_objects']['post'][12] = new WP_Post(12, 'draft');
        $this->action->add('save_post', [RecordingHandler::class, 'post'])->async()->via('recording');
        wp_stub_fire('save_post', new WP_Post(12, 'draft'));

        $GLOBALS['wp_objects']['post'][12] = new WP_Post(12, 'publish');
        $this->driver->runAll();

        expect(RecordingHandler::$calls[0]['arguments'][0]->post_status)->toBe('publish');
    });

    it('drops the handler when a referenced object was deleted in the meantime', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'post'])->async()->via('recording');
        wp_stub_fire('save_post', new WP_Post(12));

        $this->driver->runAll();

        expect(RecordingHandler::$calls)->toBe([])
            ->and($GLOBALS['incidents'])->toBe([]);
    });

    it('runs it with null in place of a deleted object when asked to', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'maybePost'])->async()->via('recording')->keepMissing();
        wp_stub_fire('save_post', new WP_Post(12));

        $this->driver->runAll();

        expect(RecordingHandler::$calls[0]['arguments'])->toBe([null]);
    });

    it('reports a failing handler and announces it, without letting the exception escape', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'fails'])->async()->via('sync');

        wp_stub_fire('save_post', 9);

        $failed = array_values(array_filter($GLOBALS['wp_actions_done'], fn (array $call): bool => $call['hook'] === 'pollora/async/failed'));
        expect($GLOBALS['incidents'])->toBe(['CRM unreachable'])
            ->and($failed)->toHaveCount(1)
            ->and($failed[0]['args'][0])->toBeInstanceOf(AsyncPayload::class)
            ->and($failed[0]['args'][1]->getMessage())->toBe('CRM unreachable');
    });

    it('reports a handler that no longer exists', function (): void {
        Async::receive((new AsyncPayload(
            id: AsyncPayload::newId(),
            hook: 'save_post',
            handler: 'App\Removed@handle',
            priority: 10,
            arguments: [1],
            origin: ['userId' => 0, 'blogId' => 1, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T10:00:00+00:00'],
        ))->toJson());

        expect($GLOBALS['incidents'][0])->toContain("'App\Removed' no longer exists");
    });

    it('reports a payload it cannot read', function (): void {
        Async::receive('{"v":99}');

        expect($GLOBALS['incidents'][0])->toContain('version 99 is not supported');
    });

    it('restores the site and the locale of the original request, then switches back', function (): void {
        $GLOBALS['wp_state'] = ['user' => 5, 'blog' => 3, 'locale' => 'de_DE', 'multisite' => true];
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->via('recording');
        wp_stub_fire('save_post', 1);

        $GLOBALS['wp_state'] = ['user' => 0, 'blog' => 1, 'locale' => 'en_US', 'multisite' => true];
        $this->driver->runAll();

        expect(RecordingHandler::$calls[0]['blog'])->toBe(3)
            ->and(RecordingHandler::$calls[0]['locale'])->toBe('de_DE')
            ->and($GLOBALS['wp_switches'])->toBe([['blog', 3], ['locale', 'de_DE'], ['restore_locale', 'en_US'], ['restore_blog', 1]]);
    });

    it('does not queue itself again when its handler fires its own hook, other handlers still do', function (): void {
        $this->action->add('save_post', [RecordingHandler::class, 'refires'])->async()->via('recording');
        $this->action->add('save_post', [RecordingHandler::class, 'handle'])->async()->via('recording');
        wp_stub_fire('save_post', 1);
        $refiring = $this->driver->queued[0];

        Async::receive($refiring['payload']->toJson());

        $queuedByTheRun = array_slice($this->driver->queued, 2);
        expect(array_column(RecordingHandler::$calls, 'method'))->toBe(['refires'])
            ->and($queuedByTheRun)->toHaveCount(1)
            ->and($queuedByTheRun[0]['payload']->handler)->toBe(RecordingHandler::class.'@handle');
    });
});
