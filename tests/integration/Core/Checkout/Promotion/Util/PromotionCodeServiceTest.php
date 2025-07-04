<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Promotion\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Promotion\PromotionEntity;
use Shopware\Core\Checkout\Promotion\PromotionException;
use Shopware\Core\Checkout\Promotion\Util\PromotionCodeService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\Test\Integration\Traits\Promotion\PromotionTestFixtureBehaviour;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
#[Package('checkout')]
class PromotionCodeServiceTest extends TestCase
{
    use IntegrationTestBehaviour;
    use PromotionTestFixtureBehaviour;

    private PromotionCodeService $codesService;

    protected function setUp(): void
    {
        $this->codesService = static::getContainer()->get(PromotionCodeService::class);
    }

    public function testGetFixedCode(): void
    {
        $code = $this->codesService->getFixedCode();

        static::assertSame(8, \strlen($code));
        static::assertMatchesRegularExpression('/([A-Z]\d){4}/', $code);
    }

    #[DataProvider('codePreviewDataProvider')]
    public function testGetCodePreview(string $codePattern, string $expectedRegex): void
    {
        $actualCode = $this->codesService->getPreview($codePattern);

        static::assertMatchesRegularExpression($expectedRegex, $actualCode);
    }

    /**
     * @return array<array<string>>
     */
    public static function codePreviewDataProvider(): array
    {
        return [
            ['%s', '/([A-Z]){1}/'],
            ['%d', '/(\d){1}/'],
            ['%s%s%s', '/([A-Z]){3}/'],
            ['%d%d%d', '/(\d){3}/'],
            ['%s%d%s', '/([A-Z]\d[A-Z])/'],
            ['%d%s%d', '/(\d[A-Z]\d)/'],
            ['PREFIX_%s%s%d%d', '/PREFIX_([A-Z]){2}(\d){2}/'],
            ['%d%d%s%s_SUFFIX', '/(\d){2}([A-Z]){2}_SUFFIX/'],
            ['PREFIX_%s%s_SUFFIX', '/PREFIX_([A-Z]){2}_SUFFIX/'],
            ['PREFIX_%d%d_SUFFIX', '/PREFIX_(\d){2}_SUFFIX/'],
            ['PREFIX_%s%d_SUFFIX', '/PREFIX_([A-Z]\d)_SUFFIX/'],
            ['PREFIX_%d%s_SUFFIX', '/PREFIX_(\d[A-Z])_SUFFIX/'],
            ['PREFIX_%d%s_SUFFIX', '/PREFIX_(\d[A-Z])_SUFFIX/'],
            ['PREFIX_%d%s_NOW_WITH_UNRENDERED_VARS_%s%s%d%d_SUFFIX', '/PREFIX_(\d[A-Z])_NOW_WITH_UNRENDERED_VARS_%s%s%d%d_SUFFIX/'],
            ['ILLEGAL_VAR_STOPS_THE_CHAIN_%d%s%q%d%s_SUFFIX', '/ILLEGAL_VAR_STOPS_THE_CHAIN_(\d[A-Z])%q%d%s_SUFFIX/'],
        ];
    }

    public function testGenerateIndividualCodesWith0RequestedCodes(): void
    {
        $pattern = 'PREFIX_%s%d%s%d_SUFFIX';
        $codeList = $this->codesService->generateIndividualCodes($pattern, 0);

        static::assertCount(0, $codeList);
    }

    #[DataProvider('generateIndividualCodesDataProvider')]
    public function testGenerateIndividualCodesWithValidRequirements(int $requestedAmount): void
    {
        $pattern = 'PREFIX_%s%d%s%d_SUFFIX';
        $expectedCodeLength = \strlen(str_replace('%', '', $pattern));
        $codeList = $this->codesService->generateIndividualCodes($pattern, $requestedAmount);
        $codeLengthList = array_map(static fn ($code) => \strlen((string) $code), $codeList);

        static::assertCount($requestedAmount, $codeList);
        static::assertCount($requestedAmount, array_unique($codeList));
        static::assertCount(1, array_unique($codeLengthList));
        static::assertSame($expectedCodeLength, $codeLengthList[0]);
    }

    /**
     * @return array<array<int>>
     */
    public static function generateIndividualCodesDataProvider(): array
    {
        return [
            [1],
            [10],
            [500],
            [20000],
        ];
    }

    #[DataProvider('generateIndividualCodesWithInsufficientPatternDataProvider')]
    public function testGenerateIndividualCodesWithInsufficientPattern(int $requestedCodeAmount): void
    {
        // Only has 10 possibilities -> 6 or more requested codes would be invalid
        $pattern = 'PREFIX_%d_SUFFIX';

        $this->expectExceptionMessage('The amount of possible codes is too low for the current pattern. Make sure your pattern is sufficiently complex.');
        $this->codesService->generateIndividualCodes($pattern, $requestedCodeAmount);
    }

    /**
     * @return array<array<int>>
     */
    public static function generateIndividualCodesWithInsufficientPatternDataProvider(): array
    {
        return [
            [6],
            [10],
            [20],
        ];
    }

    public function testReplaceIndividualCodes(): void
    {
        $promotionRepository = static::getContainer()->get('promotion.repository');
        $codeRepository = static::getContainer()->get('promotion_individual_code.repository');
        $salesChannelContext = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL);
        $context = $salesChannelContext->getContext();

        $id = Uuid::randomHex();
        $codes = ['myIndividualCode_00A', 'myIndividualCode_11B'];
        $this->createPromotion($id, null, $promotionRepository, $salesChannelContext);
        $this->createIndividualCode($id, $codes[0], $codeRepository, $context);
        $this->createIndividualCode($id, $codes[1], $codeRepository, $context);

        $criteria = (new Criteria([$id]))
            ->addAssociation('individualCodes');

        /** @var PromotionEntity|null $promotion */
        $promotion = $promotionRepository->search($criteria, $context)->get($id);

        static::assertNotNull($promotion);
        static::assertNotNull($promotion->getIndividualCodes());
        static::assertCount(2, $promotion->getIndividualCodes()->getElements());

        $this->codesService->replaceIndividualCodes($id, 'newPattern_%d%d%s', 10, $context);

        /** @var PromotionEntity $promotion */
        $promotion = $promotionRepository->search($criteria, $context)->first();
        static::assertNotNull($promotion->getIndividualCodes());
        $individualCodes = $promotion->getIndividualCodes()->getElements();
        static::assertCount(10, $individualCodes);
        static::assertNotContains($codes[0], $individualCodes);
        static::assertNotContains($codes[1], $individualCodes);
    }

    public function testReplaceIndividualCodesWithDuplicatePattern(): void
    {
        $promotionRepository = static::getContainer()->get('promotion.repository');
        $salesChannelContext = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL);

        $id = Uuid::randomHex();
        $duplicatePattern = 'TEST_%d%s_END';

        // Create 2 Promotions. The first one has a pattern, which the second will try to use as well later on
        $this->createPromotionWithCustomData(['individualCodePattern' => $duplicatePattern], $promotionRepository, $salesChannelContext);
        $this->createPromotionWithCustomData(['id' => $id], $promotionRepository, $salesChannelContext);

        $this->expectExceptionMessage('Code pattern already exists in another promotion. Please provide a different pattern.');
        $this->codesService->replaceIndividualCodes($id, $duplicatePattern, 1, $salesChannelContext->getContext());
    }

    public function testAddIndividualCodes(): void
    {
        $id = Uuid::randomHex();
        $pattern = 'somePattern_%d%d%d';
        $data = [
            'id' => $id,
            'useCodes' => true,
            'useIndividualCodes' => true,
            'individualCodePattern' => $pattern,
        ];
        $promotionRepository = static::getContainer()->get('promotion.repository');
        $salesChannelContext = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL);

        $this->createPromotionWithCustomData($data, $promotionRepository, $salesChannelContext);

        // 1000 possible codes -> 500 valid codes
        $this->codesService->replaceIndividualCodes($id, $pattern, 100, $salesChannelContext->getContext());

        $this->addCodesAndAssertCount($id, 200, 300);
        $this->addCodesAndAssertCount($id, 200, 500);

        $this->expectExceptionMessage('The amount of possible codes is too low for the current pattern. Make sure your pattern is sufficiently complex.');
        $this->addCodesAndAssertCount($id, 1, 501);
    }

    public function testSplitPatternWithInvalidCodeThrowsInvalidCodePattern(): void
    {
        static::expectException(PromotionException::class);

        $this->codesService->splitPattern('PREFIX_%foo_SUFFIX');
    }

    private function addCodesAndAssertCount(string $id, int $newCodeAmount, int $expectedCodeAmount): void
    {
        $salesChannelContext = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL);
        $promotionRepository = static::getContainer()->get('promotion.repository');
        $criteria = (new Criteria())
            ->addAssociation('individualCodes');

        $this->codesService->addIndividualCodes($id, $newCodeAmount, $salesChannelContext->getContext());

        /** @var PromotionEntity|null $promotion */
        $promotion = $promotionRepository->search($criteria, $salesChannelContext->getContext())->first();

        static::assertNotNull($promotion);
        static::assertNotNull($promotion->getIndividualCodes());
        static::assertCount($expectedCodeAmount, $promotion->getIndividualCodes()->getIds());
    }
}
