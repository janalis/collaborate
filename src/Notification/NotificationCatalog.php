<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Notification;

/**
 * The configured topics a user subscribes to and the types of notification each one holds.
 *
 * @phpstan-type Topic array{label: string, description: string|null, default: bool, mandatory: bool, domain: string}
 * @phpstan-type Type array{topic: string, sentence: string, label: string|null, icon: string|null, domain: string}
 */
final readonly class NotificationCatalog
{
    /**
     * @param array<string, Topic> $topics
     * @param array<string, Type>  $types
     */
    public function __construct(
        public array $topics = [],
        public array $types = [],
    ) {}

    /**
     * @return Type
     *
     * @throws \InvalidArgumentException for a type the configuration does not declare
     */
    public function type(string $name): array
    {
        return (
            $this->types[$name] ?? throw new \InvalidArgumentException(\sprintf(
                'The notification type "%s" is not configured (collaborate.notifications.types).',
                $name,
            ))
        );
    }

    /** @return Topic|null */
    public function topic(string $name): ?array
    {
        return $this->topics[$name] ?? null;
    }
}
