import template from './sw-form-field-renderer.html.twig';

const { Mixin } = Shopware;
const { types } = Shopware.Utils;
/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @description
 * Dynamically renders components with a given configuration. The rendered component can be forced by defining
 * the config.componentName property. If not set the form-field-renderer will guess a suitable
 * component for the type. Everything inside the config prop will be passed to the rendered child prop as properties.
 * Also all additional props will be passed to the child.
 * @example-type code-only
 * @component-example
 * {# Datepicker #}
 * <sw-form-field-renderer
 *     v-model="yourValue"
 *     type="datetime">
 * </sw-form-field-renderer>
 *
 * {# Text field #}
 * <sw-form-field-renderer
 *     v-model="yourValue"
 *     type="string">
 * </sw-form-field-renderer>
 *
 * {# sw-number-field #}
 * <sw-form-field-renderer
 *     v-model="yourValue"
 *     :config="{
 *         componentName: 'sw-field',
 *         type: 'number',
 *         numberType: 'float'
 *     }">
 * </sw-form-field-renderer>
 *
 * {# sw-select - multi #}
 * <sw-form-field-renderer
 *     v-model="yourValue"
 *     :config="{
 *         componentName: 'sw-multi-select',
 *         label: {
 *             'en-GB': 'Multi Select'
 *         },
 *         multi: true,
 *         options: [
 *             { value: 'option1', label: { 'en-GB': 'One' } },
 *             { value: 'option2', label: 'Two' },
 *             { value: 'option3', label: { 'en-GB': 'Three', 'de-DE': 'Drei' } }
 *         ]
 *     }">
 * </sw-form-field-renderer>
 *
 * {# sw-select - single #}
 * <sw-form-field-renderer
 *     v-model="yourValue"
 *     :componentName: 'sw-single-select',
 *     :config="{
 *         label: 'Single Select',
 *         options: [
 *             { value: 'option1', label: { 'en-GB': 'One' } },
 *             { value: 'option2', label: 'Two' },
 *             { value: 'option3', label: { 'en-GB': 'Three', 'de-DE': 'Drei' } }
 *         ]
 *     }">
 * </sw-form-field-renderer>
 */
export default {
    template,

    inheritAttrs: false,

    inject: [
        'repositoryFactory',
        'feature',
    ],

    emits: ['update:value'],

    mixins: [
        Mixin.getByName('sw-inline-snippet'),
    ],

    props: {
        type: {
            type: String,
            required: false,
            default: null,
        },
        config: {
            type: Object,
            required: false,
            default: null,
        },
        // eslint-disable-next-line vue/require-prop-types
        value: {
            required: true,
        },
        error: {
            type: Object,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            currency: { id: Shopware.Context.app.systemCurrencyId, factor: 1 },
            currentComponentName: '',
            swFieldConfig: {},
            currentValue:
                this.type === 'price' && !this.value && !Array.isArray(this.value)
                    ? [
                          {
                              currencyId: Shopware.Context.app.systemCurrencyId,
                              gross: null,
                              net: null,
                              linked: true,
                          },
                      ]
                    : this.value,
        };
    },

    computed: {
        bind() {
            let bind = {};

            // Filter all listeners from the $attrs object
            Object.keys(this.$attrs).forEach((key) => {
                if (!['onUpdate:value'].includes(key)) {
                    bind[key] = this.$attrs[key];
                }
            });

            bind = {
                ...bind,
                ...this.config,
                ...this.swFieldType,
                ...this.translations,
                ...this.optionTranslations,
            };

            if (this.componentName === 'sw-entity-multi-id-select') {
                bind.repository = this.createRepository(this.config.entity);
            }

            return bind;
        },

        hasConfig() {
            return !!this.config;
        },

        componentName() {
            if (this.hasConfig) {
                // Handle old "sw-field" component with custom type
                if (this.config.componentName === 'sw-field') {
                    return this.getComponentFromType(this.config.type);
                }

                return this.config.componentName || this.getComponentFromType();
            }
            return this.getComponentFromType();
        },

        swFieldType() {
            if (this.type === 'price') {
                return {
                    type: 'price',
                    allowModal: true,
                    hideListPrices: true,
                    currency: this.currency,
                };
            }

            if (this.hasConfig && this.config.hasOwnProperty('type')) {
                return {};
            }

            if (this.type === 'int') {
                return { type: 'number', numberType: 'int' };
            }

            if (this.type === 'float') {
                return { type: 'number', numberType: 'float' };
            }

            if (this.type === 'string' || this.type === 'text') {
                return { type: 'text' };
            }

            if (this.type === 'bool') {
                return { type: 'switch', bordered: true };
            }

            if (this.type === 'datetime') {
                return { type: 'date', dateType: 'datetime' };
            }

            if (this.type === 'date') {
                return { type: 'date', dateType: 'date' };
            }

            if (this.type === 'time') {
                return { type: 'date', dateType: 'time' };
            }

            return { type: this.type };
        },

        translations() {
            return this.getTranslations(this.componentName);
        },

        optionTranslations() {
            if (
                [
                    'sw-single-select',
                    'sw-multi-select',
                ].includes(this.componentName)
            ) {
                if (!this.config.hasOwnProperty('options')) {
                    return {};
                }

                const options = [];
                let labelProperty = 'label';

                // Use custom label property if defined
                if (this.config.hasOwnProperty('labelProperty')) {
                    labelProperty = this.config.labelProperty;
                }

                this.config.options.forEach((option) => {
                    const translation = this.getTranslations('options', option, [labelProperty]);
                    if (!translation.label) {
                        translation.label = option.value;
                    }
                    // Merge original option with translation
                    const translatedOption = { ...option, ...translation };
                    options.push(translatedOption);
                });

                return { options };
            }

            return {};
        },

        componentPropName() {
            switch (this.componentName) {
                case 'mt-textarea':
                case 'mt-switch':
                case 'mt-number-field':
                    return 'modelValue';
                default:
                    return 'value';
            }
        },
    },

    watch: {
        currentValue: {
            handler(value) {
                if (
                    Array.isArray(value) &&
                    Array.isArray(this.value) &&
                    value.length === this.value.length &&
                    value.every((val, index) => val === this.value[index])
                ) {
                    return;
                }

                if (value !== this.value) {
                    this.$emit('update:value', value);
                }
            },
            deep: true,
        },
        value() {
            this.currentValue = this.value;
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.fetchSystemCurrency();
        },

        emitUpdate(data) {
            this.$emit('update:value', data);
        },

        getTranslations(
            componentName,
            config = this.config,
            translatableFields = [
                'label',
                'placeholder',
                'helpText',
            ],
        ) {
            if (!translatableFields) {
                return {};
            }

            const translations = {};
            translatableFields.forEach((field) => {
                if (config[field] && config[field] !== '') {
                    translations[field] = this.getInlineSnippet(config[field]);
                }
            });

            return translations;
        },

        getComponentFromType(customType = undefined) {
            const type = customType ?? this.type;

            const components = {
                bool: 'sw-switch-field-deprecated',
                switch: 'sw-switch-field-deprecated',
                textarea: 'sw-textarea-field-deprecated',
                checkbox: 'sw-checkbox-field-deprecated',
                colorpicker: 'sw-colorpicker-deprecated',
                compactColorpicker: 'sw-compact-colorpicker',
                date: 'sw-datepicker-deprecated',
                datetime: 'sw-datepicker-deprecated',
                time: 'sw-datepicker-deprecated',
                email: 'sw-email-field-deprecated',
                float: 'sw-number-field-deprecated',
                int: 'sw-number-field-deprecated',
                number: 'sw-number-field-deprecated',
                'multi-entity-id-select': 'sw-entity-multi-id-select',
                'multi-select': 'sw-multi-select',
                password: 'sw-password-field-deprecated',
                price: 'sw-price-field',
                radio: 'sw-radio-field',
                'single-entity-id-select': 'sw-entity-single-select',
                'single-select': 'sw-single-select',
                string: 'sw-text-field-deprecated',
                text: 'sw-text-field-deprecated',
                tagged: 'sw-tagged-field',
                url: 'sw-url-field-deprecated',
            };

            return components[type] ?? 'sw-text-field-deprecated';
        },

        createRepository(entity) {
            if (types.isUndefined(entity)) {
                throw new Error('sw-form-field-renderer - sw-entity-multi-id-select component needs entity property');
            }

            return this.repositoryFactory.create(entity);
        },

        fetchSystemCurrency() {
            const systemCurrencyId = Shopware.Context.app.systemCurrencyId;

            this.createRepository('currency')
                .get(systemCurrencyId)
                .then((response) => {
                    this.currency = response;
                });
        },

        getScopedSlots() {
            return this.$slots;
        },
    },
};
