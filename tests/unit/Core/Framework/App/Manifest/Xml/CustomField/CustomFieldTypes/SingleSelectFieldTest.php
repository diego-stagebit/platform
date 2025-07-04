<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\App\Manifest\Xml\CustomField\CustomFieldTypes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\App\Manifest\Manifest;
use Shopware\Core\Framework\App\Manifest\Xml\CustomField\CustomFieldTypes\SingleSelectField;

/**
 * @internal
 */
#[CoversClass(SingleSelectField::class)]
class SingleSelectFieldTest extends TestCase
{
    public function testCreateFromXml(): void
    {
        $manifest = Manifest::createFromXmlFile(__DIR__ . '/_fixtures/single-select-field.xml');

        static::assertNotNull($manifest->getCustomFields());
        static::assertCount(1, $manifest->getCustomFields()->getCustomFieldSets());

        $customFieldSet = $manifest->getCustomFields()->getCustomFieldSets()[0];

        static::assertCount(1, $customFieldSet->getFields());

        $singleSelectField = $customFieldSet->getFields()[0];
        static::assertInstanceOf(SingleSelectField::class, $singleSelectField);
        static::assertSame('test_single_select_field', $singleSelectField->getName());
        static::assertSame([
            'en-GB' => 'Test single-select field',
        ], $singleSelectField->getLabel());
        static::assertSame([], $singleSelectField->getHelpText());
        static::assertSame(1, $singleSelectField->getPosition());
        static::assertSame(['en-GB' => 'Choose an option...'], $singleSelectField->getPlaceholder());
        static::assertFalse($singleSelectField->getRequired());
        static::assertSame([
            'first' => [
                'en-GB' => 'First',
                'de-DE' => 'Erster',
            ],
            'second' => [
                'en-GB' => 'Second',
            ],
        ], $singleSelectField->getOptions());
    }
}
