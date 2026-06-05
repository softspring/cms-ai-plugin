<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AiContentLabForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contentType', ChoiceType::class, [
                'choices' => $options['content_types'],
                'placeholder' => 'Choose a content type',
            ])
            ->add('layout', ChoiceType::class, [
                'choices' => $options['layouts'],
                'placeholder' => 'Choose a layout',
                'disabled' => [] === $options['layouts'],
            ])
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
            ->add('topic', TextType::class, [
                'required' => false,
            ])
            ->add('instructions', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 8],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'content_types' => [],
            'layouts' => [],
            'platforms' => [],
            'models' => [],
        ]);

        $resolver->setAllowedTypes('content_types', 'array');
        $resolver->setAllowedTypes('layouts', 'array');
        $resolver->setAllowedTypes('platforms', 'array');
        $resolver->setAllowedTypes('models', 'array');
    }
}
