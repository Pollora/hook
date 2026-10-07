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
        // The internal hook of async actions is kept apart, so tests counting registrations are not affected
        $store = $hook === 'pollora/async/run' ? 'wp_async_listeners' : 'wp_actions';
        $GLOBALS[$store][] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority, 'args' => $acceptedArgs];
    }
}

if (! function_exists('remove_action')) {
    function remove_action(string $hook, mixed $callback, int $priority = 10): void
    {
        $GLOBALS['wp_actions_removed'][] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
        $GLOBALS['wp_actions'] = array_values(array_filter(
            $GLOBALS['wp_actions'] ?? [],
            fn (array $registration): bool => ! ($registration['hook'] === $hook && $registration['callback'] === $callback && $registration['priority'] === $priority),
        ));
    }
}

/**
 * Run the callbacks registered on an action, as do_action() would.
 */
function wp_stub_fire(string $hook, mixed ...$args): void
{
    $registrations = array_filter($GLOBALS['wp_actions'] ?? [], fn (array $registration): bool => $registration['hook'] === $hook);
    usort($registrations, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

    foreach ($registrations as $registration) {
        call_user_func_array($registration['callback'], array_slice($args, 0, $registration['args']));
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        $GLOBALS['wp_actions_done'][] = ['hook' => $hook, 'args' => $args];
    }
}

if (! function_exists('has_action')) {
    function has_action(string $hook, mixed $callback = false): int|bool
    {
        foreach ([...($GLOBALS['wp_async_listeners'] ?? []), ...($GLOBALS['wp_actions'] ?? [])] as $registration) {
            if ($registration['hook'] === $hook && ($callback === false || $registration['callback'] === $callback)) {
                return $callback === false ? true : $registration['priority'];
            }
        }

        return false;
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

/*
 * Request state, read from $GLOBALS['wp_state']: user, blog, locale, multisite.
 * Switches are recorded in $GLOBALS['wp_switches'].
 */

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return $GLOBALS['wp_state']['user'] ?? 0;
    }
}

if (! function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return $GLOBALS['wp_state']['blog'] ?? 1;
    }
}

if (! function_exists('determine_locale')) {
    function determine_locale(): string
    {
        return $GLOBALS['wp_state']['locale'] ?? 'en_US';
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return $GLOBALS['wp_state']['multisite'] ?? false;
    }
}

if (! function_exists('switch_to_blog')) {
    function switch_to_blog(int $blogId): bool
    {
        $GLOBALS['wp_switches'][] = ['blog', $blogId];
        $GLOBALS['wp_state']['blog_stack'][] = $GLOBALS['wp_state']['blog'] ?? 1;
        $GLOBALS['wp_state']['blog'] = $blogId;

        return true;
    }
}

if (! function_exists('restore_current_blog')) {
    function restore_current_blog(): bool
    {
        $GLOBALS['wp_state']['blog'] = array_pop($GLOBALS['wp_state']['blog_stack']);
        $GLOBALS['wp_switches'][] = ['restore_blog', $GLOBALS['wp_state']['blog']];

        return true;
    }
}

if (! function_exists('switch_to_locale')) {
    function switch_to_locale(string $locale): bool
    {
        $GLOBALS['wp_switches'][] = ['locale', $locale];
        $GLOBALS['wp_state']['locale_stack'][] = $GLOBALS['wp_state']['locale'] ?? 'en_US';
        $GLOBALS['wp_state']['locale'] = $locale;

        return true;
    }
}

if (! function_exists('restore_previous_locale')) {
    function restore_previous_locale(): string|false
    {
        $GLOBALS['wp_state']['locale'] = array_pop($GLOBALS['wp_state']['locale_stack']);
        $GLOBALS['wp_switches'][] = ['restore_locale', $GLOBALS['wp_state']['locale']];

        return $GLOBALS['wp_state']['locale'];
    }
}
