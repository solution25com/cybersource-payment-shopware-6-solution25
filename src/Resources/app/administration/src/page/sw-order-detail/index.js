import template from './sw-order-detail.html.twig';

const { Component, Mixin, Data: { Criteria } } = Shopware;

Component.override('sw-order-detail', {
    template,

    mixins: ['notification', Mixin.getByName('api-validation-errors')],

    inject: ['cybersourceOrderService', 'orderService'],

    props: {
        orderId: {
            required: true,
            type: String,
        },
    },

    data() {
        return {
            paymentStatus: '',
            previousTotalAmount: 0,
            cybersourceTransactionId: null,
            isDisplayingSaveChangesWarning: false,
            next: null,
            hasPriceIncreased: false,
            lineItems: [],
            isLoading: false,
            selectedState: '',
            stateType: '',
        };
    },

    watch: {
        order: {
            deep: true,
            immediate: true,
            handler() {
                this.fetchOrderDetails();
            },
        },
    },

    methods: {
        isCyberSourceCreditCardPaymentMethod() {
            if (!this.order) return false;
            const transaction = this.order.transactions.first();
            return transaction?.paymentMethod?.name === 'CyberSourceCreditCard';
        },

        createdComponent() {
            this.fetchOrderDetails();
            this.$super('createdComponent');
        },

        isElligibleForCyberSourceRefund() {
            return (
                this.order &&
                ['paid', 'refunded_partially'].includes(this.paymentStatus) &&
                +this.order.price.totalPrice !== this.previousTotalAmount
            );
        },

        fetchOrderDetails() {
            if (!this.isCyberSourceCreditCardPaymentMethod()) return;
            this.cybersourceTransactionId = null;
            this.paymentStatus = '';
            this.previousTotalAmount = 0;
            this.lineItems = [];

            if (!this.cybersourceOrderService?.getOrderByOrderId) {
                console.error('cybersourceOrderService or getOrderByOrderId is undefined');
                this.createNotificationError({ message: 'Payment service unavailable.' });
                return;
            }

            this.cybersourceOrderService.getOrderByOrderId(this.orderId)
                .then((orderDetailsResponse) => {
                    if(orderDetailsResponse) {
                        this.cybersourceTransactionId = orderDetailsResponse['cybersource_transaction_id'];
                        this.paymentStatus = orderDetailsResponse['payment_status'];
                        this.previousTotalAmount = +orderDetailsResponse.amount;
                    }
                })
                .catch((error) => {
                    return this.handleError(error.response?.data?.errors[0] || error);
                });
        },

        onSaveEdits() {
            if (this.isOrderEditing && this.isElligibleForCyberSourceRefund()) {
                this.hasPriceIncreased = +this.order.price.totalPrice > this.previousTotalAmount;
                this.isDisplayingSaveChangesWarning = true;
            } else {
                this.$super('onSaveEdits');
            }
        },

        onSaveModalClose() {
            this.isDisplayingSaveChangesWarning = false;
            this.isOrderEditing = true;
            if (!this.hasPriceIncreased) {
                this.$super('onSaveEdits');
            }
        },

        onSaveModalCancel() {
            this.isDisplayingSaveChangesWarning = false;
        },

        onSaveModalConfirm() {
            this.isDisplayingSaveChangesWarning = false;
            if (this.hasPriceIncreased) {
                return this.$super('onSaveEdits');
            }
            const lineItems = this.order.lineItems.map((lineItem) => ({
                number: lineItem.position,
                productName: lineItem.label,
                productCode: lineItem.payload.productNumber,
                unitPrice: lineItem.unitPrice,
                totalAmount: lineItem.totalPrice,
                quantity: lineItem.quantity,
                taxAmount: lineItem.price.calculatedTaxes.reduce(
                    (totalTax, taxItem) => (totalTax += taxItem.tax),
                    0
                ),
                productSku: lineItem.payload.productNumber,
            }));

            if (!this.cybersourceOrderService?.refundPayment) {
                console.error('cybersourceOrderService or refundPayment is undefined');
                this.createNotificationError({ message: 'Payment service unavailable.' });
                return;
            }

            this.cybersourceOrderService.refundPayment(
                this.orderId,
                this.cybersourceTransactionId,
                this.order.price.totalPrice,
                lineItems
            )
                .then((responseFromCapture) => {
                    this.createNotificationSuccess({
                        message: this.$tc('cybersource_shopware6.refund.successMessage'),
                    });
                    if (Object.prototype.hasOwnProperty.call(responseFromCapture, 'id')) {
                        this.paymentStatus = 'refunded';
                    }
                    this.buttonLoading = false;
                    this.$super('onSaveEdits');
                })
                .catch((error) => {
                    this.buttonLoading = false;
                    console.error('refundPayment error:', error);
                    return this.handleError(error.response?.data?.errors[0] || error);
                });
        },

        openStateChangeModal(stateType = 'order_transaction') {
            const transaction = this.order?.transactions[0];
            const delivery = this.order?.deliveries[0];
            this.stateType = stateType;
            if (stateType === 'order_transaction') {
                this.selectedState = transaction?.stateMachineState?.technicalName || '';
            } else if (stateType === 'order_delivery') {
                this.selectedState = delivery?.stateMachineState?.technicalName || '';
            } else {
                this.selectedState = this.order?.stateMachineState?.technicalName || '';
            }
            console.log(`Opening state change modal for ${stateType} with initial selectedState:`, this.selectedState);
            this.$refs.orderStateModal?.openModal();
        },
    },
});