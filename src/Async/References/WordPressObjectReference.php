<?php

declare(strict_types=1);

namespace Pollora\Hook\Async\References;

use Pollora\Hook\Async\Contracts\ObjectReference;

/**
 * Carries WP_Post, WP_Term, WP_User and WP_Comment objects by type and ID.
 */
final class WordPressObjectReference implements ObjectReference
{
    public function name(): string
    {
        return 'wp';
    }

    public function supports(object $object): bool
    {
        return $object instanceof \WP_Post
            || $object instanceof \WP_Term
            || $object instanceof \WP_User
            || $object instanceof \WP_Comment;
    }

    public function reference(object $object): array
    {
        return match (true) {
            $object instanceof \WP_Post => ['type' => 'post', 'id' => (int) $object->ID],
            $object instanceof \WP_Term => ['type' => 'term', 'id' => (int) $object->term_id],
            $object instanceof \WP_User => ['type' => 'user', 'id' => (int) $object->ID],
            $object instanceof \WP_Comment => ['type' => 'comment', 'id' => (int) $object->comment_ID],
            default => throw new \InvalidArgumentException(sprintf('%s is not a WordPress object.', $object::class)),
        };
    }

    public function resolve(array $reference): ?object
    {
        $id = (int) ($reference['id'] ?? 0);

        $object = match ($reference['type'] ?? null) {
            'post' => get_post($id),
            'term' => get_term($id),
            'user' => get_userdata($id),
            'comment' => get_comment($id),
            default => null,
        };

        return match (true) {
            $object instanceof \WP_Post, $object instanceof \WP_Term, $object instanceof \WP_User, $object instanceof \WP_Comment => $object,
            default => null,
        };
    }
}
