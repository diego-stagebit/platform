/**
 * @sw-package framework
 */

import template from './sw-notifications.html.twig';
import './sw-notifications.scss';

/**
 * @private
 * @description
 * Wrapper element for all notifications of the administration.
 * @status ready
 * @example-type code-only
 */
export default {
    template,

    inject: ['feature'],

    props: {
        position: {
            type: String,
            required: false,
            default: 'topRight',
            validator(value) {
                if (!value.length) {
                    return true;
                }
                return [
                    'topRight',
                    'bottomRight',
                ].includes(value);
            },
        },
        notificationsGap: {
            type: String,
            default: '20px',
        },
        notificationsTopGap: {
            type: String,
            default: '165px',
        },
    },

    computed: {
        notifications() {
            return Object.values(Shopware.Store.get('notification').growlNotifications);
        },

        notificationsStyle() {
            let notificationsGap = this.notificationsGap;

            if (`${parseInt(notificationsGap, 10)}` === notificationsGap) {
                notificationsGap = `${notificationsGap}px`;
            }

            if (this.position === 'bottomRight') {
                return {
                    top: 'auto',
                    right: notificationsGap,
                    bottom: notificationsGap,
                    left: 'auto',
                };
            }

            return {
                top: this.notificationsTopGap,
                right: notificationsGap,
                bottom: 'auto',
                left: 'auto',
            };
        },
    },

    methods: {
        onClose(notification) {
            Shopware.Store.get('notification').removeGrowlNotification(notification);
        },

        handleAction(action, notification) {
            // Allow external links for example to the shopware account or store
            if (Shopware.Utils.string.isUrl(action.route)) {
                window.open(action.route);
                return;
            }

            if (action.route) {
                this.$router.push(action.route);
            }

            if (action.method && typeof action.method === 'function') {
                action.method.call();
            }

            this.onClose(notification);
        },

        getNotificationVariant(notification) {
            // If notification has a correct new variant, return it
            if (
                [
                    'info',
                    'critical',
                    'positive',
                    'attention',
                    'neutral',
                ].includes(notification.variant)
            ) {
                return notification.variant;
            }

            if (notification.variant === 'info') {
                return 'info';
            }

            if (notification.variant === 'error') {
                return 'critical';
            }

            if (notification.variant === 'success') {
                return 'positive';
            }

            if (notification.variant === 'warning') {
                return 'attention';
            }

            return 'neutral';
        },
    },
};
