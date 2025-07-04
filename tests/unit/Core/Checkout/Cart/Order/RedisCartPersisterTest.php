<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Cart\Order;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartCompressor;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\CartSerializationCleaner;
use Shopware\Core\Checkout\Cart\Exception\CartTokenNotFoundException;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\RedisCartPersister;
use Shopware\Core\Content\Product\Cart\ProductNotFoundError;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Stub\EventDispatcher\CollectingEventDispatcher;
use Shopware\Core\Test\Stub\Redis\RedisStub;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * @internal
 */
#[CoversClass(RedisCartPersister::class)]
#[Package('checkout')]
class RedisCartPersisterTest extends TestCase
{
    public function testDecorated(): void
    {
        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);
        $persister = new RedisCartPersister(new RedisStub(), new CollectingEventDispatcher(), $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90);
        $this->expectException(DecorationPatternException::class);
        $persister->getDecorated();
    }

    public function testSave(): void
    {
        $token = Uuid::randomHex();
        $cart = new Cart($token);
        $cart->add(new LineItem('test', 'test'));

        $dispatcher = $this->createMock(EventDispatcher::class);

        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);

        $redis = new RedisStub();

        $context = $this->createMock(SalesChannelContext::class);

        $persister = new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90);

        $persister->save($cart, $context);

        static::assertTrue($redis->exists(RedisCartPersister::PREFIX . $token));
    }

    public function testEmptyCartGetsDeleted(): void
    {
        $token = Uuid::randomHex();
        $cart = new Cart($token);

        $dispatcher = $this->createMock(EventDispatcher::class);

        $redis = new RedisStub();

        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);
        $context = $this->createMock(SalesChannelContext::class);

        $persister = new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90);
        $persister->save($cart, $context);

        static::assertFalse($redis->exists(RedisCartPersister::PREFIX . $token));
    }

    public function testLoad(): void
    {
        $token = Uuid::randomHex();
        $cart = new Cart($token);
        $cart->add(new LineItem('test', 'test'));

        $dispatcher = $this->createMock(EventDispatcher::class);

        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);

        $redis = new RedisStub();

        $context = $this->createMock(SalesChannelContext::class);

        $persister = new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90);

        $persister->save($cart, $context);

        $loadedCart = $persister->load($token, $context);

        $cart->setData(null);

        static::assertEquals($cart, $loadedCart);
    }

    /**
     * @param class-string<\Throwable> $exceptionClass
     */
    #[DataProvider('dataProviderInvalidData')]
    public function testLoadingInvalidCart(mixed $data, string $exceptionClass): void
    {
        $token = Uuid::randomHex();
        $dispatcher = $this->createMock(EventDispatcher::class);
        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);

        $redis = new RedisStub();
        $redis->set(RedisCartPersister::PREFIX . $token, $data);

        $context = $this->createMock(SalesChannelContext::class);
        $this->expectException($exceptionClass);
        (new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90))->load($token, $context);
    }

    /**
     * @return iterable<string, array{mixed, class-string<CartException>}>
     */
    public static function dataProviderInvalidData(): iterable
    {
        yield 'not existing' => [null, CartTokenNotFoundException::class];
        yield 'invalid serialize' => ['abc', CartTokenNotFoundException::class];
        yield 'not cart serialize' => [\serialize(new \ArrayObject()), CartTokenNotFoundException::class];
        yield 'valid outer object, but invalid content' => [\serialize(['compressed' => false, 'content' => \serialize(new \ArrayObject())]), CartTokenNotFoundException::class];
        yield 'valid outer object, but not cart' => [serialize(['compressed' => false, 'content' => serialize(['cart' => ''])]), CartException::class];
    }

    public function testDelete(): void
    {
        $token = Uuid::randomHex();

        $dispatcher = $this->createMock(EventDispatcher::class);
        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);

        $redis = new RedisStub();
        $redis->set(RedisCartPersister::PREFIX . $token, 'test');

        $persister = new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90);

        $context = $this->createMock(SalesChannelContext::class);

        $persister->delete($token, $context);

        static::assertFalse($redis->exists(RedisCartPersister::PREFIX . $token));
    }

    public function testLoadWithDifferentCompression(): void
    {
        $token = Uuid::randomHex();
        $cart = new Cart($token);
        $cart->add(new LineItem('test', 'test'));

        $dispatcher = $this->createMock(EventDispatcher::class);

        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);

        $compressedRedis = new RedisStub();

        $context = $this->createMock(SalesChannelContext::class);

        $compressor = new CartCompressor(false, 'gzip');
        $persister = new RedisCartPersister($compressedRedis, $dispatcher, $cartSerializationCleaner, $compressor, 90);

        $persister->save($cart, $context);

        $dispatcher = $this->createMock(EventDispatcher::class);

        [$compression, $content] = $compressor->serialize(['cart' => $cart, 'rule_ids' => []]);

        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);

        $uncompressedRedis = new RedisStub();
        $uncompressedRedis->set(RedisCartPersister::PREFIX . $token, \serialize(['compressed' => $compression, 'content' => $content]));

        $context = $this->createMock(SalesChannelContext::class);

        $loadedCart = (new RedisCartPersister($uncompressedRedis, $dispatcher, $cartSerializationCleaner, $compressor, 90))->load($token, $context);

        static::assertEquals($cart, $loadedCart);
    }

    public function testReplace(): void
    {
        $oldToken = Uuid::randomHex();
        $newToken = Uuid::randomHex();
        $cart = new Cart($oldToken);
        $cart->add(new LineItem('test', 'test'));

        $dispatcher = $this->createMock(EventDispatcher::class);

        $compressor = new CartCompressor(false, 'gzip');

        [$compression, $cart] = $compressor->serialize(['cart' => $cart, 'rule_ids' => []]);

        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);

        $redis = new RedisStub();
        $redis->set(RedisCartPersister::PREFIX . $oldToken, \serialize(['compressed' => $compression, 'content' => $cart]));

        $context = $this->createMock(SalesChannelContext::class);

        $persister = new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, $compressor, 90);

        $persister->replace($oldToken, $newToken, $context);

        static::assertFalse($redis->exists(RedisCartPersister::PREFIX . $oldToken));
        static::assertTrue($redis->exists(RedisCartPersister::PREFIX . $newToken));
    }

    public function testReplaceCopyRuleIds(): void
    {
        $oldToken = Uuid::randomHex();
        $newToken = Uuid::randomHex();
        $cart = new Cart($oldToken);
        $cart->add(new LineItem('test', 'test'));

        $dispatcher = new CollectingEventDispatcher();
        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);

        $redis = new RedisStub();

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getRuleIds')->willReturn(['test']);

        $persister = new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90);

        $persister->save($cart, $context);

        $persister->replace($oldToken, $newToken, $context);

        $movedCart = $persister->load($newToken, $context);

        static::assertSame(['test'], $movedCart->getRuleIds());
    }

    public function testInvalidCartReplace(): void
    {
        $token = Uuid::randomHex();

        $dispatcher = $this->createMock(EventDispatcher::class);
        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);

        $redis = new RedisStub();

        $persister = new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90);

        $context = $this->createMock(SalesChannelContext::class);

        $newToken = Uuid::randomHex();
        $persister->replace($token, $newToken, $context);

        static::assertFalse($redis->exists(RedisCartPersister::PREFIX . $token));
        static::assertFalse($redis->exists(RedisCartPersister::PREFIX . $newToken));
    }

    public function testExpiration(): void
    {
        $token = Uuid::randomHex();
        $cart = new Cart($token);
        $cart->add(new LineItem('test', 'test'));

        $dispatcher = $this->createMock(EventDispatcher::class);

        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);
        $redis = new RedisStub();

        $context = $this->createMock(SalesChannelContext::class);

        $persister = new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90);

        $persister->save($cart, $context);

        static::assertSame(90 * 86400, $redis->ttl(RedisCartPersister::PREFIX . $token));
    }

    public function testSaveCartWithoutErrorCleanup(): void
    {
        $token = Uuid::randomHex();
        $cart = new Cart($token);
        $cart->add(new LineItem('test', 'test'));
        $cart->addErrors(new ProductNotFoundError(Uuid::randomHex()));

        $context = $this->createMock(SalesChannelContext::class);
        $dispatcher = $this->createMock(EventDispatcher::class);
        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);
        $redis = new RedisStub();
        $persister = new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90);

        $persister->save($cart, $context);

        $cart = $persister->load($cart->getToken(), $context);

        static::assertNotEmpty($cart->getLineItems());
        static::assertEmpty($cart->getErrors());
    }

    public function testSaveCartWithPersistCartErrorPermission(): void
    {
        $token = Uuid::randomHex();
        $cart = new Cart($token);
        $cart->add(new LineItem('test', 'test'));

        $productId = Uuid::randomHex();
        $cart->addErrors(new ProductNotFoundError($productId));

        $cart->setBehavior(new CartBehavior([
            AbstractCartPersister::PERSIST_CART_ERROR_PERMISSION => true,
        ]));

        $context = $this->createMock(SalesChannelContext::class);
        $dispatcher = $this->createMock(EventDispatcher::class);
        $cartSerializationCleaner = $this->createMock(CartSerializationCleaner::class);
        $redis = new RedisStub();
        $persister = new RedisCartPersister($redis, $dispatcher, $cartSerializationCleaner, new CartCompressor(false, 'gzip'), 90);

        $persister->save($cart, $context);
        $cart = $persister->load($cart->getToken(), $context);

        static::assertNotEmpty($cart->getLineItems());
        static::assertNotEmpty($cart->getErrors());

        $error = $cart->getErrors()->first();
        static::assertInstanceOf(ProductNotFoundError::class, $error);
        static::assertEquals(['id' => $productId], $error->getParameters());
    }
}
