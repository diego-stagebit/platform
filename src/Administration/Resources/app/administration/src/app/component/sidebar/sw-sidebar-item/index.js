import template from './sw-sidebar-item.html.twig';
import './sw-sidebar-item.scss';

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @example-type code-only
 * @component-example
 * <sw-sidebar-item
 *         title="Product"
 *         icon="regular-products"
 *         hasSimpleBadge
 *         badgeType='error'>
 *     Product in sidebar
 * </sw-sidebar-item>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inject: {
        registerSidebarItem: {
            from: 'registerSidebarItem',
            default: null,
        },
    },

    emits: [
        'toggle-active',
        'close-content',
        'click',
    ],

    props: {
        title: {
            type: String,
            required: true,
        },

        icon: {
            type: String,
            required: true,
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },

        position: {
            type: String,
            required: false,
            default: 'top',
            validator(value) {
                return [
                    'top',
                    'bottom',
                ].includes(value);
            },
        },

        badge: {
            type: Number,
            required: false,
            default: 0,
        },

        hasSimpleBadge: {
            type: Boolean,
            required: false,
            default: false,
        },

        badgeType: {
            type: String,
            required: false,
            default: 'info',
            validator(value) {
                return [
                    'info',
                    'warning',
                    'error',
                    'success',
                ].includes(value);
            },
        },
    },

    data() {
        return {
            isActive: false,
            toggleActiveListener: [],
            closeContentListener: [],
        };
    },

    computed: {
        sidebarItemClasses() {
            return {
                'is--active': this.showContent,
                'is--disabled': this.disabled,
            };
        },

        hasDefaultSlot() {
            return !!this.$slots.default;
        },

        showContent() {
            return this.hasDefaultSlot && this.isActive;
        },
    },

    watch: {
        disabled(newDisabledState) {
            if (newDisabledState) {
                this.closeContent();
            }
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            if (this.registerSidebarItem) {
                this.registerSidebarItem(this);
            }
        },

        registerToggleActiveListener(listener) {
            this.toggleActiveListener.push(listener);
        },

        registerCloseContentListener(listener) {
            this.closeContentListener.push(listener);
        },

        openContent() {
            if (this.showContent) {
                return;
            }

            this.$emit('toggle-active', this);
            this.toggleActiveListener.forEach((listener) => {
                listener(this);
            });
        },

        closeContent() {
            if (this.isActive) {
                this.isActive = false;

                this.$emit('close-content');
                this.closeContentListener.forEach((listener) => {
                    listener(this);
                });
            }
        },

        sidebarButtonClick(sidebarItem) {
            if (this === sidebarItem) {
                this.isActive = !this.isActive;
                this.$emit('click');
                return;
            }

            if (sidebarItem.hasDefaultSlot) {
                this.isActive = false;
            }
        },
    },
};
