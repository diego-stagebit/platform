<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Order\Transformer;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\AdminSalesChannelApiSource;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Json;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[Package('checkout')]
class CartTransformer
{
    /**
     * @deprecated tag:v6.8.0 - reason:parameter-name-change - parameter `$setOrderDate` will be renamed to `$setPersistentData`
     *
     * @return array<string, mixed>
     */
    public static function transform(Cart $cart, SalesChannelContext $context, string $stateId, bool $setOrderDate = true): array
    {
        $currency = $context->getCurrency();

        $data = [
            'price' => $cart->getPrice(),
            'shippingCosts' => $cart->getShippingCosts(),
            'stateId' => $stateId,
            'currencyId' => $currency->getId(),
            'currencyFactor' => $currency->getFactor(),
            'salesChannelId' => $context->getSalesChannelId(),
            'lineItems' => [],
            'deliveries' => [],
            'customerComment' => $cart->getCustomerComment(),
            'affiliateCode' => $cart->getAffiliateCode(),
            'campaignCode' => $cart->getCampaignCode(),
            'source' => $cart->getSource(),
        ];

        if ($setOrderDate) {
            $data['orderDateTime'] = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
            $data['deepLinkCode'] = Random::getBase64UrlString(32);
        }

        $source = $context->getContext()->getSource();
        if ($source instanceof AdminSalesChannelApiSource) {
            $originalContextSource = $source->getOriginalContext()->getSource();
            if ($originalContextSource instanceof AdminApiSource) {
                $data['createdById'] = $originalContextSource->getUserId();
            }
        }

        $data['itemRounding'] = json_decode(Json::encode($context->getItemRounding()), true, 512, \JSON_THROW_ON_ERROR);
        $data['totalRounding'] = json_decode(Json::encode($context->getTotalRounding()), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
