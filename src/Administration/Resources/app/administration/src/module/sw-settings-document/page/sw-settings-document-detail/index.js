import template from './sw-settings-document-detail.html.twig';
import './sw-settings-document-detail.scss';

const { Component, Mixin } = Shopware;
const { get, cloneDeep } = Shopware.Utils.object;
const { Criteria, EntityCollection } = Shopware.Data;
const { mapPropertyErrors } = Component.getComponentHelper();

const documentTypesForDisplayNoteDelivery = [
    'storno',
    'credit_note',
    'invoice',
];

/**
 * @sw-package after-sales
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inject: [
        'repositoryFactory',
        'acl',
        'feature',
        'customFieldDataProviderService',
    ],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('placeholder'),
    ],

    shortcuts: {
        'SYSTEMKEY+S': 'onSave',
        ESCAPE: 'onCancel',
    },

    props: {
        documentConfigId: {
            type: String,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            documentConfig: {
                config: {
                    displayAdditionalNoteDelivery: false,
                    fileTypes: [],
                },
            },
            documentConfigSalesChannelOptionsCollection: [],
            documentConfigSalesChannels: [],
            isLoading: false,
            isSaveSuccessful: false,
            salesChannels: {},
            selectedType: {},
            isShowDisplayNoteDelivery: false,
            isShowDivergentDeliveryAddress: false,
            isShowCountriesSelect: false,
            generalFormFields: [
                {
                    name: 'pageOrientation',
                    type: 'radio',
                    config: {
                        componentName: 'sw-single-select',
                        labelProperty: 'name',
                        valueProperty: 'id',
                        options: [
                            { id: 'portrait', name: 'Portrait' },
                            { id: 'landscape', name: 'Landscape' },
                        ],
                        label: this.$tc('sw-settings-document.detail.labelPageOrientation'),
                    },
                },
                {
                    name: 'pageSize',
                    type: 'radio',
                    config: {
                        componentName: 'sw-single-select',
                        labelProperty: 'name',
                        valueProperty: 'id',
                        options: [
                            { id: 'a4', name: 'A4' },
                            { id: 'a5', name: 'A5' },
                            { id: 'legal', name: 'Legal' },
                            { id: 'letter', name: 'Letter' },
                        ],
                        label: this.$tc('sw-settings-document.detail.labelPageSize'),
                    },
                },
                {
                    name: 'itemsPerPage',
                    type: 'number',
                    config: {
                        type: 'number',
                        label: this.$tc('sw-settings-document.detail.labelItemsPerPage'),
                    },
                },
                {
                    name: 'fileTypes',
                    type: 'array',
                    config: {
                        componentName: 'sw-multi-select',
                        labelProperty: 'name',
                        valueProperty: 'id',
                        options: [
                            {
                                id: 'pdf',
                                name: 'PDF',
                            },
                            {
                                id: 'html',
                                name: 'HTML',
                            },
                        ],
                        label: this.$tc('sw-settings-document.detail.labelFileTypes'),
                    },
                },
                {
                    name: 'displayHeader',
                    type: 'bool',
                    config: {
                        type: 'checkbox',
                        label: this.$tc('sw-settings-document.detail.labelDisplayHeader'),
                    },
                },
                {
                    name: 'displayFooter',
                    type: 'bool',
                    config: {
                        type: 'checkbox',
                        label: this.$tc('sw-settings-document.detail.labelDisplayFooter'),
                    },
                },
                {
                    name: 'displayPageCount',
                    type: 'bool',
                    config: {
                        type: 'checkbox',
                        label: this.$tc('sw-settings-document.detail.labelDisplayPageCount'),
                    },
                },
                {
                    name: 'displayLineItems',
                    type: 'bool',
                    config: {
                        type: 'checkbox',
                        label: this.$tc('sw-settings-document.detail.labelDisplayLineItems'),
                    },
                },
                {
                    name: 'displayLineItemPosition',
                    type: 'bool',
                    config: {
                        type: 'checkbox',
                        label: this.$tc('sw-settings-document.detail.labelDisplayLineItemPosition'),
                    },
                },
                {
                    name: 'displayPrices',
                    type: 'bool',
                    config: {
                        type: 'checkbox',
                        label: this.$tc('sw-settings-document.detail.labelDisplayPrices'),
                    },
                },
                {
                    name: 'displayInCustomerAccount',
                    type: 'bool',
                    config: {
                        type: 'checkbox',
                        label: this.$tc('sw-settings-document.detail.labelDisplayDocumentInCustomerAccount'),
                        helpText: this.$tc('sw-settings-document.detail.helpTextDisplayDocumentInCustomerAccount'),
                    },
                },
            ],
            companyFormFields: [
                {
                    name: 'displayCompanyAddress',
                    type: 'bool',
                    config: {
                        type: 'checkbox',
                        label: this.$tc('sw-settings-document.detail.labelDisplayCompanyAddress'),
                        class: 'sw-settings-document-detail__company-address-checkbox',
                    },
                },
                {
                    name: 'companyStreet',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelCompanyStreet'),
                    },
                },
                {
                    name: 'companyZipcode',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelCompanyZipcode'),
                    },
                },
                {
                    name: 'companyCity',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelCompanyCity'),
                    },
                },
                {
                    name: 'companyCountryId',
                    type: 'sw-entity-single-select',
                    config: {
                        entity: 'country',
                        componentName: 'sw-entity-single-select',
                        label: this.$tc('sw-settings-document.detail.labelCompanyCountry'),
                    },
                },
                {
                    name: 'companyName',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelCompanyName'),
                    },
                },
                {
                    name: 'companyEmail',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelCompanyEmail'),
                    },
                },
                {
                    name: 'companyPhone',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelCompanyPhone'),
                    },
                },
                {
                    name: 'companyUrl',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelCompanyUrl'),
                    },
                },
                {
                    name: 'taxNumber',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelTaxNumber'),
                    },
                },
                {
                    name: 'taxOffice',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelTaxOffice'),
                    },
                },
                {
                    name: 'vatId',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelVatId'),
                    },
                },
                {
                    name: 'bankName',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelBankName'),
                    },
                },
                {
                    name: 'bankIban',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelBankIban'),
                    },
                },
                {
                    name: 'bankBic',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelBankBic'),
                    },
                },
                {
                    name: 'placeOfJurisdiction',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelPlaceOfJurisdiction'),
                    },
                },
                {
                    name: 'placeOfFulfillment',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelPlaceOfFulfillment'),
                    },
                },
                {
                    name: 'executiveDirector',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelExecutiveDirector'),
                    },
                },
                {
                    name: 'paymentDueDate',
                    type: 'text',
                    config: {
                        type: 'text',
                        label: this.$tc('sw-settings-document.detail.labelPaymentDueDate'),
                        helpText: this.$tc('sw-settings-document.detail.helpTextPaymentDueDate'),
                    },
                },
            ],
            alreadyAssignedSalesChannelIdsToType: [],
            typeIsLoading: false,
            customFieldSets: null,
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(this.identifier),
        };
    },

    computed: {
        identifier() {
            return get(this.documentConfig, 'name', '');
        },

        countryRepository() {
            return this.repositoryFactory.create('country');
        },

        documentBaseConfigCriteria() {
            const criteria = new Criteria(1, 25);

            criteria.addAssociation('documentType').getAssociation('salesChannels').addAssociation('salesChannel');

            return criteria;
        },

        documentBaseConfigRepository() {
            return this.repositoryFactory.create('document_base_config');
        },

        documentTypeRepository() {
            return this.repositoryFactory.create('document_type');
        },

        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        },

        documentBaseConfigSalesChannelRepository() {
            return this.repositoryFactory.create('document_base_config_sales_channel');
        },

        tooltipSave() {
            if (this.acl.can('document.editor')) {
                return {
                    message: `${this.$device.getSystemKey()} + S`,
                    appearance: 'light',
                };
            }
            return {
                message: this.$tc('sw-privileges.tooltip.warning'),
                disabled: this.acl.can('order.editor'),
                showOnDisabledElements: true,
            };
        },

        tooltipCancel() {
            return {
                message: 'ESC',
                appearance: 'light',
            };
        },

        documentBaseConfig() {
            return this.documentConfig;
        },
        ...mapPropertyErrors('documentBaseConfig', [
            'name',
            'documentTypeId',
        ]),

        showCustomFields() {
            return this.customFieldSets && this.customFieldSets.length > 0;
        },

        fileTypesSelected() {
            if (!this.documentConfig?.config?.fileTypes) {
                return [];
            }

            return this.documentConfig.config.fileTypes;
        },

        // We don't want to select ZUGFeRD as a type. "invoice" configuration is used instead (NEXT-40492)
        documentCriteria() {
            const criteria = new Criteria(1, 25);

            criteria.addFilter(Criteria.not('AND', [Criteria.prefix('technicalName', 'zugferd_')]));

            return criteria;
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        async createdComponent() {
            this.isLoading = true;
            await this.loadAvailableSalesChannel();
            if (this.documentConfigId) {
                await Promise.all([
                    this.loadEntityData(),
                    this.loadCustomFieldSets(),
                ]);
            } else {
                this.documentConfig = this.documentBaseConfigRepository.create();
                this.documentConfig.global = false;
                this.documentConfig.config = {};
            }

            this.isLoading = false;
        },

        async loadEntityData() {
            this.isLoading = true;
            const documentConfigId = this.documentConfigId || this.$route.params.id;

            this.documentConfig = await this.documentBaseConfigRepository.get(
                documentConfigId,
                Shopware.Context.api,
                this.documentBaseConfigCriteria,
            );
            if (!this.documentConfig) {
                this.documentConfig = {};
            }
            if (!this.documentConfig.config) {
                this.documentConfig.config = {};
            }

            await this.onChangeType(this.documentConfig.documentType);

            if (this.documentConfig.salesChannels === undefined) {
                this.documentConfig.salesChannels = [];
            }

            this.documentConfig.salesChannels.forEach((salesChannelAssoc) => {
                this.documentConfigSalesChannels.push(salesChannelAssoc.id);
            });

            this.isLoading = false;
        },

        loadCustomFieldSets() {
            this.customFieldDataProviderService.getCustomFieldSets('document_base_config').then((sets) => {
                this.customFieldSets = sets;
            });
        },

        async loadAvailableSalesChannel() {
            this.salesChannels = await this.salesChannelRepository.search(new Criteria(1, 500));
        },

        showOption(item) {
            return item.id !== this.documentConfig.id;
        },

        async onChangeType(documentType) {
            if (!documentType) {
                return;
            }

            this.typeIsLoading = true;

            this.documentConfig.documentType = documentType;
            this.documentConfigSalesChannels = [];
            this.isShowDisplayNoteDelivery = false;
            this.isShowDivergentDeliveryAddress = false;

            const documentTypeCurrent = cloneDeep(documentType);

            if (documentTypeCurrent.technicalName === 'invoice') {
                this.isShowDivergentDeliveryAddress = true;
            }

            if (documentTypesForDisplayNoteDelivery.includes(documentTypeCurrent.technicalName)) {
                this.isShowDisplayNoteDelivery = true;
            }

            this.createSalesChannelSelectOptions();
            const documentSalesChannelCriteria = new Criteria(1, 25);
            documentSalesChannelCriteria.addFilter(Criteria.equals('documentTypeId', documentType.id));

            this.documentBaseConfigSalesChannelRepository
                .search(documentSalesChannelCriteria)
                .then((responseSalesChannels) => {
                    this.alreadyAssignedSalesChannelIdsToType = [];
                    responseSalesChannels.forEach((salesChannel) => {
                        if (
                            salesChannel.salesChannelId !== null &&
                            salesChannel.documentBaseConfigId !== this.documentConfig.id
                        ) {
                            this.alreadyAssignedSalesChannelIdsToType.push(salesChannel.salesChannelId);
                        }
                    });
                    this.typeIsLoading = false;
                });
        },

        onChangeSalesChannel() {
            // check selected sales channels and associate to config
            if (this.documentConfigSalesChannels && this.documentConfigSalesChannels.length > 0) {
                this.documentConfigSalesChannels.forEach((salesChannelId) => {
                    if (!this.documentConfig.salesChannels.has(salesChannelId)) {
                        this.documentConfig.salesChannels.push(
                            this.documentConfigSalesChannelOptionsCollection.get(salesChannelId),
                        );
                    }
                });
            }

            this.documentConfig.salesChannels.forEach((salesChannelAssoc) => {
                if (!this.documentConfigSalesChannels.includes(salesChannelAssoc.id)) {
                    this.documentConfig.salesChannels.remove(salesChannelAssoc.id);
                }
            });
        },

        async saveFinish() {
            if (this.documentConfig.isNew()) {
                await this.$router.replace({
                    name: 'sw.settings.document.detail',
                    params: { id: this.documentConfig.id },
                });
            }
            this.loadEntityData();
        },

        onSave() {
            this.isSaveSuccessful = false;
            this.isLoading = true;
            this.onChangeSalesChannel();

            this.isSaveSuccessful = true;

            return this.documentBaseConfigRepository
                .save(this.documentConfig)
                .then(() => {
                    this.isLoading = false;
                    this.isSaveSuccessful = true;
                })
                .catch(() => {
                    this.isLoading = false;
                });
        },

        onCancel() {
            this.$router.push({ name: 'sw.settings.document.index' });
        },

        createSalesChannelSelectOptions() {
            this.documentConfigSalesChannelOptionsCollection = new EntityCollection(
                this.documentConfig.salesChannels.source,
                this.documentConfig.salesChannels.entity,
                Shopware.Context.api,
            );

            // Abort if no type is assigned yet
            if (!this.documentConfig.documentType) {
                return;
            }

            this.salesChannels.forEach((salesChannel) => {
                let salesChannelAlreadyAssigned = false;
                this.documentConfig.salesChannels.forEach((documentConfigSalesChannel) => {
                    if (documentConfigSalesChannel.salesChannelId === salesChannel.id) {
                        salesChannelAlreadyAssigned = true;
                        this.documentConfigSalesChannelOptionsCollection.push(documentConfigSalesChannel);
                    }
                });
                if (!salesChannelAlreadyAssigned) {
                    const option = this.documentBaseConfigSalesChannelRepository.create();
                    option.documentBaseConfigId = this.documentConfig.id;
                    option.documentTypeId = this.documentConfig.documentType.id;
                    option.salesChannelId = salesChannel.id;
                    option.salesChannel = salesChannel;
                    this.documentConfigSalesChannelOptionsCollection.push(option);
                }
            });
        },

        onRemoveDocumentType(type) {
            let fileTypes = this.documentConfig.config.fileTypes ?? [];
            if (fileTypes.length === 1) {
                return;
            }

            fileTypes = fileTypes.filter((fileType) => fileType !== type.id);
            this.documentConfig.config.fileTypes = fileTypes;
        },

        onAddDocumentType(type) {
            if (!this.documentConfig.config.fileTypes) {
                this.documentConfig.config.fileTypes = [];
            }

            this.documentConfig.config.fileTypes.push(type.id);
        },
    },
};
