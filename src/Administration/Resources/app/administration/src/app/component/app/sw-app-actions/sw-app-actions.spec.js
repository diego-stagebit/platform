/**
 * @sw-package framework
 */

import { config, mount } from '@vue/test-utils';
import SwExtensionIcon from 'src/app/component/extension/sw-extension-icon';
import InvalidActionButtonParameterError from '../../../../core/service/api/errors/InvalidActionButtonParameterError';
import { createRouter, actionButtonData, actionResultData } from './_fixtures/app-action.fixtures';
import 'src/app/component/app/sw-app-actions';
import 'src/app/component/base/sw-button';
import 'src/app/component/app/sw-app-action-button';
import 'src/app/component/context-menu/sw-context-button';
import 'src/app/component/context-menu/sw-context-menu';
import 'src/app/component/context-menu/sw-context-menu-item';
import 'src/app/component/utils/sw-popover';

Shopware.Component.register('sw-extension-icon', SwExtensionIcon);

describe('sw-app-actions', () => {
    let wrapper = null;
    let router = null;
    let stubs;

    // eslint-disable-next-line no-shadow
    async function createWrapper(router, resultData = actionResultData) {
        // delete global $router and $routes mocks
        delete config.global.mocks.$router;
        delete config.global.mocks.$route;

        return mount(await wrapTestComponent('sw-app-actions', { sync: true }), {
            global: {
                stubs,
                directives: {
                    popover: {},
                },
                plugins: [router],
                provide: {
                    appActionButtonService: {
                        runAction: jest.fn((actionButtonId) => {
                            if (actionButtonId) {
                                return Promise.resolve(resultData);
                            }

                            return Promise.resolve([]);
                        }),
                        getActionButtonsPerView(entity, view) {
                            if (!entity || !view) {
                                throw new InvalidActionButtonParameterError('error');
                            }

                            if (entity === 'product' && view === 'detail') {
                                return Promise.resolve(actionButtonData);
                            }

                            if (entity === 'product' && view === 'list') {
                                return Promise.resolve([]);
                            }

                            return Promise.reject(new Error('error occured'));
                        },
                    },

                    extensionSdkService: {},

                    repositoryFactory: {
                        create: () => ({
                            search: jest.fn(() => {
                                return Promise.resolve([]);
                            }),
                            create: () => ({}),
                        }),
                    },
                },
            },
        });
    }

    beforeAll(async () => {
        stubs = {
            'sw-app-action-button': await wrapTestComponent('sw-app-action-button', { sync: true }),
            'sw-context-button': await wrapTestComponent('sw-context-button', { sync: true }),
            'sw-context-menu': await wrapTestComponent('sw-context-menu', { sync: true }),
            'sw-context-menu-item': await wrapTestComponent('sw-context-menu-item', { sync: true }),
            'sw-popover': await wrapTestComponent('sw-popover', { sync: true }),
            'sw-popover-deprecated': await wrapTestComponent('sw-popover-deprecated', { sync: true }),
            'sw-modal': true,
            'sw-extension-icon': await wrapTestComponent('sw-extension-icon', { sync: true }),
            'sw-checkbox-field': true,
            'mt-floating-ui': true,
        };

        router = createRouter();
    });

    beforeEach(async () => {
        Shopware.Store.get('shopwareApps').selectedIds = [
            Shopware.Utils.createId(),
        ];
    });

    afterEach(() => {
        if (wrapper) {
            wrapper.unmount();
        }
    });

    it('should be a Vue.js component', async () => {
        wrapper = await createWrapper(router);

        router.push({ name: 'sw.product.detail' });
        await flushPromises();

        expect(wrapper.vm).toBeTruthy();

        expect(wrapper.classes()).toEqual(
            expect.arrayContaining([
                'sw-app-actions',
            ]),
        );
    });

    it('creates an sw-app-action-button per action', async () => {
        wrapper = await createWrapper(router);

        Shopware.Store.get('shopwareApps').selectedIds = [
            Shopware.Utils.createId(),
        ];

        router.push({ name: 'sw.product.detail' });
        await flushPromises();

        // wait created and open context menu
        const contextButton = wrapper.findComponent(stubs['sw-context-button']);
        await contextButton.trigger('click');

        const actionButtons = wrapper.findAllComponents(stubs['sw-app-action-button']);

        expect(actionButtons).toHaveLength(2);
        expect(actionButtons.at(0).props('action')).toEqual(actionButtonData[0]);
        expect(actionButtons.at(1).props('action')).toEqual(actionButtonData[1]);
    });

    it('should not reset the selectedIds on creation when entity exists', async () => {
        expect(Shopware.Store.get('shopwareApps').selectedIds).toEqual([
            expect.any(String),
        ]);

        wrapper = await createWrapper(router);
        await flushPromises();

        expect(Shopware.Store.get('shopwareApps').selectedIds).toEqual([
            expect.any(String),
        ]);
    });

    it('is not rendered if action buttons is empty', async () => {
        wrapper = await createWrapper(router);

        router.push({ name: 'sw.product.list' });
        await flushPromises();

        const emptyState = wrapper.find('.sw-app-actions__empty');
        expect(emptyState.exists()).toBe(true);
    });

    it('should throw a notification if appActionButtonService.appActionButtonService throws an error', async () => {
        wrapper = await createWrapper(router);
        wrapper.vm.createNotificationError = jest.fn();

        router.push({ name: 'sw.order.detail' });
        await flushPromises();

        const notificationMock = wrapper.vm.createNotificationError;

        expect(notificationMock).toHaveBeenCalledTimes(1);
        expect(notificationMock).toHaveBeenCalledWith({
            message: 'sw-app.component.sw-app-actions.messageErrorFetchButtons',
        });
        const emptyState = wrapper.find('.sw-app-actions__empty');
        expect(emptyState.exists()).toBe(true);
    });

    it('ignores pages where entity and view are not set', async () => {
        wrapper = await createWrapper(router);
        wrapper.vm.createNotificationError = jest.fn();

        router.push({ name: 'sw.settings.index' });
        await flushPromises();

        const notificationMock = wrapper.vm.createNotificationError;

        expect(notificationMock).toHaveBeenCalledTimes(0);
        const emptyState = wrapper.find('.sw-app-actions__empty');
        expect(emptyState.exists()).toBe(true);
    });

    it('calls appActionButtonService.runAction if triggered by context menu button', async () => {
        wrapper = await createWrapper(router);

        Shopware.Store.get('shopwareApps').selectedIds = [
            Shopware.Utils.createId(),
        ];

        router.push({ name: 'sw.product.detail' });
        await flushPromises();

        const contextButton = wrapper.findComponent(stubs['sw-context-button']);

        await contextButton.trigger('click');
        let swAppActions = wrapper.findAllComponents(stubs['sw-app-action-button']);
        await swAppActions.at(0).trigger('click');

        // expect context menu is closed
        expect(wrapper.findComponent(stubs['sw-context-menu']).exists()).toBe(false);

        await contextButton.trigger('click');
        swAppActions = wrapper.findAllComponents(stubs['sw-app-action-button']);
        await swAppActions.at(1).trigger('click');

        // expect context menu is closed
        expect(wrapper.findComponent(stubs['sw-context-menu']).exists()).toBe(false);

        const runActionsMock = wrapper.vm.appActionButtonService.runAction;

        expect(runActionsMock.mock.calls).toHaveLength(2);
        expect(runActionsMock.mock.calls[0]).toEqual([
            actionButtonData[0].id,
            { ids: Shopware.Store.get('shopwareApps').selectedIds },
        ]);

        expect(runActionsMock.mock.calls[1]).toEqual([
            actionButtonData[1].id,
            { ids: Shopware.Store.get('shopwareApps').selectedIds },
        ]);
    });

    it('calls appActionButtonService.runAction with correct response', async () => {
        wrapper = await createWrapper(router);
        wrapper.vm.createNotification = jest.fn();

        Shopware.Store.get('shopwareApps').selectedIds = [
            Shopware.Utils.createId(),
        ];

        router.push({ name: 'sw.product.detail' });
        await flushPromises();

        const contextButton = wrapper.findComponent(stubs['sw-context-button']);

        await contextButton.trigger('click');
        const swAppActions = wrapper.findAllComponents(stubs['sw-app-action-button']);
        await swAppActions.at(0).trigger('click');

        const actionButtonId = Shopware.Utils.createId();
        await wrapper.vm.appActionButtonService.runAction(actionButtonId);

        const notificationMock = wrapper.vm.createNotification;

        expect(notificationMock).toHaveBeenCalledTimes(1);
        expect(notificationMock).toHaveBeenCalledWith({
            variant: actionResultData.data.status,
            message: actionResultData.data.message,
        });
    });

    it('calls appActionButtonService.runAction with open modal response', async () => {
        const openModalResponseData = {
            data: {
                actionType: 'openModal',
                iframeUrl: 'http://test/com',
                size: 'medium',
            },
        };
        wrapper = await createWrapper(router, openModalResponseData);

        Shopware.Store.get('shopwareApps').selectedIds = [
            Shopware.Utils.createId(),
        ];

        router.push({ name: 'sw.product.detail' });
        await flushPromises();

        expect(wrapper.find('.sw-modal-app-action-button').exists()).toBe(false);

        const contextButton = wrapper.findComponent(stubs['sw-context-button']);

        await contextButton.trigger('click');
        const swAppActions = wrapper.findAllComponents(stubs['sw-app-action-button']);
        await swAppActions.at(0).trigger('click');

        const actionButtonId = Shopware.Utils.createId();
        await wrapper.vm.appActionButtonService.runAction(actionButtonId);

        expect(wrapper.find('.sw-modal-app-action-button').exists()).toBe(true);
    });
});
