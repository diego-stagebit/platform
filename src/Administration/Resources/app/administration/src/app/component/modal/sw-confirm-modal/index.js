import template from './sw-confirm-modal.html.twig';

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @example-type static
 * @component-example
 * <sw-confirm-modal
 *     v-if="codeDeleteModal === item.id"
 *     class="sw-my-component__confirm-delete-modal"
 *     type="delete"
 *     :text="Are you sure you want to delete this?"
 *     \@confirm="onConfirmCodeDelete(item.id)"
 *     \@close="onCloseDeleteModal"
 *     \@cancel="onCloseDeleteModal">
 * </sw-confirm-modal>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    emits: [
        'close',
        'cancel',
        'confirm',
    ],

    props: {
        title: {
            type: String,
            required: false,
            default: '',
        },

        text: {
            type: String,
            required: false,
            default: '',
        },

        variant: {
            type: String,
            required: false,
            default: 'small',
            validValues: [
                'default',
                'small',
                'large',
                'full',
            ],
            validator(value) {
                if (!value.length) {
                    return true;
                }
                return [
                    'default',
                    'small',
                    'large',
                    'full',
                ].includes(value);
            },
        },

        type: {
            type: String,
            required: false,
            default: 'confirm',
            validValues: [
                'confirm',
                'delete',
                'yesno',
                'discard',
            ],
            validator(value) {
                if (!value.length) {
                    return true;
                }
                return [
                    'confirm',
                    'delete',
                    'yesno',
                    'discard',
                ].includes(value);
            },
        },
    },

    computed: {
        titleText() {
            if (this.title !== null && this.title.length > 0) {
                return this.title;
            }

            return this.$tc('global.default.warning');
        },

        descriptionText() {
            if (this.text !== null && this.text.length > 0) {
                return this.text;
            }

            return this.$tc('sw-confirm-modal.defaultText');
        },

        confirmText() {
            switch (this.type) {
                case 'delete':
                    return this.$tc('global.default.delete');
                case 'yesno':
                    return this.$tc('global.default.yes');
                case 'discard':
                    return this.$tc('global.default.discard');
                default:
                    return this.$tc('global.default.confirm');
            }
        },

        cancelText() {
            if (this.type === 'yesno') {
                return this.$tc('global.default.no');
            }

            return this.$tc('global.default.cancel');
        },

        confirmButtonVariant() {
            switch (this.type) {
                case 'delete':
                case 'discard':
                    return 'critical';
                default:
                    return 'primary';
            }
        },
    },
};
