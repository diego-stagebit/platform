import template from './sw-condition-goods-price.html.twig';
import './sw-condition-goods-price.scss';

const { Component } = Shopware;
const { mapPropertyErrors } = Component.getComponentHelper();

/**
 * @public
 * @sw-package fundamentals@after-sales
 * @description Condition for the GoodsPriceRule. This component must a be child of sw-condition-tree.
 * @status prototype
 * @example-type code-only
 * @component-example
 * <sw-condition-goods-price :condition="condition" :level="0"></sw-condition-goods-price>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    data() {
        return {
            showFilterModal: false,
        };
    },

    computed: {
        operators() {
            return this.conditionDataProviderService.getOperatorSet('number');
        },

        amount: {
            get() {
                this.ensureValueExist();
                return this.condition.value.amount;
            },
            set(amount) {
                this.ensureValueExist();
                this.condition.value = { ...this.condition.value, amount };
            },
        },

        ...mapPropertyErrors('condition', [
            'value.operator',
            'value.amount',
        ]),

        currentError() {
            return this.conditionValueOperatorError || this.conditionValueAmountError;
        },
    },
};
