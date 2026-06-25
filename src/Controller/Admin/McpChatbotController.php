<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Controller\Admin;

use Softspring\CmsAiPlugin\Form\Admin\McpChatbotForm;
use Softspring\CmsAiPlugin\Lab\McpChatLab;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class McpChatbotController extends AbstractController
{
    protected const SESSION_PLATFORM_KEY = 'sfs_cms_ai.mcp_chatbot.platform';
    protected const SESSION_MODEL_KEY = 'sfs_cms_ai.mcp_chatbot.model';
    protected const SESSION_HISTORY_KEY = 'sfs_cms_ai.mcp_chatbot.history';
    protected const SESSION_TOOL_CALLS_KEY = 'sfs_cms_ai.mcp_chatbot.tool_calls';

    #[Route('/', name: 'sfs_cms_ai_admin_mcp_chatbot')]
    public function __invoke(Request $request, McpChatLab $lab, FormFactoryInterface $formFactory): Response
    {
        $submittedData = $request->request->all('mcp_chatbot');
        $session = $request->getSession();

        if ($request->query->getBoolean('new')) {
            $session->remove(self::SESSION_PLATFORM_KEY);
            $session->remove(self::SESSION_MODEL_KEY);
            $session->remove(self::SESSION_HISTORY_KEY);
            $session->remove(self::SESSION_TOOL_CALLS_KEY);

            return $this->redirectToRoute('sfs_cms_ai_admin_mcp_chatbot', [
                '_locale' => $request->attributes->get('_locale'),
            ]);
        }

        $history = $this->getHistory($request);
        $toolCalls = $session->get(self::SESSION_TOOL_CALLS_KEY, []);
        $platforms = $lab->getPlatforms();
        $defaultPlatform = array_key_first($platforms);

        $selectedPlatform = $submittedData['platform'] ?? $session->get(self::SESSION_PLATFORM_KEY, $defaultPlatform);
        if (!$selectedPlatform || !array_key_exists($selectedPlatform, $platforms)) {
            $selectedPlatform = $defaultPlatform;
        }

        $models = $lab->getModels($selectedPlatform);
        $selectedModel = $submittedData['model'] ?? $session->get(self::SESSION_MODEL_KEY);
        if (!$selectedModel || !array_key_exists($selectedModel, $models)) {
            $selectedModel = null;
        }

        $form = $formFactory->create(McpChatbotForm::class, [
            'platform' => $selectedPlatform,
            'model' => $selectedModel,
            'question' => $submittedData['question'] ?? null,
        ], [
            'platforms' => $platforms,
            'models' => $models,
        ]);
        $form->handleRequest($request);

        $chat = null;
        $exception = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $session->set(self::SESSION_PLATFORM_KEY, $form->get('platform')->getData());
            $session->set(self::SESSION_MODEL_KEY, $form->get('model')->getData());

            try {
                $chat = $lab->ask(
                    $form->get('question')->getData(),
                    $form->get('model')->getData(),
                    $form->get('platform')->getData(),
                    $history,
                );
                $metrics = $this->normalizeMetrics($chat['metrics'] ?? null);

                $history[] = [
                    'role' => 'user',
                    'content' => $form->get('question')->getData(),
                ];
                $assistantMessage = [
                    'role' => 'assistant',
                    'content' => $chat['answer'] ?? '',
                ];
                if ($metrics) {
                    $assistantMessage['metrics'] = $metrics;
                }
                $history[] = $assistantMessage;
                $toolCalls = $chat['tool_calls'] ?? [];
                $session->set(self::SESSION_HISTORY_KEY, $history);
                $session->set(self::SESSION_TOOL_CALLS_KEY, $toolCalls);
            } catch (Throwable $e) {
                $exception = $e;
            }
        }

        if ($this->wantsJson($request)) {
            if ($exception) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => $exception->getMessage(),
                    'type' => $exception::class,
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            if (!$form->isSubmitted() || !$form->isValid()) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => 'The chatbot request is invalid.',
                    'formErrors' => $this->getFormErrors($form),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return new JsonResponse([
                'ok' => true,
                'answer' => $chat['answer'] ?? '',
                'metrics' => $this->normalizeMetrics($chat['metrics'] ?? null),
                'toolCalls' => $chat['tool_calls'] ?? [],
                'tools' => $chat['tools'] ?? $lab->getAvailableTools(),
                'history' => $history,
            ]);
        }

        return $this->render('@SfsCmsAiPlugin/admin/mcp_chatbot.html.twig', [
            'form' => $form->createView(),
            'chat' => $chat,
            'history' => $history,
            'tool_calls' => $toolCalls,
            'exception' => $exception,
            'tools' => $lab->getAvailableTools(),
        ]);
    }

    protected function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest() || str_contains($request->headers->get('Accept', ''), 'application/json');
    }

    protected function getFormErrors($form): array
    {
        $errors = [];

        foreach ($form->getErrors(true) as $error) {
            $origin = $error->getOrigin();
            $errors[] = [
                'field' => $origin?->getName(),
                'message' => $error->getMessage(),
            ];
        }

        return $errors;
    }

    protected function getHistory(Request $request): array
    {
        $history = $request->getSession()->get(self::SESSION_HISTORY_KEY, []);

        if (!is_array($history)) {
            return [];
        }

        $messages = [];

        foreach ($history as $message) {
            if (!is_array($message)
                || !in_array($message['role'] ?? null, ['user', 'assistant'], true)
                || !is_string($message['content'] ?? null)
                || '' === trim($message['content'])
            ) {
                continue;
            }

            $normalized = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
            $metrics = $this->normalizeMetrics($message['metrics'] ?? null);
            if ($metrics) {
                $normalized['metrics'] = $metrics;
            }

            $messages[] = $normalized;
        }

        return $messages;
    }

    protected function normalizeMetrics(mixed $metrics): ?array
    {
        if (!is_array($metrics)) {
            return null;
        }

        $normalized = [];

        if (is_numeric($metrics['durationMs'] ?? null)) {
            $normalized['durationMs'] = max(0, (int) $metrics['durationMs']);
        }

        if (is_array($metrics['tokens'] ?? null)) {
            $tokens = [];

            foreach ($metrics['tokens'] as $key => $value) {
                if (!is_string($key) || null === $value || !is_numeric($value)) {
                    continue;
                }

                $tokens[$key] = max(0, (int) $value);
            }

            if ($tokens) {
                $normalized['tokens'] = $tokens;
            }
        }

        return $normalized ?: null;
    }
}
