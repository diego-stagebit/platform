/**
 * @sw-package framework
 */

import template from './sw-one-to-many-grid.html.twig';

const { Criteria } = Shopware.Data;

/**
 * @private
 */
export default {
    template,

    inject: ['repositoryFactory'],

    emits: [
        'load-finish',
        'delete-item-failed',
        'items-delete-finish',
        'column-sort',
    ],

    props: {
        collection: {
            required: true,
            type: Array,
        },
        localMode: {
            type: Boolean,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
        },
        // eslint-disable-next-line vue/require-default-prop
        dataSource: {
            type: [
                Array,
                Object,
            ],
            required: false,
            default(props) {
                return props.localMode && props.collection ? props.collection : null;
            },
        },
        allowDelete: {
            type: Boolean,
            required: false,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
        },
        tooltipDelete: {
            type: Object,
            required: false,
            default() {
                return {
                    message: '',
                    disabled: true,
                };
            },
        },
    },

    data() {
        return {
            page: 1,
            limit: 25,
            total: 0,
            initial: true,
        };
    },

    watch: {
        collection: {
            handler() {
                if (!this.initial) {
                    this.load();
                }
            },
            deep: true,
        },
    },

    methods: {
        createdComponent() {
            this.$super('createdComponent');

            // assign collection as records for the sw-data-grid
            this.applyResult(this.collection);

            this.initial = false;

            // local mode means, the records are loaded with the parent record
            if (this.localMode) {
                return Promise.resolve();
            }

            // Create repository by collection sources
            // the collection contains the route for the entities /customer/{id}/addresses
            this.repository = this.repositoryFactory.create(
                // product_price
                this.collection.entity,

                // product/{id}/price-rules/
                this.collection.source,
            );

            // records contains a pre loaded offset
            if (Array.isArray(this.records) && this.records.length > 0) {
                return Promise.resolve();
            }

            return this.load();
        },

        applyResult(result) {
            this.result = result;

            if (!this.collection || !this.initial) {
                this.records = result;
            }

            if (result.total) {
                this.total = result.total;
            } else {
                this.total = result.length;
            }

            if (result.criteria) {
                this.page = result.criteria.page || this.page;
                this.limit = result.criteria.limit || this.limit;
            }
        },

        save(record) {
            if (this.localMode) {
                // records will be saved with the root record
                return Promise.resolve();
            }

            return this.repository.save(record, this.result.context).then(() => {
                return this.load();
            });
        },

        revert() {
            if (this.localMode) {
                return Promise.resolve();
            }

            return this.load();
        },

        load() {
            // If in local mode, return early since data is loaded with parent
            if (this.localMode) {
                return Promise.resolve();
            }

            return this.repository.search(this.result.criteria, this.result.context).then((response) => {
                this.applyResult(response);
                this.$emit('load-finish');
            });
        },

        deleteItem(id) {
            if (this.localMode) {
                this.collection.remove(id);
                // records will be saved with the root record
                return Promise.resolve();
            }

            return this.repository
                .delete(id, this.result.context)
                .then(() => {
                    this.resetSelection();

                    return this.load();
                })
                .catch((errorResponse) => {
                    this.$emit('delete-item-failed', { id, errorResponse });
                });
        },

        deleteItems() {
            const selection = Object.values(this.selection);
            if (this.localMode) {
                selection.forEach((selectedProxy) => {
                    this.collection.remove(selectedProxy.id);
                });

                this.resetSelection();

                // records will be saved with the root record
                return Promise.resolve();
            }

            this.isBulkLoading = true;
            const selectedIds = selection.map((selectedProxy) => selectedProxy.id);

            return this.repository
                .syncDeleted(selectedIds, this.result.context)
                .then(() => {
                    this.resetSelection();
                    this.load();
                })
                .catch(() => {
                    return this.deleteItemsFinish();
                });
        },

        deleteItemsFinish() {
            this.resetSelection();
            this.isBulkLoading = false;
            this.showBulkDeleteModal = false;
            this.$emit('items-delete-finish');

            return this.load();
        },

        sort(column) {
            if (this.localMode) {
                this.$emit('column-sort', column);

                return Promise.resolve();
            }

            this.result.criteria.resetSorting();

            let direction = 'ASC';
            if (this.currentSortBy === column.dataIndex) {
                if (this.currentSortDirection === direction) {
                    direction = 'DESC';
                }
            }

            this.result.criteria.addSorting(Criteria.sort(column.dataIndex, direction, !!column.naturalSorting));

            this.currentSortBy = column.dataIndex;
            this.currentSortDirection = direction;
            this.currentNaturalSorting = !!column.naturalSorting;

            return this.load();
        },

        paginate(params) {
            if (this.localMode) {
                return Promise.resolve();
            }

            this.result.criteria.setPage(params.page);
            this.result.criteria.setLimit(params.limit);

            return this.load();
        },
    },
};
