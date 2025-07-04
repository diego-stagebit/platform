/**
 * @sw-package framework
 */

import template from './sw-purchase-price-field.html.twig';

/**
 * @private
 */
export default {
    template,

    emits: ['update:value'],

    props: {
        price: {
            type: Array,
            required: true,
        },

        compact: {
            type: Boolean,
            required: false,
            default: false,
        },

        taxRate: {
            type: Object,
            required: true,
        },

        error: {
            type: Object,
            required: false,
            default: null,
        },

        // eslint-disable-next-line vue/require-prop-types
        label: {
            required: false,
            default: true,
        },

        // eslint-disable-next-line vue/require-prop-types
        disabled: {
            required: false,
            default: false,
        },

        currency: {
            type: Object,
            required: true,
        },
    },

    computed: {
        purchasePrice: {
            get() {
                const priceForCurrency = this.price.find((price) => price.currencyId === this.currency.id);
                if (priceForCurrency) {
                    return [priceForCurrency];
                }

                return [
                    {
                        gross: null,
                        currencyId: this.currency.id,
                        linked: true,
                        net: null,
                    },
                ];
            },

            set(newPurchasePrice) {
                let priceForCurrency = this.price.find((price) => price.currencyId === newPurchasePrice.currencyId);
                if (priceForCurrency) {
                    priceForCurrency = newPurchasePrice;
                } else {
                    // eslint-disable-next-line vue/no-mutating-props
                    this.price.push(newPurchasePrice);
                }

                this.$emit('update:value', this.price);
            },
        },
    },

    methods: {
        purchasePriceChanged(value) {
            this.purchasePrice = value;
        },
    },
};
