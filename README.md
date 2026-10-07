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

Add `async()` after `add()` and the handler no longer runs inside the request that fires the hook: it is queued, then run later by WP-Cron.

```php
use Pollora\Hook\Action;

Action::add('save_post_event', [CrmSync::class, 'push'])->async();
```

An asynchronous handler changes guarantees. Four rules to keep in mind:

- **At least once, not exactly once.** A double trigger can run the handler twice: it must be safe to replay.
- **No guaranteed order.** Two asynchronous actions on the same hook may run in any order.
- **A variable delay.** With WP-Cron and no system cron, the handler waits for the next visit to the site.
- **A class or a named function, not a closure.** The handler travels by name and runs in its current version; closures are rejected.

The handler is written as a synchronous one. It receives the hook arguments and, if it declares a parameter of that type, an `AsyncContext`:

```php
use Pollora\Hook\Async\AsyncContext;

final class CrmSync
{
    public function push(int $postId, WP_Post $post, AsyncContext $context): void
    {
        $context->userId;       // user who fired the hook
        $context->dispatchedAt; // trigger date
    }
}
```

**Arguments.** Scalars and arrays keep their value. `WP_Post`, `WP_Term`, `WP_User` and `WP_Comment` travel as a reference and are reloaded, in their state at execution time; if one was deleted in the meantime, the handler is dropped (`keepMissing()` runs it with `null` instead). Enums and dates are rebuilt, a `JsonSerializable` object becomes its array. Any other object is refused.

**Options**, chained after `async()`:

| Method | Effect |
|---|---|
| `delay(60)` | Minimum delay, in seconds or as a `DateInterval` |
| `via('sync')` | Driver for this registration |
| `keepMissing()` | Run with `null` in place of a deleted object |
| `except('save_post_page')` | Keep some hooks of the same `add()` synchronous; also `async(except: …)` |

**Drivers.** `wp-cron` is the default: the payload is stored in an option that is not autoloaded and the event carries only its identifier. A daily task schedules again the payloads whose event was lost. `sync` runs the handler right away, which suits development and end-to-end tests. Choose the default with the `POLLORA_ASYNC_DRIVER` constant in `wp-config.php`, or the `pollora/hook/async_driver` filter. More drivers register through `Async::extend()`.

**Failures.** At execution, the original site and locale are restored, and a handler that fires its own hook does not queue itself again. A failing handler is reported and announced through the `pollora/async/failed` action. When an action cannot be queued, `WP_DEBUG` throws; otherwise the incident goes to the PHP error log (or `Async::reportUsing()`) and the handler runs in place, so the work always happens.

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
