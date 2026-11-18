<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Tests\Controller;

use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Softspring\CmsAiPlugin\Controller\Admin\ContentEditorAgentController;
use Softspring\CmsAiPlugin\Lab\ContentEditorAgent;
use Softspring\CmsAiPlugin\Lab\ContentVersionPayloadContext;
use Softspring\CmsBundle\Manager\ContentManagerInterface;
use Softspring\CmsBundle\Model\ContentInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class ContentEditorAgentControllerTest extends TestCase
{
    public function testDisabledEditorDoesNotInvokeTheAgent(): void
    {
        $agent = $this->createMock(ContentEditorAgent::class);
        $agent->expects(self::never())->method('edit');
        $response = (new ContentEditorAgentController(false))(
            'page', 'content-id', new Request(), $agent,
            $this->createStub(ContentVersionPayloadContext::class),
            $this->createStub(ContentManagerInterface::class),
        );
        self::assertSame(404, $response->getStatusCode());
    }

    public function testInvalidPatchDoesNotReportSuccessOrChangeHistory(): void
    {
        $content = $this->createStub(ContentInterface::class);
        $content->method('getId')->willReturn('content-id');
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($content);
        $manager = $this->createStub(ContentManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $context = $this->createStub(ContentVersionPayloadContext::class);
        $context->method('getContentConfig')->willReturn([]);
        $agent = $this->createStub(ContentEditorAgent::class);
        $agent->method('buildConversationKey')->willReturn('history');
        $agent->method('getPlatforms')->willReturn(['gemini' => 'gemini']);
        $agent->method('getModels')->willReturn(['test' => 'test']);
        $agent->method('normalizeHistory')->willReturn([]);
        $agent->method('edit')->willReturn([
            'isValid' => false, 'answer' => 'Updated all paragraphs.',
            'payload' => ['data' => ['main' => ['invalid']]],
            'errors' => [['path' => 'data.main.0', 'message' => 'Missing module type.']],
        ]);
        $request = Request::create('/', 'POST', ['layout' => 'test', 'instruction' => 'Add emojis']);
        $session = new Session(new MockArraySessionStorage());
        $session->set('history', [['role' => 'user', 'content' => 'Previous request']]);
        $before = $session->all();
        $request->setSession($session);
        $response = (new ContentEditorAgentController())('page', 'content-id', $request, $agent, $context, $manager);
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($data['ok']);
        self::assertArrayNotHasKey('payload', $data);
        self::assertArrayNotHasKey('answer', $data);
        self::assertSame($before, $session->all());
    }
}
