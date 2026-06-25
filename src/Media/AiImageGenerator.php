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
        protected MediaImageGenerationRequirements $generationRequirements,
        protected string $openAiApiKey,
        protected string $geminiApiKey,
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
        $size = $this->generationRequirements->resolveGenerationSize($uploadRequirements);

        $mimeType = 'image/png';
        $contents = match ($platform) {
            'openai' => $this->requestOpenAiImage($this->buildPrompt($prompt, $uploadRequirements, $this->resolveOpenAiSizeForModel($size, $model)), $this->resolveOpenAiSizeForModel($size, $model), $model),
            'gemini' => $this->requestGeminiImage($this->buildPrompt($prompt, $uploadRequirements, $size), $this->resolveGeminiAspectRatio($size), $model),
            default => throw new RuntimeException(sprintf('The "%s" AI platform is not supported for media image generation yet.', $platform)),
        };
        if ('gemini' === $platform) {
            $mimeType = 'image/jpeg';
        }

        $path = tempnam(sys_get_temp_dir(), 'sfs-cms-ai-media-');
        if (false === $path) {
            throw new RuntimeException('A temporary file could not be created for the generated image.');
        }

        file_put_contents($path, $contents);

        return new AiGeneratedImage($path, $this->buildOriginalName($prompt, $mimeType), $mimeType);
    }

    protected function requestGeminiImage(string $prompt, string $aspectRatio, string $model): string
    {
        if ('' === trim($this->geminiApiKey)) {
            throw new RuntimeException('The Gemini API key is not configured for media image generation.');
        }

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ],
            'response_format' => [
                'type' => 'image',
                'mime_type' => 'image/jpeg',
                'aspect_ratio' => $aspectRatio,
            ],
        ];

        try {
            $response = $this->httpClient->request('POST', 'https://generativelanguage.googleapis.com/v1beta/interactions', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->geminiApiKey,
                ],
                'json' => $payload,
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            throw new RuntimeException(sprintf('Gemini image generation request failed: %s', $exception->getMessage()), 0, $exception);
        }

        if ($statusCode >= 400) {
            $message = $data['error']['message'] ?? sprintf('Gemini returned HTTP %d.', $statusCode);
            throw new RuntimeException($message);
        }

        $image = $this->findBase64Image($data);
        if (!$image) {
            throw new RuntimeException('Gemini did not return an image.');
        }

        $contents = base64_decode($image, true);
        if (false === $contents) {
            throw new RuntimeException('The generated Gemini image could not be decoded.');
        }

        return $contents;
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
                'timeout' => 120,
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

    protected function resolveOpenAiSizeForModel(string $size, string $model): string
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

    protected function resolveGeminiAspectRatio(string $size): string
    {
        if (!preg_match('/^(?<width>\d+)x(?<height>\d+)$/', $size, $matches)) {
            return '1:1';
        }

        $width = (int) $matches['width'];
        $height = (int) $matches['height'];
        if ($width <= 0 || $height <= 0) {
            return '1:1';
        }

        $divisor = $this->greatestCommonDivisor($width, $height);

        return sprintf('%d:%d', (int) ($width / $divisor), (int) ($height / $divisor));
    }

    protected function greatestCommonDivisor(int $a, int $b): int
    {
        while (0 !== $b) {
            [$a, $b] = [$b, $a % $b];
        }

        return max(1, abs($a));
    }

    protected function findBase64Image(array $data): ?string
    {
        foreach (['output_image', 'image', 'inline_data', 'inlineData'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                if (is_string($data[$key]['data'] ?? null) && '' !== $data[$key]['data']) {
                    return $data[$key]['data'];
                }

                $image = $this->findBase64Image($data[$key]);
                if ($image) {
                    return $image;
                }
            }
        }

        $mimeType = $data['mime_type'] ?? $data['mimeType'] ?? null;
        $imageData = $data['data'] ?? null;
        if (is_string($mimeType) && str_starts_with($mimeType, 'image/') && is_string($imageData) && '' !== $imageData) {
            return $imageData;
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }

            $image = $this->findBase64Image($value);
            if ($image) {
                return $image;
            }
        }

        return null;
    }

    protected function buildPrompt(string $prompt, array $uploadRequirements, string $size): string
    {
        $requirements = $this->generationRequirements->buildPromptRequirements($uploadRequirements, $size);

        return implode("\n", [
            'Create a production-ready website media image from this prompt.',
            'Avoid embedded text, logos, watermarks, UI chrome, borders, and captions unless the prompt explicitly asks for them.',
            implode(' ', $requirements),
            '',
            'Prompt:',
            $prompt,
        ]);
    }

    protected function buildOriginalName(string $prompt, string $mimeType = 'image/png'): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($prompt)) ?: 'ai-generated-image';
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 80) ?: 'ai-generated-image';
        $extension = 'image/jpeg' === $mimeType ? 'jpg' : 'png';

        return $slug.'.'.$extension;
    }
}
