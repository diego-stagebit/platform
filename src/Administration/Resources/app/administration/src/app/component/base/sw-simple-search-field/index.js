import template from './sw-simple-search-field.html.twig';
import './sw-simple-search-field.scss';

const { Utils } = Shopware;

/**
 * @sw-package framework
 *
 * @private
 * @description a search field with delayed update
 * @status ready
 * @example-type static
 * @component-example
 * <sw-simple-search-field
 *   v-model="value"
 *   :delay="1000"
 *   @input="onInput"
 *   @search-term-change="debouncedInputEvent"
 *  />
 */
export default {
    template,
    inheritAttrs: false,

    emits: [
        'update:value',
        'search-term-change',
    ],

    props: {
        variant: {
            type: String,
            required: false,
            default: 'default',
            validValues: [
                'default',
                'inverted',
                'form',
            ],
            validator(value) {
                if (!value.length) {
                    return true;
                }
                return [
                    'default',
                    'inverted',
                    'form',
                ].includes(value);
            },
        },

        value: {
            type: String,
            default: null,
            required: false,
        },

        size: {
            type: String,
            required: false,
            default: 'default',
        },

        delay: {
            type: Number,
            required: false,
            default: 400,
        },

        icon: {
            type: String,
            required: false,
            default: 'regular-search-s',
        },
    },

    data() {
        return {
            onSearchTermChanged: Utils.debounce(function debounceInput(input) {
                this.$emit('search-term-change', input);
            }, this.delay),
        };
    },

    computed: {
        fieldClasses() {
            return [
                `sw-simple-search-field--${this.variant}`,
            ];
        },

        placeholder() {
            return this.$attrs.placeholder || this.$tc('global.sw-simple-search-field.defaultPlaceholder');
        },
    },

    methods: {
        onInput(input) {
            this.$emit('update:value', input);
            this.onSearchTermChanged(input);
        },
    },
};
