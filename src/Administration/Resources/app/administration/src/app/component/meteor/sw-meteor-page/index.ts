import type { PropType } from 'vue';
import type { RouteLocationNamedRaw } from 'vue-router';
import type { ModuleManifest } from 'src/core/factory/module.factory';
import template from './sw-meteor-page.html.twig';
import './sw-meteor-page.scss';

type ComponentData = {
    module: ModuleManifest | null;
    parentRoute: string | null;
};

/**
 * @sw-package framework
 *
 * @private
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    props: {
        fullWidth: {
            type: Boolean,
            required: false,
            default: false,
        },

        hideIcon: {
            type: Boolean,
            required: false,
            default: false,
        },

        fromLink: {
            type: Object as PropType<RouteLocationNamedRaw | null>,
            required: false,
            default: null,
        },
    },

    data(): ComponentData {
        return {
            module: null,
            parentRoute: null,
        };
    },

    computed: {
        pageClasses(): object {
            return {
                'sw-meteor-page--full-width': this.fullWidth,
            };
        },

        hasIcon(): boolean {
            return typeof this.module?.icon === 'string';
        },

        hasIconOrIconSlot(): boolean {
            return this.hasIcon || typeof this.$slots['smart-bar-icon'] !== 'undefined';
        },

        hasTabs(): boolean {
            return typeof this.$slots['page-tabs'] !== 'undefined';
        },

        pageColor(): string {
            return this.module?.color ?? '#d8dde6';
        },
    },

    beforeUnmount(): void {
        void Shopware.Store.get('error').resetApiErrors();
    },

    mounted(): void {
        this.mountedComponent();
    },

    methods: {
        mountedComponent(): void {
            this.initPage();
        },

        emitNewTab(tabItem: string) {
            this.$emit('new-item-active', tabItem);
        },

        initPage(): void {
            if (typeof this.$route?.meta?.$module !== 'undefined') {
                this.module = this.$route.meta.$module as ModuleManifest | null;
            }

            if (typeof this.$route?.meta?.parentPath === 'string') {
                this.parentRoute = this.$route.meta.parentPath;
            }
        },
    },
});
