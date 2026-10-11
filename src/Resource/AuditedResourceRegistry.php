<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Resource;

final class AuditedResourceRegistry implements AuditedResourceRegistryInterface
{
    /** @var array<string, AuditedResource> */
    private array $resources = [];

    /** @var array<class-string, string|false> resource name by entity class */
    private array $matches = [];

    /** @param array<string, array{class: string, owner?: ?string, owner_property_path?: ?string, ignored_fields?: list<string>}> $resources */
    public function __construct(array $resources)
    {
        foreach ($resources as $name => $resource) {
            /** @var class-string $class */
            $class = $resource['class'];
            $this->resources[$name] = new AuditedResource(
                $name,
                $class,
                $resource['owner'] ?? null,
                $resource['owner_property_path'] ?? null,
                $resource['ignored_fields'] ?? [],
            );
        }
    }

    public function match(object $entity): ?AuditedResource
    {
        $class = $entity::class;
        if (!array_key_exists($class, $this->matches)) {
            $this->matches[$class] = false;
            foreach ($this->resources as $name => $resource) {
                if (is_a($entity, $resource->class)) {
                    $this->matches[$class] = $name;

                    break;
                }
            }
        }

        $name = $this->matches[$class];

        return false === $name ? null : $this->resources[$name];
    }

    public function get(string $name): ?AuditedResource
    {
        return $this->resources[$name] ?? null;
    }

    public function all(): array
    {
        return $this->resources;
    }
}
