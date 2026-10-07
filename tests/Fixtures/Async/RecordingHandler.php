<?php

declare(strict_types=1);

namespace Pollora\Hook\Tests\Fixtures\Async;

use Pollora\Hook\Async\AsyncContext;

/**
 * Records every call it receives in self::$calls.
 */
final class RecordingHandler
{
    /** @var list<array{method: string, arguments: list<mixed>, blog: int|null, locale: string|null}> */
    public static array $calls = [];

    public function __construct(public string $source = 'direct') {}

    public function handle(int $postId, AsyncContext $context): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function contextFirst(AsyncContext $context, int $postId, string $status): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function post(\WP_Post $post): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function maybePost(?\WP_Post $post): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function objects(object $object): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function savePostEvent(int $postId): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function savePostPage(int $postId): void
    {
        $this->record(__FUNCTION__, func_get_args());
    }

    public function fails(int $postId): void
    {
        throw new \RuntimeException('CRM unreachable');
    }

    public function refires(int $postId): void
    {
        $this->record(__FUNCTION__, func_get_args());
        wp_stub_fire('save_post', $postId);
    }

    public function source(): void
    {
        $this->record(__FUNCTION__, [$this->source]);
    }

    /**
     * @param  list<mixed>  $arguments
     */
    private function record(string $method, array $arguments): void
    {
        self::$calls[] = [
            'method' => $method,
            'arguments' => $arguments,
            'blog' => $GLOBALS['wp_state']['blog'] ?? null,
            'locale' => $GLOBALS['wp_state']['locale'] ?? null,
        ];
    }
}
