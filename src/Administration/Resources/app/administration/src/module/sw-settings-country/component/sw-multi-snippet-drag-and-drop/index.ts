import type { PropType, ComponentObjectPropsOptions } from 'vue';
import type { DragConfig } from 'src/app/directive/dragdrop.directive';
import template from './sw-multi-snippet-drag-and-drop.html.twig';
import './sw-multi-snippet-drag-and-drop.scss';

const { Component } = Shopware;

interface DragItem {
    index: number;
    linePosition?: number | null;
    snippet: string[];
}

const DEFAULT_MIN_LINES = 1 as number;
const DEFAULT_MAX_LINES = 10 as number;

/**
 * @sw-package fundamentals@discovery
 *
 * @private
 */
export default Component.wrapComponentConfig({
    template,

    inject: ['feature'],

    props: {
        value: {
            type: Array as PropType<Array<string[]>>,
            required: true,
        },

        totalLines: {
            type: Number,
            required: true,
        },

        linePosition: {
            type: Number,
            required: true,
        },

        isLoading: {
            type: Boolean,
            required: false,
            default: false,
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },

        selectionDisablingMethod: {
            type: Function,
            required: false,
            default: () => false,
        },

        dragConfig: {
            type: Object,
            required: false,
            default(props: ComponentObjectPropsOptions<{ disabled: boolean }>): DragConfig<DragItem> {
                return {
                    delay: 200,
                    dragGroup: 'sw-multi-snippet',
                    validDragCls: 'is--valid-drag',
                    preventEvent: true,
                    disabled: props.disabled,
                } as unknown as DragConfig<DragItem>;
            },
        },

        dropConfig: {
            type: Object,
            required: false,
            default(props: ComponentObjectPropsOptions<{ disabled: boolean }>): DragConfig<DragItem> {
                return {
                    delay: 200,
                    dragGroup: 'sw-multi-snippet',
                    validDragCls: 'is--valid-drag',
                    preventEvent: true,
                    disabled: props.disabled,
                } as unknown as DragConfig<DragItem>;
            },
        },

        getLabelProperty: {
            type: Function,
            required: false,
            default: (value: string) => value,
        },
    },

    data(): {
        defaultConfig: DragConfig<DragItem>;
    } {
        return {
            defaultConfig: {
                delay: 200,
                dragGroup: 'sw-multi-snippet',
                validDragCls: 'is--valid-drag',
                preventEvent: true,
                disabled: this.disabled,
            } as DragConfig<DragItem>,
        };
    },

    computed: {
        errorObject(): null {
            return null;
        },

        mergedDragConfig(): DragConfig<DragItem> {
            return {
                ...this.defaultConfig,
                // eslint-disable-next-line @typescript-eslint/unbound-method
                onDragStart: this.onDragStart,
                // eslint-disable-next-line @typescript-eslint/unbound-method
                onDragEnter: this.onDragEnter,
                // eslint-disable-next-line @typescript-eslint/unbound-method
                onDrop: this.onDrop,
                ...this.dragConfig,
            } as DragConfig<DragItem>;
        },

        mergedDropConfig(): DragConfig {
            return {
                ...this.defaultConfig,
                ...this.dropConfig,
            } as DragConfig;
        },

        isMaxLines(): boolean {
            return this.totalLines >= DEFAULT_MAX_LINES;
        },

        isMinLines(): boolean {
            return this.totalLines <= DEFAULT_MIN_LINES;
        },
    },

    methods: {
        onDragStart(config: DragConfig<DragItem>, element: HTMLElement, dragElement: HTMLElement): void {
            this.$emit('drag-start', { config, element, dragElement });
        },

        onDragEnter(dragData: DragItem, dropData: DragItem) {
            if (!dragData || !dropData) {
                return;
            }

            this.$emit('drag-enter', { dragData, dropData });
        },

        onDrop(dragData: DragItem, dropData: DragItem) {
            if (!dragData || !dropData) {
                return;
            }

            if (dragData.linePosition === dropData.linePosition) {
                const newValue = Object.assign([], this.value, {
                    [dragData.index]: this.value[dropData.index],
                    [dropData.index]: this.value[dragData.index],
                });

                this.$emit('update:value', this.linePosition, newValue);

                return;
            }

            this.$emit('drop-end', this.linePosition, { dragData, dropData });
        },

        isSelectionDisabled(selection: $TSFixMe): boolean {
            if (this.disabled) {
                return true;
            }

            // eslint-disable-next-line @typescript-eslint/no-unsafe-return, @typescript-eslint/no-unsafe-call
            return this.selectionDisablingMethod(selection);
        },

        onClickDismiss(index: number) {
            this.$emit(
                'update:value',
                this.linePosition,
                this.value.filter((_, key) => key !== index),
            );
        },

        addNewLineAt(position: number) {
            this.$emit('add-new-line', this.linePosition, position);
        },

        moveToNewPosition(position = null) {
            this.$emit('position-move', this.linePosition, position);
        },

        onDelete() {
            this.$emit('update:value', this.linePosition);
        },

        openModal() {
            this.$emit('open-snippet-modal', this.linePosition);
        },
    },
});
