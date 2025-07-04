/**
 * @sw-package framework
 */
import template from './sw-inheritance-switch.html.twig';
import './sw-inheritance-switch.scss';

/**
 * @private
 */
export default {
    template,

    inject: {
        restoreInheritanceHandler: {
            from: 'restoreInheritanceHandler',
            default: null,
        },
        removeInheritanceHandler: {
            from: 'removeInheritanceHandler',
            default: null,
        },
    },

    emits: [
        'inheritance-restore',
        'inheritance-remove',
    ],

    props: {
        isInherited: {
            type: Boolean,
            required: true,
            default: false,
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    computed: {
        unInheritClasses() {
            return { 'is--clickable': !this.disabled };
        },
    },

    methods: {
        onClickRestoreInheritance() {
            if (this.disabled) {
                return;
            }
            this.$emit('inheritance-restore');

            if (this.restoreInheritanceHandler) {
                this.restoreInheritanceHandler();
            }
        },

        onClickRemoveInheritance() {
            if (this.disabled) {
                return;
            }
            this.$emit('inheritance-remove');

            if (this.removeInheritanceHandler) {
                this.removeInheritanceHandler();
            }
        },
    },
};
