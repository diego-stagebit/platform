import template from './sw-order-document-settings-storno-modal.html.twig';

/**
 * @sw-package checkout
 */

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    emits: [
        'loading-document',
        'loading-preview',
    ],

    props: {
        order: {
            type: Object,
            required: true,
        },
        currentDocumentType: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            documentConfig: {
                custom: {
                    stornoNumber: '',
                    invoiceNumber: '',
                },
                documentNumber: 0,
                documentComment: '',
                documentDate: '',
            },
        };
    },

    computed: {
        documentPreconditionsFulfilled() {
            return !!this.documentConfig.custom.invoiceNumber;
        },

        invoices() {
            return this.order.documents.filter((document) => {
                return (
                    document.documentType.technicalName === 'invoice' ||
                    document.documentType.technicalName === 'zugferd_embedded_invoice'
                );
            });
        },

        documentNumber: {
            get() {
                return String(this.documentConfig.documentNumber);
            },
            set(value) {
                this.documentConfig.documentNumber = Number(value);
            },
        },

        invoiceOptions() {
            return this.invoices.map((item, index) => {
                return {
                    id: index,
                    value: item.config.custom.invoiceNumber,
                    label: `${item.config.custom.invoiceNumber}`,
                };
            });
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.numberRangeService
                .reserve(`document_${this.currentDocumentType.technicalName}`, this.order.salesChannelId, true)
                .then((response) => {
                    this.documentConfig.documentNumber = response.number;
                    this.documentNumberPreview = this.documentConfig.documentNumber;
                    this.documentConfig.documentDate = new Date().toISOString();
                });
        },

        onCreateDocument(additionalAction = false) {
            this.$emit('loading-document');

            const selectedInvoice = this.invoices.filter((item) => {
                return item.config.custom.invoiceNumber === this.documentConfig.custom.invoiceNumber;
            })[0];

            if (this.documentNumberPreview === this.documentConfig.documentNumber) {
                this.numberRangeService
                    .reserve(`document_${this.currentDocumentType.technicalName}`, this.order.salesChannelId, false)
                    .then((response) => {
                        this.documentConfig.custom.stornoNumber = response.number;
                        if (response.number !== this.documentConfig.documentNumber) {
                            this.createNotificationInfo({
                                message: this.$tc('sw-order.documentCard.info.DOCUMENT__NUMBER_WAS_CHANGED'),
                            });
                        }
                        this.documentConfig.documentNumber = response.number;
                        this.callDocumentCreate(additionalAction, selectedInvoice.id);
                    });
            } else {
                this.documentConfig.custom.stornoNumber = this.documentConfig.documentNumber;
                this.callDocumentCreate(additionalAction, selectedInvoice.id);
            }
        },

        onPreview(fileType = 'pdf') {
            this.$emit('loading-preview');
            this.documentConfig.custom.stornoNumber = this.documentConfig.documentNumber;
            this.$super('onPreview', fileType);
        },
    },
};
