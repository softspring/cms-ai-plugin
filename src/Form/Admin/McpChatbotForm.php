<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class McpChatbotForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('platform', ChoiceType::class, [
                'choices' => $options['platforms'],
                'required' => false,
                'placeholder' => 'Default platform',
            ])
            ->add('model', ChoiceType::class, [
                'choices' => $options['models'],
                'required' => false,
                'placeholder' => 'Choose a model',
                'disabled' => [] === $options['models'],
                'choice_translation_domain' => false,
            ])
            ->add('question', TextareaType::class, [
                'required' => true,
                'attr' => ['rows' => 5],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'platforms' => [],
            'models' => [],
        ]);

        $resolver->setAllowedTypes('platforms', 'array');
        $resolver->setAllowedTypes('models', 'array');
    }
}
