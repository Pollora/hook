<?php

/**
 * Asynchronous actions declared the way a plain WordPress plugin would, without the framework.
 *
 * Each handler records what it received in the 'pollora_hook_fixture_runs' option.
 */

declare(strict_types=1);

use Pollora\Hook\Action;
use Pollora\Hook\Async\AsyncContext;
use Pollora\Hook\Async\AsyncPayload;

final class PolloraHookFixture
{
    public const string RUNS = 'pollora_hook_fixture_runs';

    /**
     * @param  array<string, mixed>  $data
     */
    public static function record(string $key, array $data = []): void
    {
        wp_cache_delete(self::RUNS, 'options');
        $runs = get_option(self::RUNS, []);
        $runs[] = ['key' => $key, ...$data];
        update_option(self::RUNS, $runs, false);
    }

    public function event(int $postId, WP_Post $post, AsyncContext $context): void
    {
        self::record('event', [
            'postId' => $postId,
            'title' => $post->post_title,
            'userId' => $context->userId,
            'attempt' => $context->attempt,
            'cron' => defined('DOING_CRON') && DOING_CRON,
        ]);
    }

    public function fails(int $id): void
    {
        throw new RuntimeException('Fixture failure');
    }

    public function loop(int $id): void
    {
        self::record('loop', ['id' => $id]);
        do_action('pollora_fixture_loop', $id);
    }
}

Action::add('pollora_fixture_event', [PolloraHookFixture::class, 'event'])->async();
Action::add('pollora_fixture_delayed', [PolloraHookFixture::class, 'event'])->async()->delay(3600);
Action::add('pollora_fixture_fails', [PolloraHookFixture::class, 'fails'])->async();
Action::add('pollora_fixture_loop', [PolloraHookFixture::class, 'loop'])->async();

add_action('pollora/async/failed', function (AsyncPayload $payload, Throwable $throwable): void {
    PolloraHookFixture::record('failed', ['hook' => $payload->hook, 'message' => $throwable->getMessage()]);
}, 10, 2);
