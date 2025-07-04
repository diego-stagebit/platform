import template from './sw-tree-input-field.html.twig';
import './sw-tree-input-field.scss';

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @example-type code-only
 * @component-example
 * <sw-tree-input-field>
 * </sw-tree-input-field>
 */
export default {
    template,

    emits: ['new-item-create'],

    props: {
        // eslint-disable-next-line vue/require-default-prop
        currentValue: {
            type: String,
            required: false,
        },

        disabled: {
            type: Boolean,
            default: false,
        },
    },

    computed: {
        classes() {
            return {
                'is--disabled': this.disabled,
            };
        },
    },

    methods: {
        createNewItem(itemName) {
            this.$emit('new-item-create', itemName);
        },
    },
};
