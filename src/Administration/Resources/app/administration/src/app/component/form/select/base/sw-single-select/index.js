/**
 * @sw-package framework
 */

import './sw-single-select.scss';
import template from './sw-single-select.html.twig';

const { Mixin } = Shopware;
const { debounce, get } = Shopware.Utils;

/**
 * @private
 */
export default {
    template,

    inject: ['feature'],

    emits: [
        'update:value',
        'item-selected',
        'on-open-change',
        'before-selection-clear',
        'search',
        'paginate',
    ],

    mixins: [
        Mixin.getByName('remove-api-error'),
    ],

    props: {
        options: {
            required: true,
            type: Array,
        },
        // eslint-disable-next-line vue/require-prop-types
        value: {
            required: true,
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
        placeholder: {
            type: String,
            required: false,
            default: '',
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

        popoverClasses: {
            type: Array,
            required: false,
            default() {
                return [];
            },
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

        disableSearchFunction: {
            type: Boolean,
            required: false,
            default: false,
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
            isExpanded: false,
            results: this.options,
            // used to track if an item was selected before closing the result list
            itemRecentlySelected: false,
        };
    },

    computed: {
        currentValue: {
            get() {
                return this.value;
            },
            set(newValue) {
                this.$emit('update:value', newValue);
            },
        },

        inputClasses() {
            return {
                'is--expanded': this.isExpanded,
            };
        },

        selectionTextClasses() {
            return {
                'is--placeholder': !this.singleSelection,
            };
        },

        singleSelection: {
            get() {
                return this.options.find((option) => {
                    return this.getKey(option, this.valueProperty) === this.currentValue;
                });
            },
            set(newValue) {
                this.currentValue = this.getKey(newValue, this.valueProperty);
                this.$emit('item-selected', newValue);
            },
        },

        /**
         * @returns {Array}
         */
        visibleResults() {
            return this.results.filter((result) => !result.hidden);
        },
    },

    methods: {
        isSelected(item) {
            return this.getKey(item, this.valueProperty) === this.value;
        },

        onSelectExpanded() {
            this.isExpanded = true;
            this.$emit('on-open-change', true);

            // Always start with a fresh list when opening the result list
            this.results = this.options;

            // Get the search text of the selected item as prefilled value
            this.searchTerm = this.tryGetSearchText(this.singleSelection);

            this.$nextTick(() => {
                this.resetActiveItem();
                this.$refs.swSelectInput.select();
                this.$refs.swSelectInput.focus();
            });
        },

        tryGetSearchText(option) {
            return this.getKey(option, this.labelProperty, '');
        },

        onSelectCollapsed() {
            // Empty the selection if the search term is empty
            if (this.searchTerm === '' && !this.itemRecentlySelected) {
                this.$emit('before-selection-clear', this.singleSelection, this.value);
                this.currentValue = null;
            }

            this.$refs.swSelectInput.blur();
            this.searchTerm = '';
            this.itemRecentlySelected = false;
            this.$emit('on-open-change', false);
            this.isExpanded = false;
        },

        closeResultList() {
            this.$refs.selectBase.collapse();
        },

        setValue(item) {
            this.itemRecentlySelected = true;
            this.singleSelection = item;
            this.closeResultList();
        },

        resetActiveItem(pos = 0) {
            // Return if the result list is closed before the search request returns
            if (!this.$refs.resultsList) {
                return;
            }
            // If an item is selected the second entry is the first search result
            if (this.singleSelection) {
                pos = 1;
            }
            this.$refs.resultsList.setActiveItemIndex(pos);
        },

        onInputSearchTerm() {
            this.debouncedSearch();
        },

        debouncedSearch: debounce(function updateSearchTerm() {
            this.search();
        }, 100),

        search() {
            this.$emit('search', this.searchTerm);

            if (this.disableSearchFunction) {
                return;
            }

            this.results = this.searchFunction({
                options: this.options,
                labelProperty: this.labelProperty,
                valueProperty: this.valueProperty,
                searchTerm: this.searchTerm,
            });

            this.$nextTick(() => {
                this.resetActiveItem();
            });
        },

        getKey(object, keyPath, defaultValue) {
            return get(object, keyPath, defaultValue);
        },

        clearSelection() {
            this.setValue(null);
        },
    },
};
