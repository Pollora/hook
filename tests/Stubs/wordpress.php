<?php

declare(strict_types=1);

/*
 * WordPress functions used by the unit suite.
 *
 * Each stub records its calls in a global so tests can assert on them. Loaded
 * once from tests/bootstrap.php, never by the WordPress integration suite.
 */

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

if (! function_exists('add_filter')) {
    function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $GLOBALS['wp_filters'][] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority, 'args' => $acceptedArgs];
    }
}

if (! function_exists('remove_filter')) {
    function remove_filter(string $hook, mixed $callback, int $priority = 10): void
    {
        $GLOBALS['wp_filters_removed'][] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}
