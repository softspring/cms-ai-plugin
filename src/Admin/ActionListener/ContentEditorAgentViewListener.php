<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Admin\ActionListener;

use Softspring\CmsAiPlugin\Lab\ContentEditorAgent;
use Softspring\CmsBundle\Model\ContentInterface;
use Softspring\CmsBundle\Model\ContentVersionInterface;
use Softspring\CmsBundle\SfsCmsEvents;
use Softspring\Component\CrudlController\Event\ViewEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;

class ContentEditorAgentViewListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly ContentEditorAgent $agent,
        private readonly RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SfsCmsEvents::ADMIN_CONTENT_VERSIONS_CREATE_VIEW => ['onContentVersionCreateView', -30],
        ];
    }

    public function onContentVersionCreateView(ViewEvent $event): void
    {
        $request = $event->getRequest();
        $contentConfig = $request->attributes->get('_content_config');
        $content = $request->attributes->get('content');
        $version = $request->attributes->get('version');

        if (!is_array($contentConfig) || !$content instanceof ContentInterface || !$version instanceof ContentVersionInterface) {
            return;
        }

        $contentType = (string) ($contentConfig['_id'] ?? '');
        $contentId = (string) $content->getId();
        $layout = (string) $version->getLayout();
        $prevVersion = $request->attributes->get('prevVersion');
        $baseVersionId = $prevVersion instanceof ContentVersionInterface ? (string) $prevVersion->getId() : null;
        $historyKey = $this->agent->buildConversationKey($contentType, $contentId, $baseVersionId, $layout);
        $session = $request->getSession();
        $platforms = $this->agent->getPlatforms();
        $selectedPlatform = $session->get(ContentEditorAgent::SESSION_PLATFORM_KEY);

        if (!is_string($selectedPlatform) || !isset($platforms[$selectedPlatform])) {
            $selectedPlatform = array_key_first($platforms);
        }

        $modelsByPlatform = $this->agent->getModelsByPlatform();
        $selectedModels = $selectedPlatform ? ($modelsByPlatform[$selectedPlatform] ?? []) : [];
        $selectedModel = $session->get(ContentEditorAgent::SESSION_MODEL_KEY);

        if (!is_string($selectedModel) || !isset($selectedModels[$selectedModel])) {
            $selectedModel = array_key_first($selectedModels);
        }

        $event->getData()['sfs_cms_ai_editor_agent'] = [
            'endpoint' => $this->router->generate('sfs_cms_ai_admin_content_editor_agent', [
                '_locale' => $request->getLocale(),
                'contentType' => $contentType,
                'content' => $contentId,
            ]),
            'history' => $this->agent->normalizeHistory($session->get($historyKey, [])),
            'platforms' => $platforms,
            'models_by_platform' => $modelsByPlatform,
            'selected_platform' => $selectedPlatform,
            'selected_model' => $selectedModel,
            'base_version_id' => $baseVersionId,
            'base_version_number' => $prevVersion instanceof ContentVersionInterface ? $prevVersion->getVersionNumber() : null,
            'tools' => $this->agent->getAvailableTools(),
        ];

        $event->setTemplate('@SfsCmsAiPlugin/admin/content/version_create_agent.html.twig');
    }
}
