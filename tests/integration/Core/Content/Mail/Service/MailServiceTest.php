<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\Mail\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Mail\Service\AbstractMailSender;
use Shopware\Core\Content\Mail\Service\MailFactory;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Framework\Adapter\Twig\StringTemplateRenderer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\ReflectionHelper;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\Locale\LanguageLocaleCodeProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\TestDefaults;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * @internal
 */
class MailServiceTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    public function testThrowSalesChannelNotFound(): void
    {
        static::expectException(ConstraintViolationException::class);

        $data = [
            'recipients' => ['foo@bar.de'],
            'salesChannelId' => Uuid::randomHex(),
            'subject' => 'test',
            'senderName' => 'test',
            'contentHtml' => 'test',
            'contentPlain' => 'test',
        ];

        $this->getContainer()->get(MailService::class)->send($data, Context::createDefaultContext());
    }

    public function testPluginsCanExtendMailData(): void
    {
        $renderer = clone static::getContainer()->get(StringTemplateRenderer::class);
        $property = ReflectionHelper::getProperty(StringTemplateRenderer::class, 'twig');

        $twig = $property->getValue($renderer);
        \assert($twig instanceof Environment);
        $environment = new TestEnvironment($twig->getLoader());
        $property->setValue($renderer, $environment);

        $mailService = new MailService(
            static::getContainer()->get(DataValidator::class),
            $renderer,
            static::getContainer()->get(MailFactory::class),
            $this->createMock(AbstractMailSender::class),
            $this->createMock(EntityRepository::class),
            static::getContainer()->get('sales_channel.repository'),
            static::getContainer()->get(SystemConfigService::class),
            static::getContainer()->get('event_dispatcher'),
            $this->createMock(LoggerInterface::class),
            $this->createMock(LanguageLocaleCodeProvider::class)
        );
        $data = [
            'senderName' => 'Foo & Bar',
            'recipients' => ['baz@example.com' => 'Baz'],
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'contentHtml' => '<h1>Test</h1>',
            'contentPlain' => 'Test',
            'subject' => 'Test subject & content',
        ];

        $this->addEventListener(
            static::getContainer()->get('event_dispatcher'),
            MailBeforeValidateEvent::class,
            function (MailBeforeValidateEvent $event): void {
                $event->setTemplateData(
                    [...$event->getTemplateData(), ...['plugin-value' => true]]
                );
            }
        );

        $mailService->send($data, Context::createDefaultContext());

        static::assertArrayHasKey(0, $environment->getCalls());
        $first = $environment->getCalls()[0];
        static::assertArrayHasKey('data', $first);
        static::assertArrayHasKey('plugin-value', $first['data']);
    }

    /**
     * @return array<int, mixed[]>
     */
    public static function senderEmailDataProvider(): array
    {
        return [
            ['basic@example.com', 'basic@example.com', null, null],
            ['config@example.com', null, 'config@example.com', null],
            ['basic@example.com', 'basic@example.com', 'config@example.com', null],
            ['data@example.com', 'basic@example.com', 'config@example.com', 'data@example.com'],
            ['data@example.com', 'basic@example.com', null, 'data@example.com'],
            ['data@example.com', null, 'config@example.com', 'data@example.com'],
        ];
    }

    #[DataProvider('senderEmailDataProvider')]
    public function testEmailSender(string $expected, ?string $basicInformationEmail = null, ?string $configSender = null, ?string $dataSenderEmail = null): void
    {
        static::getContainer()
            ->get(Connection::class)
            ->executeStatement('DELETE FROM system_config WHERE configuration_key  IN ("core.mailerSettings.senderAddress", "core.basicInformation.email")');

        $systemConfig = static::getContainer()->get(SystemConfigService::class);
        if ($configSender !== null) {
            $systemConfig->set('core.mailerSettings.senderAddress', $configSender);
        }
        if ($basicInformationEmail !== null) {
            $systemConfig->set('core.basicInformation.email', $basicInformationEmail);
        }

        $languageLocaleProvider = $this->createMock(LanguageLocaleCodeProvider::class);
        $languageLocaleProvider
            ->method('getLocaleForLanguageId')
            ->willReturn('en-GB');

        $mailSender = $this->createMock(AbstractMailSender::class);
        $mailService = new MailService(
            static::getContainer()->get(DataValidator::class),
            static::getContainer()->get(StringTemplateRenderer::class),
            static::getContainer()->get(MailFactory::class),
            $mailSender,
            $this->createMock(EntityRepository::class),
            static::getContainer()->get('sales_channel.repository'),
            $systemConfig,
            $this->createMock(EventDispatcher::class),
            $this->createMock(LoggerInterface::class),
            $languageLocaleProvider
        );

        $salesChannel = $this->createSalesChannel();

        $data = [
            'senderName' => 'Foo & Bar',
            'recipients' => ['baz@example.com' => 'Baz'],
            'salesChannelId' => $salesChannel['id'],
            'contentHtml' => '<h1>Test</h1>',
            'contentPlain' => 'Test',
            'subject' => 'Test subject & content',
        ];
        if ($dataSenderEmail !== null) {
            $data['senderMail'] = $dataSenderEmail;
        }

        $mailSender->expects($this->once())
            ->method('send')
            ->with(static::callback(function (Email $mail) use ($expected, $data): bool {
                $from = $mail->getFrom();
                $this->assertSame($data['senderName'], $from[0]->getName());
                $this->assertSame($data['subject'], $mail->getSubject());
                $this->assertCount(1, $from);
                $this->assertSame($data['senderMail'] ?? $expected, $from[0]->getAddress());

                $this->assertSame('en-GB', $mail->getHeaders()->get('Content-Language')?->getBodyAsString());

                return true;
            }));
        $mailService->send($data, Context::createDefaultContext());
    }

    public function testItAllowsManipulationOfDataInBeforeValidateEvent(): void
    {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(MailBeforeValidateEvent::class, static function (MailBeforeValidateEvent $event): void {
            $data = $event->getData();
            $data['senderEmail'] = 'test@email.com';

            $event->setData($data);
        });
        $mailSender = $this->createMock(AbstractMailSender::class);
        $mailService = new MailService(
            static::getContainer()->get(DataValidator::class),
            $this->createMock(StringTemplateRenderer::class),
            static::getContainer()->get(MailFactory::class),
            $mailSender,
            $this->createMock(EntityRepository::class),
            static::getContainer()->get('sales_channel.repository'),
            static::getContainer()->get(SystemConfigService::class),
            $eventDispatcher,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LanguageLocaleCodeProvider::class)
        );

        $salesChannel = $this->createSalesChannel();

        $data = [
            'senderName' => 'Foo Bar',
            'recipients' => ['baz@example.com' => 'Baz'],
            'salesChannelId' => $salesChannel['id'],
            'contentHtml' => '<h1>Test</h1>',
            'contentPlain' => 'Test',
            'subject' => 'Test subject',
        ];

        $mailSender->expects($this->once())
            ->method('send')
            ->with(static::callback(function (Email $mail): bool {
                $from = $mail->getFrom();
                $this->assertCount(1, $from);
                $this->assertSame('test@email.com', $from[0]->getAddress());

                return true;
            }));
        $mailService->send($data, Context::createDefaultContext());
    }

    public function testMailSendingInTestMode(): void
    {
        $mailSender = $this->createMock(AbstractMailSender::class);
        $templateRenderer = $this->createMock(StringTemplateRenderer::class);
        $mailService = new MailService(
            $this->getContainer()->get(DataValidator::class),
            $templateRenderer,
            static::getContainer()->get(MailFactory::class),
            $mailSender,
            $this->createMock(EntityRepository::class),
            static::getContainer()->get('sales_channel.repository'),
            static::getContainer()->get(SystemConfigService::class),
            $this->createMock(EventDispatcher::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(LanguageLocaleCodeProvider::class)
        );

        $salesChannel = $this->createSalesChannel();

        $data = [
            'senderName' => 'Foo Bar',
            'recipients' => ['baz@example.com' => 'Baz'],
            'salesChannelId' => $salesChannel['id'],
            'contentHtml' => '<span>Test</span>',
            'contentPlain' => 'Test',
            'subject' => 'Test subject',
            'testMode' => true,
        ];

        $templateData = [
            'salesChannel' => [],
            'order' => [
                'deepLinkCode' => 'home',
            ],
            'eventName' => 'state_enter.order_transaction.state.paid',
        ];

        $context = Context::createDefaultContext();

        $mailSender->expects($this->once())
            ->method('send')
            ->with(static::callback(function (Email $mail) use ($salesChannel, $context): bool {
                $from = $mail->getFrom();
                $this->assertCount(1, $from);

                $this->assertNotNull($mail->getHeaders()->get('X-Shopware-Event-Name'));
                $this->assertNotNull($mail->getHeaders()->get('X-Shopware-Sales-Channel-Id'));
                $this->assertNotNull($mail->getHeaders()->get('X-Shopware-Language-Id'));

                $salesChannelIdHeader = $mail->getHeaders()->get('X-Shopware-Sales-Channel-Id');
                $this->assertSame($salesChannel['id'], $salesChannelIdHeader->getBodyAsString());

                $languageIdHeader = $mail->getHeaders()->get('X-Shopware-Language-Id');
                $this->assertSame($context->getLanguageId(), $languageIdHeader->getBodyAsString());

                return true;
            }));
        $mailService->send($data, $context, $templateData);
    }

    public function testHtmlEscaping(): void
    {
        $mailSender = $this->createMock(AbstractMailSender::class);
        $mailService = new MailService(
            static::getContainer()->get(DataValidator::class),
            static::getContainer()->get(StringTemplateRenderer::class),
            static::getContainer()->get(MailFactory::class),
            $mailSender,
            $this->createMock(EntityRepository::class),
            static::getContainer()->get('sales_channel.repository'),
            static::getContainer()->get(SystemConfigService::class),
            $this->createMock(EventDispatcher::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(LanguageLocaleCodeProvider::class)
        );

        $salesChannel = $this->createSalesChannel();

        $data = [
            'senderName' => 'Foo & Bar',
            'recipients' => ['baz@example.com' => 'Baz'],
            'salesChannelId' => $salesChannel['id'],
            'contentHtml' => '<a href="{{ url }}">{{ text }}</a>',
            'contentPlain' => '{{ text }} {{ url }}',
            'subject' => 'Test',
            'senderEmail' => 'test@example.com',
        ];

        $mail = $mailService->send($data, Context::createDefaultContext(), [
            'text' => '<foobar>',
            'url' => 'http://example.com/?foo&bar=baz',
        ]);

        static::assertInstanceOf(Email::class, $mail);
        static::assertSame('<a href="http://example.com/?foo&amp;bar=baz">&lt;foobar&gt;</a>', $mail->getHtmlBody());
        static::assertSame('<foobar> http://example.com/?foo&bar=baz', $mail->getTextBody());
    }
}

/**
 * @internal
 */
class TestEnvironment extends Environment
{
    /**
     * @var array<int, mixed[]>
     */
    private array $calls = [];

    /**
     * @param mixed[] $context
     */
    public function render($name, array $context = []): string
    {
        $this->calls[] = ['source' => $name, 'data' => $context];

        return parent::render($name, $context);
    }

    /**
     * @return array<int, mixed[]>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }
}
