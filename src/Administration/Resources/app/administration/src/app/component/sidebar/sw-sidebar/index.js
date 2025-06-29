import template from './sw-sidebar.html.twig';
import './sw-sidebar.scss';

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @example-type static
 * @component-example
 * <sw-sidebar #sidebar>
 *     <sw-sidebar-item title="Refresh" icon="regular-undo"></sw-sidebar-item>
 * </sw-sidebar>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    provide() {
        return {
            registerSidebarItem: this.registerSidebarItem,
        };
    },

    inject: [
        'setSwPageSidebarOffset',
        'removeSwPageSidebarOffset',
    ],

    emits: ['item-click'],

    props: {
        propagateWidth: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    data() {
        return {
            items: [],
            isOpened: false,
            // eslint-disable-next-line vue/no-reserved-keys
            _parent: this.$parent,
        };
    },

    computed: {
        sections() {
            const sections = {};
            this.items.forEach((item) => {
                if (!sections[item.position]) {
                    sections[item.position] = [];
                }
                sections[item.position].push(item);
            });

            return sections;
        },

        sidebarClasses() {
            return {
                'is--opened': this.isOpened,
            };
        },
    },

    created() {
        this.createdComponent();
    },

    mounted() {
        this.mountedComponent();
    },

    unmounted() {
        this.destroyedComponent();
    },

    methods: {
        createdComponent() {
            let parent = this.$parent;

            while (parent) {
                if (parent.$options.name === 'sw-page') {
                    this._parent = parent;
                    return;
                }

                parent = parent.$parent;
            }
        },

        mountedComponent() {
            if (this.propagateWidth) {
                const sidebarWidth = this.$el.querySelector('.sw-sidebar__navigation').offsetWidth;

                this.setSwPageSidebarOffset(sidebarWidth);
            }
        },

        destroyedComponent() {
            if (!this.propagateWidth) {
                return;
            }

            this.removeSwPageSidebarOffset();
        },

        _isItemRegistered(itemToCheck) {
            const index = this.items.findIndex((item) => {
                return item === itemToCheck;
            });
            return index > -1;
        },

        _isAnyItemActive() {
            const index = this.items.findIndex((item) => {
                return item.isActive;
            });
            return index > -1;
        },

        closeSidebar() {
            this.isOpened = false;
        },

        registerSidebarItem(item) {
            if (this._isItemRegistered(item)) {
                return;
            }

            this.items.push(item);

            item.registerToggleActiveListener(this.setItemActive);
            item.registerCloseContentListener(this.closeSidebar);
        },

        setItemActive(clickedItem) {
            this.$emit('item-click', clickedItem);

            this.items.forEach((item) => {
                if (item.sidebarButtonClick) {
                    item.sidebarButtonClick(clickedItem);
                }
            });

            if (clickedItem.hasDefaultSlot) {
                this.isOpened = this._isAnyItemActive();
            }
        },
    },
};
