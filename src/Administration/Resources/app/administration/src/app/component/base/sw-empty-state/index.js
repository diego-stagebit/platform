import template from './sw-empty-state.html.twig';
import './sw-empty-state.scss';

/**
 * @sw-package framework
 *
 * @private
 */
export default {
    template,

    props: {
        title: {
            type: String,
            default: null,
            required: true,
        },
        subline: {
            type: String,
            default: null,
            required: false,
        },
        showDescription: {
            type: Boolean,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
            required: false,
        },
        color: {
            type: String,
            default: null,
            required: false,
        },
        icon: {
            type: String,
            default: null,
            required: false,
        },
        absolute: {
            type: Boolean,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
            required: false,
        },
        emptyModule: {
            type: Boolean,
            default: false,
            required: false,
        },
        autoHeight: {
            type: Boolean,
            default: false,
            required: false,
        },
    },

    computed: {
        moduleColor() {
            return this.color ?? this.$route.meta.$module.color;
        },

        moduleDescription() {
            return this.subline ?? this.$tc(this.$route.meta.$module.description);
        },

        moduleIcon() {
            return this.icon ?? this.$route.meta.$module.icon;
        },

        hasActionSlot() {
            return !!this.$slots.actions;
        },

        classes() {
            return {
                'sw-empty-state--absolute': this.absolute,
                'sw-empty-state--empty-module': this.emptyModule,
                'sw-empty-state--auto-height': this.autoHeight,
            };
        },
    },
};
