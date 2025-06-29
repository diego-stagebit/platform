/*
 * @sw-package inventory
 */

import template from './sw-property-option-list.html.twig';
import './sw-property-option-list.scss';

const { Store } = Shopware;

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inject: [
        'repositoryFactory',
        'acl',
    ],

    props: {
        propertyGroup: {
            type: Object,
            required: true,
        },

        optionRepository: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            isLoading: false,
            currentOption: null,
            term: null,
            naturalSorting: true,
            selection: null,
            deleteButtonDisabled: true,
            sortBy: 'name',
            sortDirection: 'ASC',
            showDeleteModal: false,
            showEmptyState: false,
        };
    },

    computed: {
        isSystemLanguage() {
            return Store.get('context').api.systemLanguageId === this.currentLanguage;
        },

        currentLanguage() {
            return Store.get('context').api.languageId;
        },

        allowInlineEdit() {
            return !!this.acl.can('property.editor');
        },

        tooltipAdd() {
            return {
                message: this.$tc('sw-property.detail.addOptionNotPossible'),
                disabled: this.isSystemLanguage,
            };
        },

        disableAddButton() {
            return this.propertyGroup.isLoading || !this.isSystemLanguage || !this.acl.can('property.editor');
        },

        /**
         * @deprecated tag:v6.8.0 - Will be removed
         */
        dataSource() {
            return this.propertyGroup.options && this.propertyGroup.options.slice(0, this.limit);
        },
    },

    watch: {
        currentLanguage() {
            this.refreshOptionList();
        },
    },

    methods: {
        onSearch() {
            this.propertyGroup.options.criteria.setTerm(this.term);
            this.refreshOptionList();
        },

        onGridSelectionChanged(selection, selectionCount) {
            this.selection = selection;
            this.deleteButtonDisabled = selectionCount <= 0;
        },

        onOptionDelete(option) {
            if (option.isNew()) {
                this.propertyGroup.options.remove(option.id);
                return Promise.resolve();
            }

            return this.optionRepository.delete(option.id);
        },

        onSingleOptionDelete(option) {
            this.$refs.grid.deleteItem(option);
        },

        onDeleteOptions() {
            if (!this.selection) {
                return;
            }

            Promise.allSettled(Object.values(this.selection).map((option) => this.onOptionDelete(option))).then(() => {
                this.refreshOptionList();
            });
        },

        onAddOption() {
            if (!this.isSystemLanguage) {
                return false;
            }

            this.currentOption = this.optionRepository.create();

            return true;
        },

        onCancelOption() {
            // close modal
            this.currentOption = null;

            this.$refs.grid.load();
        },

        onSaveOption() {
            if (this.propertyGroup.isNew()) {
                return this.saveGroupLocal();
            }

            return this.saveGroupRemote();
        },

        saveGroupLocal() {
            if (this.currentOption.isNew()) {
                if (!this.propertyGroup.options.has(this.currentOption.id)) {
                    this.propertyGroup.options.add(this.currentOption);
                }

                this.currentOption = null;
            }

            return Promise.resolve();
        },

        saveGroupRemote() {
            return this.optionRepository.save(this.currentOption).then(() => {
                // closing modal
                this.currentOption = null;
                this.$refs.grid.load();
            });
        },

        refreshOptionList() {
            this.isLoading = true;

            this.$refs.grid.load().then(() => {
                this.isLoading = false;
            });
        },

        onOptionEdit(option) {
            const localCopy = option;
            localCopy._isNew = false;

            this.currentOption = localCopy;
        },

        getGroupColumns() {
            return [
                {
                    property: 'name',
                    label: this.$tc('sw-property.detail.labelOptionName'),
                    routerLink: 'sw.property.detail',
                    inlineEdit: 'string',
                    primary: true,
                },
                {
                    property: 'colorHexCode',
                    label: this.$tc('sw-property.detail.labelOptionColor'),
                },
                {
                    property: 'position',
                    label: this.$tc('sw-property.detail.labelOptionPosition'),
                    inlineEdit: 'number',
                },
            ];
        },

        checkEmptyState() {
            this.showEmptyState = this.$refs.grid?.total === 0;
        },
    },
};
