<?php

declare(strict_types=1);

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Drivers\SyncDriver;
use Pollora\Hook\Async\Exceptions\DriverUnavailable;

beforeEach(function (): void {
    Async::flush();
});

afterEach(function (): void {
    Async::flush();
});

function asyncTestDriver(bool $available): AsyncDriver
{
    return new class($available) implements AsyncDriver
    {
        public function __construct(private bool $available) {}

        public function available(): bool
        {
            return $this->available;
        }

        public function dispatch(AsyncPayload $payload, int $delay = 0): void {}
    };
}

it('provides the sync driver', function (): void {
    expect(Async::driver('sync'))->toBeInstanceOf(SyncDriver::class);
});

it('builds a registered driver once', function (): void {
    $builds = 0;
    Async::extend('custom', function () use (&$builds): AsyncDriver {
        $builds++;

        return asyncTestDriver(true);
    });

    expect(Async::driver('custom'))->toBe(Async::driver('custom'))
        ->and($builds)->toBe(1);
});

it('names the registered drivers when one is unknown', function (): void {
    Async::extend('custom', fn (): AsyncDriver => asyncTestDriver(true));

    expect(fn () => Async::driver('rabbitmq'))->toThrow(DriverUnavailable::class, 'Registered drivers: custom, action-scheduler, wp-cron, sync.');
});

it('refuses a driver unavailable in this request', function (): void {
    Async::extend('action-scheduler', fn (): AsyncDriver => asyncTestDriver(false));

    expect(fn () => Async::driver('action-scheduler'))->toThrow(DriverUnavailable::class, "'action-scheduler' is not available");
});

it('follows WP_DEBUG unless debug mode is forced', function (): void {
    expect(Async::isDebug())->toBe(defined('WP_DEBUG') && WP_DEBUG);

    Async::setDebug(true);
    expect(Async::isDebug())->toBeTrue();

    Async::setDebug(null);
    expect(Async::isDebug())->toBe(defined('WP_DEBUG') && WP_DEBUG);
});

it('hands incidents to the reporter with the exception in the context', function (): void {
    $reported = [];
    Async::reportUsing(function (string $message, array $context) use (&$reported): void {
        $reported[] = [$message, $context];
    });
    $exception = new RuntimeException('CRM unreachable');

    Async::report($exception, ['hook' => 'save_post']);

    expect($reported)->toBe([['CRM unreachable', ['hook' => 'save_post', 'exception' => $exception]]]);
});

it('writes incidents to the PHP error log without a reporter', function (): void {
    $log = tempnam(sys_get_temp_dir(), 'pollora-hook');
    $previous = ini_set('error_log', $log);

    try {
        Async::report('Queue unavailable', ['hook' => 'save_post', 'exception' => new RuntimeException]);
    } finally {
        ini_set('error_log', (string) $previous);
    }

    expect(file_get_contents($log))->toContain('[pollora/hook] Queue unavailable {"hook":"save_post"}');
    unlink($log);
});

describe('Default driver', function (): void {
    beforeEach(function (): void {
        $GLOBALS['wp_filters'] = [];
    });

    it('is wp-cron', function (): void {
        expect(Async::defaultDriver())->toBe('wp-cron');
    });

    it('follows the pollora/hook/async_driver filter', function (): void {
        add_filter(Async::DRIVER_FILTER, fn (): string => 'action-scheduler');

        expect(Async::defaultDriver())->toBe('action-scheduler');
    });

    it('ignores a filter that returns no driver name', function (): void {
        add_filter(Async::DRIVER_FILTER, fn (): null => null);

        expect(Async::defaultDriver())->toBe('wp-cron');
    });

    it('is set from code before the filter', function (): void {
        add_filter(Async::DRIVER_FILTER, fn (): string => 'action-scheduler');
        Async::setDefaultDriver('sync');

        expect(Async::defaultDriver())->toBe('sync');
    });

    it('resolves auto to WP-Cron when Action Scheduler is not available', function (): void {
        $GLOBALS['as_initialized'] = false;

        expect(Async::defaultDriver())->toBe('wp-cron');
    });

    it('resolves auto to Action Scheduler once it is initialised', function (): void {
        $GLOBALS['as_initialized'] = true;

        try {
            expect(Async::defaultDriver())->toBe('action-scheduler');
        } finally {
            $GLOBALS['as_initialized'] = false;
        }
    });

    it('resolves auto through the drivers set for it, in order', function (): void {
        Async::extend('queue', fn (): AsyncDriver => asyncTestDriver(true));
        Async::setAutoDrivers(['queue', 'wp-cron']);

        expect(Async::defaultDriver())->toBe('queue');

        Async::extend('queue', fn (): AsyncDriver => asyncTestDriver(false));

        expect(Async::defaultDriver())->toBe('wp-cron');
    });
});

describe('POLLORA_ASYNC_DRIVER constant', function (): void {
    /**
     * Read the default driver in a separate PHP process, so the constant does not leak into other tests.
     */
    function defaultDriverInSeparateProcess(string $setup): string
    {
        $script = tempnam(sys_get_temp_dir(), 'pollora-hook').'.php';
        file_put_contents($script, sprintf(
            '<?php require %s; require %s; %s echo Pollora\\Hook\\Async\\Async::defaultDriver();',
            var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true),
            var_export(dirname(__DIR__, 2).'/Stubs/wordpress.php', true),
            $setup,
        ));

        try {
            return (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1');
        } finally {
            unlink($script);
        }
    }

    it('sets the default driver', function (): void {
        expect(defaultDriverInSeparateProcess("define('POLLORA_ASYNC_DRIVER', 'sync');"))->toBe('sync');
    });

    it('comes before the filter', function (): void {
        expect(defaultDriverInSeparateProcess("define('POLLORA_ASYNC_DRIVER', 'sync'); add_filter('pollora/hook/async_driver', fn () => 'action-scheduler');"))->toBe('sync');
    });

    it('comes after the driver set from code', function (): void {
        expect(defaultDriverInSeparateProcess("define('POLLORA_ASYNC_DRIVER', 'sync'); Pollora\\Hook\\Async\\Async::setDefaultDriver('wp-cron');"))->toBe('wp-cron');
    });

    it('is ignored when empty', function (): void {
        expect(defaultDriverInSeparateProcess("define('POLLORA_ASYNC_DRIVER', '');"))->toBe('wp-cron');
    });
});
