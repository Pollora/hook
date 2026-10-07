<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/hook.png" width="100%" alt="Pollora Hook: WordPress actions and filters with a clean PHP API">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/hook"><img src="https://img.shields.io/packagist/v/pollora/hook" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/hook"><img src="https://img.shields.io/packagist/dt/pollora/hook" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/hook/actions/workflows/tests.yml"><img src="https://github.com/Pollora/hook/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/hook" alt="License"></a>
</p>

A small, dependency-free PHP layer over WordPress actions and filters. It registers class-based callbacks by hook name, detects how many arguments a callback accepts, and removes hooks reliably, so you stop counting `$accepted_args` by hand and stop keeping closures around just to unhook them.

> Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress. In a Pollora project it is already installed: use the `#[Action]` / `#[Filter]` attributes or the `Pollora\Support\Facades\Action` / `Filter` facades instead. The standalone classes emit a notice when the framework is present.

## Installation

```bash
composer require pollora/hook
```

Requires PHP 8.3+ and WordPress (the adapters call `add_action()`, `add_filter()` and friends).

## Quick start

```php
use Pollora\Hook\Action;
use Pollora\Hook\Filter;

Action::add('init', function (): void {
    // runs on WordPress init
});

Filter::add('the_content', fn (string $content): string => $content.'<p>Appended!</p>');

Action::do('my_custom_action', $order, $user);
$title = Filter::apply('my_title_filter', $title, $post);

if (Action::exists('init')) {
    Action::remove('init', $callback);
}
```

## What you get

- **`add()`** takes one hook name or an array of them, a callback, a priority (default `10`) and an optional argument count.
- **Argument detection**: when `$acceptedArgs` is omitted, the callback's parameters are counted through reflection, and the result is cached per callback.
- **Class callbacks**: pass a class name and the method named after the hook is called, `wp_loaded` → `wpLoaded()`.
- **Reliable removal**: `remove()` matches class-based callbacks by class, not only by instance, and also unhooks callbacks added outside the package (by WordPress core or a plugin).
- **Introspection**: `exists()` and `callbacks()` list what was registered through the package.
- **Hexagonal core**: `AbstractHook` holds the logic with no WordPress or Laravel dependency; `Adapter\Out\WordPress\Action` and `Filter` talk to WordPress.

## Class-based callbacks

```php
Action::add('wp_loaded', MyInitializer::class);
// Resolves to [new MyInitializer, 'wpLoaded']

Action::add('save_post', [CrmSync::class, 'push']);
// An instance method named by class resolves to [new CrmSync, 'push']
```

A static method stays as you pass it, and a class that is not loaded yet is left for WordPress to resolve when the hook fires.

To build those classes through a container, use the adapter directly and give it a `CallbackResolverInterface`:

```php
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Domain\Contract\CallbackResolverInterface;
use Psr\Container\ContainerInterface;

final class ContainerResolver implements CallbackResolverInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function resolve(string $className): object
    {
        return $this->container->get($className);
    }
}

$action = new Action;
$action->setCallbackResolver(new ContainerResolver($container));

$action->add('wp_loaded', MyInitializer::class);
// MyInitializer is built by the container, then wpLoaded() is hooked
```

## Asynchronous actions

Add `async()` after `add()` and the handler no longer runs inside the request that fires the hook: it is queued, then run later by Action Scheduler or WP-Cron.

```php
use Pollora\Hook\Action;

Action::add('save_post_event', [CrmSync::class, 'push'])->async();
```

An asynchronous handler changes guarantees. Four rules to keep in mind:

- **At least once, not exactly once.** A retry or a double trigger can run the handler twice: it must be safe to replay.
- **No guaranteed order.** Two asynchronous actions on the same hook may run in any order.
- **A variable delay.** With WP-Cron and no system cron, the handler waits for the next visit to the site.
- **Prefer a class to a closure.** A class or a named function travels by name and runs in its current version; a closure runs as it was when queued, and needs `laravel/serializable-closure`.

The handler is written as a synchronous one. It receives the hook arguments and, if it declares a parameter of that type, an `AsyncContext`:

```php
use Pollora\Hook\Async\AsyncContext;

final class CrmSync
{
    public function push(int $postId, WP_Post $post, AsyncContext $context): void
    {
        $context->userId;          // user who fired the hook
        $context->dispatchedAt;    // trigger date
        $context->attempt;         // attempt number
        $context->get('status');   // value recorded by capture()
    }
}
```

**Arguments.** Scalars and arrays keep their value. `WP_Post`, `WP_Term`, `WP_User` and `WP_Comment` travel as a reference and are reloaded, in their state at execution time; if one was deleted in the meantime, the handler is dropped (`keepMissing()` runs it with `null` instead). Enums and dates are rebuilt, a `JsonSerializable` object becomes its array. Any other object is refused.

**Capturing values at trigger time.** At execution there is no request any more, and the content may have changed. `capture()` runs in the original request, with the hook arguments, and its values are read back through `$context->get()`. A handler class's public `capture()` method is used when `capture()` is not called. Capture an ID rather than personal data or a secret: values wait in the database.

```php
Action::add('save_post_event', [CrmSync::class, 'push'])
    ->async()
    ->when(fn (int $postId) => ! wp_is_post_revision($postId) && ! wp_is_post_autosave($postId))
    ->capture(fn (int $postId, WP_Post $post) => ['status' => $post->post_status])
    ->unique()
    ->tries(3);
```

**Options**, chained after `async()`:

| Method | Effect |
|---|---|
| `delay(60)` | Minimum delay, in seconds or as a `DateInterval` |
| `when(fn (...) => bool)` | Queue only when the condition, given the hook arguments, returns `true` |
| `capture(fn (...) => [...])` | Record values at trigger time |
| `unique()` | Merge identical triggers (same hook, handler and arguments) until the first one runs; `unique(for: 3600)` sets how long the lock lasts if never released (a day by default) |
| `tries(3)` | Attempts when the handler throws; `backoff([10, 60, 300])` sets the seconds before each retry (the default) |
| `asUser()` | Run as the user who fired the hook, with that user's capabilities. Without it, there is no current user |
| `via('sync')` | Driver for this registration |
| `onQueue('integrations')` | Queue name: the Action Scheduler group |
| `keepMissing()` | Run with `null` in place of a deleted object |
| `except('save_post_page')` | Keep some hooks of the same `add()` synchronous; also `async(except: …)` |

**Drivers.** The default, `auto`, picks Action Scheduler when a plugin bundling it (WooCommerce, for example) is active and initialised, WP-Cron otherwise.

- `action-scheduler`: the payload travels in the action, the queue name becomes its group (`pollora` by default), and history shows in Tools › Scheduled Actions.
- `wp-cron`: the payload is stored in an option that is not autoloaded and the event carries only its identifier. A daily task schedules again the payloads whose event was lost.
- `sync`: runs the handler right away, which suits development and end-to-end tests.

Choose the default with the `POLLORA_ASYNC_DRIVER` constant in `wp-config.php`, or the `pollora/hook/async_driver` filter. More drivers register through `Async::extend()`.

**Closures.** With `laravel/serializable-closure` installed, a closure can be queued. It is serialized and signed with a key derived from the WordPress salts, and the signature is checked before anything is unserialized. Declare it `static` and let it use IDs rather than objects.

**Failures.** At execution, the original site and locale are restored, and a handler that fires its own hook does not queue itself again. A handler that throws is retried while it has attempts left; the last failure is reported and announced through the `pollora/async/failed` action. When an action cannot be queued, `WP_DEBUG` throws; otherwise the incident goes to the PHP error log (or `Async::reportUsing()`) and the handler runs in place, so the work always happens.

**Testing.** `Async::fake()` records queued handlers instead of queuing them:

```php
use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;

Async::fake();

wp_update_post(['ID' => $postId, 'post_title' => 'New title']);

Async::assertDispatched(CrmSync::class, fn (AsyncPayload $payload) => $payload->captured['status'] === 'publish');
Async::assertDispatchedTimes(CrmSync::class, 1);
```

## Documentation

Hooks in a Pollora project: [Actions & filters](https://pollora.dev/hooks/actions-filters/).

## Testing

```bash
composer test            # unit suite, PHPStan, Pint
composer test:wordpress  # against a real WordPress, see tests/WordPress/load.php
```

## Contributing

Contributions are welcome: see the [contributing guide](https://github.com/Pollora/.github/blob/main/CONTRIBUTING.md). Report security issues privately, as described in the [security policy](https://github.com/Pollora/.github/blob/main/SECURITY.md).

## License

Pollora Hook is open-source software licensed under the [MIT license](LICENSE). © [RuBee group](https://rubee.group)
