<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Entity;

class AuditLogEntry implements AuditLogEntryInterface
{
    protected ?int $id = null;

    protected ?\DateTimeImmutable $createdAt = null;

    protected ?string $action = null;

    protected ?string $resourceType = null;

    protected ?string $resourceClass = null;

    protected ?string $resourceId = null;

    protected ?string $resourceLabel = null;

    protected ?string $ownerType = null;

    protected ?string $ownerId = null;

    /** @var array<string, array<string, mixed>> */
    protected array $changes = [];

    protected ?string $actorType = null;

    protected ?int $actorId = null;

    protected ?string $actorName = null;

    protected ?string $source = null;

    protected ?string $ipAddress = null;

    protected ?string $requestId = null;

    protected ?string $route = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    public function setResourceType(string $resourceType): void
    {
        $this->resourceType = $resourceType;
    }

    public function getResourceClass(): ?string
    {
        return $this->resourceClass;
    }

    public function setResourceClass(string $resourceClass): void
    {
        $this->resourceClass = $resourceClass;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function setResourceId(?string $resourceId): void
    {
        $this->resourceId = $resourceId;
    }

    public function getResourceLabel(): ?string
    {
        return $this->resourceLabel;
    }

    public function setResourceLabel(?string $resourceLabel): void
    {
        $this->resourceLabel = $resourceLabel;
    }

    public function getOwnerType(): ?string
    {
        return $this->ownerType;
    }

    public function setOwnerType(?string $ownerType): void
    {
        $this->ownerType = $ownerType;
    }

    public function getOwnerId(): ?string
    {
        return $this->ownerId;
    }

    public function setOwnerId(?string $ownerId): void
    {
        $this->ownerId = $ownerId;
    }

    public function getChanges(): array
    {
        return $this->changes;
    }

    public function setChanges(array $changes): void
    {
        $this->changes = $changes;
    }

    public function getChangedFields(): array
    {
        return array_map('strval', array_keys($this->changes));
    }

    public function getActorType(): ?string
    {
        return $this->actorType;
    }

    public function setActorType(string $actorType): void
    {
        $this->actorType = $actorType;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    public function setActorId(?int $actorId): void
    {
        $this->actorId = $actorId;
    }

    public function getActorName(): ?string
    {
        return $this->actorName;
    }

    public function setActorName(?string $actorName): void
    {
        $this->actorName = $actorName;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): void
    {
        $this->ipAddress = $ipAddress;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function setRoute(?string $route): void
    {
        $this->route = $route;
    }
}
