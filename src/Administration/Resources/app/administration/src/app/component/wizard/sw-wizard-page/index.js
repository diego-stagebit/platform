/**
 * @sw-package framework
 */

import './sw-wizard-page.scss';
import template from './sw-wizard-page.html.twig';

/**
 * See `sw-wizard` for an example.
 *
 * @private
 */
export default {
    template,

    inject: [
        'feature',
        'swWizardPageAdd',
        'swWizardPageRemove',
    ],

    props: {
        isActive: {
            type: Boolean,
            required: false,
            // eslint-disable-next-line vue/no-boolean-default
            default() {
                return false;
            },
        },
        title: {
            type: String,
            required: false,
            default() {
                return '';
            },
        },
        position: {
            type: Number,
            required: false,
            default() {
                return 0;
            },
        },
    },

    data() {
        return {
            isCurrentlyActive: this.isActive,
            modalTitle: this.title,
        };
    },

    created() {
        this.createdComponent();
    },

    unmounted() {
        this.destroyedComponent();
    },

    methods: {
        createdComponent() {
            this.swWizardPageAdd(this);
        },

        destroyedComponent() {
            this.swWizardPageRemove(this);
        },
    },
};
