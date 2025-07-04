import './sw-grid-column.scss';
import template from './sw-grid-column.html.twig';

/**
 * @sw-package framework
 *
 * @private
 */
export default {
    template,

    inject: {
        feature: {
            from: 'feature',
            default: null,
        },
        swGridColumns: {
            from: 'swGridColumns',
            default: null,
        },
    },

    props: {
        label: {
            type: String,
            required: false,
            default: null,
        },
        iconLabel: {
            type: String,
            required: false,
            default: null,
        },
        align: {
            type: String,
            default: 'left',
        },
        // eslint-disable-next-line vue/require-prop-types
        flex: {
            required: false,
            default: 1,
        },
        sortable: {
            type: Boolean,
            required: false,
            default: false,
        },
        dataIndex: {
            type: String,
            required: false,
            default: '',
        },
        editable: {
            type: Boolean,
            required: false,
            default: false,
        },
        truncate: {
            type: Boolean,
            required: false,
            default: false,
        },
    },

    computed: {
        parentGrid() {
            return undefined;
        },
    },

    watch: {
        label(newLabel, oldLabel) {
            const parentGridColumns = this.swGridColumns;

            const index = parentGridColumns.findIndex((col) => col.label === oldLabel);

            if (index === -1 || !newLabel) {
                return;
            }

            if (this.parentGrid) {
                parentGridColumns[index].label = newLabel;
            }
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.registerColumn();
        },

        registerColumn() {
            const parentGridColumns = this.swGridColumns;

            const hasColumn = parentGridColumns.some((column) => {
                return column.label === this.label;
            });

            if (!hasColumn && this.label) {
                parentGridColumns.push({
                    label: this.label,
                    iconLabel: this.iconLabel,
                    flex: this.flex,
                    sortable: this.sortable,
                    dataIndex: this.dataIndex,
                    align: this.align,
                    editable: this.editable,
                    truncate: this.truncate,
                });
            }
        },
    },
};
