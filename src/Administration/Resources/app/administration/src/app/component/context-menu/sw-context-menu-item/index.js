import template from './sw-context-menu-item.html.twig';
import './sw-context-menu-item.scss';

/**
 * @sw-package framework
 *
 * @private
 */
export default {
    template,

    props: {
        icon: {
            type: String,
            required: false,
            default: null,
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },

        routerLink: {
            type: Object,
            required: false,
            default: null,
        },

        target: {
            type: String,
            required: false,
            default: null,
        },

        variant: {
            type: String,
            required: false,
            default: '',
            validator(value) {
                if (!value.length) {
                    return true;
                }
                return [
                    'success',
                    'danger',
                    'warning',
                    'headline',
                ].includes(value);
            },
        },
    },

    computed: {
        contextMenuItemStyles() {
            return {
                [`sw-context-menu-item--${this.variant}`]: this.variant,
                'is--disabled': this.disabled && this.variant !== 'headline',
                'sw-context-menu-item--icon': this.icon,
            };
        },
    },

    methods: {
        // the listener has the `capture` modifier in the template to prevent parent listeners from being called
        handleClick(event) {
            if (this.disabled) {
                event.stopPropagation();
            }
        },
    },
};
