<?php

declare(strict_types=1);

namespace Tmonier\SyliusAuditLogPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Tmonier\SyliusAuditLogPlugin\Resource\AuditedResourceRegistryInterface;
use Tmonier\SyliusAuditLogPlugin\Twig\AuditLogPresenter;

final class ResourceFilterType extends AbstractType
{
    public function __construct(
        private readonly AuditedResourceRegistryInterface $registry,
        private readonly AuditLogPresenter $presenter,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach (array_keys($this->registry->all()) as $type) {
            $choices[$this->presenter->resourceTypeLabel($type)] = $type;
        }
        ksort($choices, \SORT_NATURAL | \SORT_FLAG_CASE);

        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'tmonier_audit_log.ui.resource_type',
                'choices' => $choices,
                'choice_translation_domain' => false,
                'placeholder' => 'sylius.ui.all',
                'required' => false,
            ])
            ->add('id', TextType::class, [
                'label' => 'tmonier_audit_log.ui.resource_id',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'tmonier_audit_log_resource_filter';
    }
}
