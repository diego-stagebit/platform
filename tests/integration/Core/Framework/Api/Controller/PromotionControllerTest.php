<?php
declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Api\Controller;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountEntity;
use Shopware\Core\Checkout\Promotion\PromotionCollection;
use Shopware\Core\Checkout\Promotion\PromotionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class PromotionControllerTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    /**
     * @var EntityRepository<PromotionCollection>
     */
    private EntityRepository $promotionRepository;

    private Context $context;

    private string $resourceUri;

    private TestBrowser $api;

    protected function setUp(): void
    {
        $this->promotionRepository = static::getContainer()->get('promotion.repository');
        $this->context = Context::createDefaultContext();

        $this->api = $this->getBrowser();
        $this->resourceUri = '/api/promotion';
    }

    /**
     * This test verifies that we can successfully
     * create a new promotion with the minimum-required
     * data with our API.
     *
     * @throws InconsistentCriteriaIdsException
     */
    #[Group('promotions')]
    public function testCreatePromotion(): void
    {
        $promotionId = Uuid::randomHex();

        $this->api->request(
            'POST',
            $this->resourceUri,
            [
                'id' => $promotionId,
                'name' => 'Super Sale',
            ]
        );

        $response = $this->api->getResponse();
        $content = $response->getContent();

        static::assertIsString($content);
        static::assertSame(204, $response->getStatusCode(), $content);

        $promotion = $this->getPromotionFromDB($promotionId);

        static::assertNotNull($promotion);
        static::assertSame($promotionId, $promotion->getId());
        static::assertSame('Super Sale', $promotion->getName());
    }

    /**
     * This test verifies that we can read the details of our
     * promotion using the API
     */
    #[Group('promotions')]
    public function testReadPromotion(): void
    {
        $promotionId = Uuid::randomHex();
        $discountId = Uuid::randomHex();
        $this->insertPromotionInDB($promotionId, $discountId);

        $this->api->request(
            'GET',
            $this->resourceUri . '/' . $promotionId
        );

        $response = $this->api->getResponse();
        $content = $response->getContent();

        static::assertIsString($content);
        static::assertSame(200, $response->getStatusCode(), $content);

        $json = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame($promotionId, $json['data']['id']);
        static::assertSame('promotion', $json['data']['type']);
        static::assertSame('Super Sale', $json['data']['attributes']['name']);
        static::assertTrue($json['data']['attributes']['active']);
    }

    /**
     * This test verifies that we can read the list data of our
     * promotions using the API
     */
    #[Group('promotions')]
    public function testReadPromotionList(): void
    {
        $promotionId = Uuid::randomHex();
        $discountId = Uuid::randomHex();
        $this->insertPromotionInDB($promotionId, $discountId);

        $this->api->request(
            'GET',
            $this->resourceUri
        );

        $response = $this->api->getResponse();
        $content = $response->getContent();

        static::assertIsString($content);
        static::assertSame(200, $response->getStatusCode(), $content);

        $json = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        // verify that we have 1 total found promotion
        static::assertSame(1, $json['meta']['total']);

        // assert values of first promotion
        static::assertSame($promotionId, $json['data'][0]['id']);
        static::assertSame('Super Sale', $json['data'][0]['attributes']['name']);
    }

    /**
     * This test verifies that we can update our promotion
     * with the API. In this test we update the name
     * and verify if the new values is stored in the database.
     */
    #[Group('promotions')]
    public function testPatchPromotion(): void
    {
        $promotionId = Uuid::randomHex();
        $discountId = Uuid::randomHex();
        $this->insertPromotionInDB($promotionId, $discountId);

        $this->api->request(
            'PATCH',
            $this->resourceUri . '/' . $promotionId,
            [
                'name' => 'Super Better Sale',
            ]
        );

        $response = $this->api->getResponse();
        $content = $response->getContent();

        static::assertIsString($content);
        static::assertSame(204, $response->getStatusCode(), $content);

        $promotion = $this->getPromotionFromDB($promotionId);

        static::assertNotNull($promotion);
        static::assertSame('Super Better Sale', $promotion->getName());
    }

    /**
     * This test verifies that we can delete our discount
     * with the API. So we delete a discount from a promotion
     * that only has 1 discount. then we load it from the database and
     * check if no more discounts exist.
     */
    #[Group('promotions')]
    public function testDeletePromotionDiscount(): void
    {
        $promotionId = Uuid::randomHex();
        $discountId = Uuid::randomHex();
        $this->insertPromotionInDB($promotionId, $discountId);

        $this->api->request(
            'DELETE',
            $this->resourceUri . '/' . $promotionId . '/discounts/' . $discountId
        );

        $response = $this->api->getResponse();
        $content = $response->getContent();

        static::assertIsString($content);
        static::assertSame(204, $response->getStatusCode(), $content);

        $promotion = $this->getPromotionFromDB($promotionId);
        static::assertNotNull($promotion);
        static::assertNotNull($promotion->getDiscounts());
        static::assertCount(0, $promotion->getDiscounts());
    }

    /**
     * This test verifies that we can update our discount with
     * new values. We change the type and value and then load it from
     * the database and see if it has been correctly updated.
     *
     * @throws InconsistentCriteriaIdsException
     */
    #[Group('promotions')]
    public function testPatchDiscount(): void
    {
        $promotionId = Uuid::randomHex();
        $discountId = Uuid::randomHex();
        $this->insertPromotionInDB($promotionId, $discountId);

        $this->api->request(
            'PATCH',
            $this->resourceUri . '/' . $promotionId . '/discounts/' . $discountId,
            [
                'type' => 'percentage',
                'value' => 12.5,
            ]
        );

        $promotion = $this->getPromotionFromDB($promotionId);
        static::assertNotNull($promotion);
        static::assertNotNull($promotion->getDiscounts());
        /** @var PromotionDiscountEntity $discount */
        $discount = $promotion->getDiscounts()->get($discountId);

        static::assertSame('percentage', $discount->getType());
        static::assertSame(12.5, $discount->getValue());
    }

    /**
     * This test verifies that we can sucessfully delete a promotion
     * with the API. We add 1 promotion in the database, then delete it
     * using our client, and finally verify if no more promotions exist
     * in the database for this ID.
     */
    #[Group('promotions')]
    public function testDeletePromotion(): void
    {
        $promotionId = Uuid::randomHex();
        $discountId = Uuid::randomHex();
        $this->insertPromotionInDB($promotionId, $discountId);

        $this->api->request(
            'DELETE',
            '/api/promotion/' . $promotionId
        );

        $response = $this->api->getResponse();
        $content = $response->getContent();

        static::assertIsString($content);
        static::assertSame(204, $response->getStatusCode(), $content);

        $promotions = $this->getPromotionFromDB($promotionId);

        static::assertNull($promotions);
    }

    private function getPromotionFromDB(string $id): ?PromotionEntity
    {
        $criteria = new Criteria([$id]);
        $criteria->addAssociation('discounts');

        /** @var PromotionEntity|null $promotion */
        $promotion = $this->promotionRepository->search($criteria, $this->context)->get($id);

        return $promotion;
    }

    private function insertPromotionInDB(string $id, string $discountId): void
    {
        $this->promotionRepository->create(
            [
                [
                    'id' => $id,
                    'name' => 'Super Sale',
                    'active' => true,
                    'validFrom' => '2019-01-01 00:00:00',
                    'validUntil' => '2030-01-01 00:00:00',
                    'maxRedemptionsGlobal' => 1000,
                    'maxRedemptionsPerCustomer' => 1,
                    'exclusive' => false,
                    'useCodes' => true,
                    'use_setgroups' => false,
                    'code' => 'super19',
                    'customer_restriction' => true,
                    'discounts' => [
                        [
                            'id' => $discountId,
                            'scope' => PromotionDiscountEntity::SCOPE_CART,
                            'type' => PromotionDiscountEntity::TYPE_ABSOLUTE,
                            'value' => 100,
                            'considerAdvancedRules' => false,
                            'graduated' => false,
                        ],
                    ],
                ],
            ],
            $this->context
        );
    }
}
