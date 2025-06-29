<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Store\Struct;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\Framework\Store\Struct\ReviewStruct;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[Package('checkout')]
#[CoversClass(ReviewStruct::class)]
class ReviewStructTest extends TestCase
{
    public function testFromRequest(): void
    {
        $request = new Request([], [
            'authorName' => 'Author',
            'headline' => 'Headline',
            'text' => 'Text',
            'tocAccepted' => true,
            'rating' => 3,
            'version' => '1.1.0',
        ]);

        $rating = ReviewStruct::fromRequest(1, $request);

        static::assertSame(1, $rating->getExtensionId());
        static::assertSame('Author', $rating->getAuthorName());
        static::assertSame('Headline', $rating->getHeadline());
        static::assertSame('Text', $rating->getText());
        static::assertTrue($rating->isAcceptGuidelines());
        static::assertSame(3, $rating->getRating());
        static::assertSame('1.1.0', $rating->getVersion());
    }

    public function testFromRequestThrowsIfAuthorNameIsInvalid(): void
    {
        $request = new Request([], [
            'tocAccepted' => true,
        ]);

        static::expectException(RoutingException::class);
        static::expectExceptionMessage('The parameter "authorName" is invalid.');
        ReviewStruct::fromRequest(1, $request);
    }

    public function testFromRequestThrowsIfHeadlineIsInvalid(): void
    {
        $request = new Request([], [
            'authorName' => 'Author',
            'tocAccepted' => true,
        ]);

        static::expectException(RoutingException::class);
        static::expectExceptionMessage('The parameter "headline" is invalid.');
        ReviewStruct::fromRequest(1, $request);
    }

    public function testFromRequestThrowsIfRatingIsInvalid(): void
    {
        $request = new Request([], [
            'authorName' => 'Author',
            'headline' => 'Headline',
            'text' => 'Text',
            'tocAccepted' => true,
        ]);

        static::expectException(RoutingException::class);
        static::expectExceptionMessage('The parameter "rating" is invalid.');
        ReviewStruct::fromRequest(1, $request);
    }
}
