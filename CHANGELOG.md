# Changelog

All notable changes to `pollora/hook` are documented in this file.

## [Unreleased]

### Added

- Asynchronous actions: `add(...)->async()` replaces each callback of the last `add()` call by one that queues it, at the same priority. The handler runs later, with the hook arguments and, if it declares a parameter of that type, an `AsyncContext` (user, site, locale, hook, trigger date, attempt).
  - Options on the returned `PendingAsync`: `delay()` (seconds or `DateInterval`), `via()` (driver), `keepMissing()`, and `except()` to keep some hooks synchronous; `async(except: ...)` too. A hook in `except` that the registration does not have throws in debug mode and is reported otherwise.
  - The handler travels by name (`CallableDescriptor`), its arguments in normalized form (`ArgumentNormalizer`: WordPress objects by reference, enums, dates, `JsonSerializable`; any other object rejected), all in a versioned JSON `AsyncPayload`. A closure or an anonymous class is rejected at registration.
  - At execution the site and locale of the original request are restored. A handler that fires its own hook does not queue itself again. A failure is reported and announced through the `pollora/async/failed` action, and never stops other queued handlers.
  - When queuing fails (an argument that cannot travel, an unavailable driver), debug mode (`WP_DEBUG`, or `Async::setDebug()`) throws; otherwise the incident is reported and the handler runs in place.
- `Async` entry point: `extend()` to register a driver, `driver()`, `reference()` to carry more object kinds, `reportUsing()` to route incidents (PHP error log by default), `setDebug()`. The internal hook `pollora/async/run` is listened to as soon as an Action service exists. `AsyncDriver` and `ObjectReference` contracts, `sync` driver.

### Changed

- PHP 8.3 is now the minimum version.
- **The `Action` contract declares `async()`.** A class of your own implementing `Pollora\Hook\Domain\Contract\Action` must add `public function async(string|array $except = []): PendingAsync`. The adapters of the package already do.
- `Pollora\Hook\Action::add()` and `Pollora\Hook\Filter::add()` return the adapter instead of nothing, so `Action::add(...)->async()` chains.
- `remove()` and `exists()` find an asynchronous handler by the callback it was registered with.

### Fixed

- A `[ClassName::class, 'method']` callback naming an instance method is now instantiated at registration, through the callback resolver when one is set, as a class name is. WordPress used to receive it as a static call and threw a `TypeError` when the hook fired. Static methods and classes not loaded yet are unchanged.
- `remove()` and `exists()` accept that same `[ClassName::class, 'method']` form and find the instance it was registered as. `exists()` now accepts a non-callable array or string as its callback.
