<?php

declare(strict_types=1);

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\Drivers\WpCronDriver;

/*
 * Asynchronous actions in a real WordPress, without the framework. Queued
 * handlers run in a separate process, through wp-cron.php, as a system cron runs it.
 */

require_once __DIR__.'/Fixtures/async-actions.php';

beforeEach(function (): void {
    global $wpdb;

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like(WpCronDriver::OPTION_PREFIX).'%',
    ));
    delete_option(PolloraHookFixture::RUNS);
    wp_unschedule_hook(Async::HOOK);
    wp_unschedule_hook(WpCronDriver::RECOVERY_HOOK);
    wp_set_current_user(0);
    wp_cache_flush();

    $this->postId = wp_insert_post(['post_title' => 'Before', 'post_status' => 'publish']);
});

afterEach(function (): void {
    wp_delete_post($this->postId, true);
});

/**
 * Run the due WP-Cron events in a separate process, then forget what this process cached.
 */
function runWpCron(): string
{
    delete_transient('doing_cron');

    $output = (string) shell_exec(sprintf('cd %s && %s wp-cron.php 2>&1', escapeshellarg(ABSPATH), escapeshellarg(PHP_BINARY)));
    wp_cache_flush();

    return $output;
}

/**
 * Run PHP in a separate WordPress process, after $before and before WordPress loads.
 */
function runInWordPress(string $before, string $code): string
{
    $script = tempnam(sys_get_temp_dir(), 'pollora-hook').'.php';
    file_put_contents($script, sprintf("<?php\n%s\ndefine('DISABLE_WP_CRON', true);\nrequire %s;\n%s\n", $before, var_export(ABSPATH.'wp-load.php', true), $code));

    try {
        return (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1');
    } finally {
        unlink($script);
        wp_cache_flush();
    }
}

/**
 * The scheduled events that run a queued handler.
 *
 * @return list<array{timestamp: int, args: array<int, mixed>}>
 */
function scheduledExecutions(): array
{
    $events = [];

    foreach (_get_cron_array() as $timestamp => $hooks) {
        foreach ($hooks[Async::HOOK] ?? [] as $event) {
            $events[] = ['timestamp' => (int) $timestamp, 'args' => $event['args']];
        }
    }

    return $events;
}

/**
 * @return list<array<string, mixed>>
 */
function fixtureRuns(): array
{
    return get_option(PolloraHookFixture::RUNS, []);
}

it('runs an asynchronous action through WP-Cron, in a WordPress without the framework', function (): void {
    global $wpdb;

    wp_set_current_user(1);
    do_action('pollora_fixture_event', $this->postId, get_post($this->postId));
    wp_set_current_user(0);

    $events = scheduledExecutions();
    expect(fixtureRuns())->toBe([])
        ->and($events)->toHaveCount(1);

    [$id] = $events[0]['args'];
    $stored = $wpdb->get_row($wpdb->prepare(
        "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
        WpCronDriver::OPTION_PREFIX.$id,
    ));

    expect($events[0]['args'])->toBe([$id])
        ->and($stored->autoload)->toBeIn(['off', 'no'])
        ->and(json_decode($stored->option_value, true)['arguments'][0])->toBe($this->postId);

    wp_update_post(['ID' => $this->postId, 'post_title' => 'After']);
    runWpCron();

    expect(fixtureRuns())->toBe([[
        'key' => 'event',
        'postId' => $this->postId,
        'title' => 'After',
        'userId' => 1,
        'attempt' => 1,
        'cron' => true,
    ]])
        ->and(scheduledExecutions())->toBe([])
        ->and(get_option(WpCronDriver::OPTION_PREFIX.$id))->toBeFalse();
});

it('waits for the delay before running', function (): void {
    do_action('pollora_fixture_delayed', $this->postId, get_post($this->postId));

    runWpCron();

    expect(fixtureRuns())->toBe([])
        ->and(scheduledExecutions())->toHaveCount(1)
        ->and(scheduledExecutions()[0]['timestamp'])->toBeGreaterThan(time() + 3500);
});

it('announces a failing handler and still runs the others of the same cron run', function (): void {
    do_action('pollora_fixture_fails', 1);
    do_action('pollora_fixture_event', $this->postId, get_post($this->postId));

    runWpCron();

    $runs = fixtureRuns();
    usort($runs, fn (array $a, array $b): int => $a['key'] <=> $b['key']);

    expect(array_column($runs, 'key'))->toBe(['event', 'failed'])
        ->and($runs[1]['hook'])->toBe('pollora_fixture_fails')
        ->and($runs[1]['message'])->toBe('Fixture failure')
        ->and(scheduledExecutions())->toBe([]);
});

it('does not queue a handler again when it fires its own hook', function (): void {
    do_action('pollora_fixture_loop', 7);

    runWpCron();

    expect(fixtureRuns())->toBe([['key' => 'loop', 'id' => 7]])
        ->and(scheduledExecutions())->toBe([]);
});

it('schedules again, then runs once, a payload whose event was lost', function (): void {
    do_action('pollora_fixture_event', $this->postId, get_post($this->postId));
    [$id] = scheduledExecutions()[0]['args'];

    expect(wp_next_scheduled(WpCronDriver::RECOVERY_HOOK))->not->toBeFalse();

    // The event is lost, an hour after the trigger
    wp_unschedule_hook(Async::HOOK);
    $option = WpCronDriver::OPTION_PREFIX.$id;
    $payload = json_decode(get_option($option), true);
    $payload['origin']['dispatchedAt'] = gmdate(DATE_ATOM, time() - 2 * WpCronDriver::RECOVERY_GRACE);
    update_option($option, json_encode($payload));

    // The daily recovery task comes due
    wp_unschedule_hook(WpCronDriver::RECOVERY_HOOK);
    wp_schedule_single_event(time() - 1, WpCronDriver::RECOVERY_HOOK);

    runWpCron();

    expect(fixtureRuns())->toBe([])
        ->and(scheduledExecutions())->toHaveCount(1)
        ->and(scheduledExecutions()[0]['args'])->toBe([$id]);

    runWpCron();
    runWpCron();

    expect(array_column(fixtureRuns(), 'key'))->toBe(['event'])
        ->and(get_option($option))->toBeFalse();
});

it('uses the driver set by the POLLORA_ASYNC_DRIVER constant', function (): void {
    $output = runInWordPress(
        "define('POLLORA_ASYNC_DRIVER', 'sync');",
        sprintf('do_action("pollora_fixture_event", %1$d, get_post(%1$d));', $this->postId),
    );

    expect($output)->toBe('')
        ->and(fixtureRuns())->toHaveCount(1)
        ->and(fixtureRuns()[0]['cron'])->toBeFalse()
        ->and(scheduledExecutions())->toBe([]);
});

it('runs a closure, signed with the WordPress salts, through WP-Cron', function (): void {
    do_action('pollora_fixture_closure', 3);

    [$id] = scheduledExecutions()[0]['args'];
    expect(json_decode(get_option(WpCronDriver::OPTION_PREFIX.$id), true)['handler'])->toStartWith('closure:');

    runWpCron();

    expect(fixtureRuns())->toBe([['key' => 'closure', 'id' => 3, 'cron' => true]]);
});

it('refuses to run a closure whose signature does not match', function (): void {
    do_action('pollora_fixture_closure', 3);
    [$id] = scheduledExecutions()[0]['args'];
    $option = WpCronDriver::OPTION_PREFIX.$id;
    $payload = json_decode(get_option($option), true);
    $payload['handler'] = preg_replace('/^closure:[0-9a-f]{64}:/', 'closure:'.str_repeat('0', 64).':', $payload['handler']);
    update_option($option, json_encode($payload));

    runWpCron();

    $runs = fixtureRuns();
    expect(array_column($runs, 'key'))->toBe(['failed'])
        ->and($runs[0]['message'])->toContain('signature of a queued closure is invalid');
});
