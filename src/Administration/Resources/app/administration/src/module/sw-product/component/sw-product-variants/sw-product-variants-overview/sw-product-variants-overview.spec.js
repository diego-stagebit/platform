/**
 * @sw-package buyers-experience
 */
import { mount } from '@vue/test-utils';
import Criteria from 'src/core/data/criteria.data';

let repositoryFactoryMock;
let repositoryFactoryCreateMock;

async function createWrapper(propsOverride = {}, repositoryFactoryOverride = {}) {
    const productMediaRepositoryMock = {
        create: jest.fn(() => {
            return {
                _isNew: true,
                mediaId: null,
                position: null,
                productId: null,
                media: null,
            };
        }),
        save: jest.fn(() => Promise.resolve()),
        syncDeleted: jest.fn(() => Promise.resolve()),
        search: jest.fn(() => Promise.resolve([])),
        get: jest.fn(() => Promise.resolve({})),
    };

    repositoryFactoryCreateMock = {
        search: () => Promise.resolve([]),
        save: jest.fn(() => Promise.resolve([])).mockName('repositoryFactory save'),
        get: () => Promise.resolve({}),
        syncDeleted: () => Promise.resolve({}),
        create: jest.fn(() => ({})), // General create mock
    };
    repositoryFactoryMock = {
        create: jest.fn((entityName) => {
            if (entityName === 'product_media') {
                return productMediaRepositoryMock;
            }
            return repositoryFactoryCreateMock;
        }),
        ...repositoryFactoryOverride,
    };

    return mount(await wrapTestComponent('sw-product-variants-overview', { sync: true }), {
        props: {
            selectedGroups: [],
            uploadTag: 'uploadTag',
            productEntity: {
                id: '72bfaf5d90214ce592715a9649d8760a',
                variantListingConfig: null,
            },
            ...propsOverride,
        },
        global: {
            provide: {
                repositoryFactory: repositoryFactoryMock,
                searchRankingService: {},
                configService: {
                    getConfig: () =>
                        Promise.resolve({
                            settings: {
                                enableUrlFeature: false,
                            },
                        }),
                },
                mediaService: {
                    addListener: () => {},
                    removeByTag: () => {},
                    removeListener: () => {},
                    getDefaultFolderId: () => {
                        return Promise.resolve('defaultFolderId');
                    },
                },
                mediaDefaultFolderService: {
                    getDefaultFolderId: () => {
                        return Promise.resolve('defaultFolderId');
                    },
                },
                shortcutService: {
                    startEventListener: () => {},
                    stopEventListener: () => {},
                },
                fileValidationService: {},
            },
            stubs: {
                'sw-container': await wrapTestComponent('sw-container', {
                    sync: true,
                }),
                'sw-simple-search-field': await wrapTestComponent('sw-simple-search-field', { sync: true }),
                'sw-context-menu': await wrapTestComponent('sw-context-menu', { sync: true }),
                'sw-tree': await wrapTestComponent('sw-tree', {
                    sync: true,
                }),
                'sw-tree-item': true,
                'sw-data-grid': await wrapTestComponent('sw-data-grid', {
                    sync: true,
                }),
                'router-link': true,
                'sw-label': true,
                'sw-inheritance-switch': true,
                'sw-price-field': true,
                'sw-price-preview': true,
                'sw-text-field': true,
                'sw-product-variants-media-upload': true,
                'sw-upload-listener': true,
                'sw-media-compact-upload-v2': await wrapTestComponent('sw-media-compact-upload-v2', { sync: true }),
                'sw-data-grid-column-boolean': true,
                'sw-context-menu-item': await wrapTestComponent('sw-context-menu-item', { sync: true }),
                'sw-pagination': true,
                'sw-bulk-edit-modal': true,
                'sw-modal': {
                    template: `
                        <div class="sw-modal">
                          <slot name="modal-header"></slot>
                          <slot></slot>
                          <slot name="modal-footer"></slot>
                        </div>
                    `,
                },
                'sw-checkbox-field': await wrapTestComponent('sw-checkbox-field', { sync: true }),
                'sw-checkbox-field-deprecated': await wrapTestComponent('sw-checkbox-field-deprecated', { sync: true }),
                'sw-base-field': await wrapTestComponent('sw-base-field', {
                    sync: true,
                }),
                'sw-loader': true,
                'sw-tree-input-field': true,
                'sw-context-button': {
                    template: '<div class="sw-context-button"><slot></slot></div>',
                },
                'sw-data-grid-settings': true,
                'sw-data-grid-inline-edit': true,
                'sw-data-grid-skeleton': true,
                'sw-field-error': true,
                'sw-ai-copilot-badge': true,
                'sw-help-text': true,
                'sw-button-group': true,
                'sw-media-url-form': true,
                'sw-media-preview-v2': true,
                'sw-context-menu-divider': true,
                'sw-media-modal-v2': true,
                'sw-provide': { template: '<slot/>', inheritAttrs: false },
            },
        },
    });
}

describe('src/module/sw-product/component/sw-product-variants/sw-product-variants-overview', () => {
    beforeEach(() => {
        global.activeAclRoles = [];

        const mockMediaEntity = {
            id: 'media-id-123',
            position: 1,
            fileName: 'test-image',
            fileExtension: 'jpg',
            url: 'http://example.com/test-image.jpg',
        };

        const mockProductMediaEntity = {
            id: 'product-media-assoc-id-1',
            mediaId: mockMediaEntity.id,
            media: mockMediaEntity,
            position: mockMediaEntity.position,
        };

        const productMediaCollection = new Shopware.Data.EntityCollection(
            '/product/72bfaf5d90214ce592715a9649d8760a/media',
            'product_media',
            Shopware.Context.api,
            null,
            [mockProductMediaEntity],
        );

        const product = {
            id: '72bfaf5d90214ce592715a9649d8760a',
            media: productMediaCollection,
        };

        product.getEntityName = () => 'T-Shirt';

        Shopware.Store.register({
            id: 'swProductDetail',
            state() {
                return {
                    product: product,
                    currencies: [],
                    variants: [
                        {
                            id: 1,
                            productNumber: '1',
                            name: null,
                            options: [
                                {
                                    id: 1,
                                    name: '30',
                                    translated: {
                                        name: '30',
                                    },
                                    groupId: 'size-group-id',
                                },
                            ],
                        },
                        {
                            id: 2,
                            productNumber: '2',
                            name: null,
                            options: [
                                {
                                    id: 2,
                                    name: '32',
                                    translated: {
                                        name: '32',
                                    },
                                    groupId: 'size-group-id',
                                },
                            ],
                        },
                    ],
                    taxes: [],
                };
            },
            getters: {
                isLoading: () => false,
            },
            actions: {
                setVariants(state, variants) {
                    state.variants = variants;
                },
                setLoading() {},
                setProduct(state, newProduct) {
                    state.product = newProduct;
                },
            },
        });
    });

    it('should be a Vue.JS component', async () => {
        const wrapper = await createWrapper();
        expect(wrapper.vm).toBeTruthy();
    });

    it('should have an disabled generate variants button', async () => {
        const wrapper = await createWrapper();
        const generateVariantsButton = wrapper.find('.sw-product-variants__generate-action');
        expect(generateVariantsButton.exists()).toBeTruthy();
        expect(generateVariantsButton.attributes('disabled')).toBeDefined();
    });

    it('should have an enabled generate variants button', async () => {
        global.activeAclRoles = ['product.creator'];

        const wrapper = await createWrapper();
        const generateVariantsButton = wrapper.find('.sw-product-variants__generate-action');
        expect(generateVariantsButton.exists()).toBeTruthy();
        expect(generateVariantsButton.attributes('disabled')).toBeUndefined();
    });

    it('should enable selection deleting of list variants', async () => {
        global.activeAclRoles = ['product.deleter'];

        const wrapper = await createWrapper();
        const selectionColumn = wrapper.find('.sw-data-grid__header .sw-data-grid__cell--selection');
        expect(selectionColumn.exists()).toBeTruthy();
    });

    it('should be able to turn on delete confirmation modal', async () => {
        global.activeAclRoles = ['product.deleter'];

        const wrapper = await createWrapper();
        await flushPromises();

        const deleteContextButton = wrapper.find('.sw-context-menu-item.sw-context-menu-item--danger');
        await deleteContextButton.trigger('click');

        const deleteModal = wrapper.find('.sw-product-variants-overview__delete-modal');
        expect(deleteModal.exists()).toBeTruthy();
    });

    it('should not be able to turn on delete confirmation modal', async () => {
        global.activeAclRoles = ['product.editor'];

        const wrapper = await createWrapper();

        const deleteContextButton = wrapper.find('.sw-context-menu-item.sw-context-menu-item--danger');
        expect(deleteContextButton.classes('is--disabled')).toBeTruthy();
    });

    it('should be able to delete variants', async () => {
        global.activeAclRoles = ['product.deleter'];

        const wrapper = await createWrapper();

        const selectAllInput = wrapper.find('.sw-data-grid__select-all input[type="checkbox"]');
        await selectAllInput.setChecked();

        const deleteVariantsButton = wrapper.find('.sw-product-variants-overview__bulk-delete-action');
        expect(deleteVariantsButton.exists()).toBeTruthy();

        await deleteVariantsButton.trigger('click');

        const deleteModal = wrapper.find('.sw-product-variants-overview__delete-modal');
        expect(deleteModal.exists()).toBeTruthy();
    });

    it('should not be able to delete variants', async () => {
        global.activeAclRoles = ['product.editor'];

        const wrapper = await createWrapper();

        const deleteVariantsButton = wrapper.find('.sw-product-variants-overview__bulk-delete-action');
        expect(deleteVariantsButton.exists()).toBeFalsy();
    });

    it('should add the downloads column when the product state is equal "is-download"', async () => {
        const wrapper = await createWrapper(
            {
                productStates: ['is-download'],
            },
            {
                create: (entity) => {
                    if (entity === 'media_default_folder') {
                        return {
                            search: () =>
                                Promise.resolve([
                                    {
                                        id: 'defaultMediaFolderId',
                                        entity: 'product_download',
                                    },
                                ]),
                        };
                    }
                    return { search: () => Promise.resolve() };
                },
            },
        );

        expect(wrapper.find('.sw-data-grid__cell--downloads').exists()).toBeTruthy();
    });

    it('should remove file from digital variant item', async () => {
        const item = {
            id: '1',
            productNumber: '1',
            name: 'Example product',
            downloads: [
                {
                    media: {
                        fileName: 'example',
                        fileExtension: 'png',
                    },
                },
                {
                    media: {
                        fileName: 'test',
                        fileExtension: 'gif',
                    },
                },
            ],
        };

        const wrapper = await createWrapper(
            { productStates: ['is-download'] },
            {
                create: () => ({
                    search: () => Promise.resolve([item]),
                    save: () => Promise.resolve(),
                }),
            },
        );
        await wrapper.vm.getList();

        // should be deleted
        await wrapper.vm.removeFile('example.png', wrapper.vm.variants.at(0));
        // should not be deleted (because it's the last one)
        await wrapper.vm.removeFile('test.gif', wrapper.vm.variants.at(0));

        const previewItems = wrapper
            .find('.sw-data-grid__cell--downloads')
            .findAll('.sw-media-compact-upload-v2__preview-item');
        expect(previewItems).toHaveLength(1);
        expect(previewItems.at(0).find('.sw-context-menu-item').text()).toBe('test.gif');
    });

    it('should save successful uploaded files', async () => {
        const item = {
            id: '1',
            productNumber: '1',
            name: 'Example product',
            downloads: [
                {
                    media: {
                        id: 'lel',
                        fileName: 'test',
                        fileExtension: 'png',
                    },
                },
            ],
        };

        const file = {
            id: 'test-id',
            fileName: 'example',
            fileExtension: 'png',
        };

        const wrapper = await createWrapper(
            { productStates: ['is-download'] },
            {
                create: () => ({
                    search: () => Promise.resolve([item]),
                    save: () => Promise.resolve(),
                    create: () => Promise.resolve(),
                    get: () => Promise.resolve(file),
                }),
            },
        );
        await wrapper.vm.getList();

        // not existing
        await wrapper.vm.successfulUpload({ targetId: 'test-id', downloads: [] }, item);
        // existing
        await wrapper.vm.successfulUpload({ targetId: 'test-id', downloads: [] }, item);

        const previewItems = wrapper
            .find('.sw-data-grid__cell--downloads')
            .findAll('.sw-media-compact-upload-v2__preview-item');
        expect(previewItems).toHaveLength(2);
        expect(previewItems.at(1).find('.sw-context-menu-item').text()).toBe('example.png');
    });

    it('should push to a new route when editing items', async () => {
        const wrapper = await createWrapper();
        wrapper.vm.$router.push = jest.fn();
        await wrapper.setData({
            $refs: {
                variantGrid: {
                    selection: {
                        foo: { states: ['is-download'] },
                    },
                },
            },
        });

        await wrapper.vm.onEditItems();
        expect(wrapper.vm.$router.push).toHaveBeenCalledWith(
            expect.objectContaining({
                name: 'sw.bulk.edit.product',
                params: expect.objectContaining({
                    parentId: '72bfaf5d90214ce592715a9649d8760a',
                    includesDigital: '0',
                }),
            }),
        );

        wrapper.vm.$router.push.mockRestore();
    });

    it('The price of variant should be set to null', async () => {
        const wrapper = await createWrapper();

        const variant = {
            price: [
                {
                    currencyId: 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
                    gross: '123',
                    net: '123',
                },
            ],
        };

        const currency = {
            id: 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
        };

        wrapper.vm.onInheritanceRestore(variant, currency);

        expect(variant.price).toBeNull();
    });

    it('buildSearchQuery modifies criteria correctly', async () => {
        const criteria = new Criteria();
        const term = 'test';
        const wrapper = await createWrapper();

        wrapper.vm.term = term;
        wrapper.vm.buildSearchQuery(criteria);

        expect(criteria.queries).toHaveLength(3);
        expect(criteria.queries[0].query.type).toBe('equals');
        expect(criteria.queries[0].query.field).toBe('product.options.name');
        expect(criteria.queries[0].query.value).toBe(term);
        expect(criteria.queries[0].score).toBe(3500);
        expect(criteria.queries[1].query.type).toBe('contains');
        expect(criteria.queries[1].query.field).toBe('product.options.name');
        expect(criteria.queries[1].query.value).toBe(term);
        expect(criteria.queries[1].score).toBe(500);
        expect(criteria.queries[2].query.type).toBe('contains');
        expect(criteria.queries[2].query.field).toBe('product.productNumber');
        expect(criteria.queries[2].query.value).toBe(term);
        expect(criteria.queries[2].score).toBe(5000);
    });

    it('should update variant listing config of product when deleting variant', async () => {
        global.activeAclRoles = ['product.deleter'];

        const wrapper = await createWrapper();
        await flushPromises();

        const product = {
            variantListingConfig: {
                displayParent: 0,
                configuratorGroupConfig: [],
                mainVariantId: 1,
            },
            ...wrapper.vm.product,
        };

        await wrapper.setProps({
            productEntity: product,
        });

        const deleteContextButton = wrapper.find('.sw-context-menu-item.sw-context-menu-item--danger');
        await deleteContextButton.trigger('click');

        const deleteModal = wrapper.find('.sw-product-variants-overview__delete-modal');
        expect(deleteModal.exists()).toBe(true);

        await wrapper.findByText('button', 'sw-product.variations.generatedListDeleteModalButtonDelete').trigger('click');
        await flushPromises();

        expect(wrapper.vm.productRepository.save).toHaveBeenCalledTimes(1);
    });

    it('should contain a currencyColumns computed property', async () => {
        const wrapper = await createWrapper();

        Shopware.Store.get('swProductDetail').currencies = undefined;

        expect(wrapper.vm.currencyColumns).toEqual([]);

        Shopware.Store.get('swProductDetail').currencies = [
            {
                id: 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
                name: 'Euro',
                isSystemDefault: true,
                translated: {
                    name: 'Euro',
                },
            },
            {
                id: 'b7d2554b0ce847cd82f3ac9bd1c0dfcb',
                name: 'Dollar',
                isSystemDefault: false,
                translated: {
                    name: 'Dollar',
                },
            },
        ];

        expect(wrapper.vm.currencyColumns).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    property: 'price.b7d2554b0ce847cd82f3ac9bd1c0dfca.net',
                    label: 'Euro',
                    visible: true,
                }),
                expect.objectContaining({
                    property: 'price.b7d2554b0ce847cd82f3ac9bd1c0dfcb.net',
                    label: 'Dollar',
                    visible: false,
                }),
            ]),
        );
    });

    it('should correctly create a new product_media entity when media inheritance is removed', async () => {
        const wrapper = await createWrapper();
        await flushPromises();

        const mockVariant = {
            id: 'variant-id-1',
            media: new Shopware.Data.EntityCollection('/product/variant-id-1/media', 'product_media', Shopware.Context.api),
        };

        const storeProductMedia = Shopware.Store.get('swProductDetail').product.media.first();
        const mediaItemToUnInherit = storeProductMedia
            ? storeProductMedia.media
            : {
                  id: 'fallback-media-id',
                  position: 1,
              };

        const mockProductMediaRepositoryCreate = jest.fn(() => {
            return {
                _isNew: true,
                mediaId: null,
                position: null,
                productId: null,
                media: null,
            };
        });

        wrapper.vm.productMediaRepository.create = mockProductMediaRepositoryCreate;

        await wrapper.vm.onMediaInheritanceRemove(mockVariant, mediaItemToUnInherit);

        expect(mockProductMediaRepositoryCreate).toHaveBeenCalledTimes(1);
        expect(mockProductMediaRepositoryCreate).toHaveBeenCalledWith(Shopware.Context.api);

        expect(mockVariant.media).toHaveLength(1);

        const newProductMedia = mockVariant.media.at(0);
        expect(newProductMedia).toBeDefined();
        expect(newProductMedia.mediaId).toBe(mediaItemToUnInherit.id);
        expect(newProductMedia.position).toBe(mediaItemToUnInherit.position);
        expect(newProductMedia.productId).toBe('72bfaf5d90214ce592715a9649d8760a');

        expect(newProductMedia.media).toEqual(mediaItemToUnInherit);
        expect(newProductMedia._isNew).toBe(true);
    });
});
