/**
 * @sw-package inventory
 */

import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

const advancedModeSettings = {
    value: {
        advancedMode: {
            label: 'sw-product.general.textAdvancedMode',
            enabled: true,
        },
        settings: [
            {
                key: 'general_information',
                label: 'sw-product.detailBase.cardTitleProductInfo',
                enabled: true,
                name: 'general',
            },
            {
                key: 'prices',
                label: 'sw-product.detailBase.cardTitlePrices',
                enabled: true,
                name: 'general',
            },
            {
                key: 'deliverability',
                label: 'sw-product.detailBase.cardTitleDeliverabilityInfo',
                enabled: true,
                name: 'general',
            },
            {
                key: 'visibility_structure',
                label: 'sw-product.detailBase.cardTitleVisibilityStructure',
                enabled: true,
                name: 'general',
            },
            {
                key: 'labelling',
                label: 'sw-product.detailBase.cardTitleSettings',
                enabled: true,
                name: 'general',
            },
        ],
    },
};

const defaultSalesChannelData = {
    'core.defaultSalesChannel.active': false,
    'core.defaultSalesChannel.salesChannel': [
        '98432def39fc4624b33213a56b8c944d',
    ],
    'core.defaultSalesChannel.visibility': {
        '98432def39fc4624b33213a56b8c944d': 10,
    },
};

describe('module/sw-product/page/sw-product-detail', () => {
    async function createWrapper(searchFunction = () => Promise.resolve([]), productId = '1234') {
        return mount(await wrapTestComponent('sw-product-detail', { sync: true }), {
            global: {
                mocks: {
                    $route: {
                        name: 'sw.product.detail.base',
                        params: {
                            id: productId,
                        },
                    },
                },
                provide: {
                    numberRangeService: {
                        reserve: () => Promise.resolve({ number: 1 }),
                    },
                    seoUrlService: {},
                    mediaService: {},
                    repositoryFactory: {
                        create: (entity) => ({
                            create: () => {
                                if (entity === 'product') {
                                    return {
                                        id: '1',
                                        parentId: '1',
                                        properties: [],
                                        visibilities: [],
                                        isNew: () => true,
                                    };
                                }
                                return {};
                            },
                            search: searchFunction,
                            get: () => {
                                return Promise.resolve({
                                    variation: [],
                                });
                            },
                            hasChanges: () => false,
                            save: () => Promise.resolve({}),
                        }),
                    },
                    systemConfigApiService: {
                        getConfig: () =>
                            Promise.resolve({
                                'core.tax.defaultTaxRate': '',
                            }),
                        getValues: () => Promise.resolve(defaultSalesChannelData),
                    },
                    entityValidationService: {
                        validate: (entity, customValidator) => {
                            let errors = [];
                            if (customValidator) {
                                errors = customValidator(errors, entity);
                            }

                            return errors.length < 1;
                        },
                    },
                },
                stubs: {
                    'sw-page': {
                        template: `<div class="sw-page">
                            <slot name="smart-bar-actions"></slot>
                            <slot name="content">
                                <div class="sw-tabs"></div>
                            </slot>
                            <slot></slot>
                        </div>`,
                    },
                    'sw-product-variant-info': true,
                    'sw-button-group': true,
                    'sw-button-process': true,
                    'sw-context-button': true,
                    'sw-context-menu-item': true,
                    'sw-language-switch': true,
                    'sw-card-view': {
                        template: '<div class="sw-card-view"><slot></slot></div>',
                    },
                    'sw-language-info': true,
                    'router-view': true,

                    'sw-context-menu-divider': true,
                    'sw-checkbox-field': true,
                    'sw-product-settings-mode': await wrapTestComponent('sw-product-settings-mode', { sync: true }),
                    'sw-loader': true,
                    'sw-tabs': {
                        template: '<div class="sw-tabs"><slot /></div>',
                    },
                    'sw-tabs-item': {
                        template: '<div class="sw-tabs-item"><slot /></div>',
                        props: [
                            'route',
                            'title',
                        ],
                    },
                    'sw-inheritance-warning': true,
                    'router-link': true,
                    'sw-product-detail': await wrapTestComponent('sw-product-detail'),
                    'sw-extension-component-section': true,
                    'sw-product-clone-modal': true,
                },
                propsData: {
                    productId,
                },
            },
        });
    }

    let wrapper;

    beforeAll(() => {
        Shopware.Store.unregister('cmsPage');
        Shopware.Store.register({
            id: 'cmsPage',
            actions: {
                resetCmsPageState: () => {},
            },
        });
    });

    beforeEach(async () => {
        wrapper = await createWrapper();
    });

    it('should be a Vue.js component', async () => {
        expect(wrapper.vm).toBeTruthy();
    });

    it('should show advanced mode settings', async () => {
        Shopware.Store.get('swProductDetail').product = { parentId: '' };
        await nextTick();
        const contextButton = wrapper.find('.sw-product-settings-mode');
        expect(contextButton.exists()).toBe(true);
    });

    it('should show item tabs', async () => {
        await wrapper.setProps({
            productId: '1234',
        });
        const tabItemClassName = [
            '.sw-product-detail__tab-advanced-prices',
            '.sw-product-detail__tab-variants',
            '.sw-product-detail__tab-layout',
            '.sw-product-detail__tab-seo',
            '.sw-product-detail__tab-cross-selling',
            '.sw-product-detail__tab-reviews',
        ];

        await nextTick();

        tabItemClassName.forEach((item) => {
            expect(wrapper.find(item).exists()).toBe(true);
        });
    });

    it('should show item tabs when advanced mode deactivate', async () => {
        wrapper.vm.userModeSettingsRepository.save = jest.fn(() => Promise.resolve());
        Shopware.Store.get('swProductDetail').product = { parentId: '' };
        await wrapper.setProps({
            productId: '1234',
        });

        Shopware.Store.get('swProductDetail').advancedModeSetting = {
            value: {
                ...advancedModeSettings.value,
                advancedMode: {
                    enabled: false,
                },
            },
        };

        const tabItemClassName = [
            '.sw-product-detail__tab-variants',
            '.sw-product-detail__tab-layout',
            '.sw-product-detail__tab-seo',
            '.sw-product-detail__tab-cross-selling',
            '.sw-product-detail__tab-reviews',
        ];

        await nextTick();

        tabItemClassName.forEach((item) => {
            expect(wrapper.find(item).attributes().style).toBe('display: none;');
        });
    });

    it('should show Advance mode setting on the variant product page', async () => {
        await wrapper.setProps({
            productId: '1234',
        });

        const contextButton = wrapper.find('.sw-product-settings-mode');
        expect(contextButton.exists()).toBeFalsy();

        const visibleTabItem = [
            '.sw-product-detail__tab-seo',
            '.sw-product-detail__tab-cross-selling',
            '.sw-product-detail__tab-reviews',
        ];

        const invisibleTabItem = [
            '.sw-product-detail__tab-variants',
            '.sw-product-detail__tab-layout',
        ];

        visibleTabItem.forEach((item) => {
            expect(wrapper.find(item).attributes().style).toBeFalsy();
        });

        invisibleTabItem.forEach((item) => {
            expect(wrapper.find(item).attributes().style).toBe('display: none;');
        });
    });

    it('should always show the correct menu, even with the defaults not matching the userConfig', async () => {
        const keys = [
            'general_information',
            'prices',
            'deliverability',
        ];
        const mockKey = 'mock_key_without_result';
        const settings = [...keys].map((key) => {
            return {
                enabled: false,
                key,
                label: key,
                name: 'general',
            };
        });
        await wrapper.vm.$nextTick();

        settings.forEach((entry) => {
            expect(entry.enabled).toBe(!keys.includes(entry.key));
        });

        keys.forEach((key) => {
            expect(settings.some((entry) => entry.key === key)).toBe(true);
        });

        expect(settings.some((entry) => entry.key === mockKey)).toBeFalsy();
    });

    it('should set purchasePrices to default value when given purchasePrices are empty', async () => {
        await wrapper.vm.$nextTick();
        wrapper.vm.currencyRepository.search = jest.fn(() => {
            return Promise.resolve([
                {
                    id: '123',
                    name: 'EUR',
                },
            ]);
        });

        await wrapper.vm.loadCurrencies();
        await nextTick();

        expect(wrapper.vm.product.purchasePrices).toStrictEqual([
            {
                currencyId: undefined,
                gross: 0,
                net: 0,
                linked: true,
            },
        ]);
    });

    it('should validate and clear listPrices/regulationPrices on save', async () => {
        wrapper.vm.getCmsPageOverrides = jest.fn(() => {
            return null;
        });
        wrapper.vm.product.isNew = jest.fn(() => {
            return false;
        });
        wrapper.vm.product.prices = [];
        wrapper.vm.product.price = [
            {
                currencyId: undefined,
                linked: true,
                gross: 100,
                net: 84.034,
                listPrice: {
                    currencyId: undefined,
                    linked: true,
                    gross: 0,
                    net: 0,
                },
                regulationPrice: {
                    currencyId: undefined,
                    linked: true,
                    gross: 0,
                    net: 0,
                },
            },
        ];

        wrapper.vm.onSave();

        expect(wrapper.vm.product.price).toStrictEqual([
            {
                currencyId: undefined,
                gross: 100,
                net: 84.034,
                linked: true,
                listPrice: null,
                regulationPrice: null,
            },
        ]);
        await flushPromises();
    });

    it('should show correct config when there is system config data', async () => {
        await flushPromises();
        await wrapper.unmount();

        wrapper = await createWrapper(() => {
            return Promise.resolve([
                {
                    id: '98432def39fc4624b33213a56b8c944d',
                    name: 'Headless',
                },
            ]);
        });

        await flushPromises();
        expect(wrapper.vm.product.visibilities).toHaveLength(1);
    });

    it('should run custom validation service and handle errors', async () => {
        wrapper.vm.getCmsPageOverrides = jest.fn(() => {
            return null;
        });
        Shopware.Store.get('swProductDetail').product = {
            isNew: jest.fn(() => true),
            prices: [],
            price: [
                {
                    currencyId: undefined,
                    linked: true,
                    gross: 100,
                    net: 84.034,
                    listPrice: {
                        currencyId: undefined,
                        linked: true,
                        gross: 0,
                        net: 0,
                    },
                    regulationPrice: {
                        currencyId: undefined,
                        linked: true,
                        gross: 0,
                        net: 0,
                    },
                },
            ],
        };

        // make it a download product which requires downloads
        Shopware.Store.get('swProductDetail').creationStates = 'is-download';

        wrapper.vm.saveProduct = jest.fn(() => {
            return Promise.resolve();
        });
        wrapper.vm.onSave();

        // save shouldn't finish successfully (nothing should be sent to the server - no saveProduct call)
        expect(wrapper.vm.saveProduct.mock.calls).toHaveLength(0);
        await flushPromises();
    });

    it('should set isSaveSuccessful to true when no SEO promises exist', () => {
        wrapper.vm.loadProduct = jest.fn();

        wrapper.vm.updateSeoPromises = [];

        wrapper.vm.onSaveFinished('success');

        expect(wrapper.vm.isSaveSuccessful).toBe(true);
        expect(wrapper.vm.loadProduct).not.toHaveBeenCalled();
    });

    it('should handle success response correctly', async () => {
        wrapper.vm.updateSeoPromises = [Promise.resolve()];
        Shopware.Store.get('swProductDetail').setLoading = jest.fn();
        Shopware.Store.get('error').resetApiErrors = jest.fn();
        wrapper.vm.loadProduct = jest.fn();

        Shopware.Utils.EventBus.emit = jest.fn();

        wrapper.vm.onSaveFinished('success');

        expect(Shopware.Store.get('swProductDetail').setLoading).toHaveBeenCalledWith([
            'product',
            true,
        ]);

        await flushPromises();

        expect(Shopware.Utils.EventBus.emit).toHaveBeenCalledWith('sw-product-detail-save-finish');
        expect(wrapper.vm.isSaveSuccessful).toBe(true);
        expect(Shopware.Store.get('error').resetApiErrors).not.toHaveBeenCalled();
        expect(Shopware.Store.get('swProductDetail').setLoading).toHaveBeenCalledWith([
            'product',
            false,
        ]);
        expect(wrapper.vm.loadProduct).toHaveBeenCalled();
    });

    it('should handle duplicate product number error correctly', async () => {
        wrapper.vm.updateSeoPromises = [Promise.resolve()];
        wrapper.vm.isSaveSuccessful = false;
        wrapper.vm.loadProduct = jest.fn();
        wrapper.vm.createNotificationError = jest.fn();

        Shopware.Utils.EventBus.emit = jest.fn();

        const duplicateErrorResponse = {
            response: {
                data: {
                    errors: [
                        {
                            code: 'CONTENT__DUPLICATE_PRODUCT_NUMBER',
                            meta: {
                                parameters: {
                                    number: 'SW-123',
                                },
                            },
                        },
                    ],
                },
            },
        };

        wrapper.vm.onSaveFinished(duplicateErrorResponse);

        await flushPromises();

        expect(wrapper.vm.createNotificationError).toHaveBeenCalledWith({
            title: 'global.default.error',
            message: 'sw-product.notification.notificationSaveErrorProductNoAlreadyExists',
        });
        expect(wrapper.vm.isSaveSuccessful).toBe(false);
        expect(wrapper.vm.loadProduct).toHaveBeenCalled();
    });

    it('should handle generic error with detail correctly', async () => {
        wrapper.vm.createNotificationError = jest.fn();
        wrapper.vm.loadProduct = jest.fn();
        wrapper.vm.updateSeoPromises = [Promise.resolve()];
        const errorResponse = {
            response: {
                data: {
                    errors: [
                        {
                            detail: 'Custom error message',
                        },
                    ],
                },
            },
        };

        wrapper.vm.onSaveFinished(errorResponse);

        await flushPromises();

        expect(wrapper.vm.createNotificationError).toHaveBeenCalledWith({
            title: 'global.default.error',
            message: 'Custom error message',
        });
        expect(wrapper.vm.isSaveSuccessful).toBe(false);
        expect(wrapper.vm.loadProduct).toHaveBeenCalled();
    });

    it('should handle SEO promise rejection correctly', async () => {
        const rejectedPromise = Promise.reject(new Error('SEO error'));
        wrapper.vm.updateSeoPromises = [rejectedPromise];
        Shopware.Store.get('swProductDetail').setLoading = jest.fn();
        wrapper.vm.loadProduct = jest.fn();

        wrapper.vm.onSaveFinished('success');

        await flushPromises();

        expect(Shopware.Store.get('swProductDetail').setLoading).toHaveBeenCalledWith([
            'product',
            false,
        ]);
        expect(wrapper.vm.loadProduct).toHaveBeenCalled();
    });
});
