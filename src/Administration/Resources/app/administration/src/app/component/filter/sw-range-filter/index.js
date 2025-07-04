/**
 * @sw-package framework
 */

import template from './sw-range-filter.html.twig';
import './sw-range-filter.scss';

const { Criteria } = Shopware.Data;

/**
 * @private
 */
export default {
    template,

    inject: ['feature'],

    emits: ['filter-update'],

    props: {
        value: {
            type: Object,
            required: true,
        },

        property: {
            type: String,
            required: true,
        },

        isShowDivider: {
            type: Boolean,
            required: false,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
        },
    },

    computed: {},

    watch: {
        value: {
            deep: true,
            handler(newValue) {
                this.updateFilter(newValue);
            },
        },
    },

    methods: {
        updateFilter(range) {
            const params = {
                ...(range.from ? { gte: range.from } : {}),
                ...(range.to ? { lte: range.to } : {}),
            };

            const filterCriteria = [Criteria.range(this.property, params)];
            this.$emit('filter-update', filterCriteria);
        },
    },
};
