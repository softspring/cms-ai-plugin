<?php

namespace Softspring\CmsAiPlugin\Lab;

use RuntimeException;
use Softspring\CmsBundle\Config\CmsConfig;
use Softspring\CmsBundle\Form\Admin\ContentVersion\VersionCreateForm;
use Softspring\CmsBundle\Model\ContentInterface;
use Softspring\CmsBundle\Model\ContentVersionInterface;
use stdClass;
use Symfony\Component\Form\ChoiceList\View\ChoiceGroupView;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolationInterface;

class ContentVersionPayloadContext
{
    public function __construct(
        protected CmsConfig $cmsConfig,
        protected FormFactoryInterface $formFactory,
        protected string $contentVersionClass,
        protected array $enabledLocales = [],
        protected string $defaultLocale = 'en',
    ) {
    }

    public function getContentTypes(): array
    {
        return array_combine(array_keys($this->cmsConfig->getContents()), array_keys($this->cmsConfig->getContents()));
    }

    public function getLayouts(string $contentType): array
    {
        $contentConfig = $this->getContentConfig($contentType);
        $layouts = $this->cmsConfig->getLayouts();
        $allowedLayouts = empty($contentConfig['allowed_layouts']) ? array_keys($layouts) : $contentConfig['allowed_layouts'];

        foreach ($layouts as $layoutId => $layoutConfig) {
            if (!in_array($layoutId, $allowedLayouts, true)) {
                continue;
            }

            if (!empty($layoutConfig['compatible_contents']) && !in_array($contentType, $layoutConfig['compatible_contents'], true)) {
                $allowedLayouts = array_values(array_filter($allowedLayouts, static fn (string $candidate): bool => $candidate !== $layoutId));
            }
        }

        return array_combine($allowedLayouts, $allowedLayouts);
    }

    public function getContentConfig(string $contentType): array
    {
        $contentConfig = $this->cmsConfig->getContent($contentType, false);

        if (null === $contentConfig) {
            throw new NotFoundHttpException(sprintf('Unknown content type "%s".', $contentType));
        }

        return $contentConfig;
    }

    public function createPreviewForm(string $contentType, string $layout, ?array $data = null, string $name = 'version_payload'): FormInterface
    {
        $content = $this->createDummyContent($contentType);
        $version = $this->createDummyVersion($content, $layout);

        return $this->formFactory->createNamed($name, VersionCreateForm::class, $version, [
            'content_type' => $contentType,
            'content' => $content,
            'content_config' => $this->getContentConfig($contentType),
            'layout' => $layout,
            'translation_domain' => 'sfs_cms_contents',
            'csrf_protection' => false,
        ]);
    }

    public function getSchema(string $contentType, string $layout): array
    {
        $this->ensureLayoutAllowed($contentType, $layout);
        $form = $this->createPreviewForm($contentType, $layout, null, 'schema_version_payload');
        $view = $form->createView();

        return $this->buildVersionSchemaFromView($view, $layout);
    }

    public function validatePayload(string $contentType, string $layout, array $payload): array
    {
        $form = $this->createPreviewForm($contentType, $layout, null, 'generated_version_payload');
        $form->submit($payload);

        return [
            'valid' => $form->isSubmitted() && $form->isValid(),
            'errors' => $this->collectErrors($form),
            'form' => $form,
        ];
    }

    protected function collectErrors(FormInterface $form): array
    {
        $errors = [];

        foreach ($form->getErrors() as $error) {
            $errors[] = $this->normalizeError($form, $error);
        }

        foreach ($form->all() as $child) {
            array_push($errors, ...$this->collectErrors($child));
        }

        return $errors;
    }

    protected function normalizeError(FormInterface $form, FormError $error): array
    {
        $cause = $error->getCause();

        return [
            'path' => $form->getPropertyPath()?->__toString() ?: $form->getName(),
            'message' => $error->getMessage(),
            'code' => $cause instanceof ConstraintViolationInterface ? $cause->getCode() : null,
        ];
    }

    protected function createDummyContent(string $contentType): ContentInterface
    {
        $contentConfig = $this->getContentConfig($contentType);
        $class = $contentConfig['entity_class'];
        $content = new $class();

        if (!$content instanceof ContentInterface) {
            throw new RuntimeException(sprintf('Configured content class "%s" must implement ContentInterface.', $class));
        }

        $content->setName('AI '.$contentType);
        $content->setDefaultLocale($this->defaultLocale);
        $content->setLocales($this->enabledLocales);
        $content->setExtraData([]);
        $content->setIndexing([]);

        foreach ($this->getSiteEntitiesForContentType($contentType) as $site) {
            $content->addSite($site);
        }

        return $content;
    }

    protected function createDummyVersion(ContentInterface $content, string $layout): ContentVersionInterface
    {
        $version = new $this->contentVersionClass();

        if (!$version instanceof ContentVersionInterface) {
            throw new RuntimeException(sprintf('Configured content version class "%s" must implement ContentVersionInterface.', $this->contentVersionClass));
        }

        $version->setContent($content);
        $version->setLayout($layout);
        $version->setData([]);

        return $version;
    }

    protected function getSiteEntitiesForContentType(string $contentType): array
    {
        return array_values($this->cmsConfig->getSitesForContent($contentType));
    }

    protected function ensureLayoutAllowed(string $contentType, string $layout): void
    {
        if (!isset($this->getLayouts($contentType)[$layout])) {
            throw new NotFoundHttpException(sprintf('Layout "%s" is not allowed for content type "%s".', $layout, $contentType));
        }
    }

    protected function buildVersionSchemaFromView(FormView $view, string $layout): array
    {
        $prototypeViews = $this->flattenPrototypeViews($view['module_prototypes_collection'] ?? null);
        $definitions = $this->buildModuleDefinitions($prototypeViews);

        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'layout' => [
                    'type' => 'string',
                    'enum' => [$layout],
                    'default' => $layout,
                ],
                'data' => $this->buildSchemaFromFieldView($view['data'], $prototypeViews) ?? [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
            'required' => ['layout', 'data'],
        ];

        if ([] !== $definitions) {
            $schema['$defs'] = $definitions;
        }

        return $schema;
    }

    protected function flattenPrototypeViews(?FormView $prototypeCollectionView): array
    {
        if (!$prototypeCollectionView instanceof FormView) {
            return [];
        }

        $prototypes = [];

        foreach ($prototypeCollectionView->vars['prototypes'] ?? [] as $groupPrototypes) {
            foreach ($groupPrototypes as $prototypeView) {
                $moduleId = $prototypeView->vars['module_id'] ?? null;

                if (is_string($moduleId)) {
                    $prototypes[$moduleId] = $prototypeView;
                }
            }
        }

        return $prototypes;
    }

    protected function buildSchemaFromFieldView(FormView $view, array $prototypeViews = []): ?array
    {
        $blockPrefixes = $view->vars['block_prefixes'] ?? [];
        $name = $view->vars['name'] ?? null;

        if (in_array('button', $blockPrefixes, true) || in_array('submit', $blockPrefixes, true) || in_array('reset', $blockPrefixes, true)) {
            return null;
        }

        if (in_array('choice', $blockPrefixes, true)) {
            return $this->buildChoiceSchemaFromView($view);
        }

        if (in_array('module_collection', $blockPrefixes, true) || in_array('module_prototypes_collection', $blockPrefixes, true)) {
            return $this->buildModuleCollectionSchemaFromView($view, $prototypeViews);
        }

        if (in_array('symfony_route', $blockPrefixes, true)) {
            return $this->buildSymfonyRouteSchema();
        }

        if ($this->isTranslatableFieldView($view)) {
            return $this->buildTranslatableSchemaFromView($view);
        }

        if ([] !== $view->children) {
            $properties = [];
            $required = [];

            foreach ($view->children as $childName => $childView) {
                $childSchema = $this->buildSchemaFromFieldView($childView, $prototypeViews);

                if (null === $childSchema) {
                    continue;
                }

                $properties[$childName] = $childSchema;

                if ($childView->vars['required'] ?? false) {
                    $required[] = $childName;
                }
            }

            $schema = [
                'type' => 'object',
                'properties' => $properties,
            ];

            if ([] !== $required) {
                $schema['required'] = $required;
            }

            return $schema;
        }

        return match (true) {
            in_array('checkbox', $blockPrefixes, true) => ['type' => 'boolean'],
            in_array('integer', $blockPrefixes, true) => ['type' => 'integer'],
            in_array('number', $blockPrefixes, true),
            in_array('money', $blockPrefixes, true),
            in_array('percent', $blockPrefixes, true),
            in_array('range', $blockPrefixes, true) => ['type' => 'number'],
            '_revision' === $name => ['type' => 'integer'],
            default => ['type' => 'string'],
        };
    }

    protected function buildModuleCollectionSchemaFromView(FormView $view, array $prototypeViews): array
    {
        $allowedModules = $view->vars['allowed_modules'] ?? array_keys($prototypeViews);
        $oneOf = $this->buildAllowedModuleRefs($allowedModules, $prototypeViews);

        return [
            'type' => 'array',
            'items' => [
                'oneOf' => $oneOf,
            ],
        ];
    }

    protected function buildSchemaFromPrototypeView(FormView $prototypeView, array $prototypeViews): array
    {
        $properties = [];
        $required = [];

        foreach ($prototypeView->children as $childName => $childView) {
            $childSchema = $this->buildSchemaFromFieldView($childView, $prototypeViews);

            if (null === $childSchema) {
                continue;
            }

            $properties[$childName] = $childSchema;

            if ($childView->vars['required'] ?? false) {
                $required[] = $childName;
            }
        }

        $moduleId = $prototypeView->vars['module_id'] ?? null;
        if (is_string($moduleId)) {
            $properties['_module'] = [
                'type' => 'string',
                'enum' => [$moduleId],
            ];
            $required[] = '_module';
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ([] !== $required) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    protected function buildModuleDefinitions(array $prototypeViews): array
    {
        $definitions = [];

        foreach ($prototypeViews as $moduleId => $prototypeView) {
            $definitions[$this->getModuleDefinitionName($moduleId)] = $this->buildSchemaFromPrototypeView($prototypeView, $prototypeViews);
        }

        return $definitions;
    }

    protected function buildAllowedModuleRefs(array $allowedModules, array $prototypeViews): array
    {
        $oneOf = [];

        foreach ($allowedModules as $moduleId) {
            if (!isset($prototypeViews[$moduleId])) {
                continue;
            }

            $oneOf[] = [
                '$ref' => '#/$defs/'.$this->getModuleDefinitionName($moduleId),
            ];
        }

        return $oneOf;
    }

    protected function getModuleDefinitionName(string $moduleId): string
    {
        return 'module__'.preg_replace('/[^A-Za-z0-9_]+/', '_', $moduleId);
    }

    protected function buildChoiceSchemaFromView(FormView $view): array
    {
        $choices = [];
        $this->collectChoiceValues($view->vars['choices'] ?? [], $choices);
        $valueType = $this->inferJsonType($choices);

        if ($view->vars['multiple'] ?? false) {
            $schema = [
                'type' => 'array',
                'items' => ['type' => $valueType],
            ];

            if ([] !== $choices) {
                $schema['items']['enum'] = $choices;
            }

            return $schema;
        }

        $schema = ['type' => $valueType];

        if ([] !== $choices) {
            $schema['enum'] = $choices;
        }

        return $schema;
    }

    protected function collectChoiceValues(array $choices, array &$values): void
    {
        foreach ($choices as $choice) {
            if ($choice instanceof ChoiceGroupView) {
                $this->collectChoiceValues($choice->choices, $values);
                continue;
            }

            if ($choice instanceof ChoiceView) {
                $values[] = $choice->value;
            }
        }
    }

    protected function inferJsonType(array $values): string
    {
        if ([] === $values) {
            return 'string';
        }

        $types = array_unique(array_map(function (mixed $value): string {
            return match (true) {
                is_bool($value) => 'boolean',
                is_int($value) => 'integer',
                is_float($value) => 'number',
                is_numeric($value) && (string) (int) $value === $value => 'integer',
                is_numeric($value) => 'number',
                default => 'string',
            };
        }, $values));

        return 1 === count($types) ? $types[0] : 'string';
    }

    protected function isTranslatableFieldView(FormView $view): bool
    {
        if ([] === $view->children) {
            return false;
        }

        $childNames = array_keys($view->children);
        $localeFields = array_intersect($childNames, $this->enabledLocales);

        return in_array('_default', $childNames, true) && in_array('_trans_id', $childNames, true) && [] !== $localeFields;
    }

    protected function buildTranslatableSchemaFromView(FormView $view): array
    {
        $childNames = array_keys($view->children);
        $localeFields = array_values(array_intersect($childNames, $this->enabledLocales));
        $defaultLocale = in_array($this->defaultLocale, $localeFields, true) ? $this->defaultLocale : ($localeFields[0] ?? $this->defaultLocale);
        $properties = [
            '_default' => [
                'type' => 'string',
                'enum' => $localeFields,
                'default' => $defaultLocale,
                'description' => 'Default locale code for this translation object.',
            ],
            '_trans_id' => [
                'type' => 'string',
                'description' => 'Technical translation id. Use a random short hash-like string, not editorial text.',
            ],
        ];

        foreach ($localeFields as $locale) {
            $properties[$locale] = [
                'type' => ['string', 'null'],
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_merge(['_default', '_trans_id'], $localeFields),
        ];
    }

    protected function buildSymfonyRouteSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'route_name' => [
                    'type' => ['string', 'null'],
                    'description' => 'Symfony route name or null when no route is selected.',
                ],
                'route_params' => [
                    'type' => 'object',
                    'description' => 'Route parameters as a JSON object.',
                    'additionalProperties' => [
                        'type' => ['string', 'number', 'integer', 'boolean', 'null'],
                    ],
                    'default' => new stdClass(),
                ],
            ],
            'required' => ['route_name', 'route_params'],
        ];
    }

    public function normalizePayload(array $payload): array
    {
        return $this->normalizeGeneratedPayload($payload);
    }

    protected function normalizeGeneratedPayload(array $payload): array
    {
        return $this->normalizeGeneratedValue($payload);
    }

    protected function normalizeGeneratedValue(array $value): array
    {
        if ($this->isSymfonyRouteArray($value)) {
            $routeName = $value['route_name'] ?? null;
            $value['route_name'] = is_string($routeName) ? $routeName : null;
            $value['route_params'] = isset($value['route_params']) && is_array($value['route_params']) ? $value['route_params'] : [];

            return $value;
        }

        if ($this->isTranslatableArray($value)) {
            $localeFields = array_values(array_intersect(array_keys($value), $this->enabledLocales));
            $defaultLocale = $value['_default'] ?? null;

            if (!is_string($defaultLocale) || !in_array($defaultLocale, $localeFields, true)) {
                $value['_default'] = in_array($this->defaultLocale, $localeFields, true) ? $this->defaultLocale : ($localeFields[0] ?? $this->defaultLocale);
            }

            if (!isset($value['_trans_id']) || !is_string($value['_trans_id']) || '' === trim($value['_trans_id'])) {
                $value['_trans_id'] = $this->generateTranslationId();
            }
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->normalizeGeneratedValue($child);
            }
        }

        return $value;
    }

    protected function isTranslatableArray(array $value): bool
    {
        $keys = array_keys($value);
        $localeFields = array_intersect($keys, $this->enabledLocales);

        return isset($value['_default'], $value['_trans_id']) && [] !== $localeFields;
    }

    protected function isSymfonyRouteArray(array $value): bool
    {
        $keys = array_keys($value);

        return in_array('route_name', $keys, true) || in_array('route_params', $keys, true);
    }

    protected function generateTranslationId(): string
    {
        return 't_'.bin2hex(random_bytes(6));
    }
}
