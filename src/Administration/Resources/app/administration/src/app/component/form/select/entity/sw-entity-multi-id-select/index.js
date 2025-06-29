/**
 * @sw-package framework
 */

import template from './sw-entity-multi-id-select.html.twig';

const { Context, Mixin } = Shopware;
const { EntityCollection, Criteria } = Shopware.Data;

/**
 * @private
 */
export default {
    template,

    inheritAttrs: false,

    inject: ['feature'],

    emits: ['update:value'],

    mixins: [
        Mixin.getByName('remove-api-error'),
    ],

    props: {
        value: {
            type: Array,
            required: false,
            default() {
                return [];
            },
        },

        repository: {
            type: Object,
            required: true,
        },

        criteria: {
            type: Object,
            required: false,
            default() {
                return new Criteria(1, 25);
            },
        },

        context: {
            type: Object,
            required: false,
            default() {
                return Context.api;
            },
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            collection: null,
        };
    },

    watch: {
        value() {
            if (this.collection === null) {
                this.createdComponent();
                return;
            }

            if (this.collection.getIds() === this.value) {
                return;
            }

            this.createdComponent();
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            const collection = new EntityCollection(this.repository.route, this.repository.entityName, this.context);

            if (this.collection === null) {
                this.collection = collection;
            }

            if (this.value.length <= 0) {
                this.collection = collection;
                return Promise.resolve(this.collection);
            }

            const criteria = Criteria.fromCriteria(this.criteria);
            criteria.setIds(this.value);
            criteria.setTerm('');
            criteria.queries = [];

            return this.repository.search(criteria, { ...this.context, inheritance: true }).then((entities) => {
                this.collection = entities;

                if (!this.collection.length && this.value.length) {
                    this.updateIds(this.collection);
                }

                return this.collection;
            });
        },

        updateIds(collection) {
            this.collection = collection;

            this.$emit('update:value', collection.getIds());
        },
    },
};
