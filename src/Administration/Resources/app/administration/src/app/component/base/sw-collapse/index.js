import template from './sw-collapse.html.twig';

/**
 * @sw-package framework
 *
 * @private
 * @description A container, which creates a collapsible list of items.
 * @status ready
 * @example-type static
 * @component-example
 * <sw-collapse>
 *     <div #header>Header slot</div>
 *     <div #content>Content slot</div>
 * </sw-collapse>
 */
export default {
    template,

    props: {
        expandOnLoading: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            expanded: this.expandOnLoading,
        };
    },

    methods: {
        collapseItem() {
            this.expanded = !this.expanded;
        },
    },
};
