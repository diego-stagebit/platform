import template from './sw-select-selection-list.html.twig';
import './sw-select-selection-list.scss';

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @description Base component for rendering selection lists.
 * @example-type code-only
 */
export default {
    template,

    inject: ['feature'],

    emits: [
        'total-count-click',
        'search-term-change',
        'last-item-delete',
        'key-down-enter',
        'item-remove',
    ],

    props: {
        selections: {
            type: Array,
            required: false,
            default: () => [],
        },
        labelProperty: {
            type: String,
            required: false,
            default: 'label',
        },
        valueProperty: {
            type: String,
            required: false,
            default: 'value',
        },
        enableSearch: {
            type: Boolean,
            required: false,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
        },
        invisibleCount: {
            type: Number,
            required: false,
            default: 0,
        },
        size: {
            type: String,
            required: false,
            default: null,
        },
        alwaysShowPlaceholder: {
            type: Boolean,
            required: false,
            default: false,
        },
        placeholder: {
            type: String,
            required: false,
            default: '',
        },
        isLoading: {
            type: Boolean,
            required: false,
            default: false,
        },
        searchTerm: {
            type: String,
            required: false,
            default: '',
        },
        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
        selectionDisablingMethod: {
            type: Function,
            required: false,
            default: () => false,
        },
        hideLabels: {
            type: Boolean,
            required: false,
            default: false,
        },
        inputLabel: {
            type: String,
            required: false,
            default: undefined,
        },
    },

    computed: {
        showPlaceholder() {
            return this.alwaysShowPlaceholder || this.selections.length === 0 || this.hideLabels ? this.placeholder : '';
        },
    },

    methods: {
        isSelectionDisabled(selection) {
            if (this.disabled) {
                return true;
            }

            return this.selectionDisablingMethod(selection);
        },

        onClickInvisibleCount() {
            this.$emit('total-count-click');
        },

        onSearchTermChange(event) {
            this.$emit('search-term-change', event.target.value, event);
        },

        onKeyDownDelete() {
            if (this.searchTerm.length < 1) {
                this.$emit('last-item-delete');
            }
        },

        onKeyDownEnter() {
            this.$emit('key-down-enter');
        },

        onClickDismiss(item) {
            this.$emit('item-remove', item);
        },

        focus() {
            this.$refs.swSelectInput.focus();
        },

        blur() {
            this.$refs.swSelectInput.blur();
        },

        select() {
            this.$refs.swSelectInput.select();
        },

        getFocusEl() {
            return this.$refs.swSelectInput;
        },
    },
};
