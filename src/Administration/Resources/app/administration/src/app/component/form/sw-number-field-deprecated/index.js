import { inject } from 'vue';
import template from './sw-number-field.html.twig';
import './sw-number-field.scss';

const { warn } = Shopware.Utils.debug;

/**
 * @sw-package framework
 *
 * @private
 * @description Number field component which supports Int and Float with optional min, max and step.
 * @status ready
 * @example-type dynamic
 * @component-example
 * <sw-number-field type="number" label="Name" v-model="model" numberType="int"
 * :max="20" :min="5" :step="5"></sw-number-field>
 */
export default {
    template,
    inheritAttrs: false,

    inject: ['feature'],

    emits: [
        'update:value',
        'input-change',
        'inheritance-restore',
        'inheritance-remove',
        'ends-with-decimal-separator',
    ],

    props: {
        numberType: {
            type: String,
            required: false,
            default: 'float',
            validValues: [
                'float',
                'int',
            ],
            validator(value) {
                return [
                    'float',
                    'int',
                ].includes(value);
            },
        },

        step: {
            type: Number,
            required: false,
            default: null,
        },

        min: {
            type: Number,
            required: false,
            default: null,
        },

        max: {
            type: Number,
            required: false,
            default: null,
        },

        value: {
            type: Number,
            required: false,
            default: null,
        },

        digits: {
            type: Number,
            required: false,
            default: 2,
            validator(value) {
                const isInt = value === Math.floor(value);
                if (!isInt) {
                    warn('sw-number-field', 'Provided prop digits must be of type integer');
                }
                return isInt;
            },
        },

        fillDigits: {
            type: Boolean,
            required: false,
            default: false,
        },

        allowEmpty: {
            type: Boolean,
            required: false,
            default: false,
        },

        numberAlignEnd: {
            type: Boolean,
            required: false,
            default: false,
        },

        ariaLabel: {
            type: String,
            required: false,
            default() {
                return inject('ariaLabel', null)?.value;
            },
        },
    },

    data() {
        return {
            currentValue: this.value,
        };
    },

    computed: {
        realStep() {
            if (this.step === null) {
                return this.numberType === 'int' ? 1 : 0.01;
            }

            return this.numberType === 'int' ? Math.round(this.step) : this.step;
        },

        realMinimum() {
            if (this.min === null) {
                return null;
            }
            return this.numberType === 'int' ? Math.ceil(this.min) : this.min;
        },

        realMaximum() {
            if (this.max === null) {
                return null;
            }

            return this.numberType === 'int' ? Math.floor(this.max) : this.max;
        },

        stringRepresentation() {
            if (this.currentValue === null || Number.isNaN(this.currentValue)) {
                return '';
            }

            // remove scientific notation
            if (this.value !== null && /\d+\.?\d*e[+-]*\d+/i.test(this.value)) {
                return this.value.toLocaleString('fullwide', {
                    useGrouping: false,
                });
            }

            return this.fillDigits && this.numberType !== 'int'
                ? this.currentValue.toFixed(this.digits)
                : this.currentValue.toString();
        },
    },

    watch: {
        value: {
            handler() {
                if (this.value === null || this.value === undefined) {
                    this.currentValue = null;
                    return;
                }

                this.computeValue(this.value.toString());
            },
            immediate: true,
        },
    },

    methods: {
        onChange(event) {
            this.computeValue(event.target.value);

            this.$emit('update:value', this.currentValue);
        },

        onInput(event) {
            const targetValue = event.target.value;
            let val = this.getNumberFromString(event.target.value);

            if (!Number.isNaN(val)) {
                if (this.max && val > this.max) {
                    val = this.max;
                }
                if (this.min && val < this.min) {
                    val = this.min;
                }

                this.currentValue = val;
                this.$emit('input-change', val);
            } else if (this.allowEmpty === true) {
                this.currentValue = val;
                this.$emit('input-change', val);
            } else {
                this.currentValue = this.min ?? 0;
                this.$emit('input-change', this.min ?? 0);
            }

            // When target value ends with a dot or comma, emit this information to the parent component
            if (targetValue.endsWith('.') || targetValue.endsWith(',')) {
                this.$emit('ends-with-decimal-separator', true);
            } else {
                this.$emit('ends-with-decimal-separator', false);
            }
        },

        increaseNumberByStep() {
            this.computeValue((this.currentValue + this.realStep).toString());
            this.$emit('update:value', this.currentValue);
        },

        decreaseNumberByStep() {
            this.computeValue((this.currentValue - this.realStep).toString());
            this.$emit('update:value', this.currentValue);
        },

        computeValue(stringRepresentation) {
            const value = this.getNumberFromString(stringRepresentation);
            this.currentValue = this.parseValue(value);
        },

        parseValue(value) {
            if (value === null || Number.isNaN(value) || !Number.isFinite(value)) {
                if (this.allowEmpty) {
                    return null;
                }

                return this.parseValue(0);
            }

            return this.checkForInteger(this.checkBoundaries(value));
        },

        checkBoundaries(value) {
            if (this.realMaximum !== null && value > this.realMaximum) {
                value = this.realMaximum;
            }

            if (this.realMinimum !== null && value < this.realMinimum) {
                value = this.realMinimum;
            }

            return value;
        },

        getNumberFromString(value) {
            let splits = value.split('e').shift();
            splits = splits.replace(/,/g, '.').split('.');

            if (splits.length === 1) {
                return parseFloat(splits[0]);
            }

            if (this.numberType === 'int') {
                return parseInt(splits.join(''), 10);
            }
            const decimals = splits[splits.length - 1].length;
            const float = parseFloat(splits.join('.')).toFixed(decimals);
            return decimals > this.digits ? Math.round(float * 10 ** this.digits) / 10 ** this.digits : Number(float);
        },

        checkForInteger(value) {
            if (this.numberType !== 'int') {
                return value;
            }

            const floor = Math.floor(value);
            if (floor !== value) {
                this.$nextTick(() => {
                    this.$forceUpdate();
                });
            }
            return floor;
        },
    },
};
