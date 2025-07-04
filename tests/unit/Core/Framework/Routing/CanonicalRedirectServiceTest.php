<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Routing\CanonicalRedirectService;
use Shopware\Core\SalesChannelRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(CanonicalRedirectService::class)]
class CanonicalRedirectServiceTest extends TestCase
{
    final public const CONFIG_KEY = 'core.seo.redirectToCanonicalUrl';

    #[DataProvider('requestDataProvider')]
    public function testGetRedirect(Request $request, ?Response $response): void
    {
        static::assertNotNull($response);
        $shouldRedirect = $response->getStatusCode() === Response::HTTP_MOVED_PERMANENTLY;
        $canonicalRedirectService = new CanonicalRedirectService($this->getSystemConfigService($shouldRedirect));

        /** @var RedirectResponse|null $actual */
        $actual = $canonicalRedirectService->getRedirect($request);

        if ($shouldRedirect) {
            static::assertNotNull($actual);
            static::assertInstanceOf(
                RedirectResponse::class,
                $actual
            );
            static::assertSame(
                $request->attributes->get(SalesChannelRequest::ATTRIBUTE_CANONICAL_LINK),
                $actual->getTargetUrl()
            );
            static::assertSame(
                Response::HTTP_MOVED_PERMANENTLY,
                $actual->getStatusCode()
            );
        } else {
            static::assertNull($actual);
        }
    }

    public function testGetRedirectWithQueryParameters(): void
    {
        $request = self::getRequest([SalesChannelRequest::ATTRIBUTE_CANONICAL_LINK => '/lorem/ipsum/dolor-sit/amet']);
        $request->server->set('QUERY_STRING', 'foo=bar');

        $canonicalRedirectService = new CanonicalRedirectService($this->getSystemConfigService(true));

        $response = $canonicalRedirectService->getRedirect($request);

        static::assertInstanceOf(RedirectResponse::class, $response);
        static::assertSame('/lorem/ipsum/dolor-sit/amet?foo=bar', $response->getTargetUrl());
    }

    /**
     * @return array<int, array<string, Request|Response>>
     */
    public static function requestDataProvider(): array
    {
        return [
            [
                'request' => self::getRequest([]),
                'response' => new Response(),
            ],
            [
                'request' => self::getRequest([SalesChannelRequest::ATTRIBUTE_CANONICAL_LINK => '']),
                'response' => new Response(),
            ],
            [
                'request' => self::getRequest([SalesChannelRequest::ATTRIBUTE_CANONICAL_LINK => true]),
                'response' => new Response(),
            ],
            [
                'request' => self::getRequest([SalesChannelRequest::ATTRIBUTE_CANONICAL_LINK => '/lorem/ipsum/dolor-sit/amet']),
                'response' => (new Response())->setStatusCode(Response::HTTP_MOVED_PERMANENTLY),
            ],
        ];
    }

    /**
     * @param array<string, true|string> $attributes
     */
    private static function getRequest(array $attributes): Request
    {
        $request = Request::create($_SERVER['APP_URL'], Request::METHOD_GET);

        foreach ($attributes as $key => $attribute) {
            $request->attributes->set($key, $attribute);
        }

        return $request;
    }

    private function getSystemConfigService(bool $shouldRedirect): SystemConfigService
    {
        $service = $this->getMockBuilder(SystemConfigService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();

        $service->method('get')
            ->with(self::CONFIG_KEY)
            ->willReturn($shouldRedirect);

        return $service;
    }
}
