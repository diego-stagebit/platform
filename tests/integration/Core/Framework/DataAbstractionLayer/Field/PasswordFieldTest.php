<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\Field;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\PasswordField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldSerializer\PasswordFieldSerializer;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommandQueue;
use Shopware\Core\Framework\DataAbstractionLayer\Write\DataStack\KeyValuePair;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteParameterBag;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\User\UserDefinition;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
class PasswordFieldTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testGetStorage(): void
    {
        $field = new PasswordField('password', 'password');
        static::assertSame('password', $field->getStorageName());
    }

    public function testNullableField(): void
    {
        $field = new PasswordField('password', 'password');
        $existence = new EntityExistence(static::getContainer()->get(UserDefinition::class)->getEntityName(), [], false, false, false, []);
        $kvPair = new KeyValuePair('password', null, true);

        $passwordFieldHandler = new PasswordFieldSerializer(
            static::getContainer()->get(ValidatorInterface::class),
            static::getContainer()->get(DefinitionInstanceRegistry::class),
            static::getContainer()->get(SystemConfigService::class)
        );

        $payload = $passwordFieldHandler->encode($field, $existence, $kvPair, new WriteParameterBag(
            static::getContainer()->get(UserDefinition::class),
            WriteContext::createFromContext(Context::createDefaultContext()),
            '',
            new WriteCommandQueue()
        ));

        $payload = iterator_to_array($payload);
        static::assertSame($kvPair->getValue(), $payload['password']);
    }

    public function testEncoding(): void
    {
        $field = new PasswordField('password', 'password');
        $existence = new EntityExistence(static::getContainer()->get(UserDefinition::class)->getEntityName(), [], false, false, false, []);
        $kvPair = new KeyValuePair('password', 'shopware', true);

        $passwordFieldHandler = new PasswordFieldSerializer(
            static::getContainer()->get(ValidatorInterface::class),
            static::getContainer()->get(DefinitionInstanceRegistry::class),
            static::getContainer()->get(SystemConfigService::class)
        );

        $payload = $passwordFieldHandler->encode($field, $existence, $kvPair, new WriteParameterBag(
            static::getContainer()->get(UserDefinition::class),
            WriteContext::createFromContext(Context::createDefaultContext()),
            '',
            new WriteCommandQueue()
        ));

        $payload = iterator_to_array($payload);
        static::assertNotSame($kvPair->getValue(), $payload['password']);
        static::assertTrue(password_verify((string) $kvPair->getValue(), (string) $payload['password']));
    }

    public function testValueIsRequiredOnInsert(): void
    {
        $field = new PasswordField('password', 'password');
        $field->addFlags(new ApiAware(), new Required());

        $existence = new EntityExistence(static::getContainer()->get(UserDefinition::class)->getEntityName(), [], false, false, false, []);
        $kvPair = new KeyValuePair('password', null, true);

        $exception = null;
        $array = null;

        try {
            $handler = static::getContainer()->get(PasswordFieldSerializer::class);

            $parameters = new WriteParameterBag(
                static::getContainer()->get(UserDefinition::class),
                WriteContext::createFromContext(Context::createDefaultContext()),
                '',
                new WriteCommandQueue()
            );

            $x = $handler->encode($field, $existence, $kvPair, $parameters);
            $array = iterator_to_array($x);
        } catch (WriteConstraintViolationException $exception) {
        }

        static::assertIsNotArray($array);
        static::assertInstanceOf(WriteConstraintViolationException::class, $exception);
        static::assertCount(1, $exception->getViolations()->findByCodes(NotBlank::IS_BLANK_ERROR));
    }

    public function testValueIsRequiredOnUpdate(): void
    {
        $field = new PasswordField('password', 'password');
        $field->addFlags(new ApiAware(), new Required());

        $existence = new EntityExistence(static::getContainer()->get(UserDefinition::class)->getEntityName(), [], true, false, false, []);
        $kvPair = new KeyValuePair('password', null, true);

        $exception = null;
        $array = null;

        try {
            $handler = static::getContainer()->get(PasswordFieldSerializer::class);

            $x = $handler->encode($field, $existence, $kvPair, new WriteParameterBag(
                static::getContainer()->get(UserDefinition::class),
                WriteContext::createFromContext(Context::createDefaultContext()),
                '',
                new WriteCommandQueue()
            ));
            $array = iterator_to_array($x);
        } catch (WriteConstraintViolationException $exception) {
        }

        static::assertIsNotArray($array);
        static::assertInstanceOf(WriteConstraintViolationException::class, $exception);
        static::assertCount(1, $exception->getViolations()->findByCodes(NotBlank::IS_BLANK_ERROR));
    }

    public function testAlreadyEncodedValueIsPassedThrough(): void
    {
        $password = password_hash('shopware', \PASSWORD_DEFAULT);

        $field = new PasswordField('password', 'password');
        $existence = new EntityExistence(static::getContainer()->get(UserDefinition::class)->getEntityName(), [], false, false, false, []);
        $kvPair = new KeyValuePair('password', $password, true);

        $passwordFieldHandler = new PasswordFieldSerializer(
            static::getContainer()->get(ValidatorInterface::class),
            static::getContainer()->get(DefinitionInstanceRegistry::class),
            static::getContainer()->get(SystemConfigService::class)
        );

        $payload = $passwordFieldHandler->encode($field, $existence, $kvPair, new WriteParameterBag(
            static::getContainer()->get(UserDefinition::class),
            WriteContext::createFromContext(Context::createDefaultContext()),
            '',
            new WriteCommandQueue()
        ));

        $payload = iterator_to_array($payload);
        static::assertSame($kvPair->getValue(), $payload['password']);
    }
}
