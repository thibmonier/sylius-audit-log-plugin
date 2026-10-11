<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Entity;

use Sylius\Resource\Model\ResourceInterface;

interface AuditLogEntryInterface extends ResourceInterface
{
    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_DELETE = 'delete';

    public const ACTIONS = [self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_DELETE];

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_CLI = 'cli';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_TYPES = [self::ACTOR_ADMIN, self::ACTOR_CUSTOMER, self::ACTOR_CLI, self::ACTOR_SYSTEM];

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_ADMIN_API = 'admin_api';

    public const SOURCE_SHOP = 'shop';

    public const SOURCE_SHOP_API = 'shop_api';

    public const SOURCE_CLI = 'cli';

    public const SOURCE_OTHER = 'other';

    public const SOURCES = [
        self::SOURCE_ADMIN,
        self::SOURCE_ADMIN_API,
        self::SOURCE_SHOP,
        self::SOURCE_SHOP_API,
        self::SOURCE_CLI,
        self::SOURCE_OTHER,
    ];

    public function getId(): ?int;

    public function getCreatedAt(): ?\DateTimeImmutable;

    public function setCreatedAt(\DateTimeImmutable $createdAt): void;

    public function getAction(): ?string;

    public function setAction(string $action): void;

    public function getResourceType(): ?string;

    public function setResourceType(string $resourceType): void;

    public function getResourceClass(): ?string;

    public function setResourceClass(string $resourceClass): void;

    public function getResourceId(): ?string;

    public function setResourceId(?string $resourceId): void;

    public function getResourceLabel(): ?string;

    public function setResourceLabel(?string $resourceLabel): void;

    /** Aggregate root the entry belongs to (the product of a variant, the order of a payment...), the resource itself otherwise. */
    public function getOwnerType(): ?string;

    public function setOwnerType(?string $ownerType): void;

    public function getOwnerId(): ?string;

    public function setOwnerId(?string $ownerId): void;

    /**
     * Field-level diff, keyed by field: {"old": x, "new": y}, {"masked": true} or, for
     * collections, {"added": [...], "removed": [...]}. References to other resources are
     * {"id": x, "label": y}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getChanges(): array;

    /** @param array<string, array<string, mixed>> $changes */
    public function setChanges(array $changes): void;

    /** @return list<string> */
    public function getChangedFields(): array;

    public function getActorType(): ?string;

    public function setActorType(string $actorType): void;

    public function getActorId(): ?int;

    public function setActorId(?int $actorId): void;

    public function getActorName(): ?string;

    public function setActorName(?string $actorName): void;

    public function getSource(): ?string;

    public function setSource(string $source): void;

    public function getIpAddress(): ?string;

    public function setIpAddress(?string $ipAddress): void;

    /** Correlation id shared by every entry written during the same request or console command. */
    public function getRequestId(): ?string;

    public function setRequestId(?string $requestId): void;

    /** Route name of the request, or console command name. */
    public function getRoute(): ?string;

    public function setRoute(?string $route): void;
}
