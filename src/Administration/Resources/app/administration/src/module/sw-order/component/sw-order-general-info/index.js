import './sw-order-general-info.scss';
import template from './sw-order-general-info.html.twig';

/**
 * @sw-package checkout
 */

const { Mixin, Store } = Shopware;
const { Criteria, EntityCollection } = Shopware.Data;
const { cloneDeep } = Shopware.Utils.object;

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    inject: {
        swOrderDetailOnSaveEdits: {
            from: 'swOrderDetailOnSaveEdits',
            default: null,
        },
        swOrderDetailAskAndSaveEdits: {
            from: 'swOrderDetailAskAndSaveEdits',
            default: () => true,
        },
        acl: {
            from: 'acl',
            default: null,
        },
        repositoryFactory: {
            from: 'repositoryFactory',
            default: null,
        },
        stateMachineService: {
            from: 'stateMachineService',
            default: null,
        },
        orderStateMachineService: {
            from: 'orderStateMachineService',
            default: null,
        },
        stateStyleDataProviderService: {
            from: 'stateStyleDataProviderService',
            default: null,
        },
    },

    emits: ['save-edits'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        order: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            currentActionName: null,
            currentStateType: null,
            deliveryStateOptions: [],
            liveOrder: null,
            modalConfirmed: false,
            orderStateOptions: [],
            paymentStateOptions: [],
            showModal: false,
            tagCollection: null,
        };
    },

    computed: {
        isLoading: () => Store.get('swOrderDetail').isLoading,

        savedSuccessful: () => Store.get('swOrderDetail').savedSuccessful,

        lastChangedUser() {
            if (this.liveOrder) {
                if (this.liveOrder.updatedBy) {
                    return this.liveOrder.updatedBy;
                }

                if (this.liveOrder.createdBy) {
                    return this.liveOrder.createdBy;
                }
            }

            return null;
        },

        lastChangedDateTime() {
            if (this.liveOrder) {
                if (this.liveOrder.updatedAt) {
                    return this.liveOrder.updatedAt;
                }

                if (this.liveOrder.createdAt) {
                    return this.liveOrder.createdAt;
                }
            }

            return null;
        },

        lastChangedByCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.setIds([this.order.id]);

            criteria.addAssociation('createdBy').addAssociation('updatedBy');

            return criteria;
        },

        orderRepository() {
            return this.repositoryFactory.create('order');
        },

        orderTagRepository() {
            return this.repositoryFactory.create(this.order.tags.entity, this.order.tags.source);
        },

        stateMachineStateRepository() {
            return this.repositoryFactory.create('state_machine_state');
        },

        stateMachineStateCriteria() {
            const criteria = new Criteria(1, null);
            criteria.addSorting({ field: 'name', order: 'ASC' });
            criteria.addAssociation('stateMachine');
            criteria.addFilter(
                Criteria.equalsAny('state_machine_state.stateMachine.technicalName', [
                    'order.state',
                    'order_transaction.state',
                    'order_delivery.state',
                ]),
            );

            return criteria;
        },

        transaction() {
            for (let i = 0; i < this.order.transactions.length; i += 1) {
                if (
                    ![
                        'cancelled',
                        'failed',
                    ].includes(this.order.transactions[i].stateMachineState.technicalName)
                ) {
                    return this.order.transactions[i];
                }
            }

            if (!Shopware.Feature.isActive('v6.8.0.0')) {
                return this.order.transactions.last();
            }

            return this.order.primaryOrderTransaction;
        },

        delivery() {
            if (!Shopware.Feature.isActive('v6.8.0.0')) {
                return this.order.deliveries[0];
            }

            return this.order.primaryOrderDelivery;
        },

        currencyFilter() {
            return Shopware.Filter.getByName('currency');
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },

        emailIdnFilter() {
            return Shopware.Filter.getByName('decode-idn-email');
        },
    },

    watch: {
        savedSuccessful() {
            if (this.savedSuccessful) {
                this.getLiveOrder();
            }
        },

        'order.id'() {
            this.createdComponent();
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            const tags = cloneDeep(this.order.tags);

            this.tagCollection = new EntityCollection(
                this.order.tags.source,
                this.order.tags.entity,
                Shopware.Context.api,
                null,
                tags,
                tags.length,
            );

            this.getLiveOrder();
            this.getTransitionOptions();
        },

        getLiveOrder() {
            this.orderRepository.search(this.lastChangedByCriteria, Shopware.Context.api).then((response) => {
                if (response && response.first()) {
                    this.liveOrder = response.first();
                }
            });
        },

        onTagAdd(item) {
            this.orderTagRepository.assign(item.id).then(() => {
                this.tagCollection.add(item);
            });
        },

        onTagRemove(item) {
            this.orderTagRepository.delete(item.id).then(() => {
                this.tagCollection.remove(item.id);
            });
        },

        getAllStates() {
            return this.stateMachineStateRepository.search(this.stateMachineStateCriteria);
        },

        buildTransitionOptions(stateMachineName, allTransitions, possibleTransitions) {
            const entries = allTransitions.filter((entry) => {
                return entry.stateMachine.technicalName === stateMachineName;
            });

            const options = entries.map((state, index) => {
                return {
                    stateName: state.technicalName,
                    id: index,
                    name: state.translated.name,
                    disabled: true,
                };
            });

            options.forEach((option) => {
                const transitionToState = possibleTransitions.filter((transition) => {
                    return transition.toStateName === option.stateName;
                });

                if (transitionToState.length >= 1) {
                    option.disabled = false;
                    option.id = transitionToState[0].actionName;
                }
            });

            return options;
        },

        backgroundStyle(stateType) {
            let technicalName;

            switch (stateType) {
                case 'order_transaction':
                    technicalName = this.transaction.stateMachineState.technicalName;
                    break;
                case 'order_delivery':
                    technicalName = this.delivery.stateMachineState.technicalName;
                    break;
                case 'order':
                    technicalName = this.order.stateMachineState.technicalName;
                    break;
                default:
                    return null;
            }

            return this.stateStyleDataProviderService.getStyle(`${stateType}.state`, technicalName).selectBackgroundStyle;
        },

        getTransitionOptions() {
            Store.get('swOrderDetail').setLoading([
                'states',
                true,
            ]);

            const statePromises = [
                this.stateMachineService.getState('order', this.order.id),
            ];

            if (this.transaction) {
                statePromises.push(this.stateMachineService.getState('order_transaction', this.transaction.id));
            }

            if (this.delivery) {
                statePromises.push(this.stateMachineService.getState('order_delivery', this.delivery.id));
            }

            return Promise.all([
                this.getAllStates(),
                ...statePromises,
            ])
                .then((data) => {
                    const allStates = data[0];
                    const orderState = data[1];

                    this.orderStateOptions = this.buildTransitionOptions(
                        'order.state',
                        allStates,
                        orderState.data.transitions,
                    );

                    if (this.transaction) {
                        const orderTransactionState = data[2];
                        this.paymentStateOptions = this.buildTransitionOptions(
                            'order_transaction.state',
                            allStates,
                            orderTransactionState.data.transitions,
                        );
                    }

                    if (this.delivery) {
                        const orderDeliveryState = data[3];
                        this.deliveryStateOptions = this.buildTransitionOptions(
                            'order_delivery.state',
                            allStates,
                            orderDeliveryState.data.transitions,
                        );
                    }

                    return Promise.resolve();
                })
                .finally(() => {
                    Store.get('swOrderDetail').setLoading([
                        'states',
                        false,
                    ]);
                });
        },

        async onStateSelected(stateType, actionName) {
            if (!stateType || !actionName) {
                this.createStateChangeErrorNotification(this.$tc('sw-order.stateCard.labelErrorNoAction'));
                return;
            }

            const proceed = await this.swOrderDetailAskAndSaveEdits();
            if (!proceed) {
                return;
            }

            if (!this.modalConfirmed) {
                this.currentActionName = actionName;
                this.currentStateType = stateType;

                this.showModal = true;

                return;
            }

            this.modalConfirmed = false;
        },

        onLeaveModalClose() {
            this.modalConfirmed = false;
            this.currentActionName = null;
            this.currentStateType = null;
            this.showModal = false;
        },

        onLeaveModalConfirm(docIds, sendMail = true) {
            this.showModal = false;

            let transition = null;

            switch (this.currentStateType) {
                case 'order_transaction':
                    transition = this.orderStateMachineService.transitionOrderTransactionState(
                        this.transaction.id,
                        this.currentActionName,
                        { documentIds: docIds, sendMail },
                    );
                    break;
                case 'order_delivery':
                    transition = this.orderStateMachineService.transitionOrderDeliveryState(
                        this.delivery.id,
                        this.currentActionName,
                        { documentIds: docIds, sendMail },
                    );
                    break;
                case 'order':
                    transition = this.orderStateMachineService.transitionOrderState(this.order.id, this.currentActionName, {
                        documentIds: docIds,
                        sendMail,
                    });
                    break;
                default:
                    this.createNotificationError({
                        message: this.$tc('sw-order.stateCard.labelErrorStateChange'),
                    });
                    return;
            }

            if (transition) {
                transition
                    .then(() => {
                        this.loadHistory();
                    })
                    .catch((error) => {
                        this.createStateChangeErrorNotification(error);
                    });
            }

            this.currentActionName = null;
            this.currentStateType = null;
        },

        loadHistory() {
            this.statesLoading = true;
            this.modalConfirmed = false;

            this.getTransitionOptions()
                .then(() => {
                    if (this.swOrderDetailOnSaveEdits) {
                        this.swOrderDetailOnSaveEdits();
                    } else {
                        this.$emit('save-edits');
                    }
                })
                .catch((error) => {
                    this.createNotificationError(error);
                });
        },

        createStateChangeErrorNotification(errorMessage) {
            this.createNotificationError({
                message: this.$tc('sw-order.stateCard.labelErrorStateChange') + errorMessage,
            });
        },
    },
};
