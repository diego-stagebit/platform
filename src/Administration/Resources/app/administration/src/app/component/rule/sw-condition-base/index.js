import template from './sw-condition-base.html.twig';
import './sw-condition-base.scss';

const { Component } = Shopware;
const { mapPropertyErrors } = Component.getComponentHelper();

/**
 * @private
 * @sw-package fundamentals@after-sales
 * @description Base condition for the condition-tree. This component must be a child of sw-condition-tree.
 * @status prototype
 * @example-type code-only
 * @component-example
 * <sw-condition-base :condition="condition"></sw-condition-base>
 */
export default {
    template,

    inheritAttrs: false,

    inject: [
        'conditionDataProviderService',
        'availableTypes',
        'childAssociationField',
        'availableGroups',
    ],

    emits: [
        'create-before',
        'create-after',
        'condition-delete',
    ],

    props: {
        condition: {
            type: Object,
            required: false,
            default: null,
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    computed: {
        conditionClasses() {
            return {
                'has--error': this.hasError,
                'is--disabled': this.hasNoComponent || this.disabled,
            };
        },

        ...mapPropertyErrors('condition', ['type']),

        currentError() {
            return this.conditionTypeError;
        },

        hasError() {
            return this.currentError !== null;
        },

        valueErrorPath() {
            return `${this.condition.getEntityName()}.${this.condition.id}.value`;
        },

        value() {
            return this.condition.value;
        },

        hasNoComponent() {
            const component = this.conditionDataProviderService.getComponentByCondition(this.condition);

            return component === 'sw-condition-not-found';
        },

        operator() {
            return this.condition.value?.operator ?? null;
        },

        isEmpty() {
            return this.operator === this.conditionDataProviderService.getOperatorSet('empty')[0].identifier;
        },
    },

    watch: {
        value() {
            if (this.hasError) {
                Shopware.Store.get('error').removeApiError(this.valueErrorPath);
            }
            if (this.isEmpty && !!this.inputKey) {
                // eslint-disable-next-line vue/no-mutating-props
                delete this.condition.value[this.inputKey];
            }
        },
    },

    methods: {
        onCreateBefore() {
            this.$emit('create-before');
        },

        onCreateAfter() {
            this.$emit('create-after');
        },

        onDeleteCondition() {
            this.$emit('condition-delete');
        },

        ensureValueExist() {
            if (typeof this.condition.value === 'undefined' || this.condition.value === null) {
                // eslint-disable-next-line vue/no-mutating-props
                this.condition.value = {};
            }
        },
    },
};
