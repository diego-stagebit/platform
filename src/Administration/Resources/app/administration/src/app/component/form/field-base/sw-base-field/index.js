/**
 * @sw-package framework
 */
import template from './sw-base-field.html.twig';
import './sw-base-field.scss';

const utils = Shopware.Utils;

/**
 * @private
 */
export default {
    template,
    inheritAttrs: false,

    inject: ['feature'],

    emits: ['base-field-mounted'],

    props: {
        name: {
            type: String,
            required: false,
            default: null,
        },

        label: {
            type: String,
            required: false,
            default: null,
        },

        helpText: {
            type: String,
            required: false,
            default: null,
        },

        hint: {
            type: String,
            required: false,
            default: null,
        },

        isInvalid: {
            type: Boolean,
            required: false,
            default: false,
        },

        aiBadge: {
            type: Boolean,
            required: false,
            default: false,
        },

        error: {
            type: [Object],
            required: false,
            default() {
                return null;
            },
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },

        required: {
            type: Boolean,
            required: false,
            default: false,
        },

        isInherited: {
            type: Boolean,
            required: false,
            default: false,
        },

        isInheritanceField: {
            type: Boolean,
            required: false,
            default: false,
        },

        disableInheritanceToggle: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            id: utils.createId(),
        };
    },

    computed: {
        identification() {
            if (this.name) {
                return this.name;
            }

            return `sw-field--${this.id}`;
        },

        hasLabel() {
            return !!this.helpText || this.isInheritanceField || this.showLabel;
        },

        hasError() {
            return this.isInvalid || !!this.error;
        },

        hasHint() {
            return !!this.hint || this.$slots.hint?.()[0]?.children.length > 0;
        },

        swFieldClasses() {
            return {
                'has--error': this.hasError,
                'has--hint': this.hasHint,
                'is--disabled': this.disabled,
                'is--inherited': this.isInherited,
            };
        },

        swFieldLabelClasses() {
            return {
                'is--required': this.required,
            };
        },

        showLabel() {
            return !!this.label || this.$slots.label?.()[0]?.children.length > 0;
        },
    },

    mounted() {
        this.$emit('base-field-mounted');
    },
};
