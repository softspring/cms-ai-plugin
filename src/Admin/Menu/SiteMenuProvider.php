<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Admin\Menu;

use Softspring\CmsBundle\Admin\Menu\AbstractSiteMenuProvider;
use Softspring\CmsBundle\Admin\Menu\MenuItem;
use Softspring\CmsBundle\Model\SiteInterface;

class SiteMenuProvider extends AbstractSiteMenuProvider
{
    public static function getPriority(): int
    {
        return 250;
    }

    public function getMenu(array $menu, ?string $currentSelection = null, ?object $entity = null): array
    {
        if (!$entity instanceof SiteInterface) {
            return $menu;
        }

        $menu[] = new MenuItem(
            'ai',
            $this->translator->trans('site.tabs_menu.ai', [], 'sfs_cms_ai'),
            $this->router->generate('sfs_cms_admin_sites_ai', ['site' => $entity->getId()]),
            'ai' === $currentSelection,
            false,
        );

        return $menu;
    }
}
