<?php

declare(strict_types=1);

use Pollora\Hook\Adapter\Out\WordPress\Action;

beforeEach(function (): void {
    $GLOBALS['wp_actions'] = [];
    $GLOBALS['wp_actions_removed'] = [];
    $GLOBALS['wp_actions_done'] = [];
});

if (! function_exists('add_action')) {
    function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $GLOBALS['wp_actions'][] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority, 'args' => $acceptedArgs];
    }
}

if (! function_exists('remove_action')) {
    function remove_action(string $hook, mixed $callback, int $priority = 10): void
    {
        $GLOBALS['wp_actions_removed'][] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        $GLOBALS['wp_actions_done'][] = ['hook' => $hook, 'args' => $args];
    }
}

describe('WordPress Action Adapter', function (): void {
    it('registers action via WordPress add_action', function (): void {
        $action = new Action;
        $callback = fn (): null => null;
        $action->add('init', $callback, 15);

        expect($GLOBALS['wp_actions'])->toHaveCount(1)
            ->and($GLOBALS['wp_actions'][0]['hook'])->toBe('init')
            ->and($GLOBALS['wp_actions'][0]['priority'])->toBe(15);
    });

    it('removes action via WordPress remove_action', function (): void {
        $action = new Action;
        $callback = fn (): null => null;
        $action->add('init', $callback);
        $action->remove('init', $callback);

        expect($GLOBALS['wp_actions_removed'])->toHaveCount(1)
            ->and($GLOBALS['wp_actions_removed'][0]['hook'])->toBe('init');
    });

    it('executes action via WordPress do_action', function (): void {
        $action = new Action;
        $action->do('my_custom_action', 'arg1', 'arg2');

        expect($GLOBALS['wp_actions_done'])->toHaveCount(1)
            ->and($GLOBALS['wp_actions_done'][0]['hook'])->toBe('my_custom_action')
            ->and($GLOBALS['wp_actions_done'][0]['args'])->toBe(['arg1', 'arg2']);
    });
});
