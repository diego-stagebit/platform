import template from './sw-settings-tax-rule-type-zip-code-cell.html.twig';

/**
 * @sw-package checkout
 */

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    props: {
        taxRule: {
            type: Object,
            required: true,
        },
    },
};
