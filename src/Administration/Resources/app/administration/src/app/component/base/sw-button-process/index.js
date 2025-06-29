import template from './sw-button-process.html.twig';
import './sw-button-process.scss';

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @description The <u>sw-button-process</u> component extends the sw-button component with visual feedback,
 * indicating loading and success states.
 * @example-type dynamic
 * @component-example
 * <sw-button-process>
 *     Button
 * </sw-button-process>
 */
export default {
    template,

    inheritAttrs: false,

    inject: ['feature'],

    emits: ['update:processSuccess'],

    props: {
        processSuccess: {
            type: Boolean,
            required: true,
        },

        animationTimeout: {
            type: Number,
            required: false,
            default: 1250,
        },
    },

    computed: {
        contentVisibilityClass() {
            return {
                'is--hidden': this.processSuccess,
            };
        },
    },

    watch: {
        processSuccess(value) {
            if (!value) {
                return;
            }

            setTimeout(() => {
                this.$emit('update:processSuccess', false);
            }, this.animationTimeout);
        },
    },
};
