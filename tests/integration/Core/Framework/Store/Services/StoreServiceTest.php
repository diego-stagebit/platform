<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Store\Services;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Store\Services\StoreService;
use Shopware\Core\Framework\Store\Struct\AccessTokenStruct;
use Shopware\Core\Framework\Store\Struct\ShopUserTokenStruct;
use Shopware\Core\Framework\Test\Store\StoreClientBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * @internal
 */
#[Package('checkout')]
class StoreServiceTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StoreClientBehaviour;

    private StoreService $storeService;

    protected function setUp(): void
    {
        $this->storeService = static::getContainer()->get(StoreService::class);
    }

    public function testUpdateStoreToken(): void
    {
        $adminStoreContext = $this->createAdminStoreContext();

        $newToken = 'updated-store-token';
        $accessTokenStruct = new AccessTokenStruct(
            new ShopUserTokenStruct(
                $newToken,
                new \DateTimeImmutable()
            )
        );

        $this->storeService->updateStoreToken(
            $adminStoreContext,
            $accessTokenStruct
        );

        /** @var AdminApiSource $adminSource */
        $adminSource = $adminStoreContext->getSource();
        /** @var string $userId */
        $userId = $adminSource->getUserId();
        $criteria = new Criteria([$userId]);

        $updatedUser = $this->getUserRepository()->search($criteria, $adminStoreContext)->getEntities()->first();

        static::assertSame('updated-store-token', $updatedUser?->getStoreToken());
    }
}
