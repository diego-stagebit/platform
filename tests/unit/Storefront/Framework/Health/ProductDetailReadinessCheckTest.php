<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Storefront\Framework\Health;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\SystemCheck\Check\Result;
use Shopware\Core\Framework\SystemCheck\Check\Status;
use Shopware\Core\Framework\SystemCheck\Check\SystemCheckExecutionContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Storefront\Framework\SystemCheck\ProductDetailReadinessCheck;
use Shopware\Storefront\Framework\SystemCheck\Util\AbstractSalesChannelDomainProvider;
use Shopware\Storefront\Framework\SystemCheck\Util\SalesChannelDomain;
use Shopware\Storefront\Framework\SystemCheck\Util\SalesChannelDomainCollection;
use Shopware\Storefront\Framework\SystemCheck\Util\SalesChannelDomainProvider;
use Shopware\Storefront\Framework\SystemCheck\Util\SalesChannelDomainUtil;
use Shopware\Storefront\Framework\SystemCheck\Util\StorefrontHealthCheckResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(ProductDetailReadinessCheck::class)]
class ProductDetailReadinessCheckTest extends TestCase
{
    private Connection&MockObject $connection;

    private SalesChannelDomainUtil&MockObject $util;

    private AbstractSalesChannelDomainProvider&MockObject $domainProvider;

    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->domainProvider = $this->createMock(SalesChannelDomainProvider::class);
        $this->ids = new IdsCollection();

        $this->initUtilMock();
    }

    public function testName(): void
    {
        $check = $this->createCheck();
        static::assertSame('ProductDetailReadiness', $check->name());
    }

    public function testCategory(): void
    {
        $check = $this->createCheck();
        static::assertSame('FEATURE', $check->category()->name);
    }

    public function testAllowedToRunIn(): void
    {
        $check = $this->createCheck();
        static::assertTrue($check->allowedToRunIn(SystemCheckExecutionContext::PRE_ROLLOUT));
    }

    public function testRunSuccessfully(): void
    {
        $this->initDataMocks();

        $this->util->method('handleRequest')->willReturn(
            StorefrontHealthCheckResult::create(
                'http://localhost:8000/product/123',
                Response::HTTP_OK,
                1.23
            )
        );

        $check = $this->createCheck();
        $result = $check->run();

        static::assertTrue($result->healthy);
        static::assertSame('ProductDetailReadiness', $result->name);
        static::assertSame('Product detail pages are OK for provided sales channels.', $result->message);
        static::assertSame('OK', $result->status->name);
        static::assertCount(2, $result->extra);

        static::assertSame(200, $result->extra[0]['responseCode']);
        static::assertSame(200, $result->extra[1]['responseCode']);
    }

    public function testRunSkipped(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([]);
        $this->initCreateEmptyResult();

        $check = $this->createCheck();
        $result = $check->run();

        static::assertTrue($result->healthy);
        static::assertSame('ProductDetailReadiness', $result->name);
        static::assertSame('No sales channels with product detail pages found.', $result->message);
        static::assertSame('SKIPPED', $result->status->name);
        static::assertCount(0, $result->extra);
    }

    public function testRunFailed(): void
    {
        $this->initDataMocks();

        $this->util->method('handleRequest')->willReturn(
            StorefrontHealthCheckResult::create(
                'http://localhost:8000/product/123',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                1.23
            )
        );

        $check = $this->createCheck();
        $result = $check->run();

        static::assertFalse($result->healthy);
        static::assertSame('ProductDetailReadiness', $result->name);
        static::assertSame('Some or all product detail pages are unhealthy.', $result->message);
        static::assertSame('FAILURE', $result->status->name);
        static::assertCount(2, $result->extra);

        static::assertSame(500, $result->extra[0]['responseCode']);
        static::assertSame(500, $result->extra[1]['responseCode']);
    }

    private function createCheck(): ProductDetailReadinessCheck
    {
        return new ProductDetailReadinessCheck($this->util, $this->connection, $this->domainProvider);
    }

    private function initUtilMock(): void
    {
        $this->util = $this->createMock(SalesChannelDomainUtil::class);
        $this->util->method('runAsSalesChannelRequest')
            ->willReturnCallback(function (callable $callback): mixed {
                return $callback();
            });

        $this->util->method('runWhileTrustingAllHosts')
            ->willReturnCallback(function (callable $callback): mixed {
                return $callback();
            });

        $this->util->method('generateDomainUrl')->willReturnCallback(function ($domain, $routeName) {
            return $domain . $routeName;
        });
    }

    private function initDataMocks(): void
    {
        $counter = 0;
        $this->connection->method('fetchOne')->willReturnCallback(function () use (&$counter) {
            ++$counter;

            if ($counter >= 3) {
                return null;
            }

            return Uuid::randomHex();
        });

        $collection = new SalesChannelDomainCollection([
            SalesChannelDomain::create($this->ids->get('sales-channel-1'), 'http://localhost:8000/de'),
            SalesChannelDomain::create($this->ids->get('sales-channel-2'), 'http://localhost:8000/en'),
            SalesChannelDomain::create($this->ids->get('sales-channel-3'), 'http://localhost:8000/invalid'),
        ]);

        $this->domainProvider->method('fetchSalesChannelDomains')->willReturn($collection);
    }

    private function initCreateEmptyResult(): void
    {
        $this->util->method('createEmptyResult')
            ->with('ProductDetailReadiness', 'No sales channels with product detail pages found.')
            ->willReturn(new Result(
                'ProductDetailReadiness',
                Status::SKIPPED,
                'No sales channels with product detail pages found.',
                true,
                []
            ));
    }
}
