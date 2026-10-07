<?php

declare(strict_types=1);

use Pollora\Hook\Async\ArgumentNormalizer;
use Pollora\Hook\Async\Contracts\ObjectReference;
use Pollora\Hook\Async\Exceptions\InvalidPayload;
use Pollora\Hook\Async\Exceptions\MissingReferencedObject;
use Pollora\Hook\Async\Exceptions\UnsupportedArgument;
use Pollora\Hook\Tests\Fixtures\Async\OrderStatus;
use Pollora\Hook\Tests\Fixtures\Async\Size;

beforeEach(function (): void {
    $GLOBALS['wp_objects'] = [];
    $this->normalizer = new ArgumentNormalizer;
    $this->roundTrip = fn (array $arguments, bool $keepMissing = false): array => $this->normalizer->denormalize(
        json_decode(json_encode($this->normalizer->normalize($arguments), JSON_PRESERVE_ZERO_FRACTION), true),
        $keepMissing,
    );
});

it('keeps scalars and arrays as they are', function (): void {
    $arguments = [42, 'draft', true, null, 1.5, 2.0, ['ids' => [1, 2], 'nested' => ['a' => 'b']]];

    expect(($this->roundTrip)($arguments))->toBe($arguments);
});

it('does not mistake a plain array with an @type key for an encoded value', function (): void {
    $arguments = [['@type' => 'ref', 'kind' => 'wp']];

    expect(($this->roundTrip)($arguments))->toBe($arguments);
});

it('carries WordPress objects by reference and reloads them', function (): void {
    $post = new WP_Post(12);
    $GLOBALS['wp_objects'] = [
        'post' => [12 => new WP_Post(12, 'trash')],
        'term' => [3 => new WP_Term(3)],
        'user' => [7 => new WP_User(7)],
        'comment' => [9 => new WP_Comment('9')],
    ];

    $normalized = $this->normalizer->normalize([$post, new WP_Term(3), new WP_User(7), new WP_Comment('9')]);
    $rebuilt = ($this->roundTrip)([$post, new WP_Term(3), new WP_User(7), new WP_Comment('9')]);

    expect($normalized[0])->toBe(['@type' => 'ref', 'kind' => 'wp', 'ref' => ['type' => 'post', 'id' => 12]])
        ->and($rebuilt[0])->toBe($GLOBALS['wp_objects']['post'][12])
        ->and($rebuilt[0]->post_status)->toBe('trash')
        ->and($rebuilt[1])->toBe($GLOBALS['wp_objects']['term'][3])
        ->and($rebuilt[2])->toBe($GLOBALS['wp_objects']['user'][7])
        ->and($rebuilt[3])->toBe($GLOBALS['wp_objects']['comment'][9]);
});

it('reports a referenced object deleted in the meantime', function (): void {
    expect(fn () => ($this->roundTrip)([new WP_Post(12)]))
        ->toThrow(MissingReferencedObject::class, 'no longer exists');
});

it('puts null in place of a deleted object when asked to keep going', function (): void {
    expect(($this->roundTrip)([new WP_Post(12), 'kept'], true))->toBe([null, 'kept']);
});

it('rebuilds enums', function (): void {
    expect(($this->roundTrip)([OrderStatus::Refunded, Size::Large]))->toBe([OrderStatus::Refunded, Size::Large]);
});

it('rebuilds dates with their class and timezone', function (): void {
    $immutable = new DateTimeImmutable('2026-10-07 14:30:15.123456', new DateTimeZone('Europe/Paris'));
    $mutable = new DateTime('2026-01-02 08:00:00', new DateTimeZone('America/New_York'));

    [$rebuiltImmutable, $rebuiltMutable] = ($this->roundTrip)([$immutable, $mutable]);

    expect($rebuiltImmutable)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($rebuiltImmutable->format('Y-m-d H:i:s.u e'))->toBe('2026-10-07 14:30:15.123456 Europe/Paris')
        ->and($rebuiltMutable)->toBeInstanceOf(DateTime::class)
        ->and($rebuiltMutable->format('Y-m-d H:i:s e'))->toBe('2026-01-02 08:00:00 America/New_York');
});

it('carries a JsonSerializable object as its array', function (): void {
    $money = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['amount' => 1999, 'currency' => 'EUR'];
        }
    };

    expect(($this->roundTrip)([$money]))->toBe([['amount' => 1999, 'currency' => 'EUR']]);
});

it('rejects a value that cannot be carried, naming its position', function (mixed $argument, string $message): void {
    expect(fn () => $this->normalizer->normalize(['ok', ['items' => [$argument]]]))
        ->toThrow(UnsupportedArgument::class, $message);
})->with([
    'plain object' => [new stdClass, 'Argument #2[items][0] cannot be carried to an asynchronous action: stdClass'],
    'closure' => [fn (): null => null, 'Closure is not supported'],
    'infinite float' => [INF, 'float is not supported'],
]);

it('rejects a resource', function (): void {
    $resource = fopen('php://memory', 'r');

    expect(fn () => $this->normalizer->normalize([$resource]))->toThrow(UnsupportedArgument::class, 'Argument #1');
});

it('uses a registered object reference before the built-in ones', function (): void {
    $reference = new class implements ObjectReference
    {
        public function name(): string
        {
            return 'model';
        }

        public function supports(object $object): bool
        {
            return $object instanceof ArrayObject;
        }

        public function reference(object $object): array
        {
            return ['key' => $object['key']];
        }

        public function resolve(array $reference): object
        {
            return new ArrayObject(['key' => $reference['key'], 'reloaded' => true]);
        }
    };
    $this->normalizer->extend($reference);

    $rebuilt = ($this->roundTrip)([new ArrayObject(['key' => 5])]);

    expect($rebuilt[0]->getArrayCopy())->toBe(['key' => 5, 'reloaded' => true]);
});

it('refuses an encoded value it cannot rebuild', function (array $encoded, string $message): void {
    expect(fn () => $this->normalizer->denormalize([$encoded]))->toThrow(InvalidPayload::class, $message);
})->with([
    'unknown type' => [['@type' => 'resource'], "unknown encoded type 'resource'"],
    'removed enum' => [['@type' => 'enum', 'class' => 'App\Removed', 'value' => 'x'], "the enum 'App\Removed' no longer exists"],
    'unregistered reference' => [['@type' => 'ref', 'kind' => 'model', 'ref' => ['key' => 1]], "no object reference named 'model'"],
]);
