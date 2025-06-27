import template from './sw-order-state-change-modal-attach-documents.html.twig';

const { Component, Mixin } = Shopware;

Component.override('sw-order-state-change-modal-attach-documents', {
    template,

    inject: {
        cybersourceOrderService: 'cybersourceOrderService',
        stateMachineService: 'Shopware\\Core\\System\\StateMachine\\StateMachineService'
    },

    mixins: [Mixin.getByName('notification')],

    emits: ['on-confirm', 'on-cybersource-confirm', 'update:is-loading', 'modal-close'],

    props: {
        order: { type: Object, required: true },
        isLoading: { type: Boolean, required: true },
        selectedState: { type: String, required: false, default: '' },
        initialSelectedState: { type: String, required: false, default: '' },
        stateType: { type: String, required: false, default: 'order_transaction' },
        technicalName: { type: String, required: true },
        actionName: { type: String, required: true },
    },

    data() {
        return { sendMail: true };
    },

    methods: {
        async onConfirm() {
            const selectedState = this.actionName;
            const stateType = this.technicalName;

            if (!selectedState || selectedState.trim() === '') {
                this.createNotificationError({ message: 'Please select a valid state for the transition.' });
                this.$emit('modal-close');
                return;
            }

            const docIds = [];
            if (this.$refs.attachDocuments?.documents) {
                this.$refs.attachDocuments.documents.forEach((doc) => {
                    if (doc.attach) docIds.push(doc.id);
                });
            }

            if (stateType === 'order_transaction') {
                const transaction = this.order.transactions[0];
                if (!transaction) {
                    this.createNotificationError({ message: 'No transaction found.' });
                    this.$emit('modal-close');
                    return;
                }

                console.log("currentState:", stateType, "targetState:", selectedState);
                const paymentMethod = this.getPaymentMethod(transaction.paymentMethodId);
                if (paymentMethod?.handlerIdentifier === 'CyberSource\\Shopware6\\Gateways\\CreditCard') {
                    await this.handleCyberSourceTransition(transaction, docIds, this.sendMail, selectedState);
                } else {
                    this.$emit('on-confirm', docIds, this.sendMail);
                }
            } else if (stateType === 'order_delivery') {
                const delivery = this.order.deliveries[0];
                if (!delivery) {
                    this.createNotificationError({ message: 'No delivery found.' });
                    this.$emit('modal-close');
                    return;
                }
                this.$emit('on-confirm', docIds, this.sendMail);
            } else {
                this.$emit('on-confirm', docIds, this.sendMail);
            }
        },

        getPaymentMethod(paymentMethodId) {
            try {
                const transaction = this.order.transactions.find(t => t.paymentMethodId === paymentMethodId);
                if (transaction && transaction.paymentMethod) return transaction.paymentMethod;
                return null;
            } catch (error) {
                this.createNotificationError({ message: 'Failed to retrieve payment method.' });
                return null;
            }
        },

        async handleCyberSourceTransition(transaction, docIds, sendMail, selectedState) {
            this.$emit('update:is-loading', true);

            if (!this.cybersourceOrderService || !this.cybersourceOrderService.transitionOrderPayment) {
                this.createNotificationError({ message: 'Payment service unavailable.' });
                this.$emit('modal-close');
                return;
            }

            const validTransitions = { authorized: ['paid', 'cancel'], paid: ['refund', 'cancel'] };
            const currentState = transaction.stateMachineState.technicalName.toLowerCase();
            const targetState = selectedState.toLowerCase();
            if (!validTransitions[currentState] || !validTransitions[currentState].includes(targetState)) {
                this.createNotificationError({ message: `Invalid transition from ${currentState} to ${targetState}.` });
                this.$emit('modal-close');
                return;
            }

            try {
                const orderId = this.order.id;
                const response = await this.cybersourceOrderService.transitionOrderPayment(orderId, targetState, currentState);

                if (response.success) {
                    this.createNotificationSuccess({ message: `Successfully transitioned to ${targetState}.` });
                    this.$emit('on-cybersource-confirm', docIds, sendMail, true);
                    this.$emit('modal-close');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    this.createNotificationError({ message: response.message || `Failed to transition to ${targetState}.` });
                    this.$emit('modal-close');
                }
            } catch (error) {
                this.createNotificationError({ message: 'Transition error. Please try again.' });
                this.$emit('modal-close');
            } finally {
                this.$emit('update:is-loading', false);
            }
        },

    },

    mounted() {},
});