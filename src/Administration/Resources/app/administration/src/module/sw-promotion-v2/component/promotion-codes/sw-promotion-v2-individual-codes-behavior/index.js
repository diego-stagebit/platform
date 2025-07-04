/**
 * @sw-package checkout
 */
import template from './sw-promotion-v2-individual-codes-behavior.html.twig';
import './sw-promotion-v2-individual-codes-behavior.scss';

const { Criteria } = Shopware.Data;

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inject: [
        'acl',
        'repositoryFactory',
        'promotionCodeApiService',
    ],

    emits: [
        'delete-finish',
        'generate-finish',
    ],

    mixins: [
        'notification',
    ],

    props: {
        promotion: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            limit: 25,
            isGridLoading: false,
            isAdding: false,
            codeDeleteModal: false,
            codeBulkDeleteModal: false,
            generateCodesModal: false,
            addCodesModal: false,
            newCodeAmount: 10,
            currentSelection: [],
        };
    },

    computed: {
        promotionRepository() {
            return this.repositoryFactory.create('promotion');
        },

        customerRepository() {
            return this.repositoryFactory.create('customer');
        },

        deleteConfirmText() {
            if (!this.currentSelection) {
                return '';
            }

            return this.$tc(
                'sw-promotion-v2.detail.base.codes.individual.textDeleteConfirm',
                {
                    code: this.currentSelection[0].code || '',
                },
                this.currentSelection.length,
            );
        },

        codeColumns() {
            return [
                {
                    property: 'code',
                    label: this.$tc('sw-promotion-v2.detail.base.codes.individual.columnCode'),
                },
                {
                    property: 'payload',
                    label: this.$tc('sw-promotion-v2.detail.base.codes.individual.columnRedeemed'),
                },
                {
                    property: 'payload.customerName',
                    label: this.$tc('sw-promotion-v2.detail.base.codes.individual.columnCustomer'),
                },
                {
                    property: 'createdAt',
                    label: this.$tc('sw-promotion-v2.detail.base.codes.individual.columnCreatedAt'),
                },
            ];
        },

        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },
    },

    mounted() {
        this.mountedComponent();
    },

    methods: {
        mountedComponent() {
            this.loadIndividualCodesGrid();
        },

        onSearchTermChange(term) {
            this.promotion.individualCodes.criteria.setTerm(term);

            this.loadIndividualCodesGrid();
        },

        loadIndividualCodesGrid() {
            if (!this.$refs.individualCodesGrid) {
                return;
            }

            this.isGridLoading = true;
            this.promotion.individualCodes.criteria.addSorting(Criteria.naturalSorting('code'));

            this.$refs.individualCodesGrid.load().then(() => {
                this.isGridLoading = false;
            });
        },

        onSelectionChange(selection) {
            this.currentSelection = Object.values(selection);
        },

        onCodeSelectionChange(selection) {
            this.currentSelection = Object.values(selection);
        },

        onShowCodeDeleteModal(id) {
            this.codeDeleteModal = id;
        },

        onShowCodeBulkDeleteModal() {
            this.codeBulkDeleteModal = true;
        },

        onConfirmCodeDelete(id) {
            this.onCloseDeleteModal();
            this.$refs.individualCodesGrid.deleteItem(id).then(() => {
                this.loadIndividualCodesGrid();
            });
        },

        onConfirmCodeBulkDelete() {
            this.onCloseBulkDeleteModal();
            this.$refs.individualCodesGrid.deleteItems().then(() => {
                this.loadIndividualCodesGrid();
            });
        },

        onCloseDeleteModal() {
            this.codeDeleteModal = false;
        },

        onCloseBulkDeleteModal() {
            this.codeBulkDeleteModal = false;
        },

        onOpenGenerateCodesModal() {
            this.generateCodesModal = true;
        },

        onGenerateFinish() {
            this.onCloseGenerateCodesModal();
            this.$emit('generate-finish');
        },

        onCloseGenerateCodesModal() {
            this.generateCodesModal = false;
        },

        onOpenAddCodesModal() {
            this.addCodesModal = true;
        },

        onAddCodes() {
            this.isAdding = true;

            this.promotionCodeApiService
                .addIndividualCodes(this.promotion.id, this.newCodeAmount)
                .then(() => {
                    this.isAdding = false;
                    this.onCloseAddCodesModal();
                    this.$emit('generate-finish');
                })
                .catch((e) => {
                    this.isAdding = false;

                    e.response.data.errors.forEach((error) => {
                        let errorType;
                        switch (error.code) {
                            case 'PROMOTION__INDIVIDUAL_CODES_PATTERN_INSUFFICIENTLY_COMPLEX':
                                errorType = 'notComplexEnoughException';
                                break;
                            case 'PROMOTION__INDIVIDUAL_CODES_PATTERN_ALREADY_IN_USE':
                                errorType = 'alreadyInUseException';
                                break;
                            default:
                                errorType = 'unknownErrorCode';
                                break;
                        }

                        this.createNotificationError({
                            autoClose: false,
                            message: this.$tc(`sw-promotion-v2.detail.base.codes.individual.generateModal.${errorType}`),
                        });
                    });
                });
        },

        onCloseAddCodesModal() {
            this.addCodesModal = false;
        },

        routeToCustomer(redeemedCustomer) {
            return this.customerRepository
                .get(redeemedCustomer.customerId)
                .then((result) => {
                    if (result === null) {
                        this.createRoutingErrorNotification(redeemedCustomer.customerName);
                        return;
                    }

                    this.$router.push({
                        name: 'sw.customer.detail',
                        params: { id: result.id },
                    });
                })
                .catch(() => {
                    this.createRoutingErrorNotification(redeemedCustomer.customerName);
                });
        },

        createRoutingErrorNotification(name) {
            this.createNotificationError({
                message: this.$tc('sw-promotion-v2.detail.base.codes.individual.routingError', { name }, 0),
            });
        },
    },
};
