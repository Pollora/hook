<?php

declare(strict_types=1);

use Pollora\Hook\Async\AsyncPayload;
use Pollora\Hook\Async\Exceptions\InvalidPayload;

function makeAsyncPayload(array $overrides = []): AsyncPayload
{
    return new AsyncPayload(...array_merge([
        'id' => AsyncPayload::newId(),
        'hook' => 'save_post',
        'handler' => 'App\Hooks\Sync@handle',
        'priority' => 10,
        'arguments' => [42, ['@type' => 'ref', 'kind' => 'wp', 'ref' => ['type' => 'post', 'id' => 42]], true],
        'origin' => ['userId' => 3, 'blogId' => 1, 'locale' => 'fr_FR', 'dispatchedAt' => '2026-10-07T14:30:00+00:00'],
        'captured' => ['status' => 'publish'],
    ], $overrides));
}

it('generates a different UUID v4 for every payload', function (): void {
    $ids = array_map(fn (): string => AsyncPayload::newId(), range(1, 50));

    expect(array_unique($ids))->toHaveCount(50)
        ->and($ids[0])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('survives a JSON round trip unchanged', function (): void {
    $payload = makeAsyncPayload(['attempt' => 2, 'tries' => 3, 'keepMissing' => true]);

    expect(AsyncPayload::fromJson($payload->toJson()))->toEqual($payload);
});

it('stores the format version', function (): void {
    expect(json_decode(makeAsyncPayload()->toJson(), true)['v'])->toBe(AsyncPayload::VERSION);
});

it('copies itself for another attempt', function (): void {
    $payload = makeAsyncPayload();
    $next = $payload->withAttempt(2);

    expect($next->attempt)->toBe(2)
        ->and($payload->attempt)->toBe(1)
        ->and($next->id)->toBe($payload->id);
});

it('builds the context handed to the handler', function (): void {
    $context = makeAsyncPayload(['attempt' => 2])->context(['status' => 'publish']);

    expect($context->userId)->toBe(3)
        ->and($context->blogId)->toBe(1)
        ->and($context->locale)->toBe('fr_FR')
        ->and($context->hook)->toBe('save_post')
        ->and($context->dispatchedAt->format(DATE_ATOM))->toBe('2026-10-07T14:30:00+00:00')
        ->and($context->attempt)->toBe(2)
        ->and($context->get('status'))->toBe('publish')
        ->and($context->get('missing', 'default'))->toBe('default')
        ->and($context->has('status'))->toBeTrue()
        ->and($context->has('missing'))->toBeFalse();
});

it('refuses a payload it cannot read', function (string $json, string $message): void {
    expect(fn () => AsyncPayload::fromJson($json))->toThrow(InvalidPayload::class, $message);
})->with([
    'not JSON' => ['{"v":1,', 'not valid JSON'],
    'scalar' => ['"payload"', 'not valid JSON'],
    'other version' => ['{"v":2}', 'version 2 is not supported'],
    'no version' => ['{}', 'version null is not supported'],
]);

it('refuses a payload with a missing or mistyped field', function (string $field, mixed $value, string $reported): void {
    $data = json_decode(makeAsyncPayload()->toJson(), true);
    if (str_contains($field, '.')) {
        [$parent, $child] = explode('.', $field);
        $data[$parent][$child] = $value;
    } else {
        $data[$field] = $value;
    }

    expect(fn () => AsyncPayload::fromJson(json_encode($data)))->toThrow(InvalidPayload::class, "'{$reported}'");
})->with([
    ['handler', null, 'handler'],
    ['priority', '10', 'priority'],
    ['arguments', 'x', 'arguments'],
    ['keepMissing', 1, 'keepMissing'],
    ['origin.userId', '3', 'origin.userId'],
    ['origin.locale', null, 'origin.locale'],
]);
