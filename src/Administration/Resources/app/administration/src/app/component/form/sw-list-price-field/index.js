/**
 * @sw-package framework
 */

import template from './sw-list-price-field.html.twig';
import './sw-list-price-field.scss';

/**
 * @private
 */
export default {
    template,

    inheritAttrs: false,

    props: {
        price: {
            type: Array,
            required: true,
            default() {
                return [];
            },
        },

        purchasePrices: {
            type: Array,
            default() {
                return [];
            },
        },

        defaultPrice: {
            type: Object,
            required: false,
            default() {
                return {};
            },
        },

        // eslint-disable-next-line vue/require-prop-types
        label: {
            required: false,
            default: true,
        },

        taxRate: {
            type: Object,
            required: true,
            default() {
                return {};
            },
        },

        currency: {
            type: Object,
            required: true,
            default() {
                return {};
            },
        },

        // eslint-disable-next-line vue/require-prop-types
        compact: {
            required: false,
            default: false,
        },

        error: {
            type: Object,
            required: false,
            default: null,
        },

        // eslint-disable-next-line vue/require-prop-types
        disabled: {
            required: false,
            default: false,
        },

        enableInheritance: {
            type: Boolean,
            required: false,
            default: false,
        },

        disableSuffix: {
            type: Boolean,
            required: false,
            default: false,
        },

        vertical: {
            type: Boolean,
            required: false,
            default: false,
        },

        hideListPrices: {
            type: Boolean,
            required: false,
            default: false,
        },

        hidePurchasePrices: {
            type: Boolean,
            required: false,
            default: false,
        },

        hideRegulationPrices: {
            type: Boolean,
            required: false,
            default: false,
        },

        showSettingPrice: {
            type: Boolean,
            required: false,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
        },
    },

    computed: {
        priceForCurrency: {
            get() {
                const priceForCurrency = Object.values(this.price).find((price) => {
                    return price.currencyId === this.currency.id;
                });

                // check if price exists
                if (priceForCurrency) {
                    return priceForCurrency;
                }

                // otherwise calculate values
                return {
                    gross: this.convertPrice(this.defaultPrice.gross),
                    linked: this.defaultPrice.linked,
                    net: this.convertPrice(this.defaultPrice.net),
                    listPrice: this.defaultPrice.listPrice,
                    regulationPrice: this.defaultPrice.regulationPrice,
                };
            },
            set(newValue) {
                this.priceForCurrency.gross = newValue.gross;
                this.priceForCurrency.linked = newValue.linked;
                this.priceForCurrency.net = newValue.net;
            },
        },

        listPrice: {
            get() {
                const price = this.priceForCurrency;

                if (price.listPrice) {
                    return [price.listPrice];
                }

                return [
                    {
                        gross: null,
                        currencyId: this.defaultPrice.currencyId ? this.defaultPrice.currencyId : this.currency.id,
                        linked: true,
                        net: null,
                    },
                ];
            },

            set(newValue) {
                const price = this.priceForCurrency;

                if (price) {
                    // eslint-disable-next-line vue/no-mutating-props
                    price.listPrice = newValue;
                }
            },
        },

        regulationPrice: {
            get() {
                const price = this.priceForCurrency;

                if (price.regulationPrice) {
                    return [price.regulationPrice];
                }

                return [
                    {
                        gross: null,
                        currencyId: this.defaultPrice.currencyId ? this.defaultPrice.currencyId : this.currency.id,
                        linked: true,
                        net: null,
                    },
                ];
            },

            set(newValue) {
                const price = this.priceForCurrency;

                if (price) {
                    price.regulationPrice = newValue;
                }
            },
        },

        defaultListPrice() {
            if (this.defaultPrice.listPrice) {
                return this.defaultPrice.listPrice;
            }

            return {
                currencyId: this.defaultPrice.currencyId ? this.defaultPrice.currencyId : this.currency.id,
                gross: null,
                net: null,
                linked: true,
            };
        },

        defaultRegulationPrice() {
            if (this.defaultPrice.regulationPrice) {
                return this.defaultPrice.regulationPrice;
            }

            return {
                currencyId: this.defaultPrice.currencyId ? this.defaultPrice.currencyId : this.currency.id,
                gross: null,
                net: null,
                linked: true,
            };
        },

        isInherited() {
            const priceForCurrency = Object.values(this.price).find((price) => {
                return price.currencyId === this.currency.id;
            });

            return !priceForCurrency;
        },

        listPriceHelpText() {
            if (!this.vertical || this.compact) {
                return null;
            }

            return this.$tc('global.sw-list-price-field.helpTextListPriceGross');
        },

        regulationPriceHelpText() {
            if (!this.vertical || this.compact) {
                return null;
            }

            return this.$tc('global.sw-list-price-field.helpTextRegulationPriceGross');
        },
    },

    methods: {
        listPriceChanged(value) {
            if (Number.isNaN(value.gross) || Number.isNaN(value.net)) {
                value = null;
            }

            this.listPrice = value;
        },

        regulationPriceChanged(value) {
            if (Number.isNaN(value.gross) || Number.isNaN(value.net)) {
                value = null;
            }

            this.regulationPrice = value;
        },

        convertPrice(value) {
            const calculatedPrice = value * this.currency.factor;
            const priceRounded = calculatedPrice.toFixed(this.currency.decimalPrecision);
            return Number(priceRounded);
        },
    },
};
