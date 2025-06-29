import template from './sw-multi-select.html.twig';

const { Mixin } = Shopware;
const { debounce, get } = Shopware.Utils;

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @description Renders a multi select field with a defined list of options. This component uses the sw-field base
 * components. This adds the base properties such as <code>helpText</code>, <code>error</code>, <code>disabled</code> etc.
 * @example-type code-only
 * @component-example
 * <sw-multi-select
 *     label="Multi Select"
 *     :options="[
 *         { value 'uuid1', label 'Portia Jobson' },
 *         { value 'uuid2', label 'Baxy Eardley' },
 *         { value 'uuid3', label 'Arturo Staker' },
 *         { value 'uuid4', label 'Dalston Top' },
 *         { value 'uuid5', label 'Neddy Jensen' }
 *     ]"
 *     value="">
 * </sw-multi-select>
 */
export default {
    template,

    inheritAttrs: false,

    inject: ['feature'],

    emits: [
        'update:value',
        'item-add',
        'item-remove',
        'search-term-change',
        'display-values-expand',
        'paginate',
    ],

    mixins: [
        Mixin.getByName('remove-api-error'),
    ],

    props: {
        options: {
            type: Array,
            required: true,
        },
        value: {
            required: true,
            validator(value) {
                return Array.isArray(value) || value === null || value === undefined;
            },
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
        placeholder: {
            type: String,
            required: false,
            default: '',
        },
        valueLimit: {
            type: Number,
            required: false,
            default: 5,
        },
        isLoading: {
            type: Boolean,
            required: false,
            default: false,
        },
        highlightSearchTerm: {
            type: Boolean,
            required: false,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
        },
        // Used to implement a custom search function.
        // Parameters passed: { options, labelProperty, valueProperty, searchTerm }
        searchFunction: {
            type: Function,
            required: false,
            default({ options, labelProperty, searchTerm }) {
                return options.filter((option) => {
                    const label = this.getKey(option, labelProperty);
                    if (!label) {
                        return false;
                    }
                    return label.toLowerCase().includes(searchTerm.toLowerCase());
                });
            },
        },
        label: {
            type: String,
            required: false,
            default: undefined,
        },
    },

    data() {
        return {
            searchTerm: '',
            limit: this.valueLimit,
        };
    },

    computed: {
        visibleValues() {
            if (!this.currentValue || this.currentValue.length <= 0) {
                return [];
            }

            return this.options
                .filter((item) => {
                    return this.currentValue.includes(this.getKey(item, this.valueProperty));
                })
                .slice(0, this.limit);
        },

        totalValuesCount() {
            if (this.currentValue.length) {
                return this.currentValue.length;
            }

            return 0;
        },

        invisibleValueCount() {
            if (!this.currentValue) {
                return 0;
            }

            return Math.max(0, this.totalValuesCount - this.limit);
        },

        currentValue: {
            get() {
                if (!this.value) {
                    return [];
                }

                return this.value;
            },
            set(newValue) {
                this.$emit('update:value', newValue);
            },
        },

        visibleResults() {
            if (this.searchTerm) {
                return this.searchFunction({
                    options: this.options,
                    labelProperty: this.labelProperty,
                    valueProperty: this.valueProperty,
                    searchTerm: this.searchTerm,
                });
            }

            return this.options;
        },
    },

    methods: {
        isSelected(item) {
            return this.currentValue.includes(this.getKey(item, this.valueProperty));
        },

        addItem(item) {
            const identifier = this.getKey(item, this.valueProperty);

            if (this.isSelected(item)) {
                this.remove(item);
                return;
            }

            this.$emit('item-add', item);

            this.currentValue = [
                ...this.currentValue,
                identifier,
            ];

            this.$refs.selectionList.focus();
            this.$refs.selectionList.select();
        },

        remove(item) {
            this.$emit('item-remove', item);

            this.currentValue = this.currentValue.filter((value) => {
                return value !== this.getKey(item, this.valueProperty);
            });
        },

        removeLastItem() {
            if (!this.visibleValues.length) {
                return;
            }

            if (this.invisibleValueCount > 0) {
                this.expandValueLimit();
                return;
            }

            const lastSelection = this.visibleValues[this.visibleValues.length - 1];
            this.remove(lastSelection);
        },

        expandValueLimit() {
            this.$emit('display-values-expand');

            this.limit += this.limit;
        },

        onSearchTermChange: debounce(function updateSearchTerm(term) {
            this.searchTerm = term;
            this.$emit('search-term-change', this.searchTerm);
            this.resetActiveItem();
        }, 100),

        resetActiveItem() {
            this.$refs.swSelectResultList.setActiveItemIndex(0);
        },

        onSelectExpanded() {
            this.$refs.selectionList.focus();
        },

        onSelectCollapsed() {
            this.searchTerm = '';
            this.$refs.selectionList.blur();

            // Focus on the input field when the select is collapsed
            if (this.$refs.selectionList?.$refs?.swSelectInput) {
                this.$refs.selectionList.$refs.swSelectInput.focus();
            }
        },

        getKey(object, keyPath, defaultValue) {
            return get(object, keyPath, defaultValue);
        },
    },
};
