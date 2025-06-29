import template from './sw-extension-teaser-sales-channel.html.twig';
import './sw-extension-teaser-sales-channel.scss';

interface TeaserSalesChannelConfig {
    positionId: string;
    salesChannel: {
        title: string;
        description: string;
        iconName: string;
    };
    popoverComponent: {
        component: string;
        src: string;
        props: {
            label: string;
            locationId: string;
            variant: string;
        };
    };
}

/**
 * @sw-package innovation
 *
 * @private
 * @description A teaser sales channel for upselling service only, no public usage
 * @example-type dynamic
 * @component-example
 * <sw-extension-teaser-sales-channel />
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    computed: {
        teaserSalesChannels(): TeaserSalesChannelConfig[] {
            return Shopware.Store.get('teaserPopover').salesChannels || [];
        },
    },
});
