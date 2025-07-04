<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Payment\DataAbstractionLayer;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\DataAbstractionLayer\PaymentDistinguishableNameSubscriber;
use Shopware\Core\Checkout\Payment\PaymentEvents;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
#[Package('checkout')]
class PaymentDistinguishableNameSubscriberTest extends TestCase
{
    use IntegrationTestBehaviour;

    private PaymentDistinguishableNameSubscriber $subscriber;

    private Context $context;

    protected function setUp(): void
    {
        $this->subscriber = new PaymentDistinguishableNameSubscriber();
        $this->context = Context::createDefaultContext();
    }

    public function testSubscribedEvents(): void
    {
        static::assertSame(
            [
                PaymentEvents::PAYMENT_METHOD_LOADED_EVENT => 'addDistinguishablePaymentName',
            ],
            $this->subscriber->getSubscribedEvents()
        );
    }

    public function testFallsBackToPaymentMethodNameIfDistinguishableNameIsNotSet(): void
    {
        $paymentRepository = static::getContainer()->get('payment_method.repository');

        $paymentRepository->create(
            [
                [
                    'id' => $creditCardPaymentId = Uuid::randomHex(),
                    'name' => [
                        'en-GB' => 'Credit card',
                        'de-DE' => 'Kreditkarte',
                    ],
                    'technicalName' => 'payment_creditcard',
                    'active' => true,
                ],
                [
                    'id' => $invoicePaymentByShopwarePluginId = Uuid::randomHex(),
                    'name' => [
                        'en-GB' => 'Invoice',
                        'de-DE' => 'Rechnungskauf',
                    ],
                    'technicalName' => 'payment_invoice1',
                    'active' => true,
                    'plugin' => [
                        'name' => 'Shopware',
                        'baseClass' => 'Swag\Paypal',
                        'autoload' => [],
                        'version' => '1.0.0',
                        'label' => [
                            'en-GB' => 'Shopware (English)',
                            'de-DE' => 'Shopware (Deutsch)',
                        ],
                    ],
                ],
                [
                    'id' => $invoicePaymentByPluginId = Uuid::randomHex(),
                    'name' => [
                        'en-GB' => 'Invoice',
                        'de-DE' => 'Rechnung',
                    ],
                    'technicalName' => 'payment_invoice2',
                    'active' => true,
                    'plugin' => [
                        'name' => 'Plugin',
                        'baseClass' => 'Plugin\Paypal',
                        'autoload' => [],
                        'version' => '1.0.0',
                        'label' => [
                            'en-GB' => 'Plugin (English)',
                            'de-DE' => 'Plugin (Deutsch)',
                        ],
                    ],
                ],
                [
                    'id' => $invoicePaymentByAppId = Uuid::randomHex(),
                    'name' => [
                        'en-GB' => 'Invoice',
                        'de-DE' => 'Rechnung',
                    ],
                    'technicalName' => 'payment_invoice3',
                    'active' => true,
                    'appPaymentMethod' => [
                        'identifier' => 'identifier',
                        'appName' => 'appName',
                        'app' => [
                            'name' => 'App',
                            'path' => 'path',
                            'version' => '1.0.0',
                            'label' => 'App',
                            'integration' => [
                                'accessKey' => 'accessKey',
                                'secretAccessKey' => 'secretAccessKey',
                                'label' => 'Integration',
                            ],
                            'aclRole' => [
                                'name' => 'aclRole',
                            ],
                        ],
                    ],
                ],
            ],
            $this->context
        );

        /** @var PaymentMethodCollection $payments */
        $payments = $paymentRepository
            ->search(new Criteria(), $this->context)
            ->getEntities();

        $creditCardPayment = $payments->get($creditCardPaymentId);
        static::assertNotNull($creditCardPayment);
        static::assertSame('Credit card', $creditCardPayment->getDistinguishableName());

        /** @var PaymentMethodEntity $invoicePaymentByShopwarePlugin */
        $invoicePaymentByShopwarePlugin = $payments->get($invoicePaymentByShopwarePluginId);
        static::assertSame('Invoice | Shopware (English)', $invoicePaymentByShopwarePlugin->getDistinguishableName());

        /** @var PaymentMethodEntity $invoicePaymentByPlugin */
        $invoicePaymentByPlugin = $payments->get($invoicePaymentByPluginId);
        static::assertSame('Invoice | Plugin (English)', $invoicePaymentByPlugin->getDistinguishableName());

        /** @var PaymentMethodEntity $invoicePaymentByApp */
        $invoicePaymentByApp = $payments->get($invoicePaymentByAppId);
        static::assertSame('Invoice | App', $invoicePaymentByApp->getDistinguishableName());

        /** @var PaymentMethodEntity $paidInAdvance */
        $paidInAdvance = $payments
            ->filterByProperty('name', 'Paid in advance')
            ->first();

        static::assertSame($paidInAdvance->getTranslation('name'), $paidInAdvance->getTranslation('distinguishableName'));

        $germanContext = new Context(
            new SystemSource(),
            [],
            Defaults::CURRENCY,
            [$this->getDeDeLanguageId(), Defaults::LANGUAGE_SYSTEM]
        );

        /** @var PaymentMethodCollection $payments */
        $payments = $paymentRepository
            ->search(new Criteria(), $germanContext)
            ->getEntities();

        $creditCardPayment = $payments->get($creditCardPaymentId);
        static::assertNotNull($creditCardPayment);
        static::assertSame('Kreditkarte', $creditCardPayment->getDistinguishableName());

        /** @var PaymentMethodEntity $invoicePaymentByShopwarePlugin */
        $invoicePaymentByShopwarePlugin = $payments->get($invoicePaymentByShopwarePluginId);
        static::assertSame('Rechnungskauf | Shopware (Deutsch)', $invoicePaymentByShopwarePlugin->getDistinguishableName());

        /** @var PaymentMethodEntity $invoicePaymentByPlugin */
        $invoicePaymentByPlugin = $payments->get($invoicePaymentByPluginId);
        static::assertSame('Rechnung | Plugin (Deutsch)', $invoicePaymentByPlugin->getDistinguishableName());

        /** @var PaymentMethodEntity $invoicePaymentByApp */
        $invoicePaymentByApp = $payments->get($invoicePaymentByAppId);
        static::assertSame('Rechnung | App', $invoicePaymentByApp->getDistinguishableName());
    }
}
