<?php

declare(strict_types=1);

namespace Pollora\Hook;

use Pollora\Hook\Adapter\Out\WordPress\Action as WordPressAction;

/**
 * Convenience entry point for WordPress Action hooks (standalone usage).
 *
 * When the Pollora framework is available, prefer the Laravel facade
 * `Pollora\Support\Facades\Action` which provides container-resolved
 * singleton with dependency injection support.
 *
 * @see WordPressAction The underlying WordPress adapter.
 */
class Action extends WordPressAction
{
    public function __construct()
    {
        if (class_exists(\Pollora\Support\Facades\Action::class)) {
            trigger_error(
                'Using Pollora\Hook\Action directly is not recommended when the Pollora framework is available. '
                .'Use the Pollora\Support\Facades\Action facade instead for DI container support.',
                E_USER_NOTICE
            );
        }
    }
}
