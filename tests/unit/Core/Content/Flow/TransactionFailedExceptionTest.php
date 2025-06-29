<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Flow;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Flow\Dispatching\TransactionFailedException;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[Package('after-sales')]
#[CoversClass(TransactionFailedException::class)]
class TransactionFailedExceptionTest extends TestCase
{
    public function testTransactionCommitFailed(): void
    {
        $previous = new \Exception('broken');
        $e = TransactionFailedException::because($previous);

        static::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getStatusCode());
        static::assertSame(TransactionFailedException::TRANSACTION_FAILED, $e->getErrorCode());
        static::assertSame('Transaction failed because an exception occurred. Exception: broken', $e->getMessage());
        static::assertSame($previous, $e->getPrevious());
    }
}
