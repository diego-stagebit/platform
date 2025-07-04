import { inject } from 'vue';
import template from './sw-textarea-field.html.twig';
import './sw-textarea-field.scss';

const { Mixin } = Shopware;

/**
 * @sw-package framework
 *
 * @private
 * @description textarea input field.
 * @status ready
 * @example-type static
 * @component-example
 * <sw-textarea-field-deprecated type="textarea" label="Name" placeholder="placeholder goes here..."></sw-textarea-field>
 */
export default {
    template,

    inheritAttrs: false,

    inject: ['feature'],

    emits: [
        'update:value',
        'change',
        'inheritance-restore',
        'inheritance-remove',
    ],

    mixins: [
        Mixin.getByName('sw-form-field'),
        Mixin.getByName('remove-api-error'),
    ],

    props: {
        value: {
            type: String,
            required: false,
            default: null,
        },

        placeholder: {
            type: String,
            required: false,
            default: null,
        },

        ariaLabel: {
            type: String,
            required: false,
            default() {
                return inject('ariaLabel', null)?.value;
            },
        },
    },

    data() {
        return {
            currentValue: this.value || '',
        };
    },

    watch: {
        value() {
            this.currentValue = this.value;
        },
    },

    methods: {
        onInput(event) {
            this.$emit('update:value', event.target.value);
        },

        onChange(event) {
            this.$emit('change', event.target.value);
        },
    },
};
