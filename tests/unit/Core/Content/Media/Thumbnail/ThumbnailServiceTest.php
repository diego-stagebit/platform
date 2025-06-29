<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Media\Thumbnail;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderEntity;
use Shopware\Core\Content\Media\Aggregate\MediaFolderConfiguration\MediaFolderConfigurationEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailCollection;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnailSize\MediaThumbnailSizeCollection;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnailSize\MediaThumbnailSizeEntity;
use Shopware\Core\Content\Media\DataAbstractionLayer\MediaIndexingMessage;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaException;
use Shopware\Core\Content\Media\MediaType\DocumentType;
use Shopware\Core\Content\Media\MediaType\ImageType;
use Shopware\Core\Content\Media\Subscriber\MediaDeletionSubscriber;
use Shopware\Core\Content\Media\Thumbnail\ThumbnailService;
use Shopware\Core\Content\Media\Thumbnail\ThumbnailSizeCalculator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexer;
use Shopware\Core\Framework\Test\TestCaseHelper\ReflectionHelper;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[CoversClass(ThumbnailService::class)]
class ThumbnailServiceTest extends TestCase
{
    private ThumbnailService $thumbnailService;

    private Context $context;

    private FilesystemOperator&MockObject $filesystemPublic;

    private FilesystemOperator&MockObject $filesystemPrivate;

    private EventDispatcherInterface&MockObject $dispatcher;

    private EntityIndexer&MockObject $indexer;

    private Connection&MockObject $connection;

    private ThumbnailSizeCalculator $thumbnailSizeCalculator;

    /**
     * @var StaticEntityRepository<MediaThumbnailCollection>
     */
    private StaticEntityRepository $thumbnailRepository;

    /**
     * @var StaticEntityRepository<MediaFolderCollection>
     */
    private StaticEntityRepository $mediaFolderRepository;

    protected function setUp(): void
    {
        $this->filesystemPublic = $this->createMock(FilesystemOperator::class);
        $this->filesystemPrivate = $this->createMock(FilesystemOperator::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->indexer = $this->createMock(EntityIndexer::class);
        $this->connection = $this->createMock(Connection::class);
        $this->thumbnailSizeCalculator = new ThumbnailSizeCalculator();
        $this->context = Context::createDefaultContext();
        $this->thumbnailRepository = new StaticEntityRepository([]);
        $this->mediaFolderRepository = new StaticEntityRepository([]);
        $this->thumbnailService = new ThumbnailService(
            $this->thumbnailRepository,
            $this->filesystemPublic,
            $this->filesystemPrivate,
            $this->mediaFolderRepository,
            $this->dispatcher,
            $this->indexer,
            $this->thumbnailSizeCalculator,
            $this->connection,
        );
    }

    public function testGenerateWithValidMediaCollection(): void
    {
        $expected = [
            'id' => '$mediaThumbnailEntity-id-1',
        ];

        $this->thumbnailRepository->addSearch($expected);

        $mediaThumbnailEntity = $this->createMediaThumbnailEntity();
        $mediaFolderEntity = $this->createMediaFolderEntity();

        $file = file_get_contents(__DIR__ . '/shopware-logo.png');
        $this->filesystemPublic->expects($this->once())->method('read')->willReturn($file);

        $mediaEntity = $this->createMediaEntity($mediaThumbnailEntity, $mediaFolderEntity);
        $mediaThumbnailEntity->setMedia($mediaEntity);
        $mediaCollection = new MediaCollection([$mediaEntity]);

        $this->indexer->expects($this->once())
            ->method('handle')
            ->with(static::isInstanceOf(MediaIndexingMessage::class));

        $result = $this->thumbnailService->generate($mediaCollection, $this->context);

        static::assertCount(1, $this->thumbnailRepository->deletes);

        $deleted = $this->thumbnailRepository->deletes[0][0] ?? [];
        static::assertArrayHasKey('id', $deleted);
        static::assertSame($expected, $deleted);
        static::assertSame(1, $result);
    }

    public function testGenerateWithEmptyMediaCollection(): void
    {
        $mediaCollection = new MediaCollection([]);
        $result = $this->thumbnailService->generate($mediaCollection, $this->context);

        static::assertSame(0, $result);
    }

    public function testGenerateWithMediaWithoutThumbnails(): void
    {
        $mediaEntity = new MediaEntity();
        $mediaEntity->setId('media-id-1');

        $mediaCollection = new MediaCollection([$mediaEntity]);

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('Thumbnail association not loaded');

        $result = $this->thumbnailService->generate($mediaCollection, $this->context);

        static::assertSame(0, $result);
    }

    public function testGenerateWithNonImageMediaTypes(): void
    {
        $this->thumbnailRepository->addSearch([
            'id' => '$mediaThumbnailEntity-id-1',
        ]);

        $mediaThumbnailEntity = $this->createMediaThumbnailEntity();

        $mediaEntity = new MediaEntity();
        $mediaEntity->setId('media-id-1');
        $mediaEntity->setMediaType(new DocumentType());
        $mediaEntity->setThumbnails(new MediaThumbnailCollection([$mediaThumbnailEntity]));

        $mediaCollection = new MediaCollection([$mediaEntity]);

        $result = $this->thumbnailService->generate($mediaCollection, $this->context);

        static::assertSame(0, $result);
    }

    public function testGenerateWithInvalidMediaConfiguration(): void
    {
        $this->thumbnailRepository->addSearch([
            'id' => '$mediaThumbnailEntity-id-1',
        ]);

        $mediaThumbnailEntity = $this->createMediaThumbnailEntity();

        $mediaEntity = new MediaEntity();
        $mediaEntity->setId('media-id-1');
        $mediaEntity->setMediaFolder(new MediaFolderEntity());
        $mediaEntity->setThumbnails(new MediaThumbnailCollection([$mediaThumbnailEntity]));

        $mediaCollection = new MediaCollection([$mediaEntity]);

        $result = $this->thumbnailService->generate($mediaCollection, $this->context);

        static::assertSame(0, $result);
    }

    public function testUpdateWithValidMediaCollection(): void
    {
        $expected = [
            'id' => '$mediaThumbnailEntity-id-1',
        ];

        $this->thumbnailRepository->addSearch($expected);

        $mediaThumbnailEntity = $this->createMediaThumbnailEntity();
        $mediaFolderEntity = $this->createMediaFolderEntity();

        $file = file_get_contents(__DIR__ . '/shopware-logo.png');
        $this->filesystemPublic->expects($this->once())->method('read')->willReturn($file);

        $mediaEntity = $this->createMediaEntity($mediaThumbnailEntity, $mediaFolderEntity);
        $mediaThumbnailEntity->setMedia($mediaEntity);

        $mediaCollection = new MediaCollection([$mediaEntity]);
        $this->thumbnailService->generate($mediaCollection, $this->context);

        $newMediaEntity = $this->createMediaEntity($mediaThumbnailEntity, $mediaFolderEntity);
        $newMediaEntity->setThumbnails(new MediaThumbnailCollection([$mediaThumbnailEntity]));

        $this->connection->expects($this->once())
            ->method('transactional')
            ->willReturn($expected);

        $actual = $this->thumbnailService->updateThumbnails($newMediaEntity, $this->context, false);

        static::assertSame(1, $actual);
    }

    public function testDeleteThumbnailsExecutesRepository(): void
    {
        $expected = [
            'id' => '$mediaThumbnailEntity-id-1',
        ];

        $this->thumbnailRepository->addSearch($expected);
        $mediaThumbnailEntity = $this->createMediaThumbnailEntity();

        $mediaEntity = new MediaEntity();
        $mediaEntity->setId('media-id-1');
        $mediaEntity->setThumbnails(new MediaThumbnailCollection([$mediaThumbnailEntity]));

        $this->thumbnailService->deleteThumbnails($mediaEntity, $this->context);

        $deleted = $this->thumbnailRepository->deletes[0][0] ?? [];
        static::assertSame($expected, $deleted);
    }

    public function testDeleteThumbnailThrowsMediaContainsNoThumbnailException(): void
    {
        $mediaEntity = new MediaEntity();
        $mediaEntity->setId('media-id-1');

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('Media contains no thumbnails.');

        $this->thumbnailService->deleteThumbnails($mediaEntity, $this->context);
    }

    /**
     * @param array<string, int> $imageSize
     * @param array<string, int<1, max>> $preferredThumbnailSize
     * @param array<string, int> $expectedSize
     */
    #[DataProvider('thumbnailSizeProvider')]
    public function testCalculateThumbnailSize(array $imageSize, bool $keepAspectRatio, array $preferredThumbnailSize, array $expectedSize): void
    {
        $mediaFolderConfigEntity = new MediaFolderConfigurationEntity();
        $mediaFolderConfigEntity->setKeepAspectRatio($keepAspectRatio);

        $thumbnailSizeEntity = new MediaThumbnailSizeEntity();
        $thumbnailSizeEntity->setWidth($preferredThumbnailSize['width']);
        $thumbnailSizeEntity->setHeight($preferredThumbnailSize['height']);

        $method = ReflectionHelper::getMethod(ThumbnailService::class, 'calculateThumbnailSize');
        $calculatedSize = $method->invokeArgs($this->thumbnailService, [$imageSize, $thumbnailSizeEntity, $mediaFolderConfigEntity]);

        static::assertSame($expectedSize, $calculatedSize);
    }

    /**
     * @return array<array<array<string, int>|bool>>
     */
    public static function thumbnailSizeProvider(): array
    {
        return [
            // image size, keep aspect ratio, preferred size, expected size
            [['width' => 800, 'height' => 600], true, ['width' => 400, 'height' => 300], ['width' => 400, 'height' => 300]],
            [['width' => 800, 'height' => 600], false, ['width' => 800, 'height' => 300], ['width' => 800, 'height' => 300]],
            [['width' => 200, 'height' => 600], false, ['width' => 800, 'height' => 300], ['width' => 200, 'height' => 600]],
        ];
    }

    public function testThumbnailGenerationThrowExceptionWhenRemoteThumbnailEnabled(): void
    {
        $this->expectException(MediaException::class);
        $this->expectExceptionMessage(MediaException::thumbnailGenerationDisabled()->getMessage());

        $service = new ThumbnailService(
            $this->thumbnailRepository,
            $this->filesystemPublic,
            $this->filesystemPrivate,
            $this->mediaFolderRepository,
            $this->dispatcher,
            $this->indexer,
            $this->thumbnailSizeCalculator,
            $this->connection,
            true,
        );

        $service->generate(new MediaCollection(), $this->context);
    }

    public function testUpdateThumbnailThrowExceptionWhenRemoteThumbnailEnabled(): void
    {
        $this->expectException(MediaException::class);
        $this->expectExceptionMessage(MediaException::thumbnailGenerationDisabled()->getMessage());

        $service = new ThumbnailService(
            $this->thumbnailRepository,
            $this->filesystemPublic,
            $this->filesystemPrivate,
            $this->mediaFolderRepository,
            $this->dispatcher,
            $this->indexer,
            $this->thumbnailSizeCalculator,
            $this->connection,
            true,
        );

        $service->updateThumbnails(new MediaEntity(), $this->context, false);
    }

    public function testDeleteThumbnailThrowExceptionWhenRemoteThumbnailEnabled(): void
    {
        $this->expectException(MediaException::class);
        $this->expectExceptionMessage(MediaException::thumbnailGenerationDisabled()->getMessage());

        $service = new ThumbnailService(
            $this->thumbnailRepository,
            $this->filesystemPublic,
            $this->filesystemPrivate,
            $this->mediaFolderRepository,
            $this->dispatcher,
            $this->indexer,
            $this->thumbnailSizeCalculator,
            $this->connection,
            true,
        );

        $service->deleteThumbnails(new MediaEntity(), $this->context);
    }

    public function testUpdateThumbnailsAddsSyncFileDeleteStateToContext(): void
    {
        $this->thumbnailRepository->addSearch([
            'id' => 'media-1',
        ]);

        $this->connection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(function (callable $callback) {
                return $callback();
            });

        $mediaThumbnailEntity = $this->createMediaThumbnailEntity();
        $mediaFolderEntity = $this->createMediaFolderEntity();

        $file = file_get_contents(__DIR__ . '/shopware-logo.png');
        $this->filesystemPublic->method('read')->willReturn($file);

        $mediaEntity = $this->createMediaEntity($mediaThumbnailEntity, $mediaFolderEntity);
        $mediaThumbnailEntity->setMedia($mediaEntity);

        $mediaCollection = new MediaCollection([$mediaEntity]);
        $this->thumbnailService->generate($mediaCollection, $this->context);

        $newMediaEntity = $this->createMediaEntity($mediaThumbnailEntity, $mediaFolderEntity);
        $newMediaEntity->setThumbnails(new MediaThumbnailCollection([$mediaThumbnailEntity]));

        $actual = $this->thumbnailService->updateThumbnails($newMediaEntity, $this->context, false);
        static::assertSame(1, $actual);
        static::assertTrue($this->context->hasState(MediaDeletionSubscriber::SYNCHRONE_FILE_DELETE));
    }

    private function createMediaEntity(MediaThumbnailEntity $mediaThumbnailEntity, MediaFolderEntity $mediaFolderEntity): MediaEntity
    {
        $mediaEntity = new MediaEntity();
        $mediaEntity->setId('media-id-1');
        $mediaEntity->setThumbnails(new MediaThumbnailCollection([$mediaThumbnailEntity]));
        $mediaEntity->setMediaFolder($mediaFolderEntity);
        $mediaEntity->setFileName('shopware-logo');
        $mediaEntity->setFileExtension('png');
        $mediaEntity->setMimeType('image/png');
        $mediaEntity->setMetaData(['example' => 'metadata']);
        $mediaType = new ImageType();
        $mediaEntity->setMediaType($mediaType);
        $mediaEntity->setFileSize(100);
        $mediaEntity->setPath(__DIR__ . '/shopware-logo.png');
        $mediaEntity->setPrivate(false);
        $mediaEntity->setTitle('Test Image');
        $mediaEntity->setMetaDataRaw('{"example": "metadata"}');
        $mediaEntity->setUploadedAt(new \DateTime());
        $mediaEntity->setAlt('Test Alt Text');
        $mediaEntity->setUrl('/url/to/shopware-logo.png');

        return $mediaEntity;
    }

    private function createMediaFolderEntity(): MediaFolderEntity
    {
        $mediaThumbnailSizeEntity = new MediaThumbnailSizeEntity();
        $mediaThumbnailSizeEntity->setId('mediaThumbnailSizeEntity-id-1');
        $mediaThumbnailSizeEntity->setWidth(100);
        $mediaThumbnailSizeEntity->setHeight(100);

        $mediaFolderConfigEntity = new MediaFolderConfigurationEntity();
        $mediaFolderConfigEntity->setMediaThumbnailSizes(new MediaThumbnailSizeCollection([$mediaThumbnailSizeEntity]));
        $mediaFolderConfigEntity->setCreateThumbnails(true);

        $mediaFolderEntity = new MediaFolderEntity();
        $mediaFolderEntity->setConfiguration($mediaFolderConfigEntity);

        return $mediaFolderEntity;
    }

    private function createMediaThumbnailEntity(): MediaThumbnailEntity
    {
        $mediaThumbnailEntity = new MediaThumbnailEntity();
        $mediaThumbnailEntity->setId('$mediaThumbnailEntity-id-1');
        $mediaThumbnailEntity->setWidth(100);
        $mediaThumbnailEntity->setHeight(200);
        $mediaThumbnailEntity->setMediaId('media-id-1');
        $mediaThumbnailEntity->setPath(__DIR__ . '/shopware-logo.png');

        return $mediaThumbnailEntity;
    }
}
