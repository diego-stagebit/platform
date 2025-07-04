/**
 * @sw-package framework
 */

import template from './sw-boolean-filter.html.twig';

const { Criteria } = Shopware.Data;

/**
 * @private
 */
export default {
    template,

    emits: [
        'filter-update',
        'filter-reset',
    ],

    props: {
        filter: {
            type: Object,
            required: true,
        },
        active: {
            type: Boolean,
            required: true,
        },
    },

    computed: {
        value() {
            return this.filter.value;
        },

        options() {
            return [
                {
                    id: 1,
                    label: this.$tc('sw-boolean-filter.active'),
                    value: 'true',
                },
                {
                    id: 2,
                    label: this.$tc('sw-boolean-filter.inactive'),
                    value: 'false',
                },
            ];
        },
    },

    methods: {
        changeValue(newValue) {
            if (!newValue) {
                this.resetFilter();
                return;
            }

            const filterCriteria = [
                Criteria.equals(this.filter.property, newValue === 'true'),
            ];

            this.$emit('filter-update', this.filter.name, filterCriteria, newValue);
        },

        resetFilter() {
            this.$emit('filter-reset', this.filter.name);
        },
    },
};
