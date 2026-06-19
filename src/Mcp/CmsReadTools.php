<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Softspring\CmsBundle\Config\CmsConfig;
use Softspring\CmsBundle\Manager\ContentManagerInterface;
use Softspring\CmsBundle\Manager\MenuManagerInterface;
use Softspring\CmsBundle\Model\ContentInterface;
use Softspring\CmsBundle\Model\ContentVersionInterface;
use Softspring\CmsBundle\Model\MenuInterface;
use Softspring\CmsBundle\Model\MenuItemInterface;
use Softspring\CmsBundle\Model\RouteInterface;
use Softspring\CmsBundle\Model\RoutePathInterface;
use Softspring\CmsBundle\Model\SiteInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;
use function array_slice;
use function count;
use function in_array;
use function is_array;
use function is_string;
use const DATE_ATOM;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

class CmsReadTools
{
    private const SITE_AI_METADATA_FIELD = 'sfs_cms_ai';

    public function __construct(
        private readonly CmsConfig $cmsConfig,
        private readonly ContentManagerInterface $contentManager,
        private readonly MenuManagerInterface $menuManager,
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[McpTool(
        name: 'cms_get_site_context',
        title: 'Get CMS site context',
        description: 'Return read-only CMS configuration, metadata, and AI instructions for one site.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function getSiteContext(
        #[Schema(type: 'string', description: 'CMS site id.')]
        string $site,
    ): array {
        try {
            $siteEntity = $this->cmsConfig->getSite($site, false);

            if (!$siteEntity instanceof SiteInterface) {
                return ['error' => sprintf('Site "%s" was not found.', $site)];
            }

            return $this->serializeSite($siteEntity, true);
        } catch (Throwable $e) {
            return $this->toolError($e);
        }
    }

    #[McpTool(
        name: 'cms_search_published_content',
        title: 'Search published CMS content',
        description: 'Search published CMS content by text, content type, site, and locale. Returns summaries only.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function searchPublishedContent(
        #[Schema(type: 'string', description: 'Free text query matched against content name and published payload.')]
        string $query = '',
        #[Schema(type: 'string', description: 'Optional CMS content type id.')]
        ?string $contentType = null,
        #[Schema(type: 'string', description: 'Optional CMS site id.')]
        ?string $site = null,
        #[Schema(type: 'string', description: 'Optional locale filter.')]
        ?string $locale = null,
        #[Schema(type: 'integer', description: 'Maximum number of results. Capped at 50.', minimum: 1, maximum: 50)]
        int $limit = 10,
    ): array {
        try {
            $limit = $this->normalizeLimit($limit);
            $query = trim($query);
            $rows = [];

            foreach ($this->getContentTypes($contentType) as $type) {
                foreach ($this->findPublishedContents($type, $site, max($limit * 5, 50)) as $content) {
                    if ($locale && !in_array($locale, $content->getLocales() ?? [], true)) {
                        continue;
                    }

                    if ('' !== $query && !$this->contentMatchesQuery($content, $query)) {
                        continue;
                    }

                    $rows[] = $this->addAdminUrls($this->serializeContentSummary($content, $type, $locale), $locale);

                    if (count($rows) >= $limit) {
                        break 2;
                    }
                }
            }

            return [
                'query' => $query,
                'filters' => [
                    'contentType' => $contentType,
                    'site' => $site,
                    'locale' => $locale,
                ],
                'count' => count($rows),
                'results' => $rows,
            ];
        } catch (Throwable $e) {
            return $this->toolError($e);
        }
    }

    #[McpTool(
        name: 'cms_get_published_content',
        title: 'Get published CMS content',
        description: 'Return a published CMS content summary and optionally its published payload.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function getPublishedContent(
        #[Schema(type: 'string', description: 'CMS content id.')]
        string $contentId,
        #[Schema(type: 'string', description: 'Optional CMS content type id.')]
        ?string $contentType = null,
        #[Schema(type: 'string', description: 'Optional locale filter for route summaries.')]
        ?string $locale = null,
        #[Schema(type: 'boolean', description: 'Whether to include the published version data payload.')]
        bool $includeData = false,
    ): array {
        try {
            $typedContent = $this->findContentById($contentId, $contentType);

            if (null === $typedContent) {
                return ['error' => sprintf('Content "%s" was not found.', $contentId)];
            }

            [$type, $content] = $typedContent;
            $version = $content->getPublishedVersion();

            if (!$version instanceof ContentVersionInterface) {
                return ['error' => sprintf('Content "%s" is not published.', $contentId)];
            }

            return $this->addAdminUrls($this->serializeContentDetail($content, $type, $version, $locale, $includeData), $locale);
        } catch (Throwable $e) {
            return $this->toolError($e);
        }
    }

    #[McpTool(
        name: 'cms_find_internal_links',
        title: 'Find CMS internal links',
        description: 'Find published CMS routes that can be used as internal links.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function findInternalLinks(
        #[Schema(type: 'string', description: 'Free text query matched against content name and published payload.')]
        string $query = '',
        #[Schema(type: 'string', description: 'Optional CMS site id.')]
        ?string $site = null,
        #[Schema(type: 'string', description: 'Optional locale filter.')]
        ?string $locale = null,
        #[Schema(type: 'integer', description: 'Maximum number of link rows. Capped at 50.', minimum: 1, maximum: 50)]
        int $limit = 10,
    ): array {
        try {
            $search = $this->searchPublishedContent($query, null, $site, $locale, $limit);
            if (isset($search['error'])) {
                return $search;
            }

            $links = [];

            foreach ($search['results'] as $row) {
                foreach ($row['routes'] as $route) {
                    foreach ($route['paths'] as $path) {
                        $links[] = [
                            'contentId' => $row['id'],
                            'contentType' => $row['type'],
                            'name' => $row['name'],
                            'routeId' => $route['id'],
                            'locale' => $path['locale'],
                            'path' => $path['compiledPath'] ?? $path['path'],
                            'sites' => $path['sites'],
                        ];
                    }
                }
            }

            return [
                'query' => trim($query),
                'count' => count($links),
                'links' => array_slice($links, 0, $this->normalizeLimit($limit)),
            ];
        } catch (Throwable $e) {
            return $this->toolError($e);
        }
    }

    #[McpTool(
        name: 'cms_get_menu_context',
        title: 'Get CMS menu context',
        description: 'Return CMS menu data and menu item tree for navigation context.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function getMenuContext(
        #[Schema(type: 'string', description: 'Optional CMS menu id. If omitted, returns a list of menus.')]
        ?string $menu = null,
    ): array {
        try {
            $repository = $this->menuManager->getRepository();

            if (null === $menu || '' === trim($menu)) {
                return [
                    'menus' => array_map(
                        fn (MenuInterface $menuEntity): array => $this->serializeMenuSummary($menuEntity),
                        $repository->findAll(),
                    ),
                ];
            }

            $menuEntity = $repository->find($menu);

            if (!$menuEntity instanceof MenuInterface) {
                return ['error' => sprintf('Menu "%s" was not found.', $menu)];
            }

            return $this->serializeMenu($menuEntity);
        } catch (Throwable $e) {
            return $this->toolError($e);
        }
    }

    /**
     * @return list<ContentInterface>
     */
    private function findPublishedContents(string $contentType, ?string $site, int $maxResults): array
    {
        $qb = $this->contentManager->getRepository($contentType)->createQueryBuilder('content')
            ->andWhere('content.publishedVersion IS NOT NULL')
            ->setMaxResults($maxResults);

        if ($site) {
            $qb
                ->innerJoin('content.sites', 'site')
                ->andWhere('site.id = :site')
                ->setParameter('site', $site);
        }

        try {
            return $qb->getQuery()->getResult();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function getContentTypes(?string $contentType): array
    {
        if (null !== $contentType && '' !== trim($contentType)) {
            return $this->cmsConfig->getContent($contentType, false) ? [$contentType] : [];
        }

        return array_keys($this->cmsConfig->getContents());
    }

    /**
     * @return array{0: string, 1: ContentInterface}|null
     */
    private function findContentById(string $contentId, ?string $contentType): ?array
    {
        foreach ($this->getContentTypes($contentType) as $type) {
            $content = $this->contentManager->getRepository($type)->find($contentId);

            if ($content instanceof ContentInterface) {
                return [$type, $content];
            }
        }

        return null;
    }

    private function contentMatchesQuery(ContentInterface $content, string $query): bool
    {
        $haystack = [
            $content->getName(),
            $content->getPublishedVersion()?->getLayout(),
            json_encode($content->getExtraData(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($content->getPublishedVersion()?->getSeo(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($content->getPublishedVersion()?->getData(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        return str_contains(mb_strtolower(implode("\n", array_filter($haystack))), mb_strtolower($query));
    }

    private function serializeContentSummary(ContentInterface $content, string $contentType, ?string $locale = null): array
    {
        $version = $content->getPublishedVersion();

        return [
            'id' => $content->getId(),
            'type' => $contentType,
            'name' => $content->getName(),
            'defaultLocale' => $content->getDefaultLocale(),
            'locales' => $content->getLocales(),
            'sites' => array_map(static fn (SiteInterface $site): ?string => $site->getId(), $content->getSites()->toArray()),
            'publishedVersion' => $version instanceof ContentVersionInterface ? [
                'id' => $version->getId(),
                'versionNumber' => $version->getVersionNumber(),
                'layout' => $version->getLayout(),
                'createdAt' => $version->getCreatedAt()?->format(DATE_ATOM),
            ] : null,
            'routes' => $this->serializeRoutes($content, $locale),
        ];
    }

    private function serializeContentDetail(ContentInterface $content, string $contentType, ContentVersionInterface $version, ?string $locale, bool $includeData): array
    {
        $detail = $this->serializeContentSummary($content, $contentType, $locale);
        $detail['extraData'] = $content->getExtraData();
        $detail['indexing'] = $content->getIndexing();
        $detail['publishedVersion']['seo'] = $version->getSeo();

        if ($includeData) {
            $detail['publishedVersion']['data'] = $version->getData();
        }

        return $detail;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeRoutes(ContentInterface $content, ?string $locale = null): array
    {
        return array_map(
            fn (RouteInterface $route): array => [
                'id' => $route->getId(),
                'type' => $route->getType(),
                'paths' => $this->serializeRoutePaths($route, $locale),
            ],
            $content->getRoutes()->toArray(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeRoutePaths(RouteInterface $route, ?string $locale = null): array
    {
        $paths = [];

        foreach ($route->getPaths() as $path) {
            if (!$path instanceof RoutePathInterface) {
                continue;
            }

            if ($locale && $path->getLocale() !== $locale) {
                continue;
            }

            $paths[] = [
                'id' => $path->getId(),
                'locale' => $path->getLocale(),
                'path' => $path->getPath(),
                'compiledPath' => $path->getCompiledPath(),
                'cacheTtl' => $path->getCacheTtl(),
                'sites' => array_map(static fn (SiteInterface $site): ?string => $site->getId(), $path->getSites()->toArray()),
            ];
        }

        return $paths;
    }

    private function serializeSite(SiteInterface $site, bool $includeConfig = false): array
    {
        $data = [
            'id' => $site->getId(),
            'canonical' => [
                'scheme' => $site->getCanonicalScheme(),
                'host' => $site->getCanonicalHost(),
                'port' => $site->getCanonicalPort(),
            ],
            'metadata' => $site->getMetadata(),
            'aiInstructions' => $site->getMetadataField(self::SITE_AI_METADATA_FIELD, []),
        ];

        if ($includeConfig) {
            $data['config'] = $site->getConfig();
        }

        return $data;
    }

    private function serializeMenuSummary(MenuInterface $menu): array
    {
        return [
            'id' => $menu->getId(),
            'type' => $menu->getType(),
            'name' => $menu->getName(),
        ];
    }

    private function serializeMenu(MenuInterface $menu): array
    {
        return $this->serializeMenuSummary($menu) + [
            'data' => $menu->getData(),
            'items' => array_map(
                fn (MenuItemInterface $item): array => $this->serializeMenuItem($item),
                $menu->getItems()?->filter(static fn (MenuItemInterface $item): bool => null === $item->getParent())->toArray() ?? [],
            ),
        ];
    }

    private function serializeMenuItem(MenuItemInterface $item): array
    {
        return [
            'id' => $item->getId(),
            'type' => $item->getType(),
            'text' => $item->getText(),
            'symfonyRoute' => $item->getSymfonyRoute(),
            'options' => $item->getOptions(),
            'items' => array_map(
                fn (MenuItemInterface $child): array => $this->serializeMenuItem($child),
                $item->getItems()?->toArray() ?? [],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function addAdminUrls(array $row, ?string $locale): array
    {
        if (!is_string($row['id'] ?? null) || !is_string($row['type'] ?? null)) {
            return $row;
        }

        $row['adminUrls'] = $this->buildContentAdminUrls($row, $locale);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string|null>
     */
    private function buildContentAdminUrls(array $row, ?string $locale): array
    {
        $contentType = $row['type'];
        $parameters = [
            'content' => $row['id'],
        ];

        $locale = $this->resolveContentLocale($row, $locale);
        if ($locale) {
            $parameters['_locale'] = $locale;
        }

        return [
            'content' => $this->generateAdminUrl("sfs_cms_admin_content_{$contentType}_content", $parameters),
            'details' => $this->generateAdminUrl("sfs_cms_admin_content_{$contentType}_details", $parameters),
            'preview' => $this->generateAdminUrl("sfs_cms_admin_content_{$contentType}_preview", $parameters),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveContentLocale(array $row, ?string $requestedLocale): ?string
    {
        if ($this->hasLocale($row, $requestedLocale)) {
            return $requestedLocale;
        }

        $requestLocale = $this->requestStack->getCurrentRequest()?->attributes->get('_locale')
            ?: $this->requestStack->getCurrentRequest()?->getLocale();

        if ($this->hasLocale($row, $requestLocale)) {
            return $requestLocale;
        }

        if (is_string($row['defaultLocale'] ?? null) && '' !== $row['defaultLocale']) {
            return $row['defaultLocale'];
        }

        if (is_array($row['locales'] ?? null)) {
            foreach ($row['locales'] as $locale) {
                if (is_string($locale) && '' !== $locale) {
                    return $locale;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasLocale(array $row, mixed $locale): bool
    {
        return is_string($locale)
            && '' !== $locale
            && is_array($row['locales'] ?? null)
            && in_array($locale, $row['locales'], true);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function generateAdminUrl(string $route, array $parameters): ?string
    {
        try {
            return $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min(50, $limit));
    }

    private function toolError(Throwable $e): array
    {
        return [
            'error' => $e->getMessage(),
            'type' => $e::class,
        ];
    }
}
