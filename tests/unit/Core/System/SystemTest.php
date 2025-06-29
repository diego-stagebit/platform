<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\System;

/**
 * @internal
 */
#[CoversClass(System::class)]
class SystemTest extends TestCase
{
    public function testTemplatePriority(): void
    {
        $system = new System();

        static::assertSame(-1, $system->getTemplatePriority());
    }
}
