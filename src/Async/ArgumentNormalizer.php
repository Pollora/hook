<?php

declare(strict_types=1);

namespace Pollora\Hook\Async;

use Pollora\Hook\Async\Contracts\ObjectReference;
use Pollora\Hook\Async\Exceptions\InvalidPayload;
use Pollora\Hook\Async\Exceptions\MissingReferencedObject;
use Pollora\Hook\Async\Exceptions\UnsupportedArgument;
use Pollora\Hook\Async\References\WordPressObjectReference;

/**
 * Turns hook arguments into JSON-representable values, and back.
 *
 * Scalars and arrays keep their value. Objects are never serialized: WordPress
 * objects (and any registered ObjectReference) become a reference reloaded at
 * execution time, enums and dates are rebuilt, a JsonSerializable object
 * becomes its array. Any other object is rejected.
 *
 * Encoded values that are not plain carry a '@type' key; a plain array that
 * happens to have one is wrapped so it is never mistaken for an encoded value.
 */
final class ArgumentNormalizer
{
    private const string TYPE = '@type';

    /** @var list<ObjectReference> */
    private array $references;

    /**
     * @param  list<ObjectReference>|null  $references  Object references, WordPress objects by default
     */
    public function __construct(?array $references = null)
    {
        $this->references = $references ?? [new WordPressObjectReference];
    }

    /**
     * Register an object reference, checked before the existing ones.
     */
    public function extend(ObjectReference $reference): void
    {
        array_unshift($this->references, $reference);
    }

    /**
     * Normalize hook arguments.
     *
     * @param  array<int|string, mixed>  $arguments
     * @return array<int|string, mixed> JSON-representable arguments
     *
     * @throws UnsupportedArgument When a value cannot be carried
     */
    public function normalize(array $arguments): array
    {
        $normalized = [];

        foreach ($arguments as $position => $value) {
            $normalized[$position] = $this->normalizeValue($value, sprintf('argument #%d', (int) $position + 1));
        }

        return $normalized;
    }

    /**
     * Rebuild normalized arguments.
     *
     * @param  array<int|string, mixed>  $arguments  What normalize() returned, after a JSON round trip
     * @param  bool  $keepMissing  Put null in place of a referenced object that no longer exists
     * @return array<int|string, mixed>
     *
     * @throws MissingReferencedObject When a referenced object no longer exists and $keepMissing is false
     */
    public function denormalize(array $arguments, bool $keepMissing = false): array
    {
        return array_map(fn (mixed $value): mixed => $this->denormalizeValue($value, $keepMissing), $arguments);
    }

    private function normalizeValue(mixed $value, string $path): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw UnsupportedArgument::at($path, $value);
            }

            return $value;
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[$key] = $this->normalizeValue($item, sprintf('%s[%s]', $path, $key));
            }

            return array_key_exists(self::TYPE, $value) ? [self::TYPE => 'array', 'items' => $items] : $items;
        }

        if (is_object($value)) {
            return $this->normalizeObject($value, $path);
        }

        throw UnsupportedArgument::at($path, $value);
    }

    private function normalizeObject(object $value, string $path): mixed
    {
        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum
                ? [self::TYPE => 'enum', 'class' => $value::class, 'value' => $value->value]
                : [self::TYPE => 'enum', 'class' => $value::class, 'name' => $value->name];
        }

        if ($value instanceof \DateTimeInterface) {
            return [
                self::TYPE => 'date',
                'class' => $value::class,
                'value' => $value->format('Y-m-d\TH:i:s.uP'),
                'timezone' => $value->getTimezone()->getName(),
            ];
        }

        foreach ($this->references as $reference) {
            if ($reference->supports($value)) {
                return [self::TYPE => 'ref', 'kind' => $reference->name(), 'ref' => $reference->reference($value)];
            }
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize(), $path);
        }

        throw UnsupportedArgument::at($path, $value);
    }

    private function denormalizeValue(mixed $value, bool $keepMissing): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return match ($value[self::TYPE] ?? null) {
            null => array_map(fn (mixed $item): mixed => $this->denormalizeValue($item, $keepMissing), $value),
            'array' => array_map(fn (mixed $item): mixed => $this->denormalizeValue($item, $keepMissing), (array) $value['items']),
            'enum' => $this->denormalizeEnum($value),
            'date' => $this->denormalizeDate($value),
            'ref' => $this->denormalizeReference($value, $keepMissing),
            default => throw InvalidPayload::unreadableArgument(sprintf("unknown encoded type '%s'.", $value[self::TYPE])),
        };
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function denormalizeEnum(array $value): \UnitEnum
    {
        $class = (string) $value['class'];

        if (! enum_exists($class)) {
            throw InvalidPayload::unreadableArgument(sprintf("the enum '%s' no longer exists.", $class));
        }

        if (array_key_exists('value', $value) && is_subclass_of($class, \BackedEnum::class)) {
            return $class::from($value['value']);
        }

        return constant($class.'::'.$value['name']);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function denormalizeDate(array $value): \DateTimeInterface
    {
        $class = (string) $value['class'];

        if (! is_a($class, \DateTime::class, true) && ! is_a($class, \DateTimeImmutable::class, true)) {
            $class = \DateTimeImmutable::class;
        }

        /** @var \DateTime|\DateTimeImmutable $date */
        $date = new $class((string) $value['value']);

        return $date->setTimezone(new \DateTimeZone((string) $value['timezone']));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function denormalizeReference(array $value, bool $keepMissing): ?object
    {
        $reference = (array) $value['ref'];

        foreach ($this->references as $candidate) {
            if ($candidate->name() !== $value['kind']) {
                continue;
            }

            $object = $candidate->resolve($reference);

            if ($object === null && ! $keepMissing) {
                throw MissingReferencedObject::for((string) $value['kind'], $reference);
            }

            return $object;
        }

        throw InvalidPayload::unreadableArgument(sprintf("no object reference named '%s' is registered.", $value['kind']));
    }
}
