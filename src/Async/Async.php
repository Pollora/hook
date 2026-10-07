<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

use Pollora\Hook\Async\Contracts\AsyncDriver;
use Pollora\Hook\Async\Contracts\ObjectReference;
use Pollora\Hook\Async\Drivers\ActionSchedulerDriver;
use Pollora\Hook\Async\Drivers\SyncDriver;
use Pollora\Hook\Async\Drivers\WpCronDriver;
use Pollora\Hook\Async\Exceptions\DriverUnavailable;
use Pollora\Hook\Domain\Contract\CallbackResolverInterface;

/**
 * Entry point of asynchronous actions: drivers, the internal hook, reporting.
 *
 *     Async::extend('queue', fn () => new QueueDriver(...));
 *     Async::reportUsing(fn (string $message, array $context) => logger()->warning($message, $context));
 */
final class Async
{
    /**
     * The internal hook drivers fire to run a queued handler.
     */
    public const string HOOK = 'pollora/async/run';

    /**
     * Default driver: the first available among the auto drivers.
     */
    public const string DEFAULT_DRIVER = 'auto';

    /**
     * Constant that sets the default driver, in wp-config.php.
     */
    public const string DRIVER_CONSTANT = 'POLLORA_ASYNC_DRIVER';

    /**
     * Filter that sets the default driver, when the constant is not defined.
     */
    public const string DRIVER_FILTER = 'pollora/hook/async_driver';

    /** @var array<string, \Closure(): AsyncDriver> */
    private static array $factories = [];

    /** @var array<string, AsyncDriver> */
    private static array $drivers = [];

    private static ?string $defaultDriver = null;

    /** @var list<string> Drivers 'auto' tries, in order */
    private static array $autoDrivers = ['action-scheduler', 'wp-cron'];

    private static ?CallbackResolverInterface $resolver = null;

    private static ?ArgumentNormalizer $normalizer = null;

    private static ?AsyncDispatcher $dispatcher = null;

    private static ?AsyncRunner $runner = null;

    private static ?bool $debug = null;

    /** @var (\Closure(string, array<string, mixed>): void)|null */
    private static ?\Closure $reporter = null;

    /**
     * Register a driver under a name, replacing any driver of that name.
     *
     * @param  callable(): AsyncDriver  $factory  Called once, the first time the driver is needed
     */
    public static function extend(string $name, callable $factory): void
    {
        self::$factories[$name] = $factory(...);
        unset(self::$drivers[$name]);
    }

    /**
     * Get a driver.
     *
     * @param  string|null  $name  Driver name, the default one when null
     *
     * @throws DriverUnavailable When the driver is not registered or not available in this request
     */
    public static function driver(?string $name = null): AsyncDriver
    {
        $name ??= self::defaultDriver();
        $factories = self::factories();

        if (! isset($factories[$name])) {
            throw DriverUnavailable::unknown($name, array_keys($factories));
        }

        $driver = self::$drivers[$name] ??= $factories[$name]();

        if (! $driver->available()) {
            throw DriverUnavailable::unavailable($name);
        }

        return $driver;
    }

    /**
     * Name of the default driver, from the first of: setDefaultDriver(), the
     * POLLORA_ASYNC_DRIVER constant, the 'pollora/hook/async_driver' filter,
     * 'auto'.
     *
     * 'auto' resolves to the first available of the auto drivers: Action
     * Scheduler when a plugin bundling it is active and initialised, WP-Cron
     * otherwise. It is resolved on each dispatch, so a hook fired before Action
     * Scheduler is initialised still queues, through WP-Cron.
     */
    public static function defaultDriver(): string
    {
        $driver = self::$defaultDriver;

        if ($driver === null && defined(self::DRIVER_CONSTANT) && is_string(constant(self::DRIVER_CONSTANT)) && constant(self::DRIVER_CONSTANT) !== '') {
            $driver = constant(self::DRIVER_CONSTANT);
        }

        if ($driver === null && function_exists('apply_filters')) {
            $filtered = apply_filters(self::DRIVER_FILTER, self::DEFAULT_DRIVER);
            $driver = is_string($filtered) && $filtered !== '' ? $filtered : null;
        }

        $driver ??= self::DEFAULT_DRIVER;

        return $driver === 'auto' ? self::resolveAuto() : $driver;
    }

    /**
     * Set the drivers 'auto' tries, in order. The framework puts its Laravel queue first.
     *
     * @param  array<int, string>  $drivers  The last one should always be available: 'wp-cron'
     */
    public static function setAutoDrivers(array $drivers): void
    {
        self::$autoDrivers = array_values($drivers);
    }

    /**
     * Set the default driver from code, before the constant and the filter. Null to follow them again.
     */
    public static function setDefaultDriver(?string $driver): void
    {
        self::$defaultDriver = $driver;
    }

    /**
     * Register an object reference, so arguments of that kind can travel.
     */
    public static function reference(ObjectReference $reference): void
    {
        self::normalizer()->extend($reference);
    }

    /**
     * Build handler classes through this resolver at execution time.
     */
    public static function useCallbackResolver(?CallbackResolverInterface $resolver): void
    {
        self::$resolver = $resolver;
        self::$runner = null;
    }

    /**
     * Listen to the internal hooks: execution, and recovery of lost WP-Cron events. Safe to call more than once.
     */
    public static function listen(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        if (has_action(self::HOOK, [self::class, 'receive']) === false) {
            add_action(self::HOOK, [self::class, 'receive'], 10, 1);
        }

        if (has_action(WpCronDriver::RECOVERY_HOOK, [WpCronDriver::class, 'recover']) === false) {
            add_action(WpCronDriver::RECOVERY_HOOK, [WpCronDriver::class, 'recover'], 10, 0);
        }
    }

    /**
     * Run a queued handler, from the message a driver hands back.
     *
     * @param  string  $message  The payload as JSON, or the identifier of a payload kept by PayloadStore
     */
    public static function receive(string $message): void
    {
        if (PayloadStore::handles($message)) {
            $message = PayloadStore::claim($message);

            if ($message === null) {
                return;
            }
        }

        try {
            $payload = AsyncPayload::fromJson($message);
        } catch (\Throwable $throwable) {
            self::report($throwable);

            return;
        }

        self::runner()->run($payload);
    }

    /**
     * Whether incidents throw instead of being reported: WP_DEBUG, unless set otherwise.
     */
    public static function isDebug(): bool
    {
        return self::$debug ?? (defined('WP_DEBUG') && WP_DEBUG);
    }

    /**
     * Force debug mode on or off, or null to follow WP_DEBUG again.
     */
    public static function setDebug(?bool $debug): void
    {
        self::$debug = $debug;
    }

    /**
     * Report an incident: through the reporter when one is set, to the PHP error log otherwise.
     *
     * @param  array<string, mixed>  $context
     */
    public static function report(string|\Throwable $incident, array $context = []): void
    {
        $message = $incident instanceof \Throwable ? $incident->getMessage() : $incident;

        if ($incident instanceof \Throwable) {
            $context['exception'] = $incident;
        }

        if (self::$reporter instanceof \Closure) {
            (self::$reporter)($message, $context);

            return;
        }

        $details = array_filter($context, fn (mixed $value): bool => is_scalar($value));
        error_log('[pollora/hook] '.$message.($details === [] ? '' : ' '.json_encode($details)));
    }

    /**
     * Send incidents somewhere else than the PHP error log.
     *
     * @param  (callable(string, array<string, mixed>): void)|null  $reporter
     */
    public static function reportUsing(?callable $reporter): void
    {
        self::$reporter = $reporter === null ? null : $reporter(...);
    }

    /**
     * @internal
     */
    public static function dispatcher(): AsyncDispatcher
    {
        return self::$dispatcher ??= new AsyncDispatcher(self::normalizer());
    }

    /**
     * @internal
     */
    public static function runner(): AsyncRunner
    {
        return self::$runner ??= new AsyncRunner(new CallableDescriptor(self::$resolver), self::normalizer());
    }

    /**
     * Forget drivers, settings and state. For tests.
     *
     * @internal
     */
    public static function flush(): void
    {
        self::$factories = [];
        self::$drivers = [];
        self::$defaultDriver = null;
        self::$autoDrivers = ['action-scheduler', 'wp-cron'];
        self::$resolver = null;
        self::$normalizer = null;
        self::$dispatcher = null;
        self::$runner = null;
        self::$debug = null;
        self::$reporter = null;
    }

    /**
     * The first available auto driver, WP-Cron when none is.
     */
    private static function resolveAuto(): string
    {
        $factories = self::factories();

        foreach (self::$autoDrivers as $name) {
            if (isset($factories[$name]) && (self::$drivers[$name] ??= $factories[$name]())->available()) {
                return $name;
            }
        }

        return 'wp-cron';
    }

    private static function normalizer(): ArgumentNormalizer
    {
        return self::$normalizer ??= new ArgumentNormalizer;
    }

    /**
     * @return array<string, \Closure(): AsyncDriver>
     */
    private static function factories(): array
    {
        return self::$factories + [
            'action-scheduler' => static fn (): AsyncDriver => new ActionSchedulerDriver,
            'wp-cron' => static fn (): AsyncDriver => new WpCronDriver,
            'sync' => static fn (): AsyncDriver => new SyncDriver,
        ];
    }
}
