<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Admin\ActionListener;

use Softspring\Component\CrudlController\Event\ViewEvent;
use Softspring\MediaBundle\SfsMediaEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MediaListViewListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SfsMediaEvents::ADMIN_MEDIAS_LIST_VIEW => [
                ['onMediaListView', -10],
            ],
        ];
    }

    public function onMediaListView(ViewEvent $event): void
    {
        $event->setTemplate('@SfsCmsAiPlugin/admin/media/list.html.twig');
    }
}
