/**
 * @sw-package framework
 */

import template from './sw-verify-user-modal.html.twig';

const { Mixin } = Shopware;

/**
 * @private
 */
export default {
    template,

    inject: [
        'loginService',
    ],

    emits: [
        'verified',
        'close',
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            confirmPassword: '',
        };
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {},

        onSubmitConfirmPassword() {
            return this.loginService
                .verifyUserToken(this.confirmPassword)
                .then((verifiedToken) => {
                    const context = { ...Shopware.Context.api };
                    context.authToken.access = verifiedToken;

                    const authObject = {
                        ...this.loginService.getBearerAuthentication(),
                        access: verifiedToken,
                    };

                    this.loginService.setBearerAuthentication(authObject);

                    this.$emit('verified', context);
                })
                .catch(() => {
                    this.createNotificationError({
                        title: this.$tc(
                            'sw-users-permissions.users.user-detail.passwordConfirmation.notificationPasswordErrorTitle',
                        ),
                        message: this.$tc(
                            'sw-users-permissions.users.user-detail.passwordConfirmation.notificationPasswordErrorMessage',
                        ),
                    });
                })
                .finally(() => {
                    this.confirmPassword = '';
                    this.$emit('close');
                });
        },

        onCloseConfirmPasswordModal() {
            this.confirmPassword = '';
            this.$emit('close');
        },
    },
};
