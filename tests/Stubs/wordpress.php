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
        // The internal hooks of async actions are kept apart, so tests counting registrations are not affected
        $store = str_starts_with($hook, 'pollora/async/') ? 'wp_async_listeners' : 'wp_actions';
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
        $registrations = array_filter($GLOBALS['wp_filters'] ?? [], fn (array $registration): bool => $registration['hook'] === $hook);
        usort($registrations, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($registrations as $registration) {
            $value = call_user_func_array($registration['callback'], array_slice([$value, ...$args], 0, $registration['args']));
        }

        return $value;
    }
}

/*
 * WordPress objects, loaded by ID from $GLOBALS['wp_objects'][type][id].
 */

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public function __construct(public int $ID = 0, public string $post_status = 'publish', public string $post_title = '') {}
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

if (! function_exists('wp_set_current_user')) {
    function wp_set_current_user(int $userId): WP_User
    {
        $GLOBALS['wp_switches'][] = ['user', $userId];
        $GLOBALS['wp_state']['user'] = $userId;

        return new WP_User($userId);
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

/*
 * Options and WP-Cron, kept in $GLOBALS['wp_options'] and $GLOBALS['wp_cron_events'].
 * $GLOBALS['wp_fail'] lists the functions that must fail: 'add_option', 'wp_schedule_single_event'.
 */

if (! function_exists('add_option')) {
    function add_option(string $option, mixed $value = '', string $deprecated = '', string|bool|null $autoload = null): bool
    {
        if (in_array('add_option', $GLOBALS['wp_fail'] ?? [], true) || isset($GLOBALS['wp_options'][$option])) {
            return false;
        }

        $GLOBALS['wp_options'][$option] = ['value' => $value, 'autoload' => $autoload];

        return true;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return $GLOBALS['wp_options'][$option]['value'] ?? $default;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        if (! isset($GLOBALS['wp_options'][$option])) {
            return false;
        }

        unset($GLOBALS['wp_options'][$option]);

        return true;
    }
}

if (! function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = [], bool $wpError = false): bool|object
    {
        if (in_array('wp_schedule_single_event', $GLOBALS['wp_fail'] ?? [], true)) {
            return $wpError ? new class
            {
                public function get_error_message(): string
                {
                    return 'A plugin prevented the event from being scheduled.';
                }
            } : false;
        }

        $GLOBALS['wp_cron_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];

        return true;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        $GLOBALS['wp_cron_events'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'recurrence' => $recurrence];

        return true;
    }
}

if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        foreach ($GLOBALS['wp_cron_events'] ?? [] as $event) {
            if ($event['hook'] === $hook && $event['args'] === $args) {
                return $event['timestamp'];
            }
        }

        return false;
    }
}

/**
 * A $wpdb answering the options query of the WP-Cron recovery from $GLOBALS['wp_options'].
 */
function wp_stub_wpdb(): object
{
    return new class
    {
        public string $options = 'wp_options';

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function prepare(string $query, mixed ...$args): array
        {
            return ['query' => $query, 'args' => $args];
        }

        public function get_results(array $prepared, string $output): array
        {
            $prefix = stripslashes(rtrim($prepared['args'][0], '%'));
            $rows = [];

            foreach ($GLOBALS['wp_options'] ?? [] as $name => $option) {
                if (str_starts_with($name, $prefix)) {
                    $rows[] = ['option_name' => $name, 'option_value' => $option['value']];
                }
            }

            return array_slice($rows, 0, $prepared['args'][1]);
        }

        /**
         * Answers the expired-lock query: prefix, time, limit.
         */
        public function get_col(array $prepared): array
        {
            [$like, $now, $limit] = $prepared['args'];
            $prefix = stripslashes(rtrim($like, '%'));
            $names = [];

            foreach ($GLOBALS['wp_options'] ?? [] as $name => $option) {
                if (str_starts_with($name, $prefix) && (int) $option['value'] < $now) {
                    $names[] = $name;
                }
            }

            return array_slice($names, 0, $limit);
        }
    };
}

if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete(int|string $key, string $group = ''): bool
    {
        $GLOBALS['wp_cache_deleted'][] = [$key, $group];

        return true;
    }
}

/*
 * Action Scheduler, available only when $GLOBALS['as_initialized'] is true.
 * Actions are kept in $GLOBALS['as_actions']; $GLOBALS['as_fail'] makes creating one fail.
 */

if (! class_exists('ActionScheduler', false)) {
    class ActionScheduler
    {
        public static function is_initialized(?string $functionName = null): bool
        {
            return $GLOBALS['as_initialized'] ?? false;
        }
    }
}

if (! function_exists('as_enqueue_async_action')) {
    function as_enqueue_async_action(string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int
    {
        return as_schedule_single_action(time(), $hook, $args, $group);
    }
}

if (! function_exists('as_schedule_single_action')) {
    function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int
    {
        if ($GLOBALS['as_fail'] ?? false) {
            throw new InvalidArgumentException('ActionScheduler_Action::$args too long.');
        }

        $GLOBALS['as_actions'][] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group];

        return count($GLOBALS['as_actions']);
    }
}

if (! function_exists('as_has_scheduled_action')) {
    function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool
    {
        foreach ($GLOBALS['as_actions'] ?? [] as $action) {
            if ($action['hook'] === $hook && ($args === null || $action['args'] === $args)) {
                return true;
            }
        }

        return false;
    }
}
