<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\DependencyInjection\CompilerPass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DependencyInjection\CompilerPass\RateLimiterCompilerPass;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
#[CoversClass(RateLimiterCompilerPass::class)]
class RateLimiterCompilerPassTest extends TestCase
{
    private Definition $rateLimiterDef;

    protected function setUp(): void
    {
        $config = [
            'shopware.api.rate_limiter' => [
                'cart_add_line_item' => [
                    'enabled' => true,
                    'id' => 'test_limit',
                    'policy' => 'system_config',
                    'reset' => '5 minutes',
                    'limits' => [
                        [
                            'domain' => 'test.limit',
                            'interval' => '10 seconds',
                        ],
                    ],
                ],
            ],
        ];
        $container = new ContainerBuilder(new ParameterBag($config));
        $container->register(RateLimiter::class);

        $rateLimiterCompilerPass = new RateLimiterCompilerPass();
        $rateLimiterCompilerPass->process($container);

        $this->rateLimiterDef = $container->getDefinition('shopware.rate_limiter');
    }

    public function testSystemServiceConfigReference(): void
    {
        static::assertSame('registerLimiterFactory', $this->rateLimiterDef->getMethodCalls()[0][0]);

        $registerLimiterFactoryCall = $this->rateLimiterDef->getMethodCalls()[0][1];
        static::assertSame('cart_add_line_item', $registerLimiterFactoryCall[0]);

        $rateLimiterDef = $registerLimiterFactoryCall[1];
        static::assertInstanceOf(Definition::class, $rateLimiterDef);

        $reference = $rateLimiterDef->getArgument(2);
        static::assertInstanceOf(Reference::class, $reference);
        static::assertSame(SystemConfigService::class, $reference->__toString());
    }
}
