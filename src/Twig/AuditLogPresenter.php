<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Twig;

use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusAuditLogPlugin\Entity\AuditLogEntryInterface;

/** Human-readable labels of entries: resource types, fields, values, actors, links. */
final class AuditLogPresenter
{
    /** @var array<string, ?string> */
    private array $routes = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RouterInterface $router,
    ) {
    }

    public function resourceTypeLabel(?string $type): string
    {
        if (null === $type) {
            return '';
        }

        return $this->translateOr(sprintf('tmonier_audit_log.resource.%s', $type), self::humanize($type));
    }

    public function fieldLabel(string $field): string
    {
        // Translated fields are stored as "name (en_US)".
        $suffix = '';
        if (1 === preg_match('/^(.+?)( \(.+\))$/', $field, $matches)) {
            [, $field, $suffix] = $matches;
        }

        return self::humanize($field) . $suffix;
    }

    public function value(mixed $value): string
    {
        return match (true) {
            null === $value, '' === $value => '—',
            true === $value => $this->translator->trans('sylius.ui.yes_label'),
            false === $value => $this->translator->trans('sylius.ui.no_label'),
            is_array($value) && array_is_list($value) => implode(', ', array_map(fn (mixed $item): string => $this->value($item), $value)),
            is_array($value) => $this->reference($value),
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }

    public function actor(AuditLogEntryInterface $entry): string
    {
        return match ($entry->getActorType()) {
            AuditLogEntryInterface::ACTOR_ADMIN, AuditLogEntryInterface::ACTOR_CUSTOMER => $entry->getActorName() ?? $this->translator->trans('tmonier_audit_log.ui.actor_type.' . $entry->getActorType()),
            AuditLogEntryInterface::ACTOR_CLI => $this->translator->trans('tmonier_audit_log.ui.actor_type.cli') . (null !== $entry->getRoute() ? sprintf(' (%s)', $entry->getRoute()) : ''),
            default => $this->translator->trans('tmonier_audit_log.ui.actor_type.system'),
        };
    }

    /** Admin page of an audited resource ("sylius_admin_<type>_show", else "_update"), when it exists. */
    public function resourceUrl(?string $type, ?string $id): ?string
    {
        if (null === $type || null === $id || '' === $id) {
            return null;
        }

        if (!array_key_exists($type, $this->routes)) {
            $this->routes[$type] = null;
            $collection = $this->router->getRouteCollection();
            foreach (['show', 'update'] as $action) {
                $name = sprintf('sylius_admin_%s_%s', $type, $action);
                $route = $collection->get($name);
                if (null !== $route && ['id'] === $route->compile()->getPathVariables()) {
                    $this->routes[$type] = $name;

                    break;
                }
            }
        }

        $route = $this->routes[$type];

        return null === $route ? null : $this->router->generate($route, ['id' => $id]);
    }

    public static function humanize(string $name): string
    {
        $words = strtolower(trim((string) preg_replace(['/([a-z\d])([A-Z])/', '/[_\-.]+/'], ['$1 $2', ' '], $name)));

        return ucfirst($words);
    }

    /** @param array<mixed> $reference */
    private function reference(array $reference): string
    {
        $label = $reference['label'] ?? null;
        $id = $reference['id'] ?? null;
        $label = is_scalar($label) ? (string) $label : '';
        $id = is_scalar($id) ? (string) $id : '';

        return match (true) {
            '' === $id => '' === $label ? '—' : $label,
            '' === $label, str_contains($label, '#' . $id) => '' === $label ? '#' . $id : $label,
            default => sprintf('%s (#%s)', $label, $id),
        };
    }

    private function translateOr(string $key, string $fallback): string
    {
        $translation = $this->translator->trans($key);

        return $translation === $key ? $fallback : $translation;
    }
}
