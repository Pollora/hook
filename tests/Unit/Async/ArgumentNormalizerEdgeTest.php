<?php

declare(strict_types=1);

use Pollora\Hook\Async\ArgumentNormalizer;
use Pollora\Hook\Async\Contracts\ObjectReference;
use Pollora\Hook\Async\Exceptions\InvalidPayload;
use Pollora\Hook\Async\Exceptions\UnsupportedArgument;
use Pollora\Hook\Async\References\WordPressObjectReference;
use Pollora\Hook\Tests\Fixtures\Async\OrderStatus;

enum NormalizerEdgeSuit
{
    case Hearts;
    case Spades;
}

final class NormalizerEdgeDate extends DateTimeImmutable {}

final class NormalizerEdgeMutableDate extends DateTime {}

beforeEach(function (): void {
    $GLOBALS['wp_objects'] = [];
    $this->normalizer = new ArgumentNormalizer;
    $this->roundTrip = fn (array $arguments, bool $keepMissing = false): array => $this->normalizer->denormalize(
        json_decode(json_encode($this->normalizer->normalize($arguments), JSON_THROW_ON_ERROR), true),
        $keepMissing,
    );
});

describe('References of the normalizer', function (): void {
    it('carries only the references it is given', function (): void {
        $normalizer = new ArgumentNormalizer([]);

        expect(fn () => $normalizer->normalize([new WP_Post(1)]))->toThrow(UnsupportedArgument::class);
    });

    it('carries WordPress objects by default', function (): void {
        expect((new ArgumentNormalizer)->normalize([new WP_Post(1)])[0]['kind'])->toBe('wp');
    });

    it('rebuilds a WordPress object even when another reference was registered before it', function (): void {
        $GLOBALS['wp_objects']['post'][12] = new WP_Post(12, 'trash');
        $this->normalizer->extend(new class implements ObjectReference
        {
            public function name(): string
            {
                return 'other';
            }

            public function supports(object $object): bool
            {
                return false;
            }

            public function reference(object $object): array
            {
                return [];
            }

            public function resolve(array $reference): ?object
            {
                return null;
            }
        });

        expect(($this->roundTrip)([new WP_Post(12)])[0])->toBe($GLOBALS['wp_objects']['post'][12]);
    });

    it('hands a reference that is not an array to the reference as a one-item array', function (): void {
        $encoded = [['@type' => 'ref', 'kind' => 'wp', 'ref' => 5]];

        expect($this->normalizer->denormalize($encoded, true))->toBe([null]);
    });
});

describe('Enums', function (): void {
    it('stores the value of a backed enum and the name of a pure one', function (): void {
        expect($this->normalizer->normalize([OrderStatus::Refunded, NormalizerEdgeSuit::Spades]))->toBe([
            ['@type' => 'enum', 'class' => OrderStatus::class, 'value' => 'refunded'],
            ['@type' => 'enum', 'class' => NormalizerEdgeSuit::class, 'name' => 'Spades'],
        ]);
    });

    it('rebuilds a pure enum', function (): void {
        expect(($this->roundTrip)([NormalizerEdgeSuit::Hearts, NormalizerEdgeSuit::Spades]))->toBe([NormalizerEdgeSuit::Hearts, NormalizerEdgeSuit::Spades]);
    });

    it('rebuilds a backed enum from its case name', function (): void {
        $encoded = [['@type' => 'enum', 'class' => OrderStatus::class, 'name' => 'Refunded']];

        expect($this->normalizer->denormalize($encoded))->toBe([OrderStatus::Refunded]);
    });

    it('rebuilds a pure enum from its name even when a value comes with it', function (): void {
        $encoded = [['@type' => 'enum', 'class' => NormalizerEdgeSuit::class, 'name' => 'Hearts', 'value' => 'ignored']];

        expect($this->normalizer->denormalize($encoded))->toBe([NormalizerEdgeSuit::Hearts]);
    });

    it('reports a class that is not a string as a missing enum', function (): void {
        $encoded = [['@type' => 'enum', 'class' => 5, 'value' => 'x']];

        expect(fn () => $this->normalizer->denormalize($encoded))->toThrow(InvalidPayload::class, "the enum '5' no longer exists");
    });
});

describe('Dates', function (): void {
    it('keeps the class of a date that extends a native one', function (): void {
        [$immutable, $mutable] = ($this->roundTrip)([
            new NormalizerEdgeDate('2026-10-07 14:30:15', new DateTimeZone('UTC')),
            new NormalizerEdgeMutableDate('2026-10-07 14:30:15', new DateTimeZone('UTC')),
        ]);

        expect($immutable)->toBeInstanceOf(NormalizerEdgeDate::class)
            ->and($mutable)->toBeInstanceOf(NormalizerEdgeMutableDate::class);
    });

    it('rebuilds an immutable date for a class that is not a date', function (string $class): void {
        $encoded = [['@type' => 'date', 'class' => $class, 'value' => '2026-10-07T14:30:15.000000+00:00', 'timezone' => 'UTC']];

        $denormalized = $this->normalizer->denormalize($encoded)[0];

        expect($denormalized)->toBeInstanceOf(DateTimeImmutable::class)
            ->and(get_debug_type($denormalized))->toBe(DateTimeImmutable::class);
    })->with(['stdClass', 'Missing\\DateClass', 'ArrayObject']);

    it('reads a class that is not a string as no date class', function (): void {
        $encoded = [['@type' => 'date', 'class' => 5, 'value' => '2026-10-07T14:30:15.000000+00:00', 'timezone' => 'UTC']];

        expect($this->normalizer->denormalize($encoded)[0])->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('reads a date given as a number as text', function (): void {
        $encoded = [['@type' => 'date', 'class' => DateTimeImmutable::class, 'value' => 2026, 'timezone' => 'UTC']];

        expect($this->normalizer->denormalize($encoded)[0]->format('H:i'))->toBe('20:26');
    });
});

describe('Nested values', function (): void {
    it('rebuilds the objects of nested arrays', function (): void {
        $GLOBALS['wp_objects']['post'][12] = new WP_Post(12, 'trash');

        $rebuilt = ($this->roundTrip)([['post' => new WP_Post(12), 'status' => OrderStatus::Completed, 'deep' => [new WP_Post(12)]]]);

        expect($rebuilt[0]['post'])->toBe($GLOBALS['wp_objects']['post'][12])
            ->and($rebuilt[0]['status'])->toBe(OrderStatus::Completed)
            ->and($rebuilt[0]['deep'][0])->toBe($GLOBALS['wp_objects']['post'][12]);
    });

    it('rebuilds the objects inside an array that has an @type key', function (): void {
        $rebuilt = ($this->roundTrip)([['@type' => 'mine', 'status' => OrderStatus::Refunded]]);

        expect($rebuilt[0])->toBe(['@type' => 'mine', 'status' => OrderStatus::Refunded]);
    });
});

describe('WordPress object references', function (): void {
    beforeEach(function (): void {
        $this->reference = new WordPressObjectReference;
    });

    it('identifies each kind of object', function (): void {
        expect($this->reference->reference(new WP_Post(12)))->toBe(['type' => 'post', 'id' => 12])
            ->and($this->reference->reference(new WP_Term(3)))->toBe(['type' => 'term', 'id' => 3])
            ->and($this->reference->reference(new WP_User(7)))->toBe(['type' => 'user', 'id' => 7])
            ->and($this->reference->reference(new WP_Comment('9')))->toBe(['type' => 'comment', 'id' => 9]);
    });

    it('supports only WordPress objects', function (): void {
        expect($this->reference->supports(new WP_Post))->toBeTrue()
            ->and($this->reference->supports(new WP_Term))->toBeTrue()
            ->and($this->reference->supports(new WP_User))->toBeTrue()
            ->and($this->reference->supports(new WP_Comment))->toBeTrue()
            ->and($this->reference->supports(new stdClass))->toBeFalse();
    });

    it('refuses to identify an object that is not a WordPress one', function (): void {
        expect(fn () => $this->reference->reference(new stdClass))
            ->toThrow(InvalidArgumentException::class, 'stdClass is not a WordPress object.');
    });

    it('reloads each kind of object by its identifier, given as a number or a string', function (): void {
        $GLOBALS['wp_objects'] = [
            'post' => [12 => new WP_Post(12)],
            'term' => [3 => new WP_Term(3)],
            'user' => [7 => new WP_User(7)],
            'comment' => [9 => new WP_Comment('9')],
        ];

        expect($this->reference->resolve(['type' => 'post', 'id' => '12']))->toBe($GLOBALS['wp_objects']['post'][12])
            ->and($this->reference->resolve(['type' => 'term', 'id' => 3]))->toBe($GLOBALS['wp_objects']['term'][3])
            ->and($this->reference->resolve(['type' => 'user', 'id' => '7']))->toBe($GLOBALS['wp_objects']['user'][7])
            ->and($this->reference->resolve(['type' => 'comment', 'id' => 9]))->toBe($GLOBALS['wp_objects']['comment'][9]);
    });

    it('looks for the identifier 0 when none is given', function (): void {
        $GLOBALS['wp_objects']['post'] = [-1 => new WP_Post(-1), 0 => new WP_Post(0), 1 => new WP_Post(1)];

        expect($this->reference->resolve(['type' => 'post'])->ID)->toBe(0);
    });

    it('returns null for an unknown type', function (): void {
        $GLOBALS['wp_objects']['post'][1] = new WP_Post(1);

        expect($this->reference->resolve(['type' => 'media', 'id' => 1]))->toBeNull()
            ->and($this->reference->resolve(['id' => 1]))->toBeNull();
    });

    it('returns null when WordPress finds no object or answers with something else', function (string $type, mixed $answer): void {
        $GLOBALS['wp_objects'][$type][5] = $answer;

        expect($this->reference->resolve(['type' => $type, 'id' => 5]))->toBeNull();
    })->with([
        'no user' => ['user', false],
        'post error' => ['post', new stdClass],
        'term error' => ['term', new stdClass],
        'user error' => ['user', new stdClass],
        'comment error' => ['comment', new stdClass],
        'no post' => ['post', null],
        'no term' => ['term', null],
        'no comment' => ['comment', null],
    ]);
});
