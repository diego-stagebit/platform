/**
 * @sw-package framework
 */

import template from './sw-login.html.twig';
import './sw-login.scss';

const { Component } = Shopware;

/**
 * @private
 * @sw-package framework
 */
export default Component.wrapComponentConfig({
    template,

    props: {
        hash: {
            type: String,
            default: null,
        },
    },

    data() {
        return {
            shouldRenderDOM: false,
            isLoading: false,
            isLoginSuccess: false,
            isLoginError: false,
        };
    },

    metaInfo() {
        return {
            title: this.title,
        };
    },

    computed: {
        title() {
            const modulName = this.$tc('sw-login.general.mainMenuItemIndex');
            const adminName = this.$tc('global.sw-admin-menu.textShopwareAdmin');

            return `${modulName} | ${adminName}`;
        },
    },

    beforeMount() {
        const refreshAfterLogout = sessionStorage.getItem('refresh-after-logout');

        if (refreshAfterLogout) {
            sessionStorage.removeItem('refresh-after-logout');
            window.location.reload();
        } else {
            this.shouldRenderDOM = true;
        }
    },

    methods: {
        setLoading(val: boolean) {
            this.isLoading = val;
        },

        loginError() {
            this.isLoginError = !this.isLoginError;
        },

        loginSuccess() {
            this.isLoginSuccess = !this.isLoginSuccess;
        },
    },
});
