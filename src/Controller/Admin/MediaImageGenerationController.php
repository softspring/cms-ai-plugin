<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Controller\Admin;

use Psr\Log\LoggerInterface;
use Softspring\CmsAiPlugin\Media\AiImageGenerator;
use Softspring\MediaBundle\Type\MediaTypesCollection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class MediaImageGenerationController extends AbstractController
{
    #[Route('/admin/{_locale}/cms-ai/media/generate-image/{type}', name: 'sfs_cms_ai_admin_media_generate_image', methods: ['POST'])]
    public function __invoke(
        string $type,
        Request $request,
        AiImageGenerator $imageGenerator,
        MediaTypesCollection $mediaTypesCollection,
        LoggerInterface $logger,
    ): Response {
        $prompt = trim((string) $request->request->get('prompt', ''));
        $platform = trim((string) $request->request->get('platform', 'openai'));
        $model = trim((string) $request->request->get('model', 'gpt-image-1'));

        if ('' === $prompt) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'The image prompt can not be empty.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $typeConfig = $mediaTypesCollection->getType($type);
            if ('image' !== ($typeConfig['type'] ?? null)) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => sprintf('Media type "%s" is not an image type.', $type),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $generatedImage = $imageGenerator->generate($prompt, $typeConfig['upload_requirements'] ?? [], $platform, $model);
        } catch (Throwable $exception) {
            $logger->error('Media AI image generation failed.', [
                'exception' => $exception,
                'media_type' => $type,
                'platform' => $platform,
                'model' => $model,
            ]);

            return new JsonResponse([
                'ok' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = new BinaryFileResponse($generatedImage->path);
        $response->headers->set('Content-Type', $generatedImage->mimeType);
        $response->headers->set('X-Generated-Filename', $generatedImage->originalName);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $generatedImage->originalName);
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
