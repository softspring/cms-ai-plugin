<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Form\Extension;

use Softspring\CmsAiPlugin\Media\MediaImageGenerationRequirements;
use Softspring\MediaBundle\Form\Admin\MediaCreateForm;
use Softspring\MediaBundle\Type\MediaTypesCollection;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MediaAiImageCreateTypeExtension extends AbstractTypeExtension
{
    private const PROMPT_FIELD = 'aiImagePrompt';
    private const GENERATED_PROMPT_FIELD = 'aiImageGeneratedPrompt';
    private const PLATFORM_FIELD = 'aiImagePlatform';
    private const MODEL_FIELD = 'aiImageModel';

    public function __construct(
        protected MediaTypesCollection $mediaTypesCollection,
        protected MediaImageGenerationRequirements $generationRequirements,
        protected ServiceLocator $platforms,
        protected UrlGeneratorInterface $urlGenerator,
        protected RequestStack $requestStack,
        protected TranslatorInterface $translator,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [MediaCreateForm::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$this->supportsMediaType($options['media_type'] ?? null)) {
            return;
        }

        $modelsByPlatform = $this->getImageModelsByPlatform();
        $platforms = array_combine(array_keys($modelsByPlatform), array_keys($modelsByPlatform)) ?: [];
        $selectedPlatform = array_key_first($platforms) ?: 'openai';
        $models = $modelsByPlatform[$selectedPlatform] ?? ['gpt-image-1' => 'gpt-image-1'];

        $builder
            ->add(self::PLATFORM_FIELD, ChoiceType::class, [
                'mapped' => false,
                'required' => true,
                'translation_domain' => 'sfs_cms_ai',
                'label' => 'media.image_generation.form.platform.label',
                'choices' => $platforms,
                'data' => $selectedPlatform,
                'choice_translation_domain' => false,
                'attr' => [
                    'data-ai-media-image-models' => json_encode($modelsByPlatform, JSON_THROW_ON_ERROR),
                    'data-ai-media-image-model-input' => 'media_create_form_aiImageModel',
                ],
            ])
            ->add(self::MODEL_FIELD, ChoiceType::class, [
                'mapped' => false,
                'required' => true,
                'translation_domain' => 'sfs_cms_ai',
                'label' => 'media.image_generation.form.model.label',
                'choices' => $models,
                'data' => $models['gpt-image-1'] ?? array_key_first($models),
                'choice_translation_domain' => false,
            ])
            ->add(self::GENERATED_PROMPT_FIELD, HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'data-ai-media-image-generated-prompt' => '',
                ],
            ])
            ->add(self::PROMPT_FIELD, TextareaType::class, [
                'mapped' => false,
                'required' => false,
                'translation_domain' => 'sfs_cms_ai',
                'label' => 'media.image_generation.form.prompt.label',
                'help' => 'media.image_generation.form.prompt.help',
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'media.image_generation.form.prompt.placeholder',
                    'data-ai-media-image-prompt' => '',
                    'data-ai-media-image-generate-url' => $this->urlGenerator->generate('sfs_cms_ai_admin_media_generate_image', [
                        '_locale' => $this->requestStack->getCurrentRequest()?->attributes->get('_locale', $this->requestStack->getCurrentRequest()?->getLocale() ?: 'en'),
                        'type' => $options['media_type'],
                    ]),
                    'data-ai-media-image-target-input' => 'media_create_form__original_upload',
                    'data-ai-media-image-generated-prompt-input' => 'media_create_form_aiImageGeneratedPrompt',
                    'data-ai-media-image-platform-input' => 'media_create_form_aiImagePlatform',
                    'data-ai-media-image-model-input' => 'media_create_form_aiImageModel',
                    'data-ai-media-image-button-label' => $this->translator->trans('media.image_generation.form.actions.generate', [], 'sfs_cms_ai'),
                    'data-ai-media-image-generating-label' => $this->translator->trans('media.image_generation.form.status.generating', [], 'sfs_cms_ai'),
                    'data-ai-media-image-error-label' => $this->translator->trans('media.image_generation.form.status.error', [], 'sfs_cms_ai'),
                ],
            ]);
    }

    protected function supportsMediaType(?string $type): bool
    {
        if (!$type) {
            return false;
        }

        $typeConfig = $this->mediaTypesCollection->getType($type);
        if ('image' !== ($typeConfig['type'] ?? null)) {
            return false;
        }

        return $this->generationRequirements->supportsPngOutput($typeConfig['upload_requirements'] ?? []);
    }

    protected function getImageModelsByPlatform(): array
    {
        $models = [];

        foreach (array_keys($this->platforms->getProvidedServices()) as $name) {
            $platformModels = $this->getImageModelChoices($name);
            if ([] !== $platformModels) {
                $models[$name] = $platformModels;
            }
        }

        return $models;
    }

    protected function getImageModelChoices(string $platformName): array
    {
        if (!$this->platforms->has($platformName)) {
            return [];
        }

        $platform = $this->platforms->get($platformName);
        if (!$platform instanceof PlatformInterface) {
            return [];
        }

        $models = [];
        foreach ($platform->getModelCatalog()->getModels() as $model => $definition) {
            foreach ($definition['capabilities'] as $capability) {
                if ($capability instanceof Capability && Capability::OUTPUT_IMAGE === $capability) {
                    $models[$model] = $model;
                    break;
                }
            }
        }

        if ('openai' === $platformName) {
            $models = ['gpt-image-1' => 'gpt-image-1'] + $models;
        }

        ksort($models);

        return $models;
    }
}
