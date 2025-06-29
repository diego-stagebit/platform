<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\Media\Cms;

use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\Cms\DefaultMediaResolver;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * @internal
 */
class DefaultMediaResolverTest extends TestCase
{
    use IntegrationTestBehaviour;

    private DefaultMediaResolver $mediaResolver;

    private FilesystemOperator $publicFilesystem;

    protected function setUp(): void
    {
        $this->publicFilesystem = $this->getPublicFilesystem();
        $this->mediaResolver = new DefaultMediaResolver($this->publicFilesystem);
    }

    public function testGetDefaultMediaEntityWithoutValidFileName(): void
    {
        $media = $this->mediaResolver->getDefaultCmsMediaEntity('this/file/does/not/exists');

        static::assertNull($media);
    }

    public function testGetDefaultMediaEntityWithValidFileName(): void
    {
        $this->publicFilesystem->write('/bundles/core/assets/default/cms/shopware.jpg', '');
        $media = $this->mediaResolver->getDefaultCmsMediaEntity('bundles/core/assets/default/cms/shopware.jpg');

        static::assertInstanceOf(MediaEntity::class, $media);
        static::assertSame('shopware', $media->getFileName());
        static::assertSame('image/jpeg', $media->getMimeType());
        static::assertSame('jpg', $media->getFileExtension());
    }
}
