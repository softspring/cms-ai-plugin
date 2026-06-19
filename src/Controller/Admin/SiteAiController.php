<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Controller\Admin;

use Softspring\CmsAiPlugin\Form\Admin\Site\AiSiteInstructionsForm;
use Softspring\CmsBundle\Config\CmsConfig;
use Softspring\CmsBundle\Manager\SiteManagerInterface;
use Softspring\CmsBundle\Model\SiteInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use function is_array;
use function is_string;

class SiteAiController extends AbstractController
{
    private const METADATA_FIELD = 'sfs_cms_ai';

    public function __construct(
        private readonly CmsConfig $cmsConfig,
        private readonly SiteManagerInterface $siteManager,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    public function ai(Request $request): Response
    {
        $site = $this->loadSite($request);
        $form = $this->formFactory->create(AiSiteInstructionsForm::class, $this->getInstructions($site));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $instructions = $this->cleanInstructions($form->getData());

            if ([] === $instructions) {
                $site->removeMetadataField(self::METADATA_FIELD);
            } else {
                $site->setMetadataField(self::METADATA_FIELD, $instructions);
            }

            $this->siteManager->saveEntity($site);
            $this->addFlash('success', 'site.ai.success_flash');

            return new RedirectResponse($this->generateUrl('sfs_cms_admin_sites_ai', ['site' => $site->getId()]));
        }

        return $this->render('@SfsCmsAiPlugin/admin/site/ai.html.twig', [
            'site' => $site,
            'form' => $form->createView(),
        ]);
    }

    private function loadSite(Request $request): SiteInterface
    {
        $siteId = (string) $request->attributes->get('site');
        $site = '' !== $siteId ? $this->cmsConfig->getSite($siteId, false) : null;

        if (!$site instanceof SiteInterface) {
            throw new NotFoundHttpException('Site not found.');
        }

        return $site;
    }

    private function getInstructions(SiteInterface $site): array
    {
        $instructions = $site->getMetadataField(self::METADATA_FIELD, []);

        return is_array($instructions) ? $instructions : [];
    }

    private function cleanInstructions(array $instructions): array
    {
        $clean = [];

        foreach ($instructions as $field => $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ('' === $value) {
                continue;
            }

            $clean[$field] = $value;
        }

        return $clean;
    }
}
