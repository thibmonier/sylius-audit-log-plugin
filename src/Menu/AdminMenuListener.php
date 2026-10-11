<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $configuration = $event->getMenu()->getChild('configuration');
        if (null === $configuration) {
            return;
        }

        $configuration
            ->addChild('tmonier_audit_log', ['route' => 'tmonier_audit_log_admin_audit_log_entry_index', 'extras' => ['routes' => [
                ['route' => 'tmonier_audit_log_admin_audit_log_entry_show'],
            ]]])
            ->setLabel('tmonier_audit_log.ui.audit_log')
            ->setLabelAttribute('icon', 'tabler:history')
        ;
    }
}
