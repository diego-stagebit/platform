<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Rule\Api;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupDefinition;
use Shopware\Core\Checkout\Customer\Rule\CustomerGroupRule;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;

/**
 * @internal
 */
#[Package('fundamentals@after-sales')]
class RuleConfigControllerTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    public function testGetConditionsConfig(): void
    {
        $this->getBrowser()->request(
            'GET',
            '/api/_info/rule-config'
        );
        $response = $this->getBrowser()->getResponse();

        static::assertSame(200, $this->getBrowser()->getResponse()->getStatusCode());

        $content = json_decode($response->getContent() ?: '', true, 512, \JSON_THROW_ON_ERROR);

        $customerGroupRuleName = (new CustomerGroupRule())->getName();
        static::assertArrayHasKey($customerGroupRuleName, $content);

        $customerGroupRouleConfig = $content[$customerGroupRuleName];

        static::assertCount(2, $customerGroupRouleConfig['operatorSet']['operators']);
        static::assertSame(RuleConfig::OPERATOR_SET_STRING, $customerGroupRouleConfig['operatorSet']['operators']);
        static::assertTrue($customerGroupRouleConfig['operatorSet']['isMatchAny']);

        static::assertCount(1, $customerGroupRouleConfig['fields']);

        static::assertSame('customerGroupIds', $customerGroupRouleConfig['fields']['customerGroupIds']['name']);
        static::assertSame('multi-entity-id-select', $customerGroupRouleConfig['fields']['customerGroupIds']['type']);
        static::assertSame(CustomerGroupDefinition::ENTITY_NAME, $customerGroupRouleConfig['fields']['customerGroupIds']['config']['entity']);
    }
}
