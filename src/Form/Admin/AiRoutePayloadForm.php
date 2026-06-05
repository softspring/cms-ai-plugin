<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Regex;

class AiRoutePayloadForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', TextType::class, [
                'constraints' => [
                    new Regex('/^[a-z][a-z0-9_]{3,}$/i'),
                ],
            ])
            ->add('parent', TextType::class, [
                'required' => false,
            ])
            ->add('paths', CollectionType::class, [
                'entry_type' => AiRoutePathPayloadForm::class,
                'entry_options' => [
                    'locales' => $options['locales'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'constraints' => [
                    new Count(min: 1),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'locales' => [],
        ]);

        $resolver->setAllowedTypes('locales', 'array');
    }
}
