import template from './sw-data-grid-settings.html.twig';
import './sw-data-grid-settings.scss';

/**
 * @sw-package framework
 *
 * @private
 */
export default {
    template,

    emits: [
        'change-compact-mode',
        'change-preview-images',
        'change-column-visibility',
        'change-column-order',
    ],

    props: {
        columns: {
            type: Array,
            default() {
                return [];
            },
            required: true,
        },
        compact: {
            type: Boolean,
            required: true,
            default: false,
        },
        previews: {
            type: Boolean,
            required: true,
            default: false,
        },
        enablePreviews: {
            type: Boolean,
            required: true,
            default: false,
        },
        disabled: {
            type: Boolean,
            required: true,
            default: false,
        },
    },

    data() {
        return {
            currentCompact: this.compact,
            currentPreviews: this.previews,
            currentColumns: this.columns,
        };
    },

    computed: {
        contextMenuClasses() {
            return {
                'sw-data-grid-settings': true,
            };
        },
    },

    watch: {
        columns() {
            this.currentColumns = this.columns;
        },

        compact() {
            this.currentCompact = this.compact;
        },

        previews() {
            this.currentPreviews = this.previews;
        },
    },

    methods: {
        onChangeCompactMode(value) {
            this.currentCompact = value;
            this.$emit('change-compact-mode', value);
        },

        onChangePreviews(value) {
            this.currentPreviews = value;
            this.$emit('change-preview-images', value);
        },

        onChangeColumnVisibility(value, index) {
            this.$emit('change-column-visibility', value, index);
        },

        onClickChangeColumnOrderUp(column) {
            const columnIndex = this.currentColumns.findIndex((col) => col.property === column.property);

            this.$emit('change-column-order', columnIndex, columnIndex - 1);
        },

        onClickChangeColumnOrderDown(column) {
            const columnIndex = this.currentColumns.findIndex((col) => col.property === column.property);

            this.$emit('change-column-order', columnIndex, columnIndex + 1);
        },
    },
};
