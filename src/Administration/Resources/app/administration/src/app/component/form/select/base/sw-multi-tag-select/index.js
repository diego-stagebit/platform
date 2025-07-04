import template from './sw-multi-tag-select.html.twig';
import './sw-multi-tag-select.scss';

const { Mixin } = Shopware;
const { get } = Shopware.Utils;

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @description Renders a multi select field for data of any kind. This component uses the sw-field base
 * components. This adds the base properties such as <code>helpText</code>, <code>error</code>, <code>disabled</code> etc.
 * @example-type static
 * @component-example
 * <sw-multi-tag-select
 *     :value="['lorem', 'ipsum', 'dolor', 'sit', 'amet']"
 * ></sw-multi-tag-select>
 */
export default {
    template,

    inheritAttrs: false,

    inject: ['feature'],

    emits: [
        'add-item-is-valid',
        'update:value',
        'display-values-expand',
    ],

    mixins: [
        Mixin.getByName('remove-api-error'),
    ],

    props: {
        value: {
            type: Array,
            required: true,
        },

        valueLimit: {
            type: Number,
            required: false,
            default: 5,
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

        validMessage: {
            type: String,
            required: false,
            default: '',
        },

        invalidMessage: {
            type: String,
            required: false,
            default: '',
        },

        validate: {
            type: Function,
            required: false,
            default: (searchTerm) => searchTerm.length > 0,
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            searchTerm: '',
            hasFocus: false,
            limit: this.valueLimit,
        };
    },

    computed: {
        errorObject() {
            return null;
        },

        inputIsValid() {
            return this.validate(this.searchTerm);
        },

        visibleValues() {
            if (!this.value || this.value.length <= 0) {
                return [];
            }

            return this.value.map((entry) => ({ value: entry })).slice(0, this.limit);
        },

        totalValuesCount() {
            if (this.value.length) {
                return this.value.length;
            }

            return 0;
        },

        invisibleValueCount() {
            if (!this.value) {
                return 0;
            }

            return Math.max(0, this.totalValuesCount - this.limit);
        },
    },

    methods: {
        onSelectionListKeyDownEnter() {
            this.addItem();
        },

        addItem() {
            this.$emit('add-item-is-valid', this.inputIsValid);

            if (!this.inputIsValid) {
                return;
            }

            this.$emit('update:value', [
                ...this.value,
                this.searchTerm,
            ]);
            this.searchTerm = '';
        },

        remove({ value }) {
            this.$emit(
                'update:value',
                this.value.filter((entry) => entry !== value),
            );
        },

        removeLastItem() {
            if (!this.value.length) {
                return;
            }

            if (this.invisibleValueCount > 0) {
                this.expandValueLimit();
                return;
            }

            this.$emit('update:value', this.value.slice(0, -1));
        },

        onSearchTermChange(term) {
            this.searchTerm = term;
        },

        /* istanbul ignore next */
        getKey: get,

        setDropDown(open = true) {
            this.$refs.selectionList.focus();
            this.hasFocus = open;

            if (open) {
                return;
            }

            this.addItem();
        },

        expandValueLimit() {
            this.$emit('display-values-expand');

            this.limit += this.limit;
        },
    },
};
