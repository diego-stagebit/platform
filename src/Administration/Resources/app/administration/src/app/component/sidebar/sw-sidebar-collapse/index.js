/**
 * @sw-package framework
 */

import template from './sw-sidebar-collapse.html.twig';
import './sw-sidebar-collapse.scss';

/**
 * @private
 */
export default {
    template,

    emits: ['change-expanded'],

    props: {
        expandChevronDirection: {
            type: String,
            required: false,
            default: 'right',
            validator: (value) =>
                [
                    'up',
                    'left',
                    'right',
                    'down',
                ].includes(value),
        },
    },

    computed: {
        expandButtonClass() {
            return {
                'is--hidden': this.expanded,
            };
        },

        collapseButtonClass() {
            return {
                'is--hidden': !this.expanded,
            };
        },
    },

    methods: {
        collapseItem() {
            this.$super('collapseItem');
            this.$emit('change-expanded', { isExpanded: this.expanded });
        },
    },
};
