<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Adapter\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Adapter\Cache\RedisConnectionFactory;

/**
 * @internal
 */
#[Group('redis')]
class RedisConnectionFactoryTest extends TestCase
{
    #[DataProvider('prefixProvider')]
    public function testPrefix(?string $aPrefix, ?string $bPrefix, bool $equals): void
    {
        /** @var string $url */
        $url = EnvironmentHelper::getVariable('REDIS_URL');

        if (!$url) {
            static::markTestSkipped('No redis server configured');
        }

        $a = (new RedisConnectionFactory($aPrefix))->create($url);
        $b = (new RedisConnectionFactory($bPrefix))->create($url);

        $a->set('foo', 'bar');
        $b->set('foo', 'foo');

        static::assertSame($equals, $a->get('foo') === $b->get('foo'));
    }

    public static function prefixProvider(): \Generator
    {
        yield 'Test different namespace' => ['namespace-1', 'namespace-2', false];
        yield 'Test same namespace' => ['namespace-1', 'namespace-1', true];
        yield 'Test with none have no namespace' => [null, 'namespace-1', false];
        yield 'Test with both have no namespace' => [null, null, true];
    }
}
