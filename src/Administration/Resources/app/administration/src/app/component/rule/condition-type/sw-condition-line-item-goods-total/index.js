import template from './sw-condition-line-item-goods-total.html.twig';
import './sw-condition-line-item-goods-total.scss';

const { Component } = Shopware;
const { mapPropertyErrors } = Component.getComponentHelper();

/**
 * @public
 * @sw-package fundamentals@after-sales
 * @description Condition for the LineItemGoodsTotalRule. This component must a be child of sw-condition-tree.
 * @status prototype
 * @example-type code-only
 * @component-example
 * <sw-condition-line-item-goods-total :condition="condition" :level="0"></sw-condition-line-item-goods-total>
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

        count: {
            get() {
                this.ensureValueExist();
                return this.condition.value.count;
            },
            set(count) {
                this.ensureValueExist();
                this.condition.value = { ...this.condition.value, count };
            },
        },

        ...mapPropertyErrors('condition', [
            'value.operator',
            'value.count',
        ]),

        currentError() {
            return this.conditionValueOperatorError || this.conditionValueCountError;
        },
    },
};
