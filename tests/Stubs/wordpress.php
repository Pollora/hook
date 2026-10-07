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

/*
 * WordPress objects, loaded by ID from $GLOBALS['wp_objects'][type][id].
 */

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public function __construct(public int $ID = 0, public string $post_status = 'publish') {}
    }
}

if (! class_exists('WP_Term')) {
    class WP_Term
    {
        public function __construct(public int $term_id = 0) {}
    }
}

if (! class_exists('WP_User')) {
    class WP_User
    {
        public function __construct(public int $ID = 0) {}
    }
}

if (! class_exists('WP_Comment')) {
    class WP_Comment
    {
        public function __construct(public string $comment_ID = '0') {}
    }
}

if (! function_exists('get_post')) {
    function get_post(int $id): ?WP_Post
    {
        return $GLOBALS['wp_objects']['post'][$id] ?? null;
    }
}

if (! function_exists('get_term')) {
    function get_term(int $id): ?WP_Term
    {
        return $GLOBALS['wp_objects']['term'][$id] ?? null;
    }
}

if (! function_exists('get_userdata')) {
    function get_userdata(int $id): WP_User|false
    {
        return $GLOBALS['wp_objects']['user'][$id] ?? false;
    }
}

if (! function_exists('get_comment')) {
    function get_comment(int $id): ?WP_Comment
    {
        return $GLOBALS['wp_objects']['comment'][$id] ?? null;
    }
}
