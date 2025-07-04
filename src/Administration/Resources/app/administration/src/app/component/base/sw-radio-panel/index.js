/**
 * @sw-package framework
 */

import template from './sw-radio-panel.html.twig';
import './sw-radio-panel.scss';

const utils = Shopware.Utils;

/**
 * @private
 * @description Radio panel that can be used for radio input with bigger content.
 * It is possible to define custom content via slots.
 * @status ready
 * @example-type static
 * @component-example
 * <sw-radio-panel
 *     value="selectionValueIfSelected"
 *     title="Example title"
 *     description="Example description"
 *     icon="regular-exclamation-triangle"
 * ></sw-radio-panel>
 */
export default {
    template,

    inject: ['feature'],

    emits: ['update:modelValue'],

    props: {
        // eslint-disable-next-line vue/require-default-prop
        value: {
            type: String,
        },
        // eslint-disable-next-line vue/require-default-prop
        modelValue: {
            type: String,
        },
        title: {
            type: String,
            default: '',
        },
        description: {
            type: String,
            default: '',
        },
        icon: {
            type: String,
            default: '',
        },
        id: {
            type: String,
            default() {
                return `sw-radio-panel--${utils.createId()}`;
            },
        },
        name: {
            type: String,
            default: null,
        },
        required: {
            type: Boolean,
            default: false,
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        truncate: {
            type: Boolean,
            default: false,
        },
    },

    computed: {
        checked() {
            return this.modelValue === this.value;
        },
    },

    methods: {
        toggle() {
            this.$emit('update:modelValue', this.value);
        },
    },
};
