<?php

declare(strict_types=1);

namespace Pollora\Hook;

use Pollora\Hook\Adapter\Out\WordPress\Filter as WordPressFilter;

/**
 * Convenience entry point for WordPress Filter hooks (standalone usage).
 *
 * When the Pollora framework is available, prefer the Laravel facade
 * `Pollora\Support\Facades\Filter` which provides container-resolved
 * singleton with dependency injection support.
 *
 * @see WordPressFilter The underlying WordPress adapter.
 */
class Filter extends WordPressFilter
{
    public function __construct()
    {
        if (class_exists(\Pollora\Support\Facades\Filter::class)) {
            trigger_error(
                'Using Pollora\Hook\Filter directly is not recommended when the Pollora framework is available. '
                .'Use the Pollora\Support\Facades\Filter facade instead for DI container support.',
                E_USER_NOTICE
            );
        }
    }
}
