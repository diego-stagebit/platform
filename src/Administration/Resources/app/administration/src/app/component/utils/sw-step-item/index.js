/**
 * @sw-package framework
 */

import template from './sw-step-item.html.twig';
import './sw-step-item.scss';

/**
 * @private
 * @description Renders a step and must be used in the slot of the sw-step-display component.
 * @status ready
 * @example-type dynamic
 * @component-example
 * <sw-step-item disabledIcon="regular-checkmark-xs">
 *     Finish
 * </sw-step-item>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inject: [
        'feature',
        'addStep',
    ],

    props: {
        disabledIcon: {
            type: String,
            default: 'regular-circle-xs',
            required: false,
        },
    },

    data() {
        return {
            variant: 'disabled',
            active: false,
        };
    },

    computed: {
        modifierClasses() {
            return [
                `sw-step-item--${this.variant}`,
                {
                    'sw-step-item--active': this.active,
                },
            ];
        },

        icon() {
            const iconConfig = {
                disabled: this.disabledIcon,
                info: 'regular-circle-xs',
                error: 'regular-times-s',
                success: 'regular-checkmark-xs',
            };

            return iconConfig[this.variant];
        },

        stepDisplay() {
            return this.$parent.$parent;
        },
    },

    mounted() {
        this.registerStep();
    },

    methods: {
        registerStep() {
            this.addStep(this);
        },

        setActive(active) {
            this.active = active;
        },

        setVariant(variant) {
            if (
                ![
                    'disabled',
                    'info',
                    'error',
                    'success',
                ].includes(variant)
            ) {
                return;
            }

            this.variant = variant;
        },
    },
};
