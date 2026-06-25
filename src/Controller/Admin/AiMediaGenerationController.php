<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Controller\Admin;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Softspring\CmsAiPlugin\Form\Admin\AiMediaGenerateForm;
use Softspring\CmsAiPlugin\Media\AiImageDescriber;
use Softspring\CmsAiPlugin\Media\AiImageGenerator;
use Softspring\MediaBundle\EntityManager\MediaManagerInterface;
use Softspring\MediaBundle\Model\MediaInterface;
use Softspring\MediaBundle\Model\MediaVersionInterface;
use Softspring\MediaBundle\Type\MediaTypesCollection;
use Softspring\TranslatableBundle\Model\Translation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class AiMediaGenerationController extends AbstractController
{
    private const SESSION_KEY = 'sfs_cms_ai_media_generation';

    public function __construct(
        protected MediaTypesCollection $mediaTypesCollection,
        protected MediaManagerInterface $mediaManager,
        protected AiImageGenerator $imageGenerator,
        protected AiImageDescriber $imageDescriber,
        protected LoggerInterface $logger,
        protected ContainerBagInterface $parameterBag,
    ) {
    }

    #[Route('/media/generate', name: 'sfs_cms_ai_admin_media_generate_create', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        $submittedData = $request->request->all('ai_media_generate_form');
        $data = [
            'previewToken' => bin2hex(random_bytes(16)),
            'type' => $request->query->get('type', 'content'),
            'platform' => $submittedData['platform'] ?? 'openai',
            'model' => $submittedData['model'] ?? 'gpt-image-1',
            'prompt' => '',
        ];

        $form = $this->createForm(AiMediaGenerateForm::class, $data, [
            'selected_platform' => $data['platform'],
            'selected_model' => $data['model'],
        ]);
        $form->handleRequest($request);

        $preview = null;
        if ($form->isSubmitted()) {
            $data = $form->getData();

            if ($form->get('refresh')->isClicked()) {
                return $this->renderGenerationForm($form, $data, $this->getPreview($session->get(self::SESSION_KEY, []), $data));
            }

            if ($form->isValid() && $form->get('preview')->isClicked()) {
                try {
                    $preview = $this->generatePreview($data);
                    $session->set(self::SESSION_KEY, $preview);
                } catch (Throwable $exception) {
                    $this->addGenerationError($form, $exception, $data);
                    $preview = $this->getPreview($session->get(self::SESSION_KEY, []), $data);
                }
            } elseif ($form->isValid() && $form->get('create')->isClicked()) {
                try {
                    $preview = $this->getPreview($session->get(self::SESSION_KEY, []), $data);
                    if (!$preview) {
                        $preview = $this->generatePreview($data);
                        $session->set(self::SESSION_KEY, $preview);
                    }

                    $media = $this->createMediaFromPreview($preview, $data);
                    $session->remove(self::SESSION_KEY);

                    return $this->redirectToRoute('sfs_media_admin_medias_read', ['media' => $media->getId()]);
                } catch (Throwable $exception) {
                    $this->addGenerationError($form, $exception, $data);
                    $preview = $this->getPreview($session->get(self::SESSION_KEY, []), $data);
                }
            } else {
                $preview = $this->getPreview($session->get(self::SESSION_KEY, []), $data);
            }
        } else {
            $preview = $this->getPreview($session->get(self::SESSION_KEY, []), $data);
        }

        return $this->renderGenerationForm($form, $data, $preview);
    }

    protected function addGenerationError(FormInterface $form, Throwable $exception, array $data): void
    {
        $this->logger->error('CMS AI media generation failed.', [
            'exception' => $exception,
            'media_type' => $data['type'] ?? null,
            'platform' => $data['platform'] ?? null,
            'model' => $data['model'] ?? null,
        ]);

        $message = trim($exception->getMessage());
        $form->addError(new FormError('' !== $message ? $message : 'Image generation failed.'));
    }

    protected function renderGenerationForm($form, array $data, ?array $preview): Response
    {
        if ($preview && is_readable($preview['path'] ?? '')) {
            $preview['dataUri'] = sprintf('data:%s;base64,%s', $preview['mimeType'], base64_encode((string) file_get_contents($preview['path'])));
        }

        return $this->render('@SfsCmsAiPlugin/admin/media/ai_generate.html.twig', [
            'form' => $form,
            'type_config' => $this->mediaTypesCollection->getType($data['type'] ?? 'content'),
            'preview' => $preview,
        ]);
    }

    protected function generatePreview(array $data): array
    {
        $type = (string) ($data['type'] ?? '');
        $typeConfig = $this->mediaTypesCollection->getType($type);
        $generatedImage = $this->imageGenerator->generate(
            (string) ($data['prompt'] ?? ''),
            $typeConfig['upload_requirements'] ?? [],
            (string) ($data['platform'] ?? 'openai'),
            (string) ($data['model'] ?? 'gpt-image-1'),
        );

        $path = $generatedImage->path;
        $size = getimagesize($path) ?: [null, null];

        return [
            'token' => (string) ($data['previewToken'] ?? bin2hex(random_bytes(16))),
            'type' => $type,
            'platform' => (string) ($data['platform'] ?? 'openai'),
            'model' => (string) ($data['model'] ?? 'gpt-image-1'),
            'prompt' => (string) ($data['prompt'] ?? ''),
            'path' => $path,
            'filename' => $generatedImage->originalName,
            'mimeType' => $generatedImage->mimeType,
            'width' => $size[0],
            'height' => $size[1],
        ];
    }

    protected function getPreview(array $preview, array $data): ?array
    {
        if ([] === $preview || !is_readable($preview['path'] ?? '')) {
            return null;
        }

        foreach (['previewToken' => 'token', 'type' => 'type', 'platform' => 'platform', 'model' => 'model', 'prompt' => 'prompt'] as $formKey => $previewKey) {
            if ((string) ($data[$formKey] ?? '') !== (string) ($preview[$previewKey] ?? '')) {
                return null;
            }
        }

        return $preview;
    }

    protected function createMediaFromPreview(array $preview, array $data): MediaInterface
    {
        $type = (string) $preview['type'];
        $typeConfig = $this->mediaTypesCollection->getType($type);
        $media = $this->mediaManager->createEntityForType($type);
        $prompt = trim((string) ($data['prompt'] ?? $preview['prompt'] ?? ''));
        $description = $this->describePreview($preview, $prompt);

        $media->setName($this->buildTitleFromPrompt($prompt));
        $media->setPrivate($typeConfig['private'] ?? false);
        $media->setDescription($description['description'] ?? $prompt);
        $media->setAltTexts($this->buildAltTexts($description['description'] ?? $prompt));
        $media->setMetadataField('sfs_cms_ai', [
            'image_generation' => [
                'source_prompt' => $prompt,
                'platform' => $preview['platform'] ?? null,
                'model' => $preview['model'] ?? null,
                'generated_at' => gmdate(DATE_ATOM),
                'source_width' => $preview['width'] ?? null,
                'source_height' => $preview['height'] ?? null,
            ],
            'image_description' => $description + [
                'source_prompt' => $prompt,
                'source_version' => '_original',
                'source_mime_type' => $preview['mimeType'] ?? null,
                'source_sha1' => is_readable($preview['path']) ? sha1_file($preview['path']) : null,
                'source_width' => $preview['width'] ?? null,
                'source_height' => $preview['height'] ?? null,
            ],
        ]);

        $original = $media->getVersion('_original');
        if (!$original instanceof MediaVersionInterface) {
            throw new RuntimeException('The original media version could not be created.');
        }

        $original->setUpload(new File($preview['path']), true);
        $original->setWidth($preview['width'] ?? null);
        $original->setHeight($preview['height'] ?? null);

        $this->mediaManager->saveEntity($media);

        @unlink($preview['path']);

        return $media;
    }

    protected function describePreview(array $preview, string $prompt): array
    {
        try {
            return $this->imageDescriber->describe(new File($preview['path']));
        } catch (Throwable $exception) {
            $this->logger->warning('CMS AI generated media description failed.', [
                'exception' => $exception,
                'media_type' => $preview['type'] ?? null,
            ]);

            return [
                'description' => $prompt,
                'platform' => null,
                'model' => null,
                'generated_at' => gmdate(DATE_ATOM),
            ];
        }
    }

    protected function buildAltTexts(string $description): Translation
    {
        $enabledLocales = $this->parameterBag->get('kernel.enabled_locales');
        $enabledLocales = is_array($enabledLocales) && [] !== $enabledLocales ? $enabledLocales : ['en'];

        $translation = new Translation();
        $translation->setDefaultLocale($enabledLocales[0]);

        foreach ($enabledLocales as $locale) {
            $translation->setTranslation($locale, $description);
        }

        return $translation;
    }

    protected function buildTitleFromPrompt(string $prompt): string
    {
        $title = preg_replace('/\s+/', ' ', trim($prompt)) ?: 'AI generated image';

        return mb_substr($title, 0, 120);
    }
}
