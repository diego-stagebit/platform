import template from './sw-data-grid-skeleton.html.twig';
import './sw-data-grid-skeleton.scss';

/**
 * @sw-package framework
 *
 * @private
 */
export default {
    template,

    props: {
        currentColumns: {
            type: Array,
            required: true,
            default() {
                return [];
            },
        },
        itemAmount: {
            type: Number,
            required: false,
            default: 7,
        },
        showSelection: {
            type: Boolean,
            required: false,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
        },
        showActions: {
            type: Boolean,
            required: false,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
        },
        hasResizeColumns: {
            type: Boolean,
            required: true,
            default: false,
        },
    },

    methods: {
        getRandomLength() {
            const max = 100;
            const min = 50;

            return Math.floor(Math.random() * (max - min + 1)) + min;
        },
    },
};
