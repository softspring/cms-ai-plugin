<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Tests\Security;

use PHPUnit\Framework\TestCase;
use Softspring\Bundle\ConfigBundle\DependencyInjection\SfsConfigExtension;
use Softspring\CmsAiPlugin\DependencyInjection\SfsCmsAiExtension;
use Softspring\CmsAiPlugin\Instructions\AiInstructions;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Yaml\Yaml;

class AiConfigurationPermissionTest extends TestCase
{
    public function testConfigurationChangesRequireThePublishedPermission(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new SfsConfigExtension());
        new SfsCmsAiExtension()->prepend($container);
        $config = $container->getExtensionConfig('sfs_config')[0];
        $definition = $config['definitions'][AiInstructions::KEY];

        self::assertSame(AiInstructions::MANAGE_PERMISSION, $definition['permissions']['update']);
        self::assertSame(AiInstructions::MANAGE_PERMISSION, $definition['permissions']['delete']);
        self::assertArrayNotHasKey('read', $definition['permissions']);
        self::assertSame('cms', $definition['group']);
        self::assertSame('groups.cms.label', $config['groups']['cms']['label']);
    }

    public function testRoleGroupGrantsThePermissionThroughThePermissionsVoter(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2).'/config/security/admin_role_hierarchy.yaml');
        $voter = new RoleHierarchyVoter(new RoleHierarchy($config['security']['role_hierarchy']), 'PERMISSION_');
        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn(['ROLE_SFS_CMS_ADMIN_AI_CONFIG']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, [AiInstructions::MANAGE_PERMISSION]));
    }

    public function testPluginDoesNotGrantThePermissionToEveryAdministrator(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2).'/config/security/admin_role_hierarchy.yaml');
        $voter = new RoleHierarchyVoter(new RoleHierarchy($config['security']['role_hierarchy']), 'PERMISSION_');
        $token = $this->createStub(TokenInterface::class);
        $token->method('getRoleNames')->willReturn(['ROLE_ADMIN']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, [AiInstructions::MANAGE_PERMISSION]));
    }
}
