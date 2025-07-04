/**
 * @sw-package framework
 */

import template from './sw-field-error.html.twig';
import './sw-field-error.scss';

/**
 * @private
 */
export default {
    template,

    props: {
        error: {
            type: Object,
            required: false,
            default: null,
        },
    },

    computed: {
        errorMessage() {
            if (!this.error) {
                return '';
            }

            const translationKey = `global.error-codes.${this.error.code}`;
            const translation = this.$tc(translationKey, 1, this.formatParameters(this.error.parameters) || {});

            if (translation === translationKey) {
                return this.error.detail;
            }
            return translation;
        },
    },

    methods: {
        formatParameters(parameters) {
            if (!parameters || Object.keys(parameters).length < 1) {
                return {};
            }

            const formattedParameters = {};
            Object.keys(parameters).forEach((key) => {
                if (parameters.hasOwnProperty(key)) {
                    const formattedKey = key.replace(/{{\s*(.*?)\s*}}/, '$1');
                    formattedParameters[formattedKey] = parameters[key];
                }
            });

            return formattedParameters;
        },
    },
};
