<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Adapter\Twig\Extension;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Twig\Loader\ArrayLoader;

/**
 * @internal
 */
class MediaExtensionTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testSingleSearch(): void
    {
        $ids = new IdsCollection();

        $data = [
            'id' => $ids->create('media'),
            'fileName' => 'testImage',
        ];

        static::getContainer()->get('media.repository')
            ->create([$data], Context::createDefaultContext());

        $result = $this->render('search-media.html.twig', [
            'ids' => $ids->getList(['media']),
            'context' => Context::createDefaultContext(),
        ]);

        static::assertSame('testImage/', $result);
    }

    public function testMultiSearch(): void
    {
        $ids = new IdsCollection();

        $data = [
            ['id' => $ids->create('media-1'), 'fileName' => 'image-1'],
            ['id' => $ids->create('media-2'), 'fileName' => 'image-2'],
        ];

        static::getContainer()->get('media.repository')
            ->create($data, Context::createDefaultContext());

        $result = $this->render('search-media.html.twig', [
            'ids' => $ids->getList(['media-1', 'media-2']),
            'context' => Context::createDefaultContext(),
        ]);

        static::assertSame('image-1/image-2/', $result);
    }

    public function testEmptySearch(): void
    {
        $result = $this->render('search-media.html.twig', [
            'ids' => [],
            'context' => Context::createDefaultContext(),
        ]);

        static::assertSame('', $result);
    }

    /**
     * @param array<string, array<string, string>|Context> $data
     */
    private function render(string $template, array $data): string
    {
        $twig = static::getContainer()->get('twig');

        $originalLoader = $twig->getLoader();
        $twig->setLoader(new ArrayLoader([
            'test.html.twig' => file_get_contents(__DIR__ . '/fixture/' . $template),
        ]));
        $output = $twig->render('test.html.twig', $data);
        $twig->setLoader($originalLoader);

        return $output;
    }
}
