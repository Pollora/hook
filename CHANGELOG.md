# Changelog

All notable changes to `pollora/hook` are documented in this file.

## [Unreleased]

### Added

- Building blocks of asynchronous actions, not wired to `add()` yet: `AsyncPayload` (versioned JSON with a unique ID), `CallableDescriptor` (handler carried by name), `ArgumentNormalizer` (WordPress objects by reference, enums, dates, `JsonSerializable`; any other object rejected), `ObjectReference` contract, `AsyncContext`.

### Changed

- PHP 8.3 is now the minimum version.

### Fixed

- A `[ClassName::class, 'method']` callback naming an instance method is now instantiated at registration, through the callback resolver when one is set, as a class name is. WordPress used to receive it as a static call and threw a `TypeError` when the hook fired. Static methods and classes not loaded yet are unchanged.
- `remove()` and `exists()` accept that same `[ClassName::class, 'method']` form and find the instance it was registered as. `exists()` now accepts a non-callable array or string as its callback.
