<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\App\Manifest\Xml\CustomField\CustomFieldTypes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\App\Manifest\Manifest;
use Shopware\Core\Framework\App\Manifest\Xml\CustomField\CustomFieldTypes\SingleEntitySelectField;

/**
 * @internal
 */
#[CoversClass(SingleEntitySelectField::class)]
class SingleEntitySelectFieldTest extends TestCase
{
    public function testCreateFromXml(): void
    {
        $manifest = Manifest::createFromXmlFile(__DIR__ . '/_fixtures/single-entity-select-field.xml');

        static::assertNotNull($manifest->getCustomFields());
        static::assertCount(1, $manifest->getCustomFields()->getCustomFieldSets());

        $customFieldSet = $manifest->getCustomFields()->getCustomFieldSets()[0];

        static::assertCount(1, $customFieldSet->getFields());

        $singleEntitySelectField = $customFieldSet->getFields()[0];
        static::assertInstanceOf(SingleEntitySelectField::class, $singleEntitySelectField);
        static::assertSame('test_single_entity_select_field', $singleEntitySelectField->getName());
        static::assertSame([
            'en-GB' => 'Test single-entity-select field',
        ], $singleEntitySelectField->getLabel());
        static::assertSame([], $singleEntitySelectField->getHelpText());
        static::assertSame(1, $singleEntitySelectField->getPosition());
        static::assertSame(['en-GB' => 'Choose an entity...'], $singleEntitySelectField->getPlaceholder());
        static::assertFalse($singleEntitySelectField->getRequired());
        static::assertSame('product', $singleEntitySelectField->getEntity());
    }
}
