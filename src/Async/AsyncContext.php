<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

/**
 * What an asynchronous handler knows about the request that fired its hook.
 *
 * A handler receives it by declaring a parameter of this type, anywhere in its signature.
 */
final readonly class AsyncContext
{
    /**
     * @param  int  $userId  User at trigger time, 0 when nobody was logged in
     * @param  int  $blogId  Site at trigger time
     * @param  string  $locale  Locale at trigger time
     * @param  string  $hook  Hook that fired
     * @param  \DateTimeImmutable  $dispatchedAt  Trigger date
     * @param  int  $attempt  Attempt number, from 1
     * @param  array<string, mixed>  $captured  Values recorded at trigger time
     */
    public function __construct(
        public int $userId,
        public int $blogId,
        public string $locale,
        public string $hook,
        public \DateTimeImmutable $dispatchedAt,
        public int $attempt = 1,
        private array $captured = [],
    ) {}

    /**
     * Read a value recorded at trigger time.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->captured) ? $this->captured[$key] : $default;
    }

    /**
     * Whether a value was recorded at trigger time.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->captured);
    }
}
