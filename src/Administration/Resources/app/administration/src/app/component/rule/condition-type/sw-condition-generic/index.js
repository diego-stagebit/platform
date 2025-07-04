import template from './sw-condition-generic.html.twig';
import './sw-condition-generic.scss';

const { Mixin } = Shopware;
const { getPlaceholderSnippet } = Shopware.Utils.genericRuleCondition;

/**
 * @public
 * @sw-package fundamentals@after-sales
 * @description Condition for generic rules. This component must a be child of sw-condition-tree.
 * @status prototype
 * @example-type code-only
 * @component-example
 * <sw-condition-generic :condition="condition" :level="0"></sw-condition-generic>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,
    inheritAttrs: false,

    mixins: [
        Mixin.getByName('generic-condition'),
    ],

    data() {
        return {
            matchesAll: false,
        };
    },

    methods: {
        getPlaceholder(fieldType) {
            return this.$tc(getPlaceholderSnippet(fieldType));
        },
    },
};
