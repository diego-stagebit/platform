<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Increment;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
class MySQLIncrementer extends AbstractIncrementer
{
    /**
     * @internal
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    public function increment(string $cluster, string $key): void
    {
        $payload = [
            'pool' => $this->poolName,
            'cluster' => $cluster,
            'key' => $key,
            'createdAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ];

        $this->connection->executeStatement('
            INSERT INTO `increment` (`pool`, `cluster`, `key`, `count`, `created_at`)
            VALUES (:pool, :cluster, :key, 1, :createdAt)
            ON DUPLICATE KEY UPDATE `count` = `count` + 1, `updated_at` = :createdAt
        ', $payload);
    }

    public function decrement(string $cluster, string $key): void
    {
        $payload = [
            'pool' => $this->poolName,
            'cluster' => $cluster,
            'key' => $key,
            'updatedAt' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ];

        $this->connection->executeStatement('
            UPDATE `increment`
            SET `count` = `count` - 1, `updated_at` = :updatedAt
            WHERE `pool` = :pool AND `cluster` = :cluster AND `key` = :key AND `count` > 0;
        ', $payload);
    }

    public function reset(string $cluster, ?string $key = null): void
    {
        $query = $this->connection->createQueryBuilder()
            ->update('increment')
            ->set('count', ':count')
            ->set('updated_at', ':updatedAt')
            ->where('pool = :pool')
            ->andWhere('cluster = :cluster')
            ->setParameter('updatedAt', (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT))
            ->setParameter('cluster', $cluster)
            ->setParameter('count', 0)
            ->setParameter('pool', $this->poolName);

        if ($key !== null) {
            $query->andWhere('`key` = :key')
                ->setParameter('key', $key);
        }

        RetryableQuery::retryable($this->connection, function () use ($query): void {
            $query->executeStatement();
        });
    }

    public function delete(string $cluster, array $keys = []): void
    {
        $query = $this->connection->createQueryBuilder()
            ->delete('increment')
            ->where('pool = :pool')
            ->andWhere('cluster = :cluster')
            ->setParameter('pool', $this->poolName)
            ->setParameter('cluster', $cluster);

        if (!empty($keys)) {
            $query->andWhere('`key` IN (:keys)')
                ->setParameter('keys', $keys, ArrayParameterType::STRING);
        }

        RetryableQuery::retryable($this->connection, function () use ($query): void {
            $query->executeStatement();
        });
    }

    public function list(string $cluster, int $limit = 5, int $offset = 0): array
    {
        $sql = 'SELECT `key` as array_key, `pool`, `cluster`, `key`, `count`
            FROM `increment`  WHERE `cluster` = :cluster AND `pool` = :pool
            ORDER BY `count` DESC, `updated_at` DESC';

        $payload = [
            'pool' => $this->poolName,
            'cluster' => $cluster,
        ];

        $types = [];

        if ($limit > -1) {
            $sql .= ' LIMIT :limit OFFSET :offset';
            $payload['limit'] = $limit;
            $payload['offset'] = $offset;
            $types = [
                'offset' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
            ];
        }

        /** @var array<string, array{count: int, key: string, cluster: string, pool: string}> $result */
        $result = $this->connection->fetchAllAssociativeIndexed($sql, $payload, $types);

        return $result;
    }
}
