<?php declare(strict_types=1);

namespace Shopware\Core\Content\Sitemap\Service;

use League\Flysystem\FilesystemOperator;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Checkout\Cart\CartRuleLoader;
use Shopware\Core\Content\Sitemap\Event\SitemapGeneratedEvent;
use Shopware\Core\Content\Sitemap\Event\SitemapGenerationStartEvent;
use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\SitemapException;
use Shopware\Core\Content\Sitemap\Struct\SitemapGenerationResult;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Package('discovery')]
class SitemapExporter implements SitemapExporterInterface
{
    /**
     * @var array<string, SitemapHandleInterface>
     */
    private array $sitemapHandles = [];

    /**
     * @param iterable<AbstractUrlProvider> $urlProvider
     *
     * @internal
     */
    public function __construct(
        private readonly iterable $urlProvider,
        private readonly CacheItemPoolInterface $cache,
        private readonly int $batchSize,
        private readonly FilesystemOperator $filesystem,
        private readonly SitemapHandleFactoryInterface $sitemapHandleFactory,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly CartRuleLoader $ruleLoader,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function generate(SalesChannelContext $context, bool $force = false, ?string $lastProvider = null, ?int $offset = null): SitemapGenerationResult
    {
        $this->refreshContextRules($context);

        $this->dispatcher->dispatch(
            new SitemapGenerationStartEvent($context)
        );

        $this->lock($context, $force);

        try {
            $this->initSitemapHandles($context);

            foreach ($this->urlProvider as $urlProvider) {
                do {
                    $result = $urlProvider->getUrls($context, $this->batchSize, $offset);

                    $this->processSiteMapHandles($result);

                    $needRun = $result->getNextOffset() !== null;
                    $offset = $result->getNextOffset();
                } while ($needRun);
            }

            $this->finishSitemapHandles();
        } finally {
            $this->unlock($context);
        }

        $this->dispatcher->dispatch(new SitemapGeneratedEvent($context));

        return new SitemapGenerationResult(
            true,
            $lastProvider,
            null,
            $context->getSalesChannelId(),
            $context->getLanguageId()
        );
    }

    private function lock(SalesChannelContext $salesChannelContext, bool $force): void
    {
        $key = $this->generateCacheKeyForSalesChannel($salesChannelContext);
        $item = $this->cache->getItem($key);
        if ($item->isHit() && !$force) {
            throw SitemapException::sitemapAlreadyLocked($salesChannelContext);
        }

        $item->set(true);
        $this->cache->save($item);
    }

    private function unlock(SalesChannelContext $salesChannelContext): void
    {
        $this->cache->deleteItem($this->generateCacheKeyForSalesChannel($salesChannelContext));
    }

    /**
     * Ensure that the rules are loaded for the current context in case that the SalesChannelContext was created from
     * Factory and is missing the attached rules.
     */
    private function refreshContextRules(SalesChannelContext $salesChannelContext): SalesChannelContext
    {
        if (\count($salesChannelContext->getRuleIds()) > 0) {
            return $salesChannelContext;
        }

        $this->ruleLoader->loadByToken($salesChannelContext, $salesChannelContext->getToken());

        return $salesChannelContext;
    }

    private function generateCacheKeyForSalesChannel(SalesChannelContext $salesChannelContext): string
    {
        return \sprintf('sitemap-exporter-running-%s-%s', $salesChannelContext->getSalesChannelId(), $salesChannelContext->getLanguageId());
    }

    private function initSitemapHandles(SalesChannelContext $context): void
    {
        $languageId = $context->getLanguageId();
        $domainsEntity = $context->getSalesChannel()->getDomains();

        $sitemapDomains = [];
        if ($domainsEntity instanceof SalesChannelDomainCollection) {
            foreach ($domainsEntity as $domain) {
                if ($domain->getLanguageId() === $languageId) {
                    $urlParts = \parse_url($domain->getUrl());

                    if ($urlParts === false) {
                        continue;
                    }

                    $arrayKey = ($urlParts['host'] ?? '') . ($urlParts['path'] ?? '');

                    if (\array_key_exists($arrayKey, $sitemapDomains) && $sitemapDomains[$arrayKey]['scheme'] === 'https') {
                        continue;
                    }

                    $sitemapDomains[$arrayKey] = [
                        'domainId' => $domain->getId(),
                        'url' => $domain->getUrl(),
                        'scheme' => $urlParts['scheme'] ?? '',
                    ];
                }
            }
        }

        $sitemapHandles = [];
        foreach ($sitemapDomains as $sitemapDomain) {
            $sitemapHandles[$sitemapDomain['url']] = $this->sitemapHandleFactory->create($this->filesystem, $context, $sitemapDomain['url'], $sitemapDomain['domainId']);
        }

        if (empty($sitemapHandles)) {
            throw SitemapException::invalidDomain();
        }

        $this->sitemapHandles = $sitemapHandles;
    }

    private function processSiteMapHandles(UrlResult $result): void
    {
        /** @var SitemapHandle $sitemapHandle */
        foreach ($this->sitemapHandles as $host => $sitemapHandle) {
            /** @var Url[] $urls */
            $urls = [];

            foreach ($result->getUrls() as $url) {
                $newUrl = clone $url;
                $newUrl->setLoc(rtrim($host, '/') . '/' . ltrim($newUrl->getLoc(), '/'));
                $urls[] = $newUrl;
            }

            $sitemapHandle->write($urls);
        }
    }

    private function finishSitemapHandles(): void
    {
        /** @var SitemapHandle $sitemapHandle */
        foreach ($this->sitemapHandles as $index => $sitemapHandle) {
            if ($index === array_key_first($this->sitemapHandles)) {
                $sitemapHandle->finish();

                continue;
            }

            $sitemapHandle->finish(false);
        }
    }
}
