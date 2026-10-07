# Changelog

All notable changes to `pollora/hook` are documented in this file.

## [Unreleased]

### Changed

- **The default driver is now `auto`**: Action Scheduler when a plugin bundling it is active and initialised, WP-Cron otherwise, resolved on each dispatch. A site with WooCommerce therefore queues through Action Scheduler after upgrading; payloads already queued through WP-Cron still run. Set `POLLORA_ASYNC_DRIVER` to `wp-cron` to keep the previous behaviour.
- The WP-Cron recovery task also leaves alone a stored payload whose Action Scheduler action is still pending.
- The type of `capture()` and `when()` callbacks is documented as `callable`, so closures with typed parameters pass static analysis.

### Added

- `capture()` on an asynchronous registration: a callback run at trigger time, in the original request, with the hook arguments; the array it returns is read back at execution through `AsyncContext::get()`. Captured values travel like arguments. A handler class's public `capture()` method is used when `capture()` is not called.
- `when()`: queue only when the callback, given the hook arguments, returns `true`. It runs before the capture, and an exception it throws goes through, as it would synchronously.
- `tries()`: a handler that throws is queued again, through the driver that queued it, until it has used its attempts; each failed attempt is reported, the last one is fired as `pollora/async/failed`. `backoff()` sets the seconds before each retry (default 10, 60, then 300; the last value repeats). A handler that no longer exists is not retried.
- `asUser()`: run as the user who fired the hook, then remove that user. Without it the handler runs without a current user.
- `onQueue()`: queue name, used as the Action Scheduler group and the Laravel queue; WP-Cron ignores it.
- `unique()`: merge identical triggers (same hook, same handler, same arguments) while the first one has not started running. The lock is an option that is not autoloaded, taken atomically with `add_option()`, released when the first attempt starts, and expiring after `unique(for: …)` seconds (one day by default) if never released; the daily maintenance task deletes expired locks. A lock that cannot be stored is a queuing failure, never a silent merge.
- `action-scheduler` driver, offered when a plugin bundling Action Scheduler is active and its data store is initialised (never a dependency). The payload travels whole as the action argument, the queue name becomes the group (`pollora` by default), and history is visible in Tools › Scheduled Actions. A payload too long for Action Scheduler's arguments (8,000 characters as JSON) is kept in an option that is not autoloaded and the action carries only its identifier.
- `Async::setAutoDrivers()`: the drivers `auto` tries, in order.
- Payloads record the driver that queued them, the queue, `asUser`, the backoff and the unique lock. These fields are optional, so payloads queued by 1.2.0 still run.

## [1.2.0] - 2026-10-07

### Added

- Asynchronous actions: `add(...)->async()` replaces each callback of the last `add()` call by one that queues it, at the same priority. The handler runs later, with the hook arguments and, if it declares a parameter of that type, an `AsyncContext` (user, site, locale, hook, trigger date, attempt).
  - Options on the returned `PendingAsync`: `delay()` (seconds or `DateInterval`), `via()` (driver), `keepMissing()`, and `except()` to keep some hooks synchronous; `async(except: ...)` too. A hook in `except` that the registration does not have throws in debug mode and is reported otherwise.
  - The handler travels by name (`CallableDescriptor`), its arguments in normalized form (`ArgumentNormalizer`: WordPress objects by reference, enums, dates, `JsonSerializable`; any other object rejected), all in a versioned JSON `AsyncPayload`. A closure or an anonymous class is rejected at registration.
  - At execution the site and locale of the original request are restored. A handler that fires its own hook does not queue itself again. A failure is reported and announced through the `pollora/async/failed` action, and never stops other queued handlers.
  - When queuing fails (an argument that cannot travel, an unavailable driver), debug mode (`WP_DEBUG`, or `Async::setDebug()`) throws; otherwise the incident is reported and the handler runs in place.
- `Async` entry point: `extend()` to register a driver, `driver()`, `reference()` to carry more object kinds, `reportUsing()` to route incidents (PHP error log by default), `setDebug()`. The internal hook `pollora/async/run` is listened to as soon as an Action service exists. `AsyncDriver` and `ObjectReference` contracts.
- `wp-cron` driver, the default: the payload is stored in an option of its own, not autoloaded (`pollora_async_{id}`), and the single event carries only its identifier, so the autoloaded `cron` option stays small. The payload is deleted when its execution starts; an event that fires twice runs once. A daily task (`pollora/async/recover`, scheduled the first time the driver queues) schedules again the payloads whose event was lost, for example after the `cron` option was reset, once they are an hour old; an unreadable stored payload is deleted. Each recovery is reported.
- `sync` driver: runs the handler in the request, after the same JSON round trip.
- Default driver, from the first of: `Async::setDefaultDriver()`, the `POLLORA_ASYNC_DRIVER` constant, the `pollora/hook/async_driver` filter, `wp-cron`. `auto` resolves to `wp-cron` for now.

### Changed

- PHP 8.3 is now the minimum version.
- **The `Action` contract declares `async()`.** A class of your own implementing `Pollora\Hook\Domain\Contract\Action` must add `public function async(string|array $except = []): PendingAsync`. The adapters of the package already do.
- `Pollora\Hook\Action::add()` and `Pollora\Hook\Filter::add()` return the adapter instead of nothing, so `Action::add(...)->async()` chains.
- `remove()` and `exists()` find an asynchronous handler by the callback it was registered with.

### Fixed

- A `[ClassName::class, 'method']` callback naming an instance method is now instantiated at registration, through the callback resolver when one is set, as a class name is. WordPress used to receive it as a static call and threw a `TypeError` when the hook fired. Static methods and classes not loaded yet are unchanged.
- `remove()` and `exists()` accept that same `[ClassName::class, 'method']` form and find the instance it was registered as. `exists()` now accepts a non-callable array or string as its callback.

[Unreleased]: https://github.com/Pollora/hook/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/Pollora/hook/compare/v1.1.1...v1.2.0
