<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Media;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class OpenAiImageModels
{
    private const CACHE_KEY = 'sfs_cms_ai_openai_image_models';
    private const CACHE_TTL = 21600;

    public function __construct(
        protected HttpClientInterface $httpClient,
        protected CacheInterface $cache,
        protected LoggerInterface $logger,
        protected string $openAiApiKey,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getChoices(): array
    {
        if ('' === trim($this->openAiApiKey)) {
            return [];
        }

        try {
            return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->fetchChoices();
            });
        } catch (Throwable $exception) {
            $this->logger->warning('OpenAI image model discovery failed.', [
                'exception' => $exception,
            ]);

            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    protected function fetchChoices(): array
    {
        $response = $this->httpClient->request('GET', 'https://api.openai.com/v1/models', [
            'auth_bearer' => $this->openAiApiKey,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode >= 400) {
            throw new RuntimeException($data['error']['message'] ?? sprintf('OpenAI returned HTTP %d.', $statusCode));
        }

        $models = [];
        foreach ($data['data'] ?? [] as $model) {
            $id = $model['id'] ?? null;
            if (!is_string($id) || !$this->isImageModel($id)) {
                continue;
            }

            $models[$id] = $id;
        }

        ksort($models);

        return $models;
    }

    protected function isImageModel(string $model): bool
    {
        return str_starts_with($model, 'gpt-image-')
            || str_starts_with($model, 'chatgpt-image-')
            || str_starts_with($model, 'dall-e-');
    }
}
