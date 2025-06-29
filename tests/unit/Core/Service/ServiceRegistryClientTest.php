<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Service\ServiceRegistryClient;
use Shopware\Core\Service\ServiceRegistryEntry;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @internal
 */
#[CoversClass(ServiceRegistryClient::class)]
class ServiceRegistryClientTest extends TestCase
{
    public static function invalidResponseProvider(): \Generator
    {
        yield 'not-json' => [''];

        yield 'no-services-key' => [json_encode(['blah' => [1, 2, 3]])];

        yield 'not-correct-list' => [json_encode(['services' => [1, 2, 3]])];

        yield 'not-correct-service-definition' => [json_encode(['services' => [['not-valid' => 1]]])];

        yield 'missing-label' => [json_encode(['services' => [['name' => 'SomeService']]])];

        yield 'missing-host' => [json_encode(['services' => [['name' => 'SomeService', 'label' => 'SomeService']]])];

        yield 'missing-app-endpoint' => [json_encode(['services' => [['name' => 'SomeService', 'label' => 'SomeService', 'host' => 'https://www.someservice.com']]])];

        yield '1-valid-1-invalid' => [json_encode([
            'services' => [
                ['name' => 'SomeService', 'label' => 'SomeService', 'host' => 'https://www.someservice.com', 'app-endpoint' => '/register'],
                ['not-valid' => 1],
            ],
        ])];
    }

    #[DataProvider('invalidResponseProvider')]
    public function testInvalidResponseBodyReturnsEmptyListOfServices(string $response): void
    {
        $client = new MockHttpClient([
            $response = new MockResponse($response),
        ]);

        $registryClient = new ServiceRegistryClient('https://www.shopware.com/services.json', $client);

        static::assertSame([], $registryClient->getAll());
        static::assertSame('https://www.shopware.com/services.json', $response->getRequestUrl());
    }

    public function testFailRequestReturnsEmptyListOfServices(): void
    {
        $client = new MockHttpClient([
            $response = new MockResponse('', ['http_code' => 503]),
        ]);

        $registryClient = new ServiceRegistryClient('https://www.shopware.com/services.json', $client);

        static::assertSame([], $registryClient->getAll());
        static::assertSame('https://www.shopware.com/services.json', $response->getRequestUrl());
    }

    public function testSuccessfulRequestReturnsListOfServices(): void
    {
        $service = [
            'services' => [
                ['name' => 'MyCoolService1', 'host' => 'https://coolservice1.com', 'label' => 'My Cool Service 1', 'app-endpoint' => '/app-endpoint'],
                ['name' => 'MyCoolService2', 'host' => 'https://coolservice2.com', 'label' => 'My Cool Service 2', 'app-endpoint' => '/app-endpoint', 'license-sync-endpoint' => '/license-sync-endpoint'],
            ],
        ];

        $client = new MockHttpClient([
            $response = new MockResponse((string) json_encode($service)),
        ]);

        $registryClient = new ServiceRegistryClient('https://www.shopware.com/services.json', $client);

        $entries = $registryClient->getAll();

        static::assertCount(2, $entries);
        static::assertContainsOnlyInstancesOf(ServiceRegistryEntry::class, $entries);
        static::assertSame('MyCoolService1', $entries[0]->name);
        static::assertSame('My Cool Service 1', $entries[0]->description);
        static::assertSame('https://coolservice1.com', $entries[0]->host);
        static::assertSame('/app-endpoint', $entries[0]->appEndpoint);
        static::assertNull($entries[0]->licenseSyncEndPoint);
        static::assertSame('MyCoolService2', $entries[1]->name);
        static::assertSame('My Cool Service 2', $entries[1]->description);
        static::assertSame('https://coolservice2.com', $entries[1]->host);
        static::assertSame('/app-endpoint', $entries[1]->appEndpoint);
        static::assertSame('https://www.shopware.com/services.json', $response->getRequestUrl());
        static::assertSame('/license-sync-endpoint', $entries[1]->licenseSyncEndPoint);
    }

    public function testServicesAreFetchedAndCached(): void
    {
        $service = [
            'services' => [
                ['name' => 'MyCoolService1', 'host' => 'https://coolservice1.com', 'label' => 'My Cool Service 1', 'app-endpoint' => '/app-endpoint'],
                ['name' => 'MyCoolService2', 'host' => 'https://coolservice2.com', 'label' => 'My Cool Service 2', 'app-endpoint' => '/app-endpoint'],
            ],
        ];

        $client = new MockHttpClient([
            new MockResponse((string) json_encode($service)),
        ]);

        $registryClient = new ServiceRegistryClient('https://www.shopware.com/services.json', $client);

        $entries1 = $registryClient->getAll();
        static::assertCount(2, $entries1);

        // second fetch would be empty array, since request would fail as we don't provide a second mocked response.
        // registry client will catch and return empty array.
        $entries2 = $registryClient->getAll();
        static::assertCount(2, $entries2);

        static::assertSame($entries1, $entries2);
    }

    public function testResetCausesRefetch(): void
    {
        $services1 = [
            'services' => [
                ['name' => 'MyCoolService1', 'host' => 'https://coolservice1.com', 'label' => 'My Cool Service 1', 'app-endpoint' => '/app-endpoint'],
                ['name' => 'MyCoolService2', 'host' => 'https://coolservice2.com', 'label' => 'My Cool Service 2', 'app-endpoint' => '/app-endpoint'],
            ],
        ];

        $services2 = [
            'services' => [
                ['name' => 'MyCoolService1', 'host' => 'https://coolservice1.com', 'label' => 'My Cool Service 1', 'app-endpoint' => '/app-endpoint'],
                ['name' => 'MyCoolService2', 'host' => 'https://coolservice2.com', 'label' => 'My Cool Service 2', 'app-endpoint' => '/app-endpoint'],
                ['name' => 'MyCoolService3', 'host' => 'https://coolservice3.com', 'label' => 'My Cool Service 3', 'app-endpoint' => '/app-endpoint'],
            ],
        ];

        $client = new MockHttpClient([
            new MockResponse((string) json_encode($services1)),
            new MockResponse((string) json_encode($services2)),
        ]);

        $registryClient = new ServiceRegistryClient('https://www.shopware.com/services.json', $client);

        $entries1 = $registryClient->getAll();
        static::assertCount(2, $entries1);

        $registryClient->reset();

        $entries2 = $registryClient->getAll();
        static::assertCount(3, $entries2);
    }
}
