/**
 * @sw-package framework
 */
import template from './sw-contextual-field.html.twig';
import './sw-contextual-field.scss';

/**
 * @private
 */
export default {
    template,
    inheritAttrs: false,

    computed: {
        hasPrefix() {
            return (
                this.$slots.hasOwnProperty('sw-contextual-field-prefix') &&
                this.$slots['sw-contextual-field-prefix']({}) !== undefined
            );
        },

        hasSuffix() {
            return (
                this.$slots.hasOwnProperty('sw-contextual-field-suffix') &&
                this.$slots['sw-contextual-field-suffix']({}) !== undefined
            );
        },
    },
};
