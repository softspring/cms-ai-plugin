<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Controller\Admin;

use InvalidArgumentException;
use Softspring\CmsAiPlugin\Lab\ContentEditorAgent;
use Softspring\CmsAiPlugin\Lab\ContentVersionPayloadContext;
use Softspring\CmsBundle\Manager\ContentManagerInterface;
use Softspring\CmsBundle\Model\ContentInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class ContentEditorAgentController extends AbstractController
{
    #[Route('/content-editor-agent/{contentType}/{content}', name: 'sfs_cms_ai_admin_content_editor_agent', methods: ['POST'])]
    public function __invoke(
        string $contentType,
        string $content,
        Request $request,
        ContentEditorAgent $agent,
        ContentVersionPayloadContext $payloadContext,
        ContentManagerInterface $contentManager,
    ): JsonResponse {
        try {
            $payload = $this->getRequestPayload($request);
            $contentConfig = $payloadContext->getContentConfig($contentType);
            $contentEntity = $contentManager->getRepository($contentType)->find($content);

            if (!$contentEntity instanceof ContentInterface) {
                throw new NotFoundHttpException(sprintf('Content "%s" was not found.', $content));
            }

            $isGranted = $contentConfig['admin']['version_create']['is_granted'] ?? null;
            if ($isGranted && !$this->isGranted($isGranted, $contentEntity)) {
                throw new AccessDeniedHttpException('You are not allowed to edit this content.');
            }

            $layout = (string) ($payload['layout'] ?? '');
            if ('' === $layout) {
                throw new InvalidArgumentException('The current layout is required.');
            }

            $baseVersionId = isset($payload['baseVersionId']) && is_string($payload['baseVersionId']) && '' !== $payload['baseVersionId']
                ? $payload['baseVersionId']
                : null;
            $historyKey = $agent->buildConversationKey($contentType, (string) $contentEntity->getId(), $baseVersionId, $layout);
            $session = $request->getSession();

            if (!empty($payload['reset'])) {
                $session->remove(ContentEditorAgent::SESSION_PLATFORM_KEY);
                $session->remove(ContentEditorAgent::SESSION_MODEL_KEY);
                $session->remove($historyKey);

                return new JsonResponse([
                    'ok' => true,
                    'history' => [],
                ]);
            }

            $platform = $this->resolvePlatform($agent, $session->get(ContentEditorAgent::SESSION_PLATFORM_KEY), $payload['platform'] ?? null);
            $model = $this->resolveModel($agent, $platform, $session->get(ContentEditorAgent::SESSION_MODEL_KEY), $payload['model'] ?? null);

            $history = $agent->normalizeHistory($session->get($historyKey, []));
            $result = $agent->edit(
                (string) ($payload['instruction'] ?? ''),
                $model,
                $platform,
                [
                    'contentType' => $contentType,
                    'contentId' => (string) $contentEntity->getId(),
                    'contentName' => $contentEntity->getName(),
                    'layout' => $layout,
                    'baseVersionId' => $baseVersionId,
                    'baseVersionNumber' => $payload['baseVersionNumber'] ?? null,
                    'selectedLocale' => $payload['selectedLocale'] ?? null,
                    'selectedSite' => $payload['selectedSite'] ?? null,
                    'locales' => is_array($payload['locales'] ?? null) ? $payload['locales'] : $contentEntity->getLocales(),
                    'sites' => is_array($payload['sites'] ?? null) ? $payload['sites'] : [],
                    'currentPayload' => is_array($payload['currentPayload'] ?? null) ? $payload['currentPayload'] : [],
                ],
                $history,
            );

            $history[] = [
                'role' => 'user',
                'content' => (string) ($payload['instruction'] ?? ''),
            ];
            $history[] = [
                'role' => 'assistant',
                'content' => $result['answer'],
            ];

            $session->set($historyKey, $agent->normalizeHistory($history));
            $session->set(ContentEditorAgent::SESSION_PLATFORM_KEY, $platform);
            $session->set(ContentEditorAgent::SESSION_MODEL_KEY, $model);

            return new JsonResponse([
                'ok' => true,
                'answer' => $result['answer'],
                'payload' => $result['payload'],
                'mergedPayload' => $result['mergedPayload'],
                'replaceCollections' => $result['replaceCollections'],
                'isValid' => $result['isValid'],
                'errors' => $result['errors'],
                'rawResponse' => $result['rawResponse'],
                'toolCalls' => $result['toolCalls'],
                'history' => $agent->normalizeHistory($history),
            ]);
        } catch (Throwable $e) {
            $status = $e instanceof NotFoundHttpException ? Response::HTTP_NOT_FOUND : Response::HTTP_INTERNAL_SERVER_ERROR;
            $status = $e instanceof AccessDeniedHttpException ? Response::HTTP_FORBIDDEN : $status;

            return new JsonResponse([
                'ok' => false,
                'error' => $e->getMessage(),
                'type' => $e::class,
            ], $status);
        }
    }

    private function getRequestPayload(Request $request): array
    {
        if (str_contains($request->headers->get('Content-Type', ''), 'application/json')) {
            $decoded = json_decode($request->getContent(), true);

            return is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    private function resolvePlatform(ContentEditorAgent $agent, mixed $sessionPlatform, mixed $requestedPlatform): ?string
    {
        $platforms = $agent->getPlatforms();
        $platform = is_string($requestedPlatform) && isset($platforms[$requestedPlatform]) ? $requestedPlatform : null;
        $platform ??= is_string($sessionPlatform) && isset($platforms[$sessionPlatform]) ? $sessionPlatform : null;

        return $platform ?: array_key_first($platforms);
    }

    private function resolveModel(ContentEditorAgent $agent, ?string $platform, mixed $sessionModel, mixed $requestedModel): ?string
    {
        $models = $agent->getModels($platform);
        $model = is_string($requestedModel) && isset($models[$requestedModel]) ? $requestedModel : null;
        $model ??= is_string($sessionModel) && isset($models[$sessionModel]) ? $sessionModel : null;

        return $model ?: array_key_first($models);
    }
}
