<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Increment;

use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
abstract class AbstractIncrementer
{
    protected string $poolName;

    /**
     * @var array<string, mixed>
     */
    protected array $config;

    abstract public function decrement(string $cluster, string $key): void;

    abstract public function increment(string $cluster, string $key): void;

    /**
     * limit -1 means no limit
     *
     * @return array<string, array{count: int, key: string, cluster: string, pool: string}>
     */
    abstract public function list(string $cluster, int $limit = 5, int $offset = 0): array;

    abstract public function reset(string $cluster, ?string $key = null): void;

    /**
     * @deprecated tag:v6.8.0 - reason:visibility-change - Will become abstract
     *
     * @param array<string> $keys
     */
    public function delete(string $cluster, array $keys = []): void
    {
        Feature::throwException('v6.8.0.0', 'AbstractIncrementer::delete() is deprecated and will become abstract in v6.8.0.0. Please implement it in your incrementer class.');
    }

    public function getPool(): string
    {
        return $this->poolName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @internal
     */
    public function setPool(string $poolName): void
    {
        $this->poolName = $poolName;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }
}
