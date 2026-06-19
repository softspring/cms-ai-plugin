<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Media;

use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiImageGenerator
{
    public function __construct(
        protected HttpClientInterface $httpClient,
        protected MediaImageRequirementsDescriber $requirementsDescriber,
        protected string $openAiApiKey,
        protected string $defaultPlatform = 'openai',
        protected string $defaultModel = 'gpt-image-1',
    ) {
    }

    public function generate(string $prompt, array $uploadRequirements = [], ?string $platform = null, ?string $model = null): AiGeneratedImage
    {
        $prompt = trim($prompt);
        if ('' === $prompt) {
            throw new RuntimeException('The image prompt can not be empty.');
        }

        $platform = $platform ?: $this->defaultPlatform;
        $model = $model ?: $this->defaultModel;

        if ('openai' !== $platform) {
            throw new RuntimeException(sprintf('The "%s" AI platform is not supported for media image generation yet.', $platform));
        }

        $size = $this->resolveSizeForModel($this->requirementsDescriber->resolveGenerationSize($uploadRequirements), $model);
        $contents = $this->requestOpenAiImage($this->buildPrompt($prompt, $uploadRequirements, $size), $size, $model);

        $path = tempnam(sys_get_temp_dir(), 'sfs-cms-ai-media-');
        if (false === $path) {
            throw new RuntimeException('A temporary file could not be created for the generated image.');
        }

        file_put_contents($path, $contents);

        return new AiGeneratedImage($path, $this->buildOriginalName($prompt), 'image/png');
    }

    protected function requestOpenAiImage(string $prompt, string $size, string $model): string
    {
        if ('' === trim($this->openAiApiKey)) {
            throw new RuntimeException('The OpenAI API key is not configured for media image generation.');
        }

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
        ];

        if (str_starts_with($model, 'dall-e-')) {
            $payload['response_format'] = 'b64_json';
        } else {
            $payload['output_format'] = 'png';
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/images/generations', [
                'auth_bearer' => $this->openAiApiKey,
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            throw new RuntimeException(sprintf('OpenAI image generation request failed: %s', $exception->getMessage()), 0, $exception);
        }

        if ($statusCode >= 400) {
            $message = $data['error']['message'] ?? sprintf('OpenAI returned HTTP %d.', $statusCode);
            throw new RuntimeException($message);
        }

        $base64Image = $data['data'][0]['b64_json'] ?? null;
        if (is_string($base64Image) && '' !== $base64Image) {
            $contents = base64_decode($base64Image, true);
            if (false === $contents) {
                throw new RuntimeException('The generated image could not be decoded.');
            }

            return $contents;
        }

        $imageUrl = $data['data'][0]['url'] ?? null;
        if (is_string($imageUrl) && '' !== $imageUrl) {
            try {
                return $this->httpClient->request('GET', $imageUrl, ['timeout' => 60])->getContent();
            } catch (TransportExceptionInterface $exception) {
                throw new RuntimeException(sprintf('Generated image download failed: %s', $exception->getMessage()), 0, $exception);
            }
        }

        throw new RuntimeException('OpenAI did not return an image.');
    }

    protected function resolveSizeForModel(string $size, string $model): string
    {
        if ('dall-e-3' === $model) {
            return match ($size) {
                '1536x1024' => '1792x1024',
                '1024x1536' => '1024x1792',
                default => '1024x1024',
            };
        }

        if ('dall-e-2' === $model) {
            return '1024x1024';
        }

        return $size;
    }

    protected function buildPrompt(string $prompt, array $uploadRequirements, string $size): string
    {
        $requirements = $this->requirementsDescriber->buildPromptRequirements($uploadRequirements, $size);

        return implode("\n", [
            'Create a production-ready website media image from this prompt.',
            'Avoid embedded text, logos, watermarks, UI chrome, borders, and captions unless the prompt explicitly asks for them.',
            implode(' ', $requirements),
            '',
            'Prompt:',
            $prompt,
        ]);
    }

    protected function buildOriginalName(string $prompt): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($prompt)) ?: 'ai-generated-image';
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 80) ?: 'ai-generated-image';

        return $slug.'.png';
    }
}
