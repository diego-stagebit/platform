<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Payment\Cart\Token;

use Doctrine\DBAL\Connection;
use Lcobucci\Clock\FrozenClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\Token\JWTFactoryV2;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\Checkout\Payment\Cart\Token\TestKey;
use Shopware\Core\Test\Stub\Checkout\Payment\Cart\Token\TestSigner;

/**
 * @internal
 */
#[CoversClass(JWTFactoryV2::class)]
#[Package('checkout')]
class JWTFactoryV2Test extends TestCase
{
    private JWTFactoryV2 $tokenFactory;

    protected function setUp(): void
    {
        $configuration = Configuration::forSymmetricSigner(new TestSigner(), new TestKey());
        $configuration = $configuration->withValidationConstraints(new NoopConstraint());
        $connection = $this->createMock(Connection::class);
        $this->tokenFactory = new JWTFactoryV2($configuration, $connection);
    }

    #[DataProvider('dataProviderExpiration')]
    public function testGenerateAndGetToken(int $expiration, bool $expired): void
    {
        $transaction = self::createTransaction();
        $tokenStruct = new TokenStruct(null, null, $transaction->getPaymentMethodId(), $transaction->getId(), null, $expiration);
        $token = $this->tokenFactory->generateToken($tokenStruct);
        static::assertNotEmpty($token);
        $tokenStruct = $this->tokenFactory->parseToken($token);

        static::assertSame($transaction->getId(), $tokenStruct->getTransactionId());
        static::assertSame($transaction->getPaymentMethodId(), $tokenStruct->getPaymentMethodId());
        static::assertSame($token, $tokenStruct->getToken());
        static::assertEqualsWithDelta(time() + $expiration, $tokenStruct->getExpires(), 1);
        static::assertSame($expired, $tokenStruct->isExpired());
    }

    public function testGetInvalidFormattedToken(): void
    {
        $token = Uuid::randomHex();

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('The provided token ' . $token . ' is invalid and the payment could not be processed.');

        static::assertNotEmpty($token);

        $this->tokenFactory->parseToken($token);
    }

    public function testGetTokenWithInvalidSignature(): void
    {
        $transaction = self::createTransaction();
        $tokenStruct = new TokenStruct(null, null, $transaction->getPaymentMethodId(), $transaction->getId());
        $token = $this->tokenFactory->generateToken($tokenStruct);
        $invalidToken = substr($token, 0, -5);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('The provided token ' . $invalidToken . ' is invalid and the payment could not be processed.');

        static::assertNotEmpty($invalidToken);

        $this->tokenFactory->parseToken($invalidToken);
    }

    public function testInvalidateToken(): void
    {
        $token = Uuid::randomHex();
        static::assertNotEmpty($token);
        $success = $this->tokenFactory->invalidateToken($token);
        static::assertFalse($success);
    }

    public function testExpiredToken(): void
    {
        $configuration = Configuration::forSymmetricSigner(new TestSigner(), new TestKey());
        $configuration = $configuration->withValidationConstraints(new StrictValidAt(new FrozenClock(new \DateTimeImmutable('now - 1 day'))));
        $tokenFactory = new JWTFactoryV2($configuration, $this->createMock(Connection::class));

        $transaction = self::createTransaction();
        $tokenStruct = new TokenStruct(null, null, $transaction->getPaymentMethodId(), $transaction->getId(), null, -50);
        $token = $tokenFactory->generateToken($tokenStruct);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('The provided token ' . $token . ' is invalid and the payment could not be processed.');

        static::assertNotEmpty($token);

        $tokenFactory->parseToken($token);
    }

    public function testTokenNotStored(): void
    {
        $configuration = Configuration::forSymmetricSigner(new TestSigner(), new TestKey());
        $configuration = $configuration->withValidationConstraints(new NoopConstraint());
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturn(false);

        $tokenFactory = new JWTFactoryV2($configuration, $connection);

        $transaction = self::createTransaction();
        $tokenStruct = new TokenStruct(null, null, $transaction->getPaymentMethodId(), $transaction->getId(), null, -50);
        $token = $tokenFactory->generateToken($tokenStruct);

        static::expectException(PaymentException::class);
        static::expectExceptionMessage('The provided token ' . $token . ' is invalidated and the payment could not be processed.');

        static::assertNotEmpty($token);

        $tokenFactory->parseToken($token);
    }

    public static function createTransaction(): OrderTransactionEntity
    {
        $transactionStruct = new OrderTransactionEntity();
        $transactionStruct->setId(Uuid::randomHex());
        $transactionStruct->setOrderId(Uuid::randomHex());
        $transactionStruct->setPaymentMethodId(Uuid::randomHex());
        $transactionStruct->setStateId(Uuid::randomHex());

        return $transactionStruct;
    }

    /**
     * @return iterable<array-key, array{int, bool}>
     */
    public static function dataProviderExpiration(): iterable
    {
        yield 'positive expire' => [30, false];
        yield 'negative expire' => [-30, true];
    }
}

/**
 * @internal
 */
#[Package('checkout')]
class NoopConstraint implements Constraint
{
    public function assert(Token $token): void
    {
    }
}
