<?php

declare(strict_types=1);

use Pollora\Hook\Async\Async;
use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\PayloadStore;

/*
 * The Action Scheduler driver in a real WordPress, with the Action Scheduler
 * plugin active. Its queue runs in a separate process.
 */

require_once __DIR__.'/Fixtures/async-actions.php';

beforeEach(function (): void {
    if (! function_exists('as_enqueue_async_action')) {
        $this->markTestSkipped('The Action Scheduler plugin is not active: wp plugin install action-scheduler --activate');
    }

    global $wpdb;

    as_unschedule_all_actions(Async::HOOK);
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(PayloadStore::OPTION_PREFIX).'%'));
    delete_option(PolloraHookFixture::RUNS);
    delete_option(PolloraHookFixture::FAILURES);
    update_option(PolloraHookFixture::DRIVER, 'action-scheduler', false);
    wp_unschedule_hook(Async::HOOK);
    wp_cache_flush();
});

afterEach(function (): void {
    delete_option(PolloraHookFixture::DRIVER);
});

/**
 * Run the Action Scheduler queue in a separate WordPress process.
 */
function runActionScheduler(): void
{
    $script = tempnam(sys_get_temp_dir(), 'pollora-hook').'.php';
    file_put_contents($script, sprintf("<?php\ndefine('DISABLE_WP_CRON', true);\nrequire %s;\nActionScheduler::runner()->run('WP CLI');\n", var_export(ABSPATH.'wp-load.php', true)));

    try {
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1');
        // Incidents are reported to the PHP error log, so only PHP errors fail here
        expect($output)->not->toMatch('/(Fatal|Warning|Deprecated|Notice):/');
    } finally {
        unlink($script);
        wp_cache_flush();
    }
}

/**
 * @return list<array{group: string, args: array<int, mixed>}>
 */
function pendingActions(): array
{
    $actions = [];

    foreach ((array) as_get_scheduled_actions(['hook' => Async::HOOK, 'status' => 'pending', 'per_page' => -1]) as $action) {
        $actions[] = ['group' => $action->get_group(), 'args' => $action->get_args()];
    }

    return $actions;
}

function fixtureRunsOf(string $key): array
{
    return array_values(array_filter(get_option(PolloraHookFixture::RUNS, []), fn (array $run): bool => $run['key'] === $key));
}

it('is chosen by auto, carries the payload whole and runs through the Action Scheduler queue', function (): void {
    update_option(PolloraHookFixture::DRIVER, 'auto', false);
    $postId = wp_insert_post(['post_title' => 'Queued', 'post_status' => 'publish']);

    do_action('pollora_fixture_event', $postId, get_post($postId));

    $actions = pendingActions();
    expect($actions)->toHaveCount(1)
        ->and($actions[0]['group'])->toBe('pollora')
        ->and(AsyncPayload::fromJson($actions[0]['args'][0])->driver)->toBe('action-scheduler')
        ->and(_get_cron_array())->not->toHaveKey('pollora/async/run');

    runActionScheduler();

    expect(fixtureRunsOf('event'))->toHaveCount(1)
        ->and(fixtureRunsOf('event')[0]['title'])->toBe('Queued')
        ->and(pendingActions())->toBe([]);

    wp_delete_post($postId, true);
});

it('uses the queue name as the group', function (): void {
    $postId = wp_insert_post(['post_title' => 'Grouped', 'post_status' => 'publish']);

    do_action('pollora_fixture_queued', $postId, get_post($postId));

    expect(pendingActions()[0]['group'])->toBe('integrations');

    wp_delete_post($postId, true);
});

it('keeps a payload too long for Action Scheduler apart, then runs it once', function (): void {
    do_action('pollora_fixture_large', 5);

    [$id] = pendingActions()[0]['args'];
    expect(PayloadStore::handles($id))->toBeTrue()
        ->and(get_option(PayloadStore::OPTION_PREFIX.$id))->toBeString();

    runActionScheduler();
    runActionScheduler();

    expect(fixtureRunsOf('large'))->toBe([['key' => 'large', 'id' => 5, 'length' => 9000]])
        ->and(get_option(PayloadStore::OPTION_PREFIX.$id))->toBeFalse();
});

it('retries a failing handler through Action Scheduler', function (): void {
    update_option(PolloraHookFixture::FAILURES, 1, false);

    do_action('pollora_fixture_flaky', 1);
    runActionScheduler();
    runActionScheduler();

    expect(array_column(fixtureRunsOf('flaky'), 'attempt'))->toBe([1, 2])
        ->and(fixtureRunsOf('failed'))->toBe([])
        ->and(pendingActions())->toBe([]);
});
