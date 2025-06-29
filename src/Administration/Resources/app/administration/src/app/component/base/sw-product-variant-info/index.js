import template from './sw-product-variant-info.html.twig';
import './sw-product-variant-info.scss';

/**
 * @sw-package framework
 *
 * @private
 * @description Component which renders the variations of variant products.
 * @status ready
 * @example-type code-only
 * @component-example
 * <sw-product-variant-info :variations="variations"></sw-product-variant-info>
 */
export default {
    template,

    inject: ['feature'],

    props: {
        variations: {
            type: Array,
            required: false,
            default: null,
        },

        highlighted: {
            type: Boolean,
            required: false,
            default: false,
        },

        searchTerm: {
            type: String,
            required: false,
            default: '',
        },

        titleTerm: {
            type: String,
            required: false,
            default: null,
        },

        showTooltip: {
            type: Boolean,
            required: false,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
        },

        ommitParenthesis: {
            type: Boolean,
            required: false,
            default: false,
        },

        seperator: {
            type: String,
            required: false,
            default: ' | ',
        },
    },

    data() {
        return {
            helpText: '',
            tooltipWidth: 200,
        };
    },

    computed: {
        productName() {
            return this.$slots?.default?.()?.[0]?.children || '';
        },
    },

    watch: {
        titleTerm() {
            this.setHelpText();
        },
    },

    mounted() {
        this.mountedComponent();
    },

    methods: {
        mountedComponent() {
            this.setHelpText();
        },

        getFirstSlot() {
            const label = this.$slots?.default?.()?.[0]?.children;

            return typeof label === 'string' ? label : label?.default?.()?.[0]?.children || '';
        },

        setHelpText() {
            this.helpText = this.titleTerm || this.getFirstSlot();

            if (this.helpText && this.variations && this.variations.length > 0) {
                this.tooltipWidth = 500;
                this.helpText += ' (';
                this.variations.forEach((variant) => {
                    this.helpText += `${variant.group} : ${variant.option}`;

                    if (variant !== this.variations[this.variations.length - 1]) {
                        this.helpText += ' | ';
                    }
                });
                this.helpText += ') ';
            }
        },
    },
};
