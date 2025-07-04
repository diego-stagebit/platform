/**
 * @sw-package framework
 */

import template from './sw-popover.html.twig';
import './sw-popover.scss';

/**
 * @private
 * @description Renders a popover
 * @status ready
 * @example-type dynamic
 * @component-example
 * <sw-popover></sw-popover>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    props: {
        zIndex: {
            type: [
                Number,
                null,
            ],
            required: false,
            default: null,
        },
        resizeWidth: {
            type: Boolean,
            required: false,
            default: false,
        },
        popoverClass: {
            type: [
                String,
                Array,
                Object,
            ],
            required: false,
            default: '',
        },

        popoverConfigExtension: {
            type: Object,
            required: false,
            default: null,
        },
    },

    computed: {
        componentStyle() {
            return {
                'z-Index': this.zIndex,
            };
        },
        popoverConfig() {
            const popoverConfigBase = this.popoverConfigExtension || {};

            return {
                ...popoverConfigBase,
                active: true,
                resizeWidth: this.resizeWidth,
            };
        },
    },
};
