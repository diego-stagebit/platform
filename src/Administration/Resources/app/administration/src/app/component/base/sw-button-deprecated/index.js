import './sw-button.scss';
import template from './sw-button.html.twig';

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @description The <u>sw-button</u> component replaces the standard html button or anchor element with a custom button
 * and a multitude of options.
 * @example-type dynamic
 * @component-example
 * <sw-button>
 *     Button
 * </sw-button>
 *
 * @deprecated tag:v6.8.0 - Will be removed, use mt-button instead.
 */
export default {
    template,

    inheritAttrs: false,

    props: {
        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
        variant: {
            type: String,
            required: false,
            default: '',
            validValues: [
                'primary',
                'ghost',
                'danger',
                'ghost-danger',
                'contrast',
                'context',
            ],
            validator(value) {
                if (!value.length) {
                    return true;
                }
                return [
                    'primary',
                    'ghost',
                    'danger',
                    'ghost-danger',
                    'contrast',
                    'context',
                ].includes(value);
            },
        },
        size: {
            type: String,
            required: false,
            default: '',
            validValues: [
                'x-small',
                'small',
            ],
            validator(value) {
                if (!value.length) {
                    return true;
                }

                return [
                    'x-small',
                    'small',
                ].includes(value);
            },
        },
        square: {
            type: Boolean,
            required: false,
            default: false,
        },
        block: {
            type: Boolean,
            required: false,
            default: false,
        },
        // eslint-disable-next-line vue/require-default-prop
        routerLink: {
            type: Object,
            required: false,
        },
        link: {
            type: String,
            required: false,
            default: null,
        },
        isLoading: {
            type: Boolean,
            default: false,
            required: false,
        },
    },

    computed: {
        buttonClasses() {
            return {
                [`sw-button--${this.variant}`]: this.variant,
                [`sw-button--${this.size}`]: this.size,
                'sw-button--block': this.block,
                'sw-button--disabled': this.disabled,
                'sw-button--square': this.square,
            };
        },

        contentVisibilityClass() {
            return {
                'is--hidden': this.isLoading,
            };
        },

        filteredAttributes() {
            const attributes = { ...this.$attrs };

            if (this.disabled) {
                // Remove onClick event if button is disabled
                attributes.onClick = null;
            }

            return attributes;
        },
    },
};
