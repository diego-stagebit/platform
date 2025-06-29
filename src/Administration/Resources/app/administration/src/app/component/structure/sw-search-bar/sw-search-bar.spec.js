/**
 * @sw-package framework
 */

/* eslint-disable max-len */
import { mount } from '@vue/test-utils';
import 'src/app/component/structure/sw-search-bar';
import 'src/app/component/structure/sw-search-bar-item';
import Criteria from 'src/core/data/criteria.data';

const { Module } = Shopware;
const register = Module.register;

const searchTypeServiceTypes = {
    product: {
        entityName: 'product',
        entityService: 'productService',
        placeholderSnippet: 'sw-product.general.placeholderSearchBar',
        listingRoute: 'sw.product.index',
    },
    category: {
        entityName: 'category',
        entityService: 'categoryService',
        placeholderSnippet: 'sw-category.general.placeholderSearchBar',
        listingRoute: 'sw.category.index',
    },
    customer: {
        entityName: 'customer',
        entityService: 'customerService',
        placeholderSnippet: 'sw-customer.general.placeholderSearchBar',
        listingRoute: 'sw.customer.index',
    },
    order: {
        entityName: 'order',
        entityService: 'orderService',
        placeholderSnippet: 'sw-order.general.placeholderSearchBar',
        listingRoute: 'sw.order.index',
    },
    media: {
        entityName: 'media',
        entityService: 'mediaService',
        placeholderSnippet: 'sw-media.general.placeholderSearchBar',
        listingRoute: 'sw.media.index',
    },
};

describe('src/app/component/structure/sw-search-bar', () => {
    /** @type Wrapper */
    let wrapper;
    let swSearchBarComponent;
    let spyLoadResults;
    let spyLoadTypeSearchResults;
    let spyLoadTypeSearchResultsByService;
    let userActivityApiServiceMock;

    async function createWrapper(props, searchTypes = searchTypeServiceTypes, privileges = [], customProviders = {}) {
        swSearchBarComponent = await wrapTestComponent('sw-search-bar');
        spyLoadResults = jest.spyOn(swSearchBarComponent.methods, 'loadResults');
        spyLoadTypeSearchResults = jest.spyOn(swSearchBarComponent.methods, 'loadTypeSearchResults');
        spyLoadTypeSearchResultsByService = jest.spyOn(swSearchBarComponent.methods, 'loadTypeSearchResultsByService');

        const defaultProviders = {
            recentlySearchService: {
                get: () => [],
            },
            userActivityApiService: {
                getIncrement: jest.fn(() => Promise.resolve({})),
                deleteActivityKeys: jest.fn(() => Promise.resolve({})),
            },
        };

        return mount(swSearchBarComponent, {
            global: {
                stubs: {
                    'sw-version': true,
                    'sw-loader': true,
                    'sw-search-more-results': true,
                    'sw-search-bar-item': await wrapTestComponent('sw-search-bar-item', { sync: true }),
                    'sw-search-preferences-modal': true,
                    'router-link': true,
                    'sw-highlight-text': true,
                    'sw-shortcut-overview-item': true,
                },
                mocks: {
                    $route: {
                        query: {
                            term: '',
                        },
                    },
                },
                provide: {
                    searchService: {
                        search: () => {
                            const result = {
                                data: {
                                    foo: {
                                        total: 1,
                                        data: [
                                            { name: 'Baz', id: '12345' },
                                        ],
                                    },
                                },
                            };

                            return Promise.resolve(result);
                        },

                        elastic: () => {
                            const result = {
                                data: {
                                    esFoo: {
                                        total: 1,
                                        index: 'admin-es-foo-listing',
                                        indexer: 'es-foo-listing',
                                        data: [
                                            { name: 'ES Baz', id: 'es-12345' },
                                        ],
                                    },
                                },
                            };

                            return Promise.resolve(result);
                        },

                        searchQuery: () =>
                            Promise.resolve({
                                data: {
                                    product: {
                                        data: {
                                            dfe80a0ec016413e8e03fa2d85db3dea: {
                                                id: 'dfe80a0ec016413e8e03fa2d85db3dea',
                                                name: 'Lightweight Iron Tossed Cookie Salad',
                                            },
                                        },
                                    },

                                    foo: {
                                        total: 1,
                                        data: [
                                            { name: 'Baz', id: '12345' },
                                        ],
                                    },
                                },
                            }),
                    },
                    repositoryFactory: {
                        create: (entity) => ({
                            search: (criteria) => {
                                if (entity === 'sales_channel') {
                                    return Promise.resolve([
                                        {
                                            id: '8a243080f92e4c719546314b577cf82b',
                                            translated: { name: 'Storefront' },
                                            type: {
                                                translated: {
                                                    name: 'Storefront',
                                                },
                                            },
                                        },
                                    ]);
                                }

                                if (entity === 'sales_channel_type') {
                                    return Promise.resolve([
                                        {
                                            id: 'xxxxxxx',
                                            translated: { name: 'Storefront' },
                                        },
                                    ]);
                                }

                                if (entity === 'category') {
                                    const result = [
                                        {
                                            name: 'Home',
                                            id: '12345',
                                        },
                                        {
                                            name: 'Electronics',
                                            id: '55523',
                                        },
                                    ];
                                    result.total = 2;

                                    return Promise.resolve(result);
                                }

                                criteria = criteria.parse();
                                if (criteria.query && !criteria.term) {
                                    const result = [
                                        {
                                            name: 'Baz',
                                            id: '12345',
                                        },
                                    ];
                                    result.total = 1;

                                    return Promise.resolve(result);
                                }

                                const result = [
                                    {
                                        name: 'Home',
                                        id: '12345',
                                    },
                                    {
                                        name: 'Electronics',
                                        id: '55523',
                                    },
                                ];
                                result.total = 2;

                                return Promise.resolve(result);
                            },
                        }),
                    },
                    searchTypeService: {
                        getTypes: () => searchTypes,
                    },
                    acl: {
                        can: (identifier) => {
                            if (!identifier) {
                                return true;
                            }

                            return privileges.includes(identifier);
                        },
                    },
                    searchRankingService: {
                        getUserSearchPreference: () => {
                            return Promise.resolve({
                                foo: { name: 500 },
                            });
                        },
                        getSearchFieldsByEntity: (entity) => {
                            const data = {
                                foo: { name: 500 },
                                category: { name: 500 },
                            };
                            return Promise.resolve(data[entity]);
                        },
                        buildSearchQueriesForEntity: (searchFields, term, criteria) => {
                            if (!searchFields) {
                                return criteria;
                            }

                            return criteria.addQuery(Criteria.equals('name', 'Baz'), 1).setTerm(null);
                        },
                        buildGlobalSearchQueries: (userSearchPreference, searchTerm) => {
                            return {
                                foo: {
                                    limit: 25,
                                    page: 1,
                                    query: [
                                        {
                                            score: 500,
                                            query: {
                                                type: 'equals',
                                                field: 'product.name',
                                                value: searchTerm,
                                            },
                                        },
                                        {
                                            score: 375,
                                            query: {
                                                type: 'contains',
                                                field: 'product.name',
                                                value: searchTerm,
                                            },
                                        },
                                    ],
                                    'total-count-mode': 1,
                                },
                            };
                        },
                    },
                    recentlySearchService: customProviders.recentlySearchService || defaultProviders.recentlySearchService,
                    userActivityApiService:
                        customProviders.userActivityApiService || defaultProviders.userActivityApiService,
                },
            },
            props,
            attachTo: document.body,
        });
    }

    beforeAll(async () => {
        swSearchBarComponent = await wrapTestComponent('sw-search-bar');
        spyLoadResults = jest.spyOn(swSearchBarComponent.methods, 'loadResults');
        spyLoadTypeSearchResults = jest.spyOn(swSearchBarComponent.methods, 'loadTypeSearchResults');
        spyLoadTypeSearchResultsByService = jest.spyOn(swSearchBarComponent.methods, 'loadTypeSearchResultsByService');

        const apiService = Shopware.Application.getContainer('factory').apiService;
        apiService.register('categoryService', {
            getList: () => {
                const result = [];
                result.meta = {
                    total: 0,
                };

                return Promise.resolve(result);
            },
        });
    });

    beforeEach(async () => {
        Shopware.Store.get('session').setCurrentUser({
            id: 'id',
        });

        userActivityApiServiceMock = {
            getIncrement: jest.fn(() => Promise.resolve({})),
            deleteActivityKeys: jest.fn(() => Promise.resolve({})),
        };

        Module.getModuleRegistry().clear();
    });

    it('should be a Vue.js component', async () => {
        wrapper = await createWrapper({
            initialSearchType: 'product',
        });

        expect(wrapper.vm).toBeTruthy();
    });

    it('should show the tag overlay on click and not the search results', async () => {
        wrapper = await createWrapper({
            initialSearchType: 'product',
        });

        // open search
        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');
        await searchInput.setValue('#');

        // check if search results are hidden and types container are visible
        const typesContainer = wrapper.find('.sw-search-bar__types_container--v2');
        expect(typesContainer.exists()).toBe(true);

        // check if active type is default type
        const activeType = wrapper.find('.sw-search-bar__field .sw-search-bar__type--v2');
        expect(activeType.text()).toBe('global.entities.product');
    });

    it('should hide the tags and not show the search results when initialSearchType and currentSearchType matches', async () => {
        wrapper = await createWrapper({
            initialSearchType: 'product',
        });

        // open search
        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');
        await searchInput.setValue('#');

        // check if search results are hidden and types container are visible
        let typesContainer = wrapper.find('.sw-search-bar__types_container--v2');

        expect(typesContainer.exists()).toBe(true);

        // type search value
        await searchInput.setValue('shirt');
        await flushPromises();

        const debouncedDoListSearchWithContainer = swSearchBarComponent.methods.doListSearchWithContainer;
        await debouncedDoListSearchWithContainer.flush();

        await flushPromises();

        // check if search results and types container are hidden
        typesContainer = wrapper.find('.sw-search-bar__types_container--v2');
        expect(typesContainer.exists()).toBe(false);
    });

    it('should hide the tags and show the search results when initialSearchType and currentSearchType are not matching', async () => {
        wrapper = await createWrapper({
            initialSearchType: 'product',
        });

        const searchInput = wrapper.find('.sw-search-bar__input');

        // open search
        await searchInput.trigger('focus');
        await searchInput.setValue('#');

        // check if search results are hidden and types container are visible
        let typesContainer = wrapper.find('.sw-search-bar__types_container--v2');
        expect(typesContainer.exists()).toBe(true);

        // set categories as active type
        const typeItems = wrapper.findAll('.sw-search-bar__types_container--v2 .sw-search-bar__type-item');
        const secondTypeItem = typeItems.at(1);
        await secondTypeItem.trigger('click');

        // open search again
        await searchInput.trigger('focus');
        await searchInput.setValue('#');

        // check if new type is set
        const activeType = wrapper.find('.sw-search-bar__field .sw-search-bar__type--v2');
        expect(activeType.text()).toBe('global.entities.category');

        // type search value
        await searchInput.setValue('shorts');
        await flushPromises();

        const debouncedDoListSearchWithContainer = swSearchBarComponent.methods.doListSearchWithContainer;
        await debouncedDoListSearchWithContainer.flush();

        await flushPromises();

        // check if search results are visible and types are hidden
        const searchResults = wrapper.find('.sw-search-bar__results');
        typesContainer = wrapper.find('.sw-search-bar__types_container--v2');

        expect(searchResults.exists()).toBe(true);
        expect(typesContainer.exists()).toBe(false);

        // check if search result is empty
        expect(searchResults.find('.sw-search-bar__results-empty-message').exists()).toBe(true);
    });

    it('should not modify search term in $route watcher when focus is on input', async () => {
        wrapper = await createWrapper({
            initialSearchType: 'product',
        });

        // open search
        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        const route = {
            query: {
                term: 'Foo product',
            },
        };

        wrapper.vm.$options.watch.$route.call(wrapper.vm, route);

        expect(wrapper.vm.searchTerm).toBe('');
    });

    it('should modify search term in $route watcher when focus is not on input', async () => {
        wrapper = await createWrapper({
            initialSearchType: 'product',
        });

        const route = {
            query: {
                term: 'Foo product',
            },
        };

        wrapper.vm.$options.watch.$route.call(wrapper.vm, route);

        expect(wrapper.vm.searchTerm).toBe('Foo product');
    });

    it('should search with repository when no service is set in searchTypeService', async () => {
        wrapper = await createWrapper(
            {
                initialSearchType: 'product',
            },
            {
                product: {
                    entityName: 'product',
                    placeholderSnippet: 'sw-product.general.placeholderSearchBar',
                    listingRoute: 'sw.product.index',
                },
                category: {
                    entityName: 'category',
                    placeholderSnippet: 'sw-category.general.placeholderSearchBar',
                    listingRoute: 'sw.category.index',
                },
                customer: {
                    entityName: 'customer',
                    placeholderSnippet: 'sw-customer.general.placeholderSearchBar',
                    listingRoute: 'sw.customer.index',
                },
                order: {
                    entityName: 'order',
                    placeholderSnippet: 'sw-order.general.placeholderSearchBar',
                    listingRoute: 'sw.order.index',
                },
                media: {
                    entityName: 'media',
                    placeholderSnippet: 'sw-media.general.placeholderSearchBar',
                    listingRoute: 'sw.media.index',
                },
            },
        );

        const searchInput = wrapper.find('.sw-search-bar__input');

        // open search
        await searchInput.trigger('focus');
        await searchInput.setValue('#');

        // set categories as active type
        const typeItems = wrapper.findAll('.sw-search-bar__types_container--v2 .sw-search-bar__type-item');
        const secondTypeItem = typeItems.at(1);
        await secondTypeItem.trigger('click');

        // open search again
        await searchInput.trigger('focus');
        await searchInput.setValue('#');

        // check if new type is set
        const activeType = wrapper.find('.sw-search-bar__field .sw-search-bar__type--v2');
        expect(activeType.text()).toBe('global.entities.category');

        // type search value
        await searchInput.setValue('shorts');
        await flushPromises();

        const debouncedDoListSearchWithContainer = swSearchBarComponent.methods.doListSearchWithContainer;
        await debouncedDoListSearchWithContainer.flush();

        await flushPromises();

        // Make sure only repository method was called
        expect(spyLoadTypeSearchResults).toHaveBeenCalledTimes(1);
        expect(spyLoadTypeSearchResultsByService).toHaveBeenCalledTimes(0);

        // Verify result was applied correctly from repository
        expect(wrapper.vm.results).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    total: 2,
                    entities: expect.arrayContaining([
                        expect.objectContaining({
                            name: 'Home',
                            id: '12345',
                        }),
                        expect.objectContaining({
                            name: 'Electronics',
                            id: '55523',
                        }),
                    ]),
                    entity: 'category',
                }),
            ]),
        );
    });

    it('should show module filters container when clicking on type dropdown', async () => {
        searchTypeServiceTypes.all = {
            entityName: '',
            placeholderSnippet: '',
            listingRoute: '',
        };
        wrapper = await createWrapper();

        const searchInput = wrapper.find('.sw-search-bar__type--v2');
        await searchInput.trigger('click');

        // check if search results are hidden and types container are visible
        const moduleFiltersContainer = wrapper.find('.sw-search-bar__types_module-filters-container');
        const typesContainer = wrapper.find('.sw-search-bar__types_container');

        expect(moduleFiltersContainer.exists()).toBe(true);
        expect(typesContainer.exists()).toBe(false);
    });

    it('should change search bar type when selecting module filters from type dropdown', async () => {
        wrapper = await createWrapper(
            {
                initialSearchType: '',
            },
            {
                all: {
                    entityName: '',
                    placeholderSnippet: '',
                    listingRoute: '',
                },
                ...searchTypeServiceTypes,
            },
        );

        const moduleFilterSelect = wrapper.find('.sw-search-bar__type--v2');
        await moduleFilterSelect.trigger('click');

        const moduleFilterItems = wrapper.findAll('.sw-search-bar__type-item');
        await moduleFilterItems.at(1).trigger('click');

        expect(moduleFilterSelect.text()).toBe('global.entities.product');
    });

    it('should search with repository after selecting module filter', async () => {
        wrapper = await createWrapper(
            {
                initialSearchType: 'product',
            },
            {
                all: {
                    entityName: '',
                    placeholderSnippet: '',
                    listingRoute: '',
                },
                product: {
                    entityName: 'product',
                    placeholderSnippet: 'sw-product.general.placeholderSearchBar',
                    listingRoute: 'sw.product.index',
                },
                category: {
                    entityName: 'category',
                    placeholderSnippet: 'sw-category.general.placeholderSearchBar',
                    listingRoute: 'sw.category.index',
                },
                customer: {
                    entityName: 'customer',
                    placeholderSnippet: 'sw-customer.general.placeholderSearchBar',
                    listingRoute: 'sw.customer.index',
                },
                order: {
                    entityName: 'order',
                    placeholderSnippet: 'sw-order.general.placeholderSearchBar',
                    listingRoute: 'sw.order.index',
                },
                media: {
                    entityName: 'media',
                    placeholderSnippet: 'sw-media.general.placeholderSearchBar',
                    listingRoute: 'sw.media.index',
                },
            },
        );

        const moduleFilterSelect = wrapper.find('.sw-search-bar__type--v2');
        await moduleFilterSelect.trigger('click');

        const moduleFilterItems = wrapper.findAll('.sw-search-bar__type-item');
        await moduleFilterItems.at(2).trigger('click');

        // open search again
        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        // check if new type is set
        const activeType = wrapper.find('.sw-search-bar__field .sw-search-bar__type--v2');
        expect(activeType.text()).toBe('global.entities.category');

        // type search value
        await searchInput.setValue('shorts');
        await flushPromises();

        const debouncedDoListSearchWithContainer = swSearchBarComponent.methods.doListSearchWithContainer;
        await debouncedDoListSearchWithContainer.flush();

        await flushPromises();

        // Make sure only repository method was called
        expect(spyLoadTypeSearchResults).toHaveBeenCalledTimes(1);
        expect(spyLoadTypeSearchResultsByService).toHaveBeenCalledTimes(0);

        // Verify result was applied correctly from repository
        expect(wrapper.vm.results).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    total: 2,
                    entities: expect.arrayContaining([
                        expect.objectContaining({
                            name: 'Home',
                            id: '12345',
                        }),
                        expect.objectContaining({
                            name: 'Electronics',
                            id: '55523',
                        }),
                    ]),
                    entity: 'category',
                }),
            ]),
        );
    });

    it('should search for module and action with a default module', async () => {
        register('sw-order', {
            title: 'Orders',
            color: '#A092F0',
            icon: 'regular-shopping-bag',
            entity: 'order',

            routes: {
                index: {
                    component: 'sw-order-list',
                    path: 'index',
                    meta: {
                        privilege: 'order.viewer',
                    },
                },

                create: {
                    component: 'sw-order-create',
                    path: 'create',
                    meta: {
                        privilege: 'order.creator',
                    },
                },
            },
        });

        wrapper = await createWrapper(
            {
                initialSearchType: '',
                initialSearch: '',
            },
            searchTypeServiceTypes,
            [
                'order.viewer',
                'order.creator',
            ],
        );

        // open search
        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        await searchInput.setValue('ord');
        expect(searchInput.element.value).toBe('ord');

        await flushPromises();

        const doGlobalSearch = swSearchBarComponent.methods.doGlobalSearch;
        await doGlobalSearch.flush();

        await flushPromises();

        const module = wrapper.vm.results[0];

        expect(module.entity).toBe('module');
        expect(module.total).toBe(2);

        expect(module.entities[0].route.name).toBe('sw.order.index');
        expect(module.entities[1].route.name).toBe('sw.order.create');
    });

    it('should search for module and action with config module', async () => {
        register('sw-category', {
            title: 'Categories',
            color: '#57D9A3',
            icon: 'regular-products',
            entity: 'category',

            searchMatcher: (regex, labelType, manifest) => {
                const match = labelType.toLowerCase().match(regex);

                if (!match) {
                    return false;
                }

                return [
                    {
                        icon: manifest.icon,
                        color: manifest.color,
                        label: labelType,
                        entity: manifest.entity,
                        route: manifest.routes.index,
                    },
                    {
                        icon: manifest.icon,
                        color: manifest.color,
                        route: {
                            name: 'sw.category.landingPageDetail',
                            params: { id: 'create' },
                        },
                        entity: 'landing_page',
                        privilege: manifest.routes.landingPageDetail?.meta.privilege,
                        action: true,
                    },
                ];
            },

            routes: {
                index: {
                    components: 'sw-category-detail',
                    meta: {
                        privilege: 'category.viewer',
                    },
                },

                landingPageDetail: {
                    component: 'sw-category-detail',
                    meta: {
                        privilege: 'category.viewer',
                    },
                },
            },
        });

        wrapper = await createWrapper(
            {
                initialSearchType: '',
                initialSearch: '',
            },
            searchTypeServiceTypes,
            ['category.viewer'],
        );

        // open search
        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        await searchInput.setValue('cat');
        expect(searchInput.element.value).toBe('cat');

        await flushPromises();

        const doGlobalSearch = swSearchBarComponent.methods.doGlobalSearch;
        await doGlobalSearch.flush();

        await flushPromises();

        const module = wrapper.vm.results[0];

        expect(module.entity).toBe('module');
        expect(module.total).toBe(2);

        expect(module.entities[0].route.name).toBe('sw.category.index');
        expect(module.entities[1].route.name).toBe('sw.category.landingPageDetail');
        expect(module.entities[1].route.params).toEqual({ id: 'create' });
    });

    it('should search for module and action with sales channel', async () => {
        wrapper = await createWrapper(
            {
                initialSearchType: '',
                initialSearch: '',
            },
            searchTypeServiceTypes,
            [
                'sales_channel.viewer',
                'sales_channel.creator',
            ],
        );

        // open search
        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        await searchInput.setValue('sto');
        expect(searchInput.element.value).toBe('sto');

        await flushPromises();

        const doGlobalSearch = swSearchBarComponent.methods.doGlobalSearch;
        await doGlobalSearch.flush();

        await flushPromises();

        const searchBarItem = wrapper.findComponent('.sw-search-bar-item');
        expect(searchBarItem.props().type).toBe('module');

        const module = wrapper.vm.results[0];

        expect(module.entity).toBe('module');
        expect(module.total).toBe(1);
        expect(module.entities[0].label).toBe('Storefront');
        expect(module.entities[0].route.name).toBe('sw.sales.channel.create');
    });

    [
        'order',
        'product',
        'customer',
    ].forEach((term) => {
        it(`should search for module and action with the term "${term}" when the ACL privilege is missing`, async () => {
            register(`sw-${term}`, {
                title: `${term}s`,
                color: '#A092F0',
                icon: 'regular-shopping-bag',
                entity: term,

                routes: {
                    index: {
                        component: `sw-${term}-list`,
                        path: 'index',
                        meta: {
                            privilege: `${term}.viewer`,
                        },
                    },

                    create: {
                        component: `sw-${term}-create`,
                        path: 'create',
                        meta: {
                            privilege: `${term}.creator`,
                        },
                    },
                },
            });

            wrapper = await createWrapper({
                initialSearchType: '',
                initialSearch: '',
            });

            // open search
            const searchInput = wrapper.find('.sw-search-bar__input');
            await searchInput.trigger('focus');

            await searchInput.setValue(term);
            expect(searchInput.element.value).toBe(term);

            await flushPromises();

            const doGlobalSearch = swSearchBarComponent.methods.doGlobalSearch;
            await doGlobalSearch.flush();

            await flushPromises();

            const results = wrapper.vm.results.filter((item) => {
                return item.entity === 'module';
            });

            expect(results).toEqual([]);
        });
    });

    [
        'order',
        'product',
        'customer',
    ].forEach((term) => {
        it(`should search for module and action with the term "${term}" when the ACL is can view`, async () => {
            register(`sw-${term}`, {
                title: `${term}s`,
                color: '#A092F0',
                icon: 'regular-shopping-bag',
                entity: term,

                routes: {
                    index: {
                        component: `sw-${term}-list`,
                        path: 'index',
                        meta: {
                            privilege: `${term}.viewer`,
                        },
                    },

                    create: {
                        component: `sw-${term}-create`,
                        path: 'create',
                        meta: {
                            privilege: `${term}.creator`,
                        },
                    },
                },
            });

            wrapper = await createWrapper(
                {
                    initialSearchType: '',
                    initialSearch: '',
                },
                searchTypeServiceTypes,
                [`${term}.viewer`],
            );

            // open search
            const searchInput = wrapper.find('.sw-search-bar__input');
            await searchInput.trigger('focus');

            await searchInput.setValue(term);
            expect(searchInput.element.value).toBe(term);

            await flushPromises();

            const doGlobalSearch = swSearchBarComponent.methods.doGlobalSearch;
            await doGlobalSearch.flush();

            await flushPromises();

            const module = wrapper.vm.results[0];

            expect(module.entity).toBe('module');
            expect(module.total).toBe(1);

            expect(module.entities[0].icon).toBe('regular-shopping-bag');
            expect(module.entities[0].color).toBe('#A092F0');
            expect(module.entities[0].label).toBe(`${term}s`);
            expect(module.entities[0].entity).toBe(term);
            expect(module.entities[0].route.name).toBe(`sw.${term}.index`);
            expect(module.entities[0].privilege).toBe(`${term}.viewer`);
        });
    });

    it('should always show search result panel correctly', async () => {
        wrapper = await createWrapper(
            {
                initialSearchType: 'product',
            },
            {
                all: {
                    entityName: '',
                    placeholderSnippet: '',
                    listingRoute: '',
                },
                product: {
                    entityName: 'product',
                    placeholderSnippet: 'sw-product.general.placeholderSearchBar',
                    listingRoute: 'sw.product.index',
                },
                category: {
                    entityName: 'category',
                    placeholderSnippet: 'sw-category.general.placeholderSearchBar',
                    listingRoute: 'sw.category.index',
                },
                customer: {
                    entityName: 'customer',
                    placeholderSnippet: 'sw-customer.general.placeholderSearchBar',
                    listingRoute: 'sw.customer.index',
                },
                order: {
                    entityName: 'order',
                    placeholderSnippet: 'sw-order.general.placeholderSearchBar',
                    listingRoute: 'sw.order.index',
                },
                media: {
                    entityName: 'media',
                    placeholderSnippet: 'sw-media.general.placeholderSearchBar',
                    listingRoute: 'sw.media.index',
                },
            },
        );

        const moduleFilterSelect = wrapper.find('.sw-search-bar__type--v2');
        await moduleFilterSelect.trigger('click');

        const moduleFilterItems = wrapper.findAll('.sw-search-bar__type-item');
        await moduleFilterItems.at(2).trigger('click');

        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');
        await searchInput.setValue('#');

        await searchInput.setValue('#');

        const moduleFilterFooter = wrapper.find('.sw-search-bar__types_container--v2 .sw-search-bar__footer');
        expect(moduleFilterFooter.exists()).toBeTruthy();

        await searchInput.setValue('home');
        await flushPromises();

        const debouncedDoListSearchWithContainer = swSearchBarComponent.methods.doListSearchWithContainer;
        await debouncedDoListSearchWithContainer.flush();

        await flushPromises();
        expect(spyLoadTypeSearchResults).toHaveBeenCalledTimes(1);
        expect(spyLoadTypeSearchResultsByService).toHaveBeenCalledTimes(0);

        const resultsFooter = wrapper.find('.sw-search-bar__results--v2 .sw-search-bar__footer');
        expect(resultsFooter.exists()).toBeTruthy();
    });

    it('should add the search query score to the criteria when search with repository', async () => {
        wrapper = await createWrapper(
            {
                initialSearchType: 'product',
            },
            {
                foo: {
                    entityName: 'foo',
                    placeholderSnippet: 'sw-foo.general.placeholderSearchBar',
                    listingRoute: 'sw.foo.index',
                },
            },
        );

        const searchInput = wrapper.find('.sw-search-bar__input');

        // open search
        await searchInput.trigger('focus');

        // set categories as active type
        const moduleFilterSelect = wrapper.find('.sw-search-bar__type--v2');
        await moduleFilterSelect.trigger('click');

        const moduleFilterItems = wrapper.findAll('.sw-search-bar__type-item');
        await moduleFilterItems.at(0).trigger('click');

        // open search again
        await searchInput.trigger('focus');

        // type search value
        await searchInput.setValue('shorts');
        await flushPromises();

        const debouncedDoListSearchWithContainer = swSearchBarComponent.methods.doListSearchWithContainer;
        await debouncedDoListSearchWithContainer.flush();

        await flushPromises();

        // Verify result was applied correctly from repository
        expect(wrapper.vm.results).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    total: 1,
                    entities: expect.arrayContaining([
                        expect.objectContaining({
                            name: 'Baz',
                            id: '12345',
                        }),
                    ]),
                    entity: 'foo',
                }),
            ]),
        );
    });

    it('should not build the search query score for the criteria when search with repository with search ranking field is null', async () => {
        wrapper = await createWrapper(
            {
                initialSearchType: 'product',
            },
            {
                foo: {
                    entityName: 'foo',
                    placeholderSnippet: 'sw-foo.general.placeholderSearchBar',
                    listingRoute: 'sw.foo.index',
                },
            },
        );

        wrapper.vm.searchRankingService.buildSearchQueriesForEntity = jest.fn(() => {
            return new Criteria(1, 25);
        });
        wrapper.vm.searchRankingService.getSearchFieldsByEntity = jest.fn(() => {
            return {};
        });

        const searchInput = wrapper.find('.sw-search-bar__input');

        // open search
        await searchInput.trigger('focus');

        // set categories as active type
        const moduleFilterSelect = wrapper.find('.sw-search-bar__type--v2');
        await moduleFilterSelect.trigger('click');

        const moduleFilterItems = wrapper.findAll('.sw-search-bar__type-item');
        await moduleFilterItems.at(0).trigger('click');

        // open search again
        await searchInput.trigger('focus');

        // type search value
        await searchInput.setValue('shorts');
        await flushPromises();

        const debouncedDoListSearchWithContainer = swSearchBarComponent.methods.doListSearchWithContainer;
        await debouncedDoListSearchWithContainer.flush();

        expect(spyLoadTypeSearchResults).toHaveBeenCalledTimes(1);

        expect(wrapper.vm.searchRankingService.getSearchFieldsByEntity).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.searchRankingService.buildSearchQueriesForEntity).toHaveBeenCalledTimes(0);

        wrapper.vm.searchRankingService.buildSearchQueriesForEntity.mockRestore();
        wrapper.vm.searchRankingService.getSearchFieldsByEntity.mockRestore();

        await flushPromises();
    });

    it('should send search query scores for all entity when do global search', async () => {
        wrapper = await createWrapper(
            {
                initialSearchType: '',
                typeSearchAlwaysInContainer: false,
            },
            {
                all: {
                    entityName: '',
                    placeholderSnippet: '',
                    listingRoute: '',
                },
                foo: {
                    entityName: 'foo',
                    placeholderSnippet: 'sw-foo.general.placeholderSearchBar',
                    listingRoute: 'sw.foo.index',
                },
            },
        );

        const moduleFilterSelect = wrapper.find('.sw-search-bar__type--v2');

        expect(moduleFilterSelect.text()).toBe('global.entities.all');

        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        // type search value
        await searchInput.setValue('shorts');
        await flushPromises();

        const debouncedDoGlobalSearch = swSearchBarComponent.methods.doGlobalSearch;
        await debouncedDoGlobalSearch.flush();

        await flushPromises();

        expect(spyLoadResults).toHaveBeenCalledTimes(1);

        expect(wrapper.vm.results).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    total: 1,
                    entities: expect.arrayContaining([
                        expect.objectContaining({
                            name: 'Baz',
                            id: '12345',
                        }),
                    ]),
                    entity: 'foo',
                }),
            ]),
        );
    });

    it('should be able to turn on search preferences modal', async () => {
        wrapper = await createWrapper();

        await wrapper.setData({
            showSearchPreferencesModal: true,
        });

        expect(wrapper.find('sw-search-preferences-modal-stub').exists()).toBe(true);
    });

    it('should be able to turn off search preferences modal', async () => {
        wrapper = await createWrapper();

        await wrapper.setData({
            showSearchPreferencesModal: false,
        });

        expect(wrapper.find('sw-search-preferences-modal-stub').exists()).toBe(false);
    });

    it('should always show frequently used searches correctly', async () => {
        register('sw-dashboard', {
            title: 'sw-dashboard.general.mainMenuItemGeneral',
            color: '#6AD6F0',
            icon: 'regular-dashboard',
            name: 'dashboard',

            routes: {
                index: {
                    components: {
                        default: 'sw-dashboard-index',
                    },
                    path: 'index',
                },
            },
        });

        const customUserActivityApiMock = {
            getIncrement: jest.fn(() => Promise.resolve({ 'dashboard@sw.dashboard.index': { count: '1' } })),
            deleteActivityKeys: jest.fn(() => Promise.resolve({})),
        };

        const customRecentlySearchMock = {
            get: jest.fn(() => [
                {
                    entity: 'product',
                    id: 'dfe80a0ec016413e8e03fa2d85db3dea',
                    timestamp: Date.now(),
                },
            ]),
        };

        wrapper = await createWrapper({}, searchTypeServiceTypes, [], {
            userActivityApiService: customUserActivityApiMock,
            recentlySearchService: customRecentlySearchMock,
        });

        const moduleFilterSelect = wrapper.find('.sw-search-bar__type--v2');

        expect(moduleFilterSelect.text()).toBe('global.entities.all');

        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        await flushPromises();

        const resultsContent = wrapper.find('.sw-search-bar__results--v2 .sw-search-bar__results-wrapper-content');

        const headerEntity = resultsContent.find('.sw-search-bar__types-header-entity');
        const searchBarItem = resultsContent.findComponent('.sw-search-bar-item');

        expect(headerEntity.text()).toBe('global.entities.frequently_used');
        expect(searchBarItem.props().type).toBe('frequently_used');

        const frequentlyUsed = wrapper.vm.resultsSearchTrends.find((item) => item.entity === 'frequently_used');

        expect(frequentlyUsed.entity).toBe('frequently_used');
        expect(frequentlyUsed.total).toBe(1);

        const { route, ...frequently } = frequentlyUsed.entities[0];
        expect(frequently).toEqual({
            color: '#6AD6F0',
            icon: 'regular-dashboard',
            title: 'sw-dashboard.general.mainMenuItemGeneral',
            name: 'dashboard',
            privilege: undefined,
            action: false,
            display: true,
        });

        expect({
            routeName: route.name,
            routeKey: route.routeKey,
        }).toEqual({
            routeName: 'sw.dashboard.index',
            routeKey: 'index',
        });
    });

    it('should always show recently searches correctly', async () => {
        register('sw-dashboard', {
            title: 'sw-dashboard.general.mainMenuItemGeneral',
            color: '#6AD6F0',
            icon: 'regular-dashboard',
            name: 'dashboard',
            routes: {
                index: {
                    name: 'sw.dashboard.index',
                    components: {
                        default: 'sw-dashboard-index',
                    },
                    path: 'index',
                },
            },
        });

        const customUserActivityApiMock = {
            getIncrement: jest.fn(() => Promise.resolve({ 'dashboard@sw.dashboard.index': { count: '1' } })),
            deleteActivityKeys: jest.fn(() => Promise.resolve({})),
        };

        const customRecentlySearchMock = {
            get: jest.fn(() => [
                {
                    entity: 'product',
                    id: 'dfe80a0ec016413e8e03fa2d85db3dea',
                    timestamp: Date.now(),
                },
            ]),
        };

        wrapper = await createWrapper(
            {},
            searchTypeServiceTypes,
            [
                'product:read',
            ],
            {
                userActivityApiService: customUserActivityApiMock,
                recentlySearchService: customRecentlySearchMock,
            },
        );

        const moduleFilterSelect = wrapper.find('.sw-search-bar__type--v2');

        expect(moduleFilterSelect.text()).toBe('global.entities.all');

        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        await flushPromises();

        const resultsContent = wrapper.find('.sw-search-bar__results--v2 .sw-search-bar__results-wrapper-content');
        const lastColumn = resultsContent.findAll('.sw-search-bar__results-column').at(1);

        const headerEntity = lastColumn.find('.sw-search-bar__types-header-entity');
        const searchBarItem = lastColumn.findComponent('.sw-search-bar-item');

        expect(headerEntity.text()).toBe('global.entities.recently_searched');
        expect(searchBarItem.props().type).toBe('product');

        const recentlySearched = wrapper.vm.resultsSearchTrends.find((item) => item.entity === 'recently_searched');

        expect(recentlySearched.entity).toBe('recently_searched');
        expect(recentlySearched.total).toBe(1);

        expect(recentlySearched.entities[0]).toEqual({
            entity: 'product',
            item: {
                id: 'dfe80a0ec016413e8e03fa2d85db3dea',
                name: 'Lightweight Iron Tossed Cookie Salad',
            },
        });
    });

    it('should set current search type correctly', async () => {
        wrapper = await createWrapper({ initialSearchType: 'product' });

        expect(wrapper.vm.isComponentMounted).toBe(true);
        expect(wrapper.vm.currentSearchType).toBe('product');

        await wrapper.setData({ searchTerm: '' });
        wrapper.vm.resetSearchType();

        expect(wrapper.vm.isComponentMounted).toBe(false);
        expect(wrapper.vm.currentSearchType).toBeNull();
    });

    it('should search global with ES when adminEsEnable is true', async () => {
        Shopware.Context.app.adminEsEnable = true;
        wrapper = await createWrapper(
            {
                initialSearchType: '',
            },
            {
                all: {
                    entityName: '',
                    placeholderSnippet: '',
                    listingRoute: '',
                },
                foo: {
                    entityName: 'foo',
                    placeholderSnippet: 'sw-foo.general.placeholderSearchBar',
                    listingRoute: 'sw.foo.index',
                },
            },
        );

        const moduleFilterSelect = wrapper.find('.sw-search-bar__type--v2');

        expect(moduleFilterSelect.text()).toBe('global.entities.all');

        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        // type search value
        await searchInput.setValue('shorts');
        await flushPromises();

        const debouncedDoGlobalSearch = swSearchBarComponent.methods.doGlobalSearch;
        await debouncedDoGlobalSearch.flush();

        await flushPromises();

        expect(spyLoadResults).toHaveBeenCalled();

        expect(wrapper.vm.results).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    total: 1,
                    index: 'admin-es-foo-listing',
                    indexer: 'es-foo-listing',
                    entities: expect.arrayContaining([
                        expect.objectContaining({
                            name: 'ES Baz',
                            id: 'es-12345',
                        }),
                    ]),
                    entity: 'esFoo',
                }),
            ]),
        );
    });

    it('should search type with ES when adminEsEnable is true', async () => {
        Shopware.Context.app.adminEsEnable = true;
        wrapper = await createWrapper(
            {
                initialSearchType: '',
            },
            {
                all: {
                    entityName: '',
                    placeholderSnippet: '',
                    listingRoute: '',
                },
                esFoo: {
                    entityName: 'esFoo',
                    placeholderSnippet: 'sw-foo.general.placeholderSearchBar',
                    listingRoute: 'sw.foo.index',
                },
            },
        );

        const searchInput = wrapper.find('.sw-search-bar__input');

        // open search
        await searchInput.trigger('focus');
        await searchInput.setValue('#');

        // check if search results are hidden and types container are visible
        const typesContainer = wrapper.find('.sw-search-bar__types_container--v2');

        expect(typesContainer.exists()).toBe(true);

        // set foo as active type
        const typeItems = wrapper.findAll('.sw-search-bar__types_container--v2 .sw-search-bar__type-item');
        const secondTypeItem = typeItems.at(1);
        await secondTypeItem.trigger('click');

        const moduleFilterSelect = wrapper.find('.sw-search-bar__type--v2');

        expect(moduleFilterSelect.text()).toBe('global.entities.esFoo');

        // type search value
        await searchInput.setValue('shirt');
        await flushPromises();

        const debouncedDoListSearchWithContainer = swSearchBarComponent.methods.doListSearchWithContainer;
        await debouncedDoListSearchWithContainer.flush();
        expect(spyLoadTypeSearchResults).toHaveBeenCalledTimes(1);
        expect(spyLoadTypeSearchResultsByService).toHaveBeenCalledTimes(0);

        expect(wrapper.vm.results).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    total: 1,
                    entities: expect.arrayContaining([
                        expect.objectContaining({
                            name: 'ES Baz',
                            id: 'es-12345',
                        }),
                    ]),
                    entity: 'esFoo',
                }),
            ]),
        );
    });

    it('should render the correct fallback icon when no entity icon exists', async () => {
        register('sw-dashboard', {
            title: 'sw-dashboard.general.mainMenuItemGeneral',
            color: '#6AD6F0',
            icon: 'regular-dashboard',
            name: 'dashboard',

            routes: {
                index: {
                    components: {
                        default: 'sw-dashboard-index',
                    },
                    path: 'index',
                },
            },
        });

        const customUserActivityApiMock = {
            getIncrement: jest.fn(() => Promise.resolve({ 'dashboard@sw.dashboard.index': { count: '1' } })),
            deleteActivityKeys: jest.fn(() => Promise.resolve({})),
        };

        const customRecentlySearchMock = {
            get: jest.fn(() => [
                {
                    entity: 'product',
                    id: 'dfe80a0ec016413e8e03fa2d85db3dea',
                    timestamp: Date.now(),
                },
            ]),
        };

        wrapper = await createWrapper({ initialSearchType: 'product' }, searchTypeServiceTypes, [], {
            userActivityApiService: customUserActivityApiMock,
            recentlySearchService: customRecentlySearchMock,
        });

        // open search
        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        await searchInput.setValue('sto');
        expect(searchInput.element.value).toBe('sto');

        await flushPromises();

        const doGlobalSearch = swSearchBarComponent.methods.doGlobalSearch;
        await doGlobalSearch.flush();

        await flushPromises();

        // should use fallback icon
        const searchBarItem = wrapper.findComponent('.sw-search-bar-item');
        expect(searchBarItem.props('entity-icon-name')).toBeUndefined();
    });

    it('should render the icon from the entity icon', async () => {
        const term = 'customer';
        register(`sw-${term}`, {
            title: `${term}s`,
            color: '#A092F0',
            icon: 'regular-shopping-bag',
            entity: term,

            routes: {
                index: {
                    component: `sw-${term}-list`,
                    path: 'index',
                    meta: {
                        privilege: `${term}.viewer`,
                    },
                },

                create: {
                    component: `sw-${term}-create`,
                    path: 'create',
                    meta: {
                        privilege: `${term}.creator`,
                    },
                },
            },
        });

        wrapper = await createWrapper({
            initialSearchType: '',
            initialSearch: '',
        });

        await wrapper.find('.sw-search-bar__type--v2').trigger('click');

        await flushPromises();

        // should use correct icon
        expect(wrapper.find('.sw-search-bar__type-item .mt-icon.icon--regular-shopping-bag')).toBeDefined();
    });

    it('should not call the search service when the search term reaches the maximum length', async () => {
        wrapper = await createWrapper({
            initialSearchType: '',
            initialSearch: '',
        });

        const searchInput = wrapper.find('.sw-search-bar__input');
        await searchInput.trigger('focus');

        await searchInput.setValue('shorts'.repeat(100));

        await flushPromises();

        expect(spyLoadResults).toHaveBeenCalledTimes(0);
    });

    it('should return empty list if getIncrement fails initially', async () => {
        userActivityApiServiceMock.getIncrement.mockRejectedValue(new Error('API Error'));
        wrapper = await createWrapper({}, searchTypeServiceTypes, [], {
            userActivityApiService: userActivityApiServiceMock,
        });

        const result = await wrapper.vm.getFrequentlyUsedModules();

        expect(userActivityApiServiceMock.getIncrement).toHaveBeenCalledTimes(1);
        expect(result).toEqual({
            entity: 'frequently_used',
            total: 0,
            entities: [],
        });
        expect(userActivityApiServiceMock.deleteActivityKeys).not.toHaveBeenCalled();
    });

    it('should process modules correctly if all exist and getIncrement succeeds', async () => {
        const mockInitialResponse = {
            'moduleA@route1': { count: 5 },
            'moduleB@route2': { count: 3 },
        };
        userActivityApiServiceMock.getIncrement.mockResolvedValue(mockInitialResponse);
        wrapper = await createWrapper({}, searchTypeServiceTypes, [], {
            userActivityApiService: userActivityApiServiceMock,
        });

        wrapper.vm.getInfoModuleFrequentlyUsed = jest.fn((key) => {
            if (key === 'moduleA@route1') return { name: 'Module A', route: 'route1', key };
            if (key === 'moduleB@route2') return { name: 'Module B', route: 'route2', key };
            return {};
        });

        const result = await wrapper.vm.getFrequentlyUsedModules();

        expect(userActivityApiServiceMock.getIncrement).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.getInfoModuleFrequentlyUsed).toHaveBeenCalledWith('moduleA@route1');
        expect(wrapper.vm.getInfoModuleFrequentlyUsed).toHaveBeenCalledWith('moduleB@route2');
        expect(userActivityApiServiceMock.deleteActivityKeys).not.toHaveBeenCalled();
        expect(result.entities).toHaveLength(2);
        expect(result.entities).toEqual(
            expect.arrayContaining([
                { name: 'Module A', route: 'route1', key: 'moduleA@route1' },
                { name: 'Module B', route: 'route2', key: 'moduleB@route2' },
            ]),
        );
    });

    it('should delete non-existent keys, re-fetch, and process if delete succeeds', async () => {
        const mockInitialResponse = {
            'moduleValid@route1': { count: 5 },
            'moduleInvalid@routeNonExistent': { count: 3 },
            'moduleValid2@route2': { count: 2 },
        };
        const mockFreshResponse = {
            'moduleValid@route1': { count: 6 },
            'moduleValid2@route2': { count: 3 },
            'newModule@routeNew': { count: 1 },
        };

        userActivityApiServiceMock.getIncrement
            .mockResolvedValueOnce(mockInitialResponse)
            .mockResolvedValueOnce(mockFreshResponse);
        userActivityApiServiceMock.deleteActivityKeys.mockResolvedValue({});

        wrapper = await createWrapper({}, searchTypeServiceTypes, [], {
            userActivityApiService: userActivityApiServiceMock,
        });

        wrapper.vm.getInfoModuleFrequentlyUsed = jest.fn((key) => {
            if (key === 'moduleValid@route1') return { name: 'Module Valid', route: 'route1', key };
            if (key === 'moduleValid2@route2') return { name: 'Module Valid 2', route: 'route2', key };
            if (key === 'newModule@routeNew') return { name: 'New Module', route: 'routeNew', key };
            if (key === 'moduleInvalid@routeNonExistent') return {};
            return { name: `Fallback for ${key}`, key };
        });

        const result = await wrapper.vm.getFrequentlyUsedModules();

        expect(userActivityApiServiceMock.getIncrement).toHaveBeenCalledTimes(2);
        expect(userActivityApiServiceMock.deleteActivityKeys).toHaveBeenCalledTimes(1);
        expect(userActivityApiServiceMock.deleteActivityKeys).toHaveBeenCalledWith({
            keys: ['moduleInvalid@routeNonExistent'],
            cluster: wrapper.vm.currentUser.id,
        });
        expect(result.entities).toHaveLength(3);
        expect(result.entities).toEqual(
            expect.arrayContaining([
                { name: 'Module Valid', route: 'route1', key: 'moduleValid@route1' },
                { name: 'Module Valid 2', route: 'route2', key: 'moduleValid2@route2' },
                { name: 'New Module', route: 'routeNew', key: 'newModule@routeNew' },
            ]),
        );
        expect(wrapper.vm.getInfoModuleFrequentlyUsed).toHaveBeenCalledWith('moduleValid@route1');
        expect(wrapper.vm.getInfoModuleFrequentlyUsed).toHaveBeenCalledWith('moduleInvalid@routeNonExistent');
        expect(wrapper.vm.getInfoModuleFrequentlyUsed).toHaveBeenCalledWith('moduleValid2@route2');
        expect(wrapper.vm.getInfoModuleFrequentlyUsed).toHaveBeenCalledWith('newModule@routeNew');
    });

    it('should fallback to initially valid modules if deleteActivityKeys fails', async () => {
        const mockInitialResponse = {
            'moduleValid@route1': { count: 5 },
            'moduleInvalid@routeNonExistent': { count: 3 },
            'moduleValid2@route2': { count: 2 },
        };

        userActivityApiServiceMock.getIncrement.mockResolvedValueOnce(mockInitialResponse);
        userActivityApiServiceMock.deleteActivityKeys.mockRejectedValue(new Error('Deletion API Error'));

        wrapper = await createWrapper({}, searchTypeServiceTypes, [], {
            userActivityApiService: userActivityApiServiceMock,
        });

        wrapper.vm.getInfoModuleFrequentlyUsed = jest.fn((key) => {
            if (key === 'moduleValid@route1') return { name: 'Module Valid', route: 'route1', key };
            if (key === 'moduleValid2@route2') return { name: 'Module Valid 2', route: 'route2', key };
            if (key === 'moduleInvalid@routeNonExistent') return {};
            return {};
        });

        const result = await wrapper.vm.getFrequentlyUsedModules();

        expect(userActivityApiServiceMock.getIncrement).toHaveBeenCalledTimes(1);
        expect(userActivityApiServiceMock.deleteActivityKeys).toHaveBeenCalledTimes(1);
        expect(userActivityApiServiceMock.deleteActivityKeys).toHaveBeenCalledWith({
            keys: ['moduleInvalid@routeNonExistent'],
            cluster: wrapper.vm.currentUser.id,
        });
        expect(result.entities).toHaveLength(2);
        expect(result.entities).toEqual(
            expect.arrayContaining([
                { name: 'Module Valid', route: 'route1', key: 'moduleValid@route1' },
                { name: 'Module Valid 2', route: 'route2', key: 'moduleValid2@route2' },
            ]),
        );
    });

    it('should NOT delete non-existent keys if checkAndDelete flag is false', async () => {
        const mockInitialResponse = {
            'moduleValid@route1': { count: 5 },
            'moduleInvalid@routeNonExistent': { count: 3 },
            'moduleValid2@route2': { count: 2 },
        };

        userActivityApiServiceMock.getIncrement.mockResolvedValueOnce(mockInitialResponse);
        userActivityApiServiceMock.deleteActivityKeys.mockResolvedValue({});

        wrapper = await createWrapper({}, searchTypeServiceTypes, [], {
            userActivityApiService: userActivityApiServiceMock,
        });

        wrapper.vm.getInfoModuleFrequentlyUsed = jest.fn((key) => {
            if (key === 'moduleValid@route1') {
                return { name: 'Module Valid', route: 'route1', key };
            }

            if (key === 'moduleValid2@route2') {
                return { name: 'Module Valid 2', route: 'route2', key };
            }

            return {};
        });

        const result = await wrapper.vm.getFrequentlyUsedModules(false);

        expect(userActivityApiServiceMock.getIncrement).toHaveBeenCalledTimes(1);
        expect(userActivityApiServiceMock.deleteActivityKeys).not.toHaveBeenCalled();

        expect(result.entities).toHaveLength(2);
        expect(result.entities).toEqual(
            expect.arrayContaining([
                { name: 'Module Valid', route: 'route1', key: 'moduleValid@route1' },
                { name: 'Module Valid 2', route: 'route2', key: 'moduleValid2@route2' },
            ]),
        );

        expect(wrapper.vm.getInfoModuleFrequentlyUsed).toHaveBeenCalledWith('moduleValid@route1');
        expect(wrapper.vm.getInfoModuleFrequentlyUsed).toHaveBeenCalledWith('moduleInvalid@routeNonExistent');
        expect(wrapper.vm.getInfoModuleFrequentlyUsed).toHaveBeenCalledWith('moduleValid2@route2');
    });
});
