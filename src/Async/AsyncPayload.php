<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

use Pollora\Hook\Async\Exceptions\InvalidPayload;

/**
 * Everything a driver stores to run a handler later, as JSON.
 *
 * The handler travels as a descriptor and the arguments in normalized form,
 * so reading a payload back never unserializes a PHP object.
 *
 * @phpstan-type Origin array{userId: int, blogId: int, locale: string, dispatchedAt: string}
 */
final readonly class AsyncPayload
{
    public const int VERSION = 1;

    /**
     * @param  string  $id  Unique identifier: two queued executions are never identical
     * @param  string  $hook  Hook that fired
     * @param  string  $handler  Handler descriptor, see CallableDescriptor
     * @param  int  $priority  Priority of the registration
     * @param  array<int|string, mixed>  $arguments  Normalized hook arguments, see ArgumentNormalizer
     * @param  array{userId: int, blogId: int, locale: string, dispatchedAt: string}  $origin  Request that fired the hook
     * @param  array<string, mixed>  $captured  Normalized values recorded at trigger time
     * @param  int  $attempt  Attempt number, from 1
     * @param  int  $tries  Number of attempts allowed
     * @param  bool  $keepMissing  Run with null in place of a referenced object that no longer exists
     * @param  string|null  $driver  Driver that queued the payload, used again to retry it
     * @param  string|null  $queue  Queue name: the group with Action Scheduler, ignored by WP-Cron
     * @param  bool  $asUser  Run as the user who fired the hook
     * @param  list<int>  $backoff  Seconds before each retry; the last value repeats
     */
    public function __construct(
        public string $id,
        public string $hook,
        public string $handler,
        public int $priority,
        public array $arguments,
        public array $origin,
        public array $captured = [],
        public int $attempt = 1,
        public int $tries = 1,
        public bool $keepMissing = false,
        public ?string $driver = null,
        public ?string $queue = null,
        public bool $asUser = false,
        public array $backoff = [],
    ) {}

    /**
     * Generate a payload identifier (UUID v4).
     */
    public static function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Read a payload back from its JSON form.
     *
     * @throws InvalidPayload When the JSON is invalid, of another version, or a field is missing
     */
    public static function fromJson(string $json): self
    {
        if (! json_validate($json)) {
            throw InvalidPayload::notJson();
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw InvalidPayload::notJson();
        }

        if (($data['v'] ?? null) !== self::VERSION) {
            throw InvalidPayload::unsupportedVersion($data['v'] ?? null);
        }

        $origin = self::field($data, 'origin', 'is_array');
        foreach (['userId' => 'is_int', 'blogId' => 'is_int', 'locale' => 'is_string', 'dispatchedAt' => 'is_string'] as $key => $check) {
            if (! array_key_exists($key, $origin) || ! $check($origin[$key])) {
                throw InvalidPayload::invalidField('origin.'.$key);
            }
        }

        return new self(
            id: self::field($data, 'id', 'is_string'),
            hook: self::field($data, 'hook', 'is_string'),
            handler: self::field($data, 'handler', 'is_string'),
            priority: self::field($data, 'priority', 'is_int'),
            arguments: self::field($data, 'arguments', 'is_array'),
            origin: $origin,
            captured: self::field($data, 'captured', 'is_array'),
            attempt: self::field($data, 'attempt', 'is_int'),
            tries: self::field($data, 'tries', 'is_int'),
            keepMissing: self::field($data, 'keepMissing', 'is_bool'),
            // Fields added after 1.2.0: optional, so payloads queued by 1.2.0 still run
            driver: self::optionalField($data, 'driver', 'is_string', null),
            queue: self::optionalField($data, 'queue', 'is_string', null),
            asUser: self::optionalField($data, 'asUser', 'is_bool', false),
            backoff: self::backoffField($data),
        );
    }

    /**
     * Encode the payload as JSON.
     *
     * @throws \JsonException When a value is not representable, which normalized values always are
     */
    public function toJson(): string
    {
        return json_encode([
            'v' => self::VERSION,
            'id' => $this->id,
            'hook' => $this->hook,
            'handler' => $this->handler,
            'priority' => $this->priority,
            'arguments' => $this->arguments,
            'origin' => $this->origin,
            'captured' => $this->captured,
            'attempt' => $this->attempt,
            'tries' => $this->tries,
            'keepMissing' => $this->keepMissing,
            'driver' => $this->driver,
            'queue' => $this->queue,
            'asUser' => $this->asUser,
            'backoff' => $this->backoff,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * The same payload for another attempt.
     */
    public function withAttempt(int $attempt): self
    {
        return new self(...[...get_object_vars($this), 'attempt' => $attempt]);
    }

    /**
     * Seconds to wait before retrying after the given failed attempt.
     */
    public function retryDelay(int $failedAttempt): int
    {
        if ($this->backoff === []) {
            return 0;
        }

        return $this->backoff[min(max($failedAttempt, 1), count($this->backoff)) - 1];
    }

    /**
     * The context handed to the handler, with the captured values rebuilt.
     *
     * @param  array<string, mixed>  $captured  Denormalized captured values
     */
    public function context(array $captured = []): AsyncContext
    {
        return new AsyncContext(
            userId: $this->origin['userId'],
            blogId: $this->origin['blogId'],
            locale: $this->origin['locale'],
            hook: $this->hook,
            dispatchedAt: new \DateTimeImmutable($this->origin['dispatchedAt']),
            attempt: $this->attempt,
            captured: $captured,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(mixed): bool  $check
     */
    private static function optionalField(array $data, string $field, callable $check, mixed $default): mixed
    {
        if (! array_key_exists($field, $data) || $data[$field] === null) {
            return $default;
        }

        return self::field($data, $field, $check);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function backoffField(array $data): array
    {
        $backoff = self::optionalField($data, 'backoff', 'is_array', []);

        if (! array_is_list($backoff) || array_filter($backoff, fn (mixed $delay): bool => ! is_int($delay) || $delay < 0) !== []) {
            throw InvalidPayload::invalidField('backoff');
        }

        return $backoff;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(mixed): bool  $check
     */
    private static function field(array $data, string $field, callable $check): mixed
    {
        if (! array_key_exists($field, $data) || ! $check($data[$field])) {
            throw InvalidPayload::invalidField($field);
        }

        return $data[$field];
    }
}
