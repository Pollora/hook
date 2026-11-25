<?php

declare(strict_types=1);

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Drivers\ScheduledDriver;
use Pollora\Hook\Async\Exceptions\AsyncException;
use Pollora\Hook\Async\PayloadStore;
use Pollora\Hook\Async\Recovery;
use Pollora\Hook\Async\UniqueLock;

beforeEach(function (): void {
    $GLOBALS['wp_options'] = [];
    $GLOBALS['wp_cron_events'] = [];
    $GLOBALS['wp_cache_deleted'] = [];
    $GLOBALS['wp_fail'] = [];
    $GLOBALS['wp_async_listeners'] = [];
    $GLOBALS['as_actions'] = [];
    $GLOBALS['as_fail'] = false;
    $GLOBALS['as_initialized'] = true;
    $GLOBALS['incidents'] = [];
    $GLOBALS['wpdb'] = wp_stub_wpdb();

    Async::flush();
    Async::setDebug(true);
    Async::reportUsing(function (string $message): void {
        $GLOBALS['incidents'][] = $message;
    });
});

afterEach(function (): void {
    $GLOBALS['as_initialized'] = false;
    Async::flush();
    unset($GLOBALS['wpdb']);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function storagePayload(array $overrides = []): AsyncPayload
{
    return new AsyncPayload(...array_merge([
        'id' => AsyncPayload::newId(),
        'hook' => 'save_post',
        'handler' => 'pollora_test_unknown_handler',
        'priority' => 10,
        'arguments' => [1],
        'origin' => ['userId' => 0, 'blogId' => 1, 'locale' => 'en_US', 'dispatchedAt' => gmdate(DATE_ATOM, time() - 2 * Recovery::GRACE)],
    ], $overrides));
}

function storeStoragePayload(?AsyncPayload $payload = null): string
{
    $payload ??= storagePayload();
    add_option(PayloadStore::OPTION_PREFIX.$payload->id, $payload->toJson(), '', false);

    return $payload->id;
}

/**
 * A $wpdb answering the options queries with the given rows or names, whatever the options hold.
 */
function fixedWpdb(mixed $rows = [], mixed $names = []): object
{
    return new class($rows, $names)
    {
        public string $options = 'wp_options';

        public function __construct(private readonly mixed $rows, private readonly mixed $names) {}

        public function esc_like(string $text): string
        {
            return $text;
        }

        /**
         * @return array{query: string, args: list<mixed>}
         */
        public function prepare(string $query, mixed ...$args): array
        {
            return ['query' => $query, 'args' => $args];
        }

        /**
         * @param  array{query: string, args: list<mixed>}  $prepared
         */
        public function get_results(array $prepared, string $output): mixed
        {
            return $this->rows;
        }

        /**
         * @param  array{query: string, args: list<mixed>}  $prepared
         */
        public function get_col(array $prepared): mixed
        {
            return $this->names;
        }
    };
}

/**
 * @return list<array<string, mixed>>
 */
function executionEventsOf(): array
{
    return array_values(array_filter($GLOBALS['wp_cron_events'], fn (array $event): bool => $event['hook'] === Async::HOOK));
}

describe('Scheduling the recovery', function (): void {
    it('schedules it one grace period from now, daily', function (): void {
        $before = time();
        Recovery::schedule();

        expect($GLOBALS['wp_cron_events'])->toHaveCount(1)
            ->and($GLOBALS['wp_cron_events'][0]['hook'])->toBe(Recovery::HOOK)
            ->and($GLOBALS['wp_cron_events'][0]['recurrence'])->toBe('daily')
            ->and($GLOBALS['wp_cron_events'][0]['timestamp'])->toBeGreaterThanOrEqual($before + Recovery::GRACE)
            ->and($GLOBALS['wp_cron_events'][0]['timestamp'])->toBeLessThanOrEqual(time() + Recovery::GRACE);
    });

    it('does not schedule it twice', function (): void {
        Recovery::schedule();
        Recovery::schedule();

        expect($GLOBALS['wp_cron_events'])->toHaveCount(1);
    });
});

describe('Recovering lost payloads', function (): void {
    it('reads the stored payloads by their prefix, a batch at a time', function (): void {
        Recovery::recover();

        $wpdb = $GLOBALS['wpdb'];
        expect($wpdb->queries[0]['query'])->toBe('SELECT option_name, option_value FROM wp_options WHERE option_name LIKE %s LIMIT %d')
            ->and($wpdb->queries[0]['args'])->toBe([addcslashes(PayloadStore::OPTION_PREFIX, '_%\\').'%', Recovery::BATCH])
            ->and($wpdb->outputs)->toBe(['ARRAY_A']);
    });

    it('does nothing without a database object', function (): void {
        $GLOBALS['wpdb'] = null;

        expect(Recovery::recover())->toBe(0);
    });

    it('does not report anything when nothing was lost', function (): void {
        expect(Recovery::recover())->toBe(0)
            ->and($GLOBALS['incidents'])->toBe([]);
    });

    it('schedules the lost execution as a single event reporting its errors', function (): void {
        $id = storeStoragePayload();

        expect(Recovery::recover())->toBe(1)
            ->and(executionEventsOf()[0]['args'])->toBe([$id])
            ->and(executionEventsOf()[0]['wp_error'])->toBeTrue();
    });

    it('counts only the executions that were scheduled', function (): void {
        $GLOBALS['wp_fail'] = ['wp_schedule_single_event'];
        storeStoragePayload();

        expect(Recovery::recover())->toBe(0)
            ->and($GLOBALS['incidents'])->toBe([]);
    });

    it('takes a payload dispatched exactly one grace period ago as lost', function (): void {
        storeStoragePayload(storagePayload(['origin' => ['userId' => 0, 'blogId' => 1, 'locale' => 'en_US', 'dispatchedAt' => gmdate(DATE_ATOM, time() - Recovery::GRACE)]]));

        expect(Recovery::recover())->toBe(1);
    });

    it('goes on with the next payload after one that is still pending', function (): void {
        $pending = storeStoragePayload();
        wp_schedule_single_event(time() + 600, Async::HOOK, [$pending]);
        storeStoragePayload();

        expect(Recovery::recover())->toBe(1);
    });

    it('goes on with the next payload after one that cannot be read', function (): void {
        add_option(PayloadStore::OPTION_PREFIX.AsyncPayload::newId(), 'not json', '', false);
        storeStoragePayload();

        expect(Recovery::recover())->toBe(1);
    });

    it('goes on with the next payload after a recent one', function (): void {
        storeStoragePayload(storagePayload(['origin' => ['userId' => 0, 'blogId' => 1, 'locale' => 'en_US', 'dispatchedAt' => gmdate(DATE_ATOM, time() - 60)]]));
        storeStoragePayload();

        expect(Recovery::recover())->toBe(1);
    });

    it('treats a missing list of rows as none', function (): void {
        $GLOBALS['wpdb'] = fixedWpdb(null);
        set_error_handler(function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            expect(Recovery::recover())->toBe(0);
        } finally {
            restore_error_handler();
        }
    });

    it('reads rows whose values are not strings', function (): void {
        $id = AsyncPayload::newId();
        $GLOBALS['wpdb'] = fixedWpdb([
            ['option_name' => 123, 'option_value' => 'ignored'],
            ['option_name' => PayloadStore::OPTION_PREFIX.$id, 'option_value' => null],
        ]);
        add_option(PayloadStore::OPTION_PREFIX.$id, 'stored', '', false);

        expect(Recovery::recover())->toBe(0)
            ->and($GLOBALS['wp_options'])->toBe([])
            ->and($GLOBALS['incidents'][0])->toContain("unreadable payload {$id} deleted");
    });
});

describe('Unique locks', function (): void {
    it('keys a trigger by hook, handler and arguments', function (): void {
        expect(UniqueLock::key('save_post', 'Handler@run', [1, 'a']))->toBe(sha1('["save_post","Handler@run",[1,"a"]]'))
            ->and(UniqueLock::key('save_post', 'Handler@run', [1]))->not->toBe(UniqueLock::key('save_post', 'Handler@run', [2]));
    });

    it('keys arguments that cannot be encoded as the empty string', function (): void {
        expect(UniqueLock::key('save_post', 'Handler@run', ["\xB1"]))->toBe(sha1(''));
    });

    it('stores the lock without a legacy description, outside the autoload', function (): void {
        UniqueLock::acquire('abc', 60);

        $lock = $GLOBALS['wp_options'][UniqueLock::OPTION_PREFIX.'abc'];
        expect($lock['deprecated'])->toBe('')
            ->and($lock['autoload'])->toBeFalse()
            ->and($lock['value'])->toBeGreaterThanOrEqual(time() + 59);
    });

    it('schedules the maintenance that prunes expired locks', function (): void {
        UniqueLock::acquire('abc', 60);

        expect(array_column($GLOBALS['wp_cron_events'], 'hook'))->toBe([Recovery::HOOK]);
    });

    it('reads the lock past the object cache when it exists', function (): void {
        UniqueLock::acquire('abc', 60);
        UniqueLock::acquire('abc', 60);

        expect($GLOBALS['wp_cache_deleted'])->toBe([[UniqueLock::OPTION_PREFIX.'abc', 'options']]);
    });

    it('holds a lock that expires this very second', function (): void {
        do {
            $now = time();
            $GLOBALS['wp_options'] = [UniqueLock::OPTION_PREFIX.'abc' => ['value' => $now, 'autoload' => false, 'deprecated' => '']];
            $acquired = UniqueLock::acquire('abc', 60);
        } while ($now !== time());

        expect($acquired)->toBeFalse();
    });

    it('takes over a lock that expired, storing it as a new one', function (): void {
        $GLOBALS['wp_options'] = [UniqueLock::OPTION_PREFIX.'abc' => ['value' => time() - 10, 'autoload' => false, 'deprecated' => '']];

        expect(UniqueLock::acquire('abc', 60))->toBeTrue();

        $lock = $GLOBALS['wp_options'][UniqueLock::OPTION_PREFIX.'abc'];
        expect($lock['deprecated'])->toBe('')
            ->and($lock['autoload'])->toBeFalse()
            ->and($lock['value'])->toBeGreaterThanOrEqual(time() + 59);
    });

    it('takes over a lock holding something that is not a time', function (): void {
        $GLOBALS['wp_options'] = [UniqueLock::OPTION_PREFIX.'abc' => ['value' => 'garbage', 'autoload' => false, 'deprecated' => '']];

        expect(UniqueLock::acquire('abc', 60))->toBeTrue();
    });

    it('releases a lock', function (): void {
        UniqueLock::acquire('abc', 60);
        UniqueLock::release('abc');

        expect($GLOBALS['wp_options'])->toBe([]);
    });
});

describe('Pruning expired locks', function (): void {
    it('asks for the locks that expired, a batch at a time', function (): void {
        UniqueLock::prune(25);

        $query = $GLOBALS['wpdb']->queries[0];
        expect($query['query'])->toBe('SELECT option_name FROM wp_options WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d')
            ->and($query['args'][0])->toBe(addcslashes(UniqueLock::OPTION_PREFIX, '_%\\').'%')
            ->and($query['args'][1])->toBeGreaterThanOrEqual(time() - 1)
            ->and($query['args'][2])->toBe(25);
    });

    it('does nothing without a database object', function (): void {
        $GLOBALS['wpdb'] = null;

        expect(UniqueLock::prune())->toBe(0);
    });

    it('counts the locks it deleted', function (): void {
        add_option(UniqueLock::OPTION_PREFIX.'one', time() - 10, '', false);
        add_option(UniqueLock::OPTION_PREFIX.'two', time() - 10, '', false);
        add_option(UniqueLock::OPTION_PREFIX.'active', time() + 600, '', false);

        expect(UniqueLock::prune())->toBe(2)
            ->and(array_keys($GLOBALS['wp_options']))->toBe([UniqueLock::OPTION_PREFIX.'active']);
    });

    it('deletes nothing when no lock expired', function (): void {
        expect(UniqueLock::prune())->toBe(0);
    });

    it('counts only the options that were really deleted', function (): void {
        add_option('first', 1, '', false);
        add_option('second', 1, '', false);
        add_option('123', 1, '', false);
        $GLOBALS['wpdb'] = fixedWpdb([], ['first', 'second', 'missing', 123]);

        expect(UniqueLock::prune())->toBe(3);
    });

    it('treats a missing list of names as none', function (): void {
        $GLOBALS['wpdb'] = fixedWpdb([], null);
        set_error_handler(function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level);
        });

        try {
            expect(UniqueLock::prune())->toBe(0);
        } finally {
            restore_error_handler();
        }
    });
});

describe('Payload store', function (): void {
    it('stores a payload without a legacy description, outside the autoload', function (): void {
        $payload = storagePayload();
        PayloadStore::store($payload);

        $stored = $GLOBALS['wp_options'][PayloadStore::OPTION_PREFIX.$payload->id];
        expect($stored['deprecated'])->toBe('')
            ->and($stored['autoload'])->toBeFalse()
            ->and($stored['value'])->toBe($payload->toJson());
    });

    it('takes a stored payload once', function (): void {
        $payload = storagePayload();
        PayloadStore::store($payload);

        expect(PayloadStore::claim($payload->id))->toBe($payload->toJson())
            ->and(PayloadStore::claim($payload->id))->toBeNull();
    });

    it('leaves alone, and does not hand out, a stored value that is not a string', function (): void {
        $id = AsyncPayload::newId();
        $GLOBALS['wp_options'][PayloadStore::OPTION_PREFIX.$id] = ['value' => 123, 'autoload' => false, 'deprecated' => ''];

        expect(PayloadStore::claim($id))->toBeNull()
            ->and($GLOBALS['wp_options'])->toHaveKey(PayloadStore::OPTION_PREFIX.$id);
    });
});

describe('Scheduled driver', function (): void {
    it('keeps one Action Scheduler driver per queue', function (): void {
        $driver = ScheduledDriver::actionScheduler();
        $driverFor = (new ReflectionProperty($driver, 'driverFor'))->getValue($driver);

        expect($driverFor('crm'))->toBe($driverFor('crm'))
            ->and($driverFor(null))->toBe($driverFor(null))
            ->and($driverFor('crm'))->not->toBe($driverFor('mail'));
    });

    it('carries a payload whole up to the limit and stores it apart beyond', function (): void {
        $lengthWith = function (int $padding): int {
            $payload = storagePayload(['arguments' => [str_repeat('a', $padding)]]);

            return strlen(json_encode([$payload->toJson()]));
        };
        $base = $lengthWith(0);
        $atLimit = storagePayload(['arguments' => [str_repeat('a', ScheduledDriver::MAX_ARGUMENTS_LENGTH - $base)]]);
        $beyond = storagePayload(['arguments' => [str_repeat('a', ScheduledDriver::MAX_ARGUMENTS_LENGTH - $base + 1)]]);

        expect(strlen(json_encode([$atLimit->toJson()])))->toBe(ScheduledDriver::MAX_ARGUMENTS_LENGTH);

        ScheduledDriver::actionScheduler()->dispatch($atLimit);
        ScheduledDriver::actionScheduler()->dispatch($beyond);

        expect($GLOBALS['as_actions'][0]['args'])->toBe([$atLimit->toJson()])
            ->and($GLOBALS['as_actions'][1]['args'])->toBe([$beyond->id]);
    });

    it('reports a payload it cannot store, keeping the code and the cause', function (): void {
        $GLOBALS['wp_fail'] = ['add_option'];

        try {
            ScheduledDriver::wpCron()->dispatch(storagePayload());
            $caught = null;
        } catch (AsyncException $caught) {
        }

        $previous = $caught?->getPrevious();

        expect($caught)->toBeInstanceOf(AsyncException::class)
            ->and($caught?->getMessage())->toBe("WP-Cron: the payload of 'save_post' could not be stored.")
            ->and($caught?->getCode())->toBe(0)
            ->and($previous)->toBeInstanceOf(AsyncException::class)
            ->and($previous?->getMessage())->toBe("The payload of 'save_post' could not be stored.");
    });

    it('never schedules an execution in the past', function (): void {
        $before = time();

        ScheduledDriver::wpCron()->dispatch(storagePayload(), -30);

        expect(executionEventsOf()[0]['timestamp'])->toBeGreaterThanOrEqual($before);
    });

    it('schedules an execution after its delay', function (): void {
        $before = time();

        ScheduledDriver::wpCron()->dispatch(storagePayload(), 120);

        expect(executionEventsOf()[0]['timestamp'])->toBeGreaterThanOrEqual($before + 120)
            ->and(executionEventsOf()[0]['timestamp'])->toBeLessThanOrEqual(time() + 120);
    });
});
