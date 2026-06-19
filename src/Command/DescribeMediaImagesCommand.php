<?php

declare(strict_types=1);

namespace Softspring\CmsAiPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Softspring\CmsAiPlugin\Media\AiImageDescriber;
use Softspring\MediaBundle\Model\MediaInterface;
use Softspring\MediaBundle\Model\MediaVersionInterface;
use Softspring\MediaBundle\Storage\StorageDriverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

#[AsCommand(name: 'sfs:cms-ai:media:describe-images', description: 'Generate AI descriptions for existing CMS media images.')]
class DescribeMediaImagesCommand extends Command
{
    protected const METADATA_FIELD = 'sfs_cms_ai';
    protected const IMAGE_DESCRIPTION_FIELD = 'image_description';

    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected StorageDriverInterface $storageDriver,
        protected AiImageDescriber $imageDescriber,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate descriptions even when metadata already exists.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List candidate images without calling AI or writing metadata.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of image media records to inspect.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only process one media type key.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $type = $input->getOption('type');
        $limit = $this->resolveLimit($input->getOption('limit'));

        $stats = [
            'seen' => 0,
            'described' => 0,
            'dry_run' => 0,
            'skipped_existing' => 0,
            'skipped_missing_original' => 0,
            'skipped_missing_url' => 0,
            'skipped_unsupported_mime' => 0,
            'errors' => 0,
        ];

        $queryBuilder = $this->entityManager->getRepository(MediaInterface::class)->createQueryBuilder('media')
            ->andWhere('media.mediaType = :imageType')
            ->setParameter('imageType', MediaInterface::MEDIA_TYPE_IMAGE)
            ->orderBy('media.createdAt', 'ASC');

        if (is_string($type) && '' !== trim($type)) {
            $queryBuilder
                ->andWhere('media.type = :type')
                ->setParameter('type', trim($type));
        }

        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }

        /** @var iterable<MediaInterface> $medias */
        $medias = $queryBuilder->getQuery()->toIterable();

        foreach ($medias as $media) {
            ++$stats['seen'];

            if (!$force && $this->hasImageDescription($media)) {
                ++$stats['skipped_existing'];
                continue;
            }

            $version = $media->getVersion('_original');
            if (!$version instanceof MediaVersionInterface) {
                ++$stats['skipped_missing_original'];
                continue;
            }

            $url = $version->getUrl();
            if (!is_string($url) || '' === $url) {
                ++$stats['skipped_missing_url'];
                continue;
            }

            if (is_string($version->getFileMimeType()) && !in_array($version->getFileMimeType(), AiImageDescriber::SUPPORTED_MIME_TYPES, true)) {
                ++$stats['skipped_unsupported_mime'];
                continue;
            }

            if ($dryRun) {
                ++$stats['dry_run'];
                $io->writeln(sprintf('Would describe media "%s" (%s)', $media->getName() ?: $media->getId(), $media->getId()));
                continue;
            }

            $tmpPath = $this->createTempPath();

            try {
                $this->storageDriver->download($url, $tmpPath);
                $file = new File($tmpPath);

                if (!in_array((string) $file->getMimeType(), AiImageDescriber::SUPPORTED_MIME_TYPES, true)) {
                    ++$stats['skipped_unsupported_mime'];
                    continue;
                }

                $description = $this->imageDescriber->describe($file);
                $dimensions = $this->safeDimensions($file);
                $description += [
                    'source_version' => $version->getVersion(),
                    'source_mime_type' => $file->getMimeType(),
                    'source_sha1' => $this->safeSha1($file),
                    'source_width' => $version->getWidth() ?? $dimensions['width'],
                    'source_height' => $version->getHeight() ?? $dimensions['height'],
                ];

                $aiMetadata = $media->getMetadataField(self::METADATA_FIELD, []);
                if (!is_array($aiMetadata)) {
                    $aiMetadata = [];
                }

                $aiMetadata[self::IMAGE_DESCRIPTION_FIELD] = $description;
                $media->setMetadataField(self::METADATA_FIELD, $aiMetadata);

                ++$stats['described'];

                if (0 === $stats['described'] % 10) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }

                $io->writeln(sprintf('Described media "%s" (%s)', $media->getName() ?: $media->getId(), $media->getId()));
            } catch (Throwable $exception) {
                ++$stats['errors'];
                $io->warning(sprintf('Could not describe media "%s" (%s): %s', $media->getName() ?: $media->getId(), $media->getId(), $exception->getMessage()));
            } finally {
                if (is_file($tmpPath)) {
                    unlink($tmpPath);
                }
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(['Metric', 'Count'], array_map(
            static fn (string $metric, int $count): array => [$metric, $count],
            array_keys($stats),
            $stats,
        ));

        return 0 === $stats['errors'] ? Command::SUCCESS : Command::FAILURE;
    }

    protected function resolveLimit(mixed $limit): ?int
    {
        if (null === $limit || '' === $limit) {
            return null;
        }

        $limit = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return false === $limit ? null : $limit;
    }

    protected function hasImageDescription(MediaInterface $media): bool
    {
        $aiMetadata = $media->getMetadataField(self::METADATA_FIELD, []);

        return is_array($aiMetadata) && is_array($aiMetadata[self::IMAGE_DESCRIPTION_FIELD] ?? null);
    }

    protected function createTempPath(): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'sfs-cms-ai-describe-');
        if (false === $tmpPath) {
            throw new RuntimeException('A temporary file could not be created for media image description.');
        }

        return $tmpPath;
    }

    protected function safeSha1(File $file): ?string
    {
        $path = $file->getRealPath() ?: $file->getPathname();

        return is_readable($path) ? sha1_file($path) ?: null : null;
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    protected function safeDimensions(File $file): array
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        if (!is_readable($path)) {
            return ['width' => null, 'height' => null];
        }

        $size = getimagesize($path);
        if (false === $size) {
            return ['width' => null, 'height' => null];
        }

        return [
            'width' => $size[0],
            'height' => $size[1],
        ];
    }
}
