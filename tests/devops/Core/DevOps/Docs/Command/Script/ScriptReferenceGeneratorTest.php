<?php declare(strict_types=1);

namespace Shopware\Tests\DevOps\Core\DevOps\Docs\Command\Script;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\DevOps\Docs\Script\ScriptReferenceDataCollector;
use Shopware\Core\DevOps\Docs\Script\ScriptReferenceGenerator;
use Shopware\Core\DevOps\Docs\Script\ScriptReferenceGeneratorCommand;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * @internal
 */
#[CoversClass(ScriptReferenceGeneratorCommand::class)]
class ScriptReferenceGeneratorTest extends TestCase
{
    use IntegrationTestBehaviour;

    public static function tearDownAfterClass(): void
    {
        ScriptReferenceDataCollector::reset();
    }

    public function testGeneratedDocumentsAreRecent(): void
    {
        $generators = $this->getGenerators();

        foreach ($generators as $generator) {
            foreach ($generator->generate() as $filename => $content) {
                static::assertSame(
                    $content,
                    file_get_contents($filename),
                    <<<MSG
The app scripts reference documentation is not up to date.
Please regenerate the documentation by running `bin/console docs:generate-scripts-reference`.
Also ensure that the copied files in the publicly accessible gitbook @ `https://github.com/shopware/docs` are also updated!'
MSG
                );
            }
        }
    }

    /**
     * Ugly hack as the container does not expose all services with a specific tag
     *
     * @return iterable<ScriptReferenceGenerator>
     */
    private function getGenerators(): iterable
    {
        $command = static::getContainer()->get(ScriptReferenceGeneratorCommand::class);

        $reflection = new \ReflectionClass($command);

        $property = $reflection->getProperty('generators');
        /** @var iterable<ScriptReferenceGenerator> $generators */
        $generators = $property->getValue($command);

        return $generators;
    }
}
