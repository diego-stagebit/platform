<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Increment;

use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
class ArrayIncrementer extends AbstractIncrementer
{
    /**
     * @var array<string, array<string, int>>
     */
    private array $logs = [];

    public function increment(string $cluster, string $key): void
    {
        $this->logs[$cluster] ??= [];

        $this->logs[$cluster][$key] ??= 0;

        ++$this->logs[$cluster][$key];
    }

    public function decrement(string $cluster, string $key): void
    {
        $this->logs[$cluster] ??= [];

        if (!\array_key_exists($key, $this->logs[$cluster]) || $this->logs[$cluster][$key] === 0) {
            return;
        }

        --$this->logs[$cluster][$key];
    }

    public function reset(string $cluster, ?string $key = null): void
    {
        if (!\array_key_exists($cluster, $this->logs)) {
            return;
        }

        if ($key === null) {
            foreach ($this->logs[$cluster] as $key => $count) {
                $this->logs[$cluster][$key] = 0;
            }

            return;
        }

        $this->logs[$cluster][$key] = 0;
    }

    public function list(string $cluster, int $limit = 5, int $offset = 0): array
    {
        $mapped = [];

        if (!\array_key_exists($cluster, $this->logs)) {
            return [];
        }

        arsort($this->logs[$cluster], \SORT_NUMERIC);

        if ($limit > -1) {
            $this->logs[$cluster] = \array_slice($this->logs[$cluster], $offset, $limit, true);
        }

        foreach ($this->logs[$cluster] as $key => $count) {
            $mapped[$key] = [
                'key' => $key,
                'cluster' => $cluster,
                'pool' => $this->getPool(),
                'count' => max(0, (int) $count),
            ];
        }

        return $mapped;
    }

    public function delete(string $cluster, array $keys = []): void
    {
        if (!\array_key_exists($cluster, $this->logs)) {
            return;
        }

        if (empty($keys)) {
            unset($this->logs[$cluster]);

            return;
        }

        foreach ($keys as $key) {
            unset($this->logs[$cluster][$key]);
        }
    }

    public function resetAll(): void
    {
        $this->logs = [];
    }
}
