# Pollora Hook

A modern PHP package for WordPress hook (action/filter) management with callback resolution and reflection caching.

## Installation

```bash
composer require pollora/hook
```

## Quick Start

```php
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Adapter\Out\WordPress\Filter;

$action = new Action;
$filter = new Filter;

// Register action
$action->add('init', function () {
    // runs on WordPress init
});

// Register filter
$filter->add('the_content', function (string $content): string {
    return $content . '<p>Appended!</p>';
});

// Execute action
$action->do('my_custom_action', $arg1, $arg2);

// Apply filter
$filtered = $filter->apply('my_filter', $value);

// Remove hook
$action->remove('init', $callback);
```

## Class-based Callbacks

```php
// Class with method matching hook name (StudlyCase convention)
$action->add('wp_loaded', MyInitializer::class);
// Resolves to [new MyInitializer, 'wpLoaded']

// With dependency injection
$action->setCallbackResolver($myResolver);
$action->add('wp_loaded', MyInitializer::class);
// Resolves via $myResolver->resolve(MyInitializer::class)
```

## Documentation

See [docs/hooks.md](docs/hooks.md) for full documentation.

## Testing

```bash
composer test
```

## License

GPL-2.0-or-later