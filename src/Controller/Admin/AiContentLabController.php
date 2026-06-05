<?php

namespace Softspring\CmsAiPlugin\Controller\Admin;

use Softspring\CmsAiPlugin\Form\Admin\AiContentLabForm;
use Softspring\CmsAiPlugin\Lab\AiContentLab;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AiContentLabController extends AbstractController
{
    #[Route('/admin/{_locale}/cms-ai/content-lab', name: 'sfs_cms_ai_admin_content_lab')]
    public function __invoke(Request $request, AiContentLab $lab, FormFactoryInterface $formFactory): Response
    {
        if ($request->request->has('persist_generated_version')) {
            $data = $request->request->all('persist_generated_version');

            try {
                $content = $lab->persistGeneratedVersion(
                    $data['content_type'],
                    $data['layout'],
                    json_decode($data['payload_json'] ?? '{}', true, 512, JSON_THROW_ON_ERROR),
                    $data['topic'] ?? null,
                );

                $this->addFlash('success', 'AI generated content version created successfully.');

                return $this->redirectToRoute(sprintf('sfs_cms_admin_content_%s_details', $data['content_type']), [
                    'content' => $content->getId(),
                    '_locale' => $request->getLocale(),
                ]);
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Could not persist generated content: '.$e->getMessage());
            }
        }

        $submittedData = $request->request->all('ai_content_lab');
        $contentTypes = $lab->getContentTypes();
        $selectedContentType = is_array($submittedData) && !empty($submittedData['contentType']) ? $submittedData['contentType'] : array_key_first($contentTypes);
        $layouts = $selectedContentType ? $lab->getLayouts($selectedContentType) : [];
        $selectedLayout = is_array($submittedData) && !empty($submittedData['layout']) ? $submittedData['layout'] : array_key_first($layouts);
        $platforms = $lab->getPlatforms();
        $defaultPlatform = array_key_first($platforms);
        $selectedPlatform = is_array($submittedData) && !empty($submittedData['platform']) ? $submittedData['platform'] : $defaultPlatform;
        $models = $lab->getModels($selectedPlatform);

        $form = $formFactory->create(AiContentLabForm::class, [
            'contentType' => $selectedContentType,
            'layout' => $selectedLayout,
            'platform' => $selectedPlatform,
            'model' => is_array($submittedData) ? ($submittedData['model'] ?? null) : null,
        ], [
            'content_types' => $contentTypes,
            'layouts' => $layouts,
            'platforms' => $platforms,
            'models' => $models,
        ]);
        $form->handleRequest($request);

        $selectedContentType = $form->get('contentType')->getData() ?: $selectedContentType;
        $selectedLayout = $form->get('layout')->getData() ?: $selectedLayout;
        $schema = null;
        $previewForm = null;
        $generation = null;
        $generationForm = null;
        $exception = null;

        if ($selectedContentType && $selectedLayout) {
            $schema = $lab->getSchema($selectedContentType, $selectedLayout);
            $previewForm = $lab->createPreviewForm($selectedContentType, $selectedLayout, null, 'preview_version_payload');
        }

        if ($form->isSubmitted() && $form->isValid() && $selectedContentType && $selectedLayout) {
            try {
                $generation = $lab->generate(
                    $selectedContentType,
                    $selectedLayout,
                    $form->get('topic')->getData(),
                    $form->get('instructions')->getData(),
                    $form->get('model')->getData(),
                    $form->get('platform')->getData()
                );

                if (!empty($generation['form'])) {
                    try {
                        $generationForm = $generation['form']->createView();
                    } catch (\Throwable $e) {
                        $generation['errors'][] = [
                            'path' => 'generation_form',
                            'message' => 'Generated payload could not be rendered back into the Symfony form: '.$e->getMessage(),
                            'code' => null,
                        ];
                        $generation['validation_exception'] ??= $e;
                    }
                }
            } catch (\Throwable $e) {
                $exception = $e;
            }
        }

        return $this->render('@SfsCmsAiPlugin/admin/content_lab.html.twig', [
            'form' => $form->createView(),
            'selected_content_type' => $selectedContentType,
            'selected_layout' => $selectedLayout,
            'schema' => $schema,
            'preview_form' => $previewForm?->createView(),
            'generation' => $generation,
            'generation_form' => $generationForm,
            'exception' => $exception,
            'platforms' => $platforms,
        ]);
    }
}
