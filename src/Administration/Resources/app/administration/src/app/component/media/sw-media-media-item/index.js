import template from './sw-media-media-item.html.twig';
import './sw-media-media-item.scss';

const { Mixin } = Shopware;
const { dom } = Shopware.Utils;

/**
 * @status ready
 * @description The <u>sw-media-media-item</u> component is used to store the media item and manage it through the
 * <u>sw-media-base-item</u> component. Use the default slot to add additional context menu items.
 * @sw-package discovery
 * @example-type code-only
 * @component-example
 * <sw-media-media-item
 *     :key="mediaItem.id"
 *     :item="mediaItem"
 *     :selected="false"
 *     :showSelectionIndicator="false"
 *     :isList="false">
 *
 *       <sw-context-menu-item
 *            #additional-context-menu-items
 *            \@click="showDetails(mediaItem)">
 *          Lorem ipsum dolor sit amet
 *       </sw-context-menu-item>
 * </sw-media-media-item>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inheritAttrs: false,

    inject: ['mediaService'],

    emits: [
        'media-item-rename-success',
        'media-item-play',
        'media-item-delete',
        'media-folder-move',
        'media-item-replaced',
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            showModalReplace: false,
            showModalDelete: false,
            showModalMove: false,
        };
    },

    computed: {
        locale() {
            return this.$root.$i18n.locale.value;
        },

        defaultContextMenuClass() {
            return {
                'sw-context-menu__group': this.$slots.default,
            };
        },

        mediaNameFilter() {
            return Shopware.Filter.getByName('mediaName');
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },

        fileSizeFilter() {
            return Shopware.Filter.getByName('fileSize');
        },

        extensionSdkButtons() {
            return Shopware.Store.get('actionButtons').buttons.filter((button) => {
                return button.entity === 'media' && button.view === 'item';
            });
        },
    },

    methods: {
        async onChangeName(updatedName, item, endInlineEdit) {
            if (!updatedName || !updatedName.trim()) {
                this.rejectRenaming(endInlineEdit);
                return;
            }

            item.isLoading = true;

            try {
                await this.mediaService.renameMedia(item.id, updatedName);
                item.fileName = updatedName;
                item.isLoading = false;
                this.createNotificationSuccess({
                    message: this.$tc('global.sw-media-media-item.notification.renamingSuccess.message'),
                });
                this.$emit('media-item-rename-success', item);
            } catch (exception) {
                const errors = exception.response.data.errors;

                errors.forEach((error) => {
                    this.handleErrorMessage(error);
                });
            } finally {
                item.isLoading = false;
                endInlineEdit();
            }
        },

        handleErrorMessage(error) {
            switch (error.code) {
                case 'CONTENT__MEDIA_FILE_NAME_IS_TOO_LONG':
                    this.createNotificationError({
                        message: this.$tc(
                            'global.sw-media-media-item.notification.fileNameTooLong.message',
                            {
                                length: error.meta.parameters.maxLength,
                            },
                            0,
                        ),
                    });
                    break;
                default:
                    this.createNotificationError({
                        message: this.$tc('global.sw-media-media-item.notification.renamingError.message'),
                    });
            }
        },

        rejectRenaming(endInlineEdit) {
            this.createNotificationError({
                message: this.$tc('global.sw-media-media-item.notification.errorBlankItemName.message'),
            });

            endInlineEdit();
        },

        onBlur(event, item, endInlineEdit) {
            const input = event.target.value;

            if (input !== item.fileName) {
                this.onChangeName(input, item, endInlineEdit);
                return;
            }

            endInlineEdit();
        },

        emitPlayEvent(originalDomEvent, item) {
            if (!this.selected) {
                this.$emit('media-item-play', {
                    originalDomEvent,
                    item,
                });
                return;
            }

            this.removeFromSelection(originalDomEvent);
        },

        async copyItemLink(item) {
            try {
                await dom.copyStringToClipboard(item.url);
                this.createNotificationSuccess({
                    message: this.$tc('sw-media.general.notification.urlCopied.message'),
                });
            } catch (err) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: this.$tc('global.sw-field.notification.notificationCopyFailureMessage'),
                });
            }
        },

        openModalDelete() {
            this.showModalDelete = true;
        },

        closeModalDelete() {
            this.showModalDelete = false;
        },

        async emitItemDeleted(deletePromise) {
            this.closeModalDelete();
            const ids = await deletePromise;
            this.$emit('media-item-delete', ids.mediaIds);
        },

        openModalReplace() {
            this.showModalReplace = true;
        },

        closeModalReplace() {
            this.showModalReplace = false;
        },

        openModalMove() {
            this.showModalMove = true;
        },

        closeModalMove() {
            this.showModalMove = false;
        },

        async onMediaItemMoved(movePromise) {
            this.closeModalMove();
            const ids = await movePromise;
            this.$emit('media-folder-move', ids);
        },

        emitRefreshMediaLibrary() {
            this.closeModalReplace();

            this.$nextTick(() => {
                this.$emit('media-item-replaced');
            });
        },

        runAppAction(action, item) {
            if (typeof action.callback !== 'function') {
                return;
            }

            const { fileName, mimeType, fileSize, url, id } = item;

            action.callback({
                id,
                url,
                fileName,
                mimeType,
                fileSize,
            });
        },
    },
};
