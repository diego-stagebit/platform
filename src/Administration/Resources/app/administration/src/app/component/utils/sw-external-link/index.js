import template from './sw-external-link.html.twig';
import './sw-external-link.scss';

/**
 * @sw-package framework
 *
 * @private
 * @description Link to another website outside the admin, that opens in a new browser tab
 * @status ready
 * @example-type dynamic
 * @component-example
 * <sw-external-link
 *   href="https://google.com">
 *   Ask google
 * </sw-external-link>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inheritAttrs: false,

    emits: ['click'],

    props: {
        small: {
            type: Boolean,
            required: false,
            default: false,
        },

        icon: {
            type: String,
            required: false,
            default: 'regular-external-link-s',
        },

        rel: {
            type: String,
            required: false,
            default: 'noopener',
        },
    },

    computed: {
        classes() {
            return {
                'sw-external-link--small': this.small,
            };
        },

        iconSize() {
            if (this.small) {
                return '8px';
            }

            return '10px';
        },
    },

    methods: {
        onClick(event) {
            this.$emit('click', event);
        },
    },
};
