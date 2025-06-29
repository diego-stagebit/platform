/**
 * @sw-package framework
 */
import 'src/app/component/structure/sw-admin';
import { mount } from '@vue/test-utils';
import { BroadcastChannel } from 'worker_threads';
import { toast } from '@shopware-ag/meteor-admin-sdk';

async function createWrapper(isLoggedIn, forwardLogout = () => {}, route = 'sw.wofoo.index') {
    return mount(await wrapTestComponent('sw-admin', { sync: true }), {
        global: {
            stubs: {
                'sw-notifications': true,
                'sw-duplicated-media-v2': true,
                'sw-settings-cache-modal': true,
                'sw-license-violation': true,
                'sw-hidden-iframes': true,
                'sw-modals-renderer': true,
                'sw-in-app-purchase-checkout': true,
                'sw-app-wrong-app-url-modal': true,
                'router-view': true,
                'sw-skip-link': true,
                'sw-media-modal-renderer': true,
            },
            mocks: {
                $router: {
                    currentRoute: {
                        value: {
                            name: route,
                        },
                    },
                },
            },
            provide: {
                cacheApiService: {},
                extensionStoreActionService: {},
                licenseViolationService: {},
                userActivityService: {
                    updateLastUserActivity: () => {
                        localStorage.setItem('lastActivity', `${Date.now()}`);
                    },
                },
                loginService: {
                    isLoggedIn: () => isLoggedIn,
                    forwardLogout,
                },
            },
        },
        attachTo: document.body,
    });
}

describe('src/app/component/structure/sw-admin/index.ts', () => {
    let wrapper;

    beforeEach(() => {
        global.BroadcastChannel = BroadcastChannel;
    });

    afterEach(async () => {
        if (wrapper) {
            await wrapper.unmount();
        }

        await flushPromises();

        localStorage.removeItem('lastActivity');
    });

    it('should be a Vue.js component', async () => {
        wrapper = await createWrapper(false);

        expect(wrapper.vm).toBeTruthy();
    });

    it('should update user activity on mousemove', async () => {
        wrapper = await createWrapper(false);

        const lastActivity = +(localStorage.getItem('lastActivity') ?? Date.now());

        const app = wrapper.find('#app');
        await app.trigger('mousemove');

        const newLastActivity = +(localStorage.getItem('lastActivity') ?? 0);

        expect(newLastActivity).toBeGreaterThanOrEqual(lastActivity);
    });

    it('should update user activity on keyup', async () => {
        wrapper = await createWrapper(false);

        const lastActivity = +(localStorage.getItem('lastActivity') ?? Date.now());

        const app = wrapper.find('#app');
        await app.trigger('keyup');

        const newLastActivity = +(localStorage.getItem('lastActivity') ?? 0);

        expect(newLastActivity).toBeGreaterThanOrEqual(lastActivity);
    });

    it('should handle session_channel message', async () => {
        const forwardLogout = jest.fn();
        wrapper = await createWrapper(false, forwardLogout);

        const channel = new BroadcastChannel('session_channel');
        channel.postMessage({
            inactive: true,
        });

        await flushPromises();

        expect(forwardLogout).toHaveBeenCalledTimes(1);
        expect(forwardLogout).toHaveBeenCalledWith(true, true);
        channel.close();
    });

    it('should not handle session_channel message with improper event data', async () => {
        const forwardLogout = jest.fn();
        wrapper = await createWrapper(false, forwardLogout);

        const channel = new BroadcastChannel('session_channel');
        channel.postMessage(null);
        channel.postMessage({});

        await flushPromises();

        expect(forwardLogout).toHaveBeenCalledTimes(0);
        channel.close();
    });

    it('should not handle session_channel message on blocked route', async () => {
        const forwardLogout = jest.fn();
        wrapper = await createWrapper(false, forwardLogout, 'sw.login.index.login');

        const channel = new BroadcastChannel('session_channel');
        channel.postMessage({
            inactive: true,
        });

        await flushPromises();

        expect(forwardLogout).toHaveBeenCalledTimes(0);
        channel.close();
    });

    it('should not handle session_channel message on active', async () => {
        const forwardLogout = jest.fn();
        wrapper = await createWrapper(false, forwardLogout);

        const channel = new BroadcastChannel('session_channel');
        channel.postMessage({
            inactive: false,
        });

        await flushPromises();

        expect(forwardLogout).toHaveBeenCalledTimes(0);
        channel.close();
    });

    it('should add toast notification', async () => {
        wrapper = await createWrapper(true);

        await toast.dispatch({
            msg: 'Jest toast',
            type: 'informal',
            dismissible: false,
        });

        const toastNotification = wrapper.find('.mt-toast-notification');
        expect(toastNotification.element).toBeVisible();
        expect(toastNotification.text()).toContain('Jest toast');
    });

    it('should remove toast notification', async () => {
        wrapper = await createWrapper(false);

        await toast.dispatch({
            msg: 'Jest toast',
            type: 'informal',
            dismissible: true,
        });

        expect(wrapper.find('.mt-toast-notification').element).toBeVisible();

        await wrapper.find('.mt-toast-notification__close-action').trigger('click');

        expect(wrapper.find('.mt-toast-notification').exists()).toBe(false);
        expect(wrapper.findComponent('.mt-toast').emitted('remove-toast')).toHaveLength(1);
    });
});
