<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class VersionTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    private TestBrowser $unauthorizedClient;

    protected function setUp(): void
    {
        $this->unauthorizedClient = $this->getBrowser();
        $this->unauthorizedClient->setServerParameters([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => ['application/vnd.api+json,application/json'],
        ]);
    }

    /**
     * @return array<array{string, string}>
     */
    public static function protectedRoutesDataProvider(): array
    {
        return [
            ['GET', '/api/product'],
            ['GET', '/api/tax'],
            ['POST', '/api/_action/sync'],
            ['GET', '/api/_info/stoplightio.html'],
            ['GET', '/api/_info/entity-schema.json'],
            ['GET', '/api/_info/events.json'],
        ];
    }

    public function testAuthShouldNotBeProtected(): void
    {
        $this->unauthorizedClient->request('POST', '/api/oauth/token');
        static::assertSame(
            Response::HTTP_BAD_REQUEST,
            $this->unauthorizedClient->getResponse()->getStatusCode(),
            'Route should be protected. (URL: /api/oauth/token)'
        );

        $content = (string) $this->unauthorizedClient->getResponse()->getContent();
        $response = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        static::assertNotSame('false', $content);
        static::assertSame('The authorization grant type is not supported by the authorization server.', $response['errors'][0]['title']);
        static::assertSame('Check that all required parameters have been provided', $response['errors'][0]['detail']);
    }

    #[DataProvider('protectedRoutesDataProvider')]
    public function testRoutesAreProtected(string $method, string $url): void
    {
        $this->unauthorizedClient->request($method, $url);
        static::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->unauthorizedClient->getResponse()->getStatusCode(),
            'Route should be protected. (URL: ' . $url . ')'
        );
    }
}
