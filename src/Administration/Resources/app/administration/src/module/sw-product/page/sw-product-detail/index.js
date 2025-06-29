/*
 * @sw-package inventory
 */

import EntityValidationService from 'src/app/service/entity-validation.service';
import template from './sw-product-detail.html.twig';
import errorConfiguration from './error.cfg.json';
import './sw-product-detail.scss';
import '../../page/sw-product-detail/store';

const { Context, Mixin } = Shopware;
const { Criteria, ChangesetGenerator } = Shopware.Data;
const { cloneDeep } = Shopware.Utils.object;
const { mapPageErrors } = Shopware.Component.getComponentHelper();
const type = Shopware.Utils.types;

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inject: [
        'mediaService',
        'repositoryFactory',
        'numberRangeService',
        'seoUrlService',
        'acl',
        'systemConfigApiService',
        'entityValidationService',
    ],

    provide() {
        return {
            swProductDetailLoadAll: this.loadAll,
        };
    },

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('placeholder'),
    ],

    shortcuts: {
        'SYSTEMKEY+S': {
            active() {
                return this.acl.can('product.editor');
            },
            method: 'onSave',
        },
        ESCAPE: 'onCancel',
    },

    props: {
        productId: {
            type: String,
            required: false,
            default: null,
        },
        /* Product "types" provided by the split button for creating a new product through a router parameter */
        creationStates: {
            type: Array,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            productNumberPreview: '',
            isSaveSuccessful: false,
            cloning: false,
            defaultSalesChannelVisibility: 30,
            updateSeoPromises: [],
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(this.identifier),
        };
    },

    computed: {
        product() {
            return Shopware.Store.get('swProductDetail').product;
        },

        parentProduct() {
            return Shopware.Store.get('swProductDetail').parentProduct;
        },

        localMode() {
            return Shopware.Store.get('swProductDetail').localMode;
        },

        advancedModeSetting() {
            return Shopware.Store.get('swProductDetail').advancedModeSetting;
        },

        modeSettings() {
            return Shopware.Store.get('swProductDetail').modeSettings;
        },

        isLoading() {
            return Shopware.Store.get('swProductDetail').isLoading;
        },

        isChild() {
            return Shopware.Store.get('swProductDetail').isChild;
        },

        defaultCurrency() {
            return Shopware.Store.get('swProductDetail').defaultCurrency;
        },

        getDefaultFeatureSet() {
            return Shopware.Store.get('swProductDetail').getDefaultFeatureSet;
        },

        showModeSetting() {
            return Shopware.Store.get('swProductDetail').showModeSetting;
        },

        advanceModeEnabled() {
            return Shopware.Store.get('swProductDetail').advanceModeEnabled;
        },

        productStates() {
            return Shopware.Store.get('swProductDetail').productStates;
        },

        ...mapPageErrors(errorConfiguration),

        identifier() {
            return this.productTitle;
        },

        productTitle() {
            // when product is variant
            if (this.isChild && this.product) {
                return this.getInheritTitle();
            }

            if (!this.$i18n) {
                return '';
            }

            // return name
            return this.placeholder(this.product, 'name', this.$tc('sw-product.detail.textHeadline'));
        },

        productRepository() {
            return this.repositoryFactory.create('product');
        },

        syncRepository() {
            return this.repositoryFactory.create('product', null, {
                useSync: true,
            });
        },

        currencyRepository() {
            return this.repositoryFactory.create('currency');
        },

        taxRepository() {
            return this.repositoryFactory.create('tax');
        },

        customFieldSetRepository() {
            return this.repositoryFactory.create('custom_field_set');
        },

        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },

        productVisibilityRepository() {
            return this.repositoryFactory.create('product_visibility');
        },

        mediaRepository() {
            if (this.product && this.product.media) {
                return this.repositoryFactory.create(this.product.media.entity, this.product.media.source);
            }
            return null;
        },

        featureSetRepository() {
            return this.repositoryFactory.create('product_feature_set');
        },

        currentUser() {
            return Shopware.Store.get('session').currentUser;
        },

        userModeSettingsRepository() {
            return this.repositoryFactory.create('user_config');
        },

        userModeSettingsCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addFilter(Criteria.equals('key', 'mode.setting.advancedModeSettings'));
            criteria.addFilter(Criteria.equals('userId', this.currentUser && this.currentUser.id));

            return criteria;
        },

        productCriteria() {
            const criteria = new Criteria(1, 25);

            criteria.getAssociation('media').addSorting(Criteria.sort('position', 'ASC'));
            criteria.addAssociation('media.media');

            criteria.getAssociation('properties').addSorting(Criteria.sort('name', 'ASC', true));

            criteria.getAssociation('prices').addSorting(Criteria.sort('quantityStart', 'ASC', true));

            criteria.getAssociation('tags').addSorting(Criteria.sort('name', 'ASC'));

            criteria.getAssociation('seoUrls').addFilter(Criteria.equals('isCanonical', true));

            criteria
                .getAssociation('crossSellings')
                .addSorting(Criteria.sort('position', 'ASC'))
                .getAssociation('assignedProducts')
                .addSorting(Criteria.sort('position', 'ASC'))
                .addAssociation('product')
                .getAssociation('product')
                .addAssociation('options.group');

            criteria
                .addAssociation('cover.media')
                .addAssociation('categories')
                .addAssociation('visibilities.salesChannel')
                .addAssociation('options')
                .addAssociation('configuratorSettings.option')
                .addAssociation('unit')
                .addAssociation('productReviews')
                .addAssociation('seoUrls')
                .addAssociation('mainCategories')
                .addAssociation('options.group')
                .addAssociation('customFieldSets')
                .addAssociation('featureSet')
                .addAssociation('cmsPage')
                .addAssociation('featureSet')
                .addAssociation('downloads.media');

            criteria.getAssociation('manufacturer').addAssociation('media');

            return criteria;
        },

        customFieldSetCriteria() {
            const criteria = new Criteria(1, null);

            criteria.addFilter(Criteria.equals('relations.entityName', 'product'));
            criteria.addSorting(Criteria.sort('config.customFieldPosition', 'ASC', true));

            return criteria;
        },

        defaultFeatureSetCriteria() {
            const criteria = new Criteria(1, 1);

            criteria.addSorting(Criteria.sort('createdAt', 'ASC')).addFilter(
                Criteria.equalsAny('name', [
                    'Default',
                    'Standard',
                ]),
            );

            return criteria;
        },

        taxCriteria() {
            const criteria = new Criteria(1, 500);
            criteria.addSorting(Criteria.sort('position'));

            return criteria;
        },

        tooltipSave() {
            const systemKey = this.$device.getSystemKey();

            return {
                message: `${systemKey} + S`,
                appearance: 'light',
            };
        },

        tooltipCancel() {
            return {
                message: 'ESC',
                appearance: 'light',
            };
        },

        getModeSettingGeneralTab() {
            return [
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
                    label: 'sw-product.detailBase.cardTitleAssignment',
                    enabled: true,
                    name: 'general',
                },
                {
                    key: 'media',
                    label: 'sw-product.detailBase.cardTitleMedia',
                    enabled: true,
                    name: 'general',
                },
                {
                    key: 'labelling',
                    label: 'sw-product.detailBase.cardTitleSettings',
                    enabled: true,
                    name: 'general',
                },
            ];
        },

        getModeSettingSpecificationsTab() {
            return [
                {
                    key: 'measures_packaging',
                    label: 'sw-product.specifications.cardTitleMeasuresPackaging',
                    enabled: true,
                    name: 'specifications',
                },
                {
                    key: 'properties',
                    label: 'sw-product.specifications.cardTitleProperties',
                    enabled: true,
                    name: 'specifications',
                },
                {
                    key: 'essential_characteristics',
                    label: 'sw-product.specifications.cardTitleEssentialCharacteristics',
                    enabled: true,
                    name: 'specifications',
                },
                {
                    key: 'custom_fields',
                    label: 'sw-product.specifications.cardTitleCustomFields',
                    enabled: true,
                    name: 'specifications',
                },
            ];
        },

        showAdvanceModeSetting() {
            if (this.isChild) {
                return false;
            }

            const routes = [
                'sw.product.detail.base',
                'sw.product.detail.specifications',
            ];

            return routes.includes(this.$route.name);
        },

        cmsPageState() {
            return Shopware.Store.get('cmsPage');
        },

        currentPage() {
            return Shopware.Store.get('cmsPage').currentPage;
        },
    },

    watch: {
        productId() {
            this.createdComponent();
        },
    },

    created() {
        this.createdComponent();
    },

    beforeRouteLeave() {
        Shopware.Store.get('shopwareApps').selectedIds = [];
    },

    methods: {
        createdComponent() {
            Shopware.ExtensionAPI.publishData({
                id: 'sw-product-detail__product',
                path: 'product',
                scope: this,
            });

            Shopware.ExtensionAPI.publishData({
                id: 'sw-product-detail__cmsPage',
                path: 'currentPage',
                scope: this,
            });

            this.cmsPageState.resetCmsPageState();

            // when create
            if (!this.productId) {
                // set language to system language
                if (!Shopware.Store.get('context').isSystemDefaultLanguage) {
                    Shopware.Store.get('context').resetLanguageToDefault();
                }
            }

            // initialize default state
            this.initState();

            this.initAdvancedModeSettings();
        },

        initState() {
            Shopware.Store.get('swProductDetail').apiContext = Shopware.Context.api;

            // when product exists
            if (this.productId) {
                return this.loadState();
            }

            // When no product id exists init state and new product with the repositoryFactory
            return this.createState().then(() => {
                // create new product number
                this.numberRangeService.reserve('product', '', true).then((response) => {
                    this.productNumberPreview = response.number;
                    this.product.productNumber = response.number;
                });
            });
        },

        initAdvancedModeSettings() {
            Shopware.Store.get('swProductDetail').advancedModeSetting = this.getAdvancedModeDefaultSetting();

            this.getAdvancedModeSetting();
        },

        createUserModeSetting() {
            const newModeSettings = this.userModeSettingsRepository.create();
            newModeSettings.key = 'mode.setting.advancedModeSettings';
            newModeSettings.userId = this.currentUser && this.currentUser.id;
            return newModeSettings;
        },

        getAdvancedModeDefaultSetting() {
            const defaultSettings = this.createUserModeSetting();
            defaultSettings.value = {
                advancedMode: {
                    label: 'sw-product.general.textAdvancedMode',
                    enabled: true,
                },
                settings: [
                    ...this.getModeSettingGeneralTab,
                    ...this.getModeSettingSpecificationsTab,
                ],
            };
            return defaultSettings;
        },

        getAdvancedModeSetting() {
            return this.userModeSettingsRepository.search(this.userModeSettingsCriteria).then(async (items) => {
                if (!items.total) {
                    return;
                }

                const modeSettings = items.first();
                const defaultSettings = this.getAdvancedModeDefaultSetting().value.settings;

                modeSettings.value.settings = defaultSettings.reduce((accumulator, defaultEntry) => {
                    const foundEntry = modeSettings.value.settings.find((dbEntry) => dbEntry.key === defaultEntry.key);
                    accumulator.push(foundEntry || defaultEntry);

                    return accumulator;
                }, []);

                Shopware.Store.get('swProductDetail').advancedModeSetting = modeSettings;
                Shopware.Store.get('swProductDetail').modeSettings = this.changeModeSettings();

                await this.$nextTick();
            });
        },

        saveAdvancedMode() {
            Shopware.Store.get('swProductDetail').setLoading([
                'advancedMode',
                true,
            ]);
            this.userModeSettingsRepository
                .save(this.advancedModeSetting)
                .then(() => {
                    this.getAdvancedModeSetting().then(() => {
                        Shopware.Store.get('swProductDetail').setLoading([
                            'advancedMode',
                            false,
                        ]);
                    });
                })
                .catch(() => {
                    this.createNotificationError({
                        message: this.$tc('global.notification.unspecifiedSaveErrorMessage'),
                    });
                });
        },

        onChangeSetting() {
            Shopware.Store.get('swProductDetail').advancedModeSetting = this.advancedModeSetting;
            this.saveAdvancedMode();
        },

        changeModeSettings() {
            const enabledModeItems = this.advancedModeSetting.value.settings.filter((item) => item.enabled);
            if (!enabledModeItems.length) {
                return [];
            }

            return enabledModeItems.map((item) => item.key);
        },

        onChangeSettingItem() {
            Shopware.Store.get('swProductDetail').modeSettings = this.changeModeSettings();
            this.saveAdvancedMode();
        },

        loadState() {
            Shopware.Store.get('swProductDetail').localMode = false;
            Shopware.Store.get('shopwareApps').selectedIds = [
                this.productId,
            ];

            return this.loadAll();
        },

        loadAll() {
            return Promise.all([
                this.loadProduct(),
                this.loadCurrencies(),
                this.loadTaxes(),
                this.loadAttributeSet(),
            ]);
        },

        createState() {
            // set local mode
            Shopware.Store.get('swProductDetail').localMode = true;
            Shopware.Store.get('shopwareApps').selectedIds = [];

            Shopware.Store.get('swProductDetail').setLoading([
                'product',
                true,
            ]);

            // set product "type"
            Shopware.Store.get('swProductDetail').creationStates = this.creationStates;

            // create empty product
            Shopware.Store.get('swProductDetail').product = this.productRepository.create();

            // fill empty data
            this.product.active = true;
            this.product.taxId = null;

            this.product.metaTitle = '';
            this.product.additionalText = '';
            this.product.variantListingConfig = {};

            if (this.creationStates) {
                this.adjustProductAccordingToType();
            }

            return Promise.all([
                this.loadCurrencies(),
                this.loadTaxes(),
                this.loadAttributeSet(),
                this.loadDefaultFeatureSet(),
            ]).then(() => {
                // set default product price and empty purchase price
                this.product.price = [
                    {
                        currencyId: this.defaultCurrency.id,
                        net: null,
                        linked: true,
                        gross: null,
                    },
                ];

                this.product.purchasePrices = this.getDefaultPurchasePrices();

                // Set default tax rate / sales channels on creation
                if (this.product.isNew) {
                    this.getDefaultTaxRate().then((result) => {
                        this.product.taxId = result;
                    });

                    this.getDefaultSalesChannels().then((result) => {
                        if (type.isEmpty(result)) {
                            return;
                        }

                        this.product.active = result.defaultActive;

                        if (!result.defaultSalesChannelIds || result.defaultSalesChannelIds.length <= 0) {
                            return;
                        }

                        this.fetchSalesChannelByIds(result.defaultSalesChannelIds).then((salesChannels) => {
                            if (!salesChannels.length) {
                                return;
                            }

                            salesChannels.forEach((salesChannel) => {
                                const visibilities = this.createProductVisibilityEntity(
                                    result.defaultVisibilities,
                                    salesChannel,
                                );
                                this.product.visibilities.push(visibilities);
                            });
                        });
                    });
                }

                if (this.getDefaultFeatureSet?.length) {
                    this.product.featureSetId = this.getDefaultFeatureSet?.[0].id;
                }

                Shopware.Store.get('swProductDetail').setLoading([
                    'product',
                    false,
                ]);
            });
        },

        adjustProductAccordingToType() {
            if (this.creationStates.includes('is-download')) {
                this.product.maxPurchase = 1;
            }
        },

        loadProduct() {
            Shopware.Store.get('swProductDetail').setLoading([
                'product',
                true,
            ]);

            return this.productRepository
                .get(this.productId || this.product.id, Shopware.Context.api, this.productCriteria)
                .then(async (product) => {
                    if (!product.purchasePrices?.length > 0 && !product.parentId) {
                        if (!this.defaultCurrency?.id) {
                            await this.loadCurrencies();
                        }

                        product.purchasePrices = this.getDefaultPurchasePrices();
                    }

                    Shopware.Store.get('swProductDetail').product = product;

                    if (this.product.parentId) {
                        this.loadParentProduct();
                    } else {
                        Shopware.Store.get('swProductDetail').parentProduct = {};
                    }

                    Shopware.Store.get('swProductDetail').setLoading([
                        'product',
                        false,
                    ]);
                });
        },

        getDefaultPurchasePrices() {
            return [
                {
                    currencyId: this.defaultCurrency.id,
                    net: 0,
                    linked: true,
                    gross: 0,
                },
            ];
        },

        loadParentProduct() {
            Shopware.Store.get('swProductDetail').setLoading([
                'parentProduct',
                true,
            ]);

            return this.productRepository
                .get(this.product.parentId, Shopware.Context.api, this.productCriteria)
                .then((res) => {
                    Shopware.Store.get('swProductDetail').parentProduct = res;
                })
                .then(() => {
                    Shopware.Store.get('swProductDetail').setLoading([
                        'parentProduct',
                        false,
                    ]);
                });
        },

        loadCurrencies() {
            Shopware.Store.get('swProductDetail').setLoading([
                'currencies',
                true,
            ]);

            return this.currencyRepository
                .search(new Criteria(1, 500))
                .then((res) => {
                    Shopware.Store.get('swProductDetail').currencies = res;
                })
                .finally(() => {
                    Shopware.Store.get('swProductDetail').setLoading([
                        'currencies',
                        false,
                    ]);
                });
        },

        loadTaxes() {
            Shopware.Store.get('swProductDetail').setLoading([
                'taxes',
                true,
            ]);

            return this.taxRepository
                .search(this.taxCriteria)
                .then((res) => {
                    Shopware.Store.get('swProductDetail').setTaxes(res);
                })
                .finally(() => {
                    Shopware.Store.get('swProductDetail').setLoading([
                        'taxes',
                        false,
                    ]);
                });
        },

        getDefaultTaxRate() {
            return this.systemConfigApiService.getValues('core.tax').then((response) => {
                return response['core.tax.defaultTaxRate'] ?? null;
            });
        },

        loadAttributeSet() {
            Shopware.Store.get('swProductDetail').setLoading([
                'customFieldSets',
                true,
            ]);

            return this.customFieldSetRepository
                .search(this.customFieldSetCriteria)
                .then((res) => {
                    Shopware.Store.get('swProductDetail').customFieldSets = res;
                })
                .finally(() => {
                    Shopware.Store.get('swProductDetail').setLoading([
                        'customFieldSets',
                        false,
                    ]);
                });
        },

        loadDefaultFeatureSet() {
            Shopware.Store.get('swProductDetail').setLoading([
                'defaultFeatureSet',
                true,
            ]);

            return this.featureSetRepository
                .search(this.defaultFeatureSetCriteria)
                .then((res) => {
                    Shopware.Store.get('swProductDetail').setDefaultFeatureSet(res);
                })
                .finally(() => {
                    Shopware.Store.get('swProductDetail').setLoading([
                        'defaultFeatureSet',
                        false,
                    ]);
                });
        },

        getDefaultSalesChannels() {
            return this.systemConfigApiService.getValues('core.defaultSalesChannel').then((response) => {
                if (type.isEmpty(response)) {
                    return {};
                }

                return {
                    defaultSalesChannelIds: response?.['core.defaultSalesChannel.salesChannel'],
                    defaultVisibilities: response?.['core.defaultSalesChannel.visibility'],
                    defaultActive: !!response?.['core.defaultSalesChannel.active'],
                };
            });
        },

        fetchSalesChannelByIds(ids) {
            const criteria = new Criteria(1, 25);

            criteria.addFilter(Criteria.equalsAny('id', ids));

            return this.salesChannelRepository.search(criteria);
        },

        createProductVisibilityEntity(visibility, salesChannel) {
            const visibilities = this.productVisibilityRepository.create(Context.api);

            Object.assign(visibilities, {
                visibility: visibility[salesChannel.id] || this.defaultSalesChannelVisibility,
                productId: this.product.id,
                salesChannelId: salesChannel.id,
                salesChannel: salesChannel,
            });

            return visibilities;
        },

        abortOnLanguageChange() {
            return this.productRepository.hasChanges(this.product);
        },

        saveOnLanguageChange() {
            return this.onSave();
        },

        onChangeLanguage(languageId) {
            Shopware.Store.get('context').setApiLanguageId(languageId);
            this.initState();
        },

        saveFinish() {
            this.isSaveSuccessful = false;

            if (!this.productId) {
                this.$router.push({
                    name: 'sw.product.detail',
                    params: { id: this.product.id },
                });
            }
        },

        onSave() {
            if (!this.validateProductPurchase()) {
                this.createNotificationError({
                    message: this.$tc('sw-product.detail.errorMinMaxPurchase'),
                });

                return new Promise((resolve) => {
                    resolve();
                });
            }

            this.validateProductPrices();

            if (!this.productId) {
                if (this.productNumberPreview === this.product.productNumber) {
                    this.numberRangeService.reserve('product').then((response) => {
                        this.productNumberPreview = 'reserved';
                        this.product.productNumber = response.number;
                    });
                }
            }

            this.isSaveSuccessful = false;

            const pageOverrides = this.getCmsPageOverrides();

            if (type.isPlainObject(pageOverrides)) {
                this.product.slotConfig = cloneDeep(pageOverrides);
            }

            if (!this.entityValidationService.validate(this.product, this.customValidate)) {
                const titleSaveError = this.$tc('global.default.error');
                const messageSaveError = this.$tc('global.notification.notificationSaveErrorMessageRequiredFieldsInvalid');

                this.createNotificationError({
                    title: titleSaveError,
                    message: messageSaveError,
                });
                return Promise.resolve();
            }

            return this.saveProduct().then(this.onSaveFinished);
        },

        customValidate(errors, product) {
            if (this.productStates.includes('is-download')) {
                // custom download product validation
                if (product.downloads === undefined || product.downloads.length < 1) {
                    errors.push(EntityValidationService.createRequiredError('/0/downloads'));
                }
            }

            return errors;
        },

        validateProductPrices() {
            this.product.prices.forEach((advancedPrice) => {
                this.validatePrices('listPrice', advancedPrice.price);
            });
            this.validatePrices('listPrice', this.product.price);

            this.product.prices.forEach((advancedPrice) => {
                this.validatePrices('regulationPrice', advancedPrice.price);
            });
            this.validatePrices('regulationPrice', this.product.price);
        },

        validatePrices(priceLabel, prices) {
            if (!prices) {
                return;
            }

            prices.forEach((price) => {
                if (!price[priceLabel]) {
                    return;
                }

                if (!price[priceLabel].gross && !price[priceLabel].net) {
                    price[priceLabel] = null;
                    return;
                }

                if (!price[priceLabel].gross) {
                    price[priceLabel].gross = 0;
                    return;
                }

                if (!price[priceLabel].net) {
                    price[priceLabel].net = 0;
                }
            });
        },

        onSaveFinished(response) {
            if (this.updateSeoPromises.length === 0) {
                this.isSaveSuccessful = true;

                return;
            }

            if (response === 'empty' && this.updateSeoPromises.length > 0) {
                response = 'success';
            }

            Shopware.Store.get('swProductDetail').setLoading([
                'product',
                true,
            ]);

            Promise.all(this.updateSeoPromises)
                .then(() => {
                    Shopware.Utils.EventBus.emit('sw-product-detail-save-finish');
                })
                .then(() => {
                    switch (response) {
                        case 'empty': {
                            this.isSaveSuccessful = true;
                            Shopware.Store.get('error').resetApiErrors();
                            break;
                        }

                        case 'success': {
                            this.isSaveSuccessful = true;

                            break;
                        }

                        default: {
                            const errorCode = response?.response?.data?.errors?.[0]?.code;

                            if (errorCode === 'CONTENT__DUPLICATE_PRODUCT_NUMBER') {
                                const titleSaveError = this.$tc('global.default.error');
                                const messageSaveError = this.$t(
                                    'sw-product.notification.notificationSaveErrorProductNoAlreadyExists',
                                    {
                                        productNo: response.response.data.errors[0].meta.parameters.number,
                                    },
                                );

                                this.createNotificationError({
                                    title: titleSaveError,
                                    message: messageSaveError,
                                });
                                break;
                            }

                            const errorDetail = response?.response?.data?.errors?.[0]?.detail;
                            const titleSaveError = this.$tc('global.default.error');
                            const messageSaveError =
                                errorDetail ??
                                this.$tc('global.notification.notificationSaveErrorMessageRequiredFieldsInvalid');

                            this.createNotificationError({
                                title: titleSaveError,
                                message: messageSaveError,
                            });
                            break;
                        }
                    }
                })
                .catch(() => Promise.resolve())
                .finally(() => {
                    Shopware.Store.get('swProductDetail').setLoading([
                        'product',
                        false,
                    ]);

                    this.loadProduct();
                });
        },

        onCancel() {
            this.$router.push({ name: 'sw.product.index' });
        },

        saveProduct() {
            Shopware.Store.get('swProductDetail').setLoading([
                'product',
                true,
            ]);

            this.updateSeoPromises = [];

            if (Shopware.Store.list().includes('swSeoUrl')) {
                const seoUrls = Shopware.Store.get('swSeoUrl').newOrModifiedUrls;
                const defaultSeoUrl = Shopware.Store.get('swSeoUrl').defaultSeoUrl;

                if (seoUrls) {
                    seoUrls.forEach((seoUrl) => {
                        if (!seoUrl.seoPathInfo) {
                            seoUrl.seoPathInfo = defaultSeoUrl.seoPathInfo;
                            seoUrl.isModified = false;
                        } else {
                            seoUrl.isModified = true;
                        }

                        this.updateSeoPromises.push(this.seoUrlService.updateCanonicalUrl(seoUrl, seoUrl.languageId));
                    });
                }
            }

            if (this.product.media) {
                this.product.media.forEach((medium, index) => {
                    medium.position = index;
                });
            }

            return new Promise((resolve) => {
                // check if product exists
                if (!this.productRepository.hasChanges(this.product)) {
                    Shopware.Store.get('swProductDetail').setLoading([
                        'product',
                        false,
                    ]);
                    resolve('empty');
                    Shopware.Store.get('swProductDetail').setLoading([
                        'product',
                        false,
                    ]);
                    return;
                }

                // save product
                this.syncRepository
                    .save(this.product)
                    .then(() => {
                        this.loadAll().then(() => {
                            Shopware.Store.get('swProductDetail').setLoading([
                                'product',
                                false,
                            ]);

                            resolve('success');
                        });
                    })
                    .catch((response) => {
                        Shopware.Store.get('swProductDetail').setLoading([
                            'product',
                            false,
                        ]);
                        resolve(response);
                    });
            });
        },

        removeMediaItem(state, mediaId) {
            const media = this.product.media.find((mediaItem) => mediaItem.mediaId === mediaId);

            // remove cover id if mediaId matches
            if (this.product.coverId === media.id) {
                this.product.coverId = null;
            }

            this.product.media.remove(mediaId);
        },

        onCoverChange(mediaId) {
            if (!mediaId || mediaId.length < 0) {
                return;
            }

            const media = this.product.media.find((mediaItem) => mediaItem.mediaId === mediaId);

            if (media) {
                this.product.coverId = media.id;
            }
        },

        getInheritTitle() {
            if (
                this.product.hasOwnProperty('translated') &&
                this.product.translated.hasOwnProperty('name') &&
                this.product.translated.name !== null
            ) {
                return this.product.translated.name;
            }
            if (this.product.name !== null) {
                return this.product.name;
            }
            if (this.parentProduct && this.parentProduct.hasOwnProperty('translated')) {
                const pProduct = this.parentProduct;
                return pProduct.translated.hasOwnProperty('name') ? pProduct.translated.name : pProduct.name;
            }
            return '';
        },

        onDuplicate() {
            this.cloning = true;
        },

        onDuplicateFinish(duplicate) {
            this.cloning = false;
            this.$router.push({
                name: 'sw.product.detail',
                params: { id: duplicate.id },
            });
        },

        validateProductPurchase() {
            if (this.product.maxPurchase && this.product.minPurchase > this.product.maxPurchase) {
                return false;
            }

            return true;
        },

        getCmsPageOverrides() {
            if (this.currentPage === null) {
                return null;
            }

            this.deleteSpecifcKeys(this.currentPage.sections);

            const changesetGenerator = new ChangesetGenerator();
            const { changes } = changesetGenerator.generate(this.currentPage);

            const slotOverrides = {};
            if (changes === null) {
                return slotOverrides;
            }

            if (type.isArray(changes.sections)) {
                changes.sections.forEach((section) => {
                    if (type.isArray(section.blocks)) {
                        section.blocks.forEach((block) => {
                            if (type.isArray(block.slots)) {
                                block.slots.forEach((slot) => {
                                    slotOverrides[slot.id] = slot.config;
                                });
                            }
                        });
                    }
                });
            }

            return slotOverrides;
        },

        deleteSpecifcKeys(sections) {
            if (!sections) {
                return;
            }

            sections.forEach((section) => {
                if (!section.blocks) {
                    return;
                }

                section.blocks.forEach((block) => {
                    if (!block.slots) {
                        return;
                    }

                    block.slots.forEach((slot) => {
                        if (!slot.config) {
                            return;
                        }

                        Object.values(slot.config).forEach((configField) => {
                            if (configField.entity) {
                                delete configField.entity;
                            }
                            if (configField.hasOwnProperty('required')) {
                                delete configField.required;
                            }
                            if (configField.type) {
                                delete configField.type;
                            }
                        });
                    });
                });
            });
        },
    },
};
