import template from './sw-condition-always-valid.html.twig';

const { Component } = Shopware;
const { mapPropertyErrors } = Component.getComponentHelper();

/**
 * @public
 * @sw-package fundamentals@after-sales
 * @description Always valid condition item for the condition-tree. This component must be a child of sw-condition-tree.
 * @status prototype
 * @example-type code-only
 * @component-example
 * <sw-condition-is-always-valid :condition="condition"></sw-condition-is-always-valid>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    computed: {
        isAlwaysValid() {
            return true;
        },

        defaultValues() {
            return {
                isAlwaysValid: true,
            };
        },

        selectValues() {
            return [
                {
                    label: this.$tc('global.default.yes'),
                    value: true,
                },
            ];
        },

        ...mapPropertyErrors('condition', ['value.isNew']),

        currentError() {
            return this.conditionValueIsNewError;
        },
    },
};
