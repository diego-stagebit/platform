/**
 * @sw-package framework
 */

import template from './sw-skeleton.html.twig';
import './sw-skeleton.scss';

/**
 * @private
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    props: {
        variant: {
            type: String,
            required: false,
            default: 'detail',
            validator(value: string) {
                const variants = [
                    'gallery',
                    'detail',
                    'detail-bold',
                    'category',
                    'listing',
                    'tree-item',
                    'tree-item-nested',
                    'media',
                    'extension-apps',
                    'extension-themes',
                ];

                return variants.includes(value);
            },
        },
    },

    computed: {
        classList() {
            return {
                'sw-skeleton__gallery': this.variant === 'gallery',
                'sw-skeleton__detail': this.variant === 'detail',
                'sw-skeleton__detail-bold': this.variant === 'detail-bold',
                'sw-skeleton__category': this.variant === 'category',
                'sw-skeleton__listing': this.variant === 'listing',
                'sw-skeleton__tree-item': this.variant === 'tree-item',
                'sw-skeleton__tree-item-nested': this.variant === 'tree-item-nested',
                'sw-skeleton__media': this.variant === 'media',
                'sw-skeleton__extension-apps': this.variant === 'extension-apps',
                'sw-skeleton__extension-themes': this.variant === 'extension-themes',
            };
        },
    },
});
