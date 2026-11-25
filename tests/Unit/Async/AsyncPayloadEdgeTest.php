<?php

declare(strict_types=1);

use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Exceptions\InvalidPayload;

/**
 * @param  array<string, mixed>  $overrides
 */
function edgePayload(array $overrides = []): AsyncPayload
{
    return new AsyncPayload(...array_merge([
        'id' => AsyncPayload::newId(),
        'hook' => 'save_post',
        'handler' => 'App\Hooks\Sync@handle',
        'priority' => 10,
        'arguments' => [42],
        'origin' => ['userId' => 3, 'blogId' => 1, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T14:30:00+00:00'],
    ], $overrides));
}

describe('Identifiers', function (): void {
    it('sets the version and variant bits of a UUID v4 and leaves the other bits random', function (): void {
        $versionDigits = [];
        $variantFirst = [];
        $variantSecond = [];

        for ($index = 0; $index < 600; $index++) {
            $id = AsyncPayload::newId();
            expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');

            $versionDigits[$id[15]] = true;
            $variantFirst[$id[19]] = true;
            $variantSecond[$id[20]] = true;
        }

        expect(count($versionDigits))->toBe(16)
            ->and(array_keys($variantFirst))->toEqualCanonicalizing(['8', '9', 'a', 'b'])
            ->and(count($variantSecond))->toBe(16);
    });

    it('has the length of a UUID', function (): void {
        expect(strlen(AsyncPayload::newId()))->toBe(36);
    });
});

describe('Reading the origin', function (): void {
    it('refuses an origin missing a field or holding a wrong one', function (string $key, mixed $value, bool $missing): void {
        $data = json_decode(edgePayload()->toJson(), true);

        if ($missing) {
            unset($data['origin'][$key]);
        } else {
            $data['origin'][$key] = $value;
        }

        expect(fn () => AsyncPayload::fromJson(json_encode($data, JSON_THROW_ON_ERROR)))
            ->toThrow(InvalidPayload::class, "'origin.{$key}'");
    })->with([
        'no userId' => ['userId', null, true],
        'no blogId' => ['blogId', null, true],
        'no locale' => ['locale', null, true],
        'no dispatchedAt' => ['dispatchedAt', null, true],
        'userId text' => ['userId', '3', false],
        'blogId text' => ['blogId', '1', false],
        'locale number' => ['locale', 5, false],
        'dispatchedAt number' => ['dispatchedAt', 5, false],
    ]);

    it('names the field in the message', function (): void {
        $data = json_decode(edgePayload()->toJson(), true);
        unset($data['origin']['blogId']);

        expect(fn () => AsyncPayload::fromJson(json_encode($data, JSON_THROW_ON_ERROR)))
            ->toThrow(InvalidPayload::class, "The asynchronous payload field 'origin.blogId' is missing or invalid.");
    });
});

describe('Writing the payload', function (): void {
    it('writes every field, in order', function (): void {
        expect(array_keys(json_decode(edgePayload()->toJson(), true)))->toBe([
            'v', 'id', 'hook', 'handler', 'priority', 'arguments', 'origin', 'captured', 'attempt', 'tries', 'keepMissing', 'driver', 'queue', 'asUser', 'backoff', 'uniqueKey',
        ]);
    });

    it('writes slashes, unicode and round floats as they are', function (): void {
        $json = edgePayload(['arguments' => ['a/b', 'café', 2.0]])->toJson();

        expect($json)->toContain('"a/b"')
            ->and($json)->toContain('"café"')
            ->and($json)->toContain('2.0');
    });

    it('throws when a value cannot be encoded', function (): void {
        expect(fn () => edgePayload(['arguments' => ["\xB1\x31"]])->toJson())->toThrow(JsonException::class);
    });
});

describe('Retry delay', function (): void {
    it('waits the delay of the failed attempt, the last one repeating', function (): void {
        $payload = edgePayload(['backoff' => [10, 60, 300]]);

        expect($payload->retryDelay(1))->toBe(10)
            ->and($payload->retryDelay(2))->toBe(60)
            ->and($payload->retryDelay(3))->toBe(300)
            ->and($payload->retryDelay(4))->toBe(300)
            ->and($payload->retryDelay(0))->toBe(10)
            ->and($payload->retryDelay(-5))->toBe(10);
    });

    it('does not wait without a backoff', function (): void {
        expect(edgePayload()->retryDelay(1))->toBe(0);
    });
});

describe('Backoff validation', function (): void {
    it('accepts a delay of zero', function (): void {
        $data = json_decode(edgePayload()->toJson(), true);
        $data['backoff'] = [0, 5];

        expect(AsyncPayload::fromJson(json_encode($data, JSON_THROW_ON_ERROR))->backoff)->toBe([0, 5]);
    });

    it('refuses a delay of minus one', function (): void {
        $data = json_decode(edgePayload()->toJson(), true);
        $data['backoff'] = [-1];

        expect(fn () => AsyncPayload::fromJson(json_encode($data, JSON_THROW_ON_ERROR)))->toThrow(InvalidPayload::class, "'backoff'");
    });
});
