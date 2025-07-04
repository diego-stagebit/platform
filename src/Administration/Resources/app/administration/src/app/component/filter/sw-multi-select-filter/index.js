/**
 * @sw-package framework
 */

import template from './sw-multi-select-filter.html.twig';

const { Criteria, EntityCollection } = Shopware.Data;

/**
 * @private
 */
export default {
    template,

    inject: ['repositoryFactory'],

    emits: [
        'filter-update',
        'filter-reset',
    ],

    props: {
        filter: {
            type: Object,
            required: true,
        },
        active: {
            type: Boolean,
            required: true,
        },
    },

    computed: {
        isEntityMultiSelect() {
            return !this.filter.options;
        },

        labelProperty() {
            return this.filter.labelProperty || 'name';
        },

        values() {
            if (!this.isEntityMultiSelect) {
                return this.filter.value || [];
            }

            const entities = new EntityCollection('', this.filter.schema.entity, Shopware.Context.api);

            if (Array.isArray(this.filter.value)) {
                this.filter.value.forEach((value) => {
                    const entityValue = {
                        id: value.id,
                        [this.labelProperty]: value[this.labelProperty],
                    };

                    if (this.filter.displayVariants) {
                        entityValue.variation = value.variation;
                    }

                    entities.push(entityValue);
                });
            }

            return entities;
        },
    },

    methods: {
        changeValue(newValues) {
            if (newValues.length <= 0) {
                this.resetFilter();
                return;
            }

            let filterCriteria = [];
            if (this.filter.existingType) {
                const multiFilter = [];
                newValues.forEach((value) => {
                    multiFilter.push(
                        Criteria.not('and', [
                            Criteria.equals(`${value}.id`, null),
                        ]),
                    );
                });
                filterCriteria.push(Criteria.multi('or', multiFilter));
            } else {
                filterCriteria = [
                    this.filter.schema
                        ? Criteria.equalsAny(
                              `${this.filter.property}.${this.filter.schema.referenceField}`,
                              newValues.map((newValue) => newValue[this.filter.schema.referenceField]),
                          )
                        : Criteria.equalsAny(this.filter.property, newValues),
                ];
            }

            const values = !this.isEntityMultiSelect
                ? newValues
                : newValues.map((value) => {
                      if (!this.filter.displayVariants) {
                          return {
                              id: value.id,
                              [this.labelProperty]: value?.translated?.[this.labelProperty] || value?.[this.labelProperty],
                          };
                      }

                      return {
                          id: value.id,
                          variation: value.variation,
                          [this.labelProperty]: value?.translated?.[this.labelProperty] || value?.[this.labelProperty],
                      };
                  });

            this.$emit('filter-update', this.filter.name, filterCriteria, values);
        },

        resetFilter() {
            this.$emit('filter-reset', this.filter.name);
        },
    },
};
