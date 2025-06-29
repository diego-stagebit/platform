/**
 * @sw-package framework
 */

import template from './sw-extension-sdk-module.html.twig';
import './sw-extension-sdk-module.scss';

/**
 * @private Only to be used by the Admin extension API
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    props: {
        id: {
            type: String,
            required: true,
        },
        back: {
            type: String,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            timedOut: false,
            loadingTimeOut: null,
        };
    },

    computed: {
        module() {
            return Shopware.Store.get('extensionSdkModules').modules.find((module) => module.id === this.id);
        },

        isLoading() {
            return !this.module;
        },

        showSearchBar() {
            return this.module?.displaySearchBar ?? true;
        },

        showSmartBar() {
            return this.module?.displaySmartBar ?? true;
        },

        showLanguageSwitch() {
            return !!this.module?.displayLanguageSwitch;
        },

        smartBarButtons() {
            return Shopware.Store.get('extensionSdkModules').smartBarButtons.filter(
                (button) => button.locationId === this.module?.locationId,
            );
        },
    },

    watch: {
        /**
         * When user changes from one app module to another the iframe should reload
         */
        'module.locationId'() {
            if (!this.$refs.iframeRenderer) {
                return;
            }

            // Trick to reload iframes with same src but different routes
            this.$refs.iframeRenderer.$refs.iframe.src = `${this.$refs.iframeRenderer.$refs.iframe.src}`;
        },
    },

    /**
     * This component should not be extendable therefore no createdComponent() hook.
     */
    created() {
        // Keep threshold synced with admin extension sdk
        this.loadingTimeOut = window.setTimeout(() => {
            if (!this.isLoading) {
                return;
            }

            this.timedOut = true;
            this.loadingTimeOut = null;
        }, 7000);
    },

    beforeUnmount() {
        if (this.loadingTimeOut) {
            window.clearTimeout(this.loadingTimeOut);
        }
    },

    methods: {
        onChangeLanguage(languageId) {
            Shopware.Store.get('context').setApiLanguageId(languageId);
        },
    },
});
