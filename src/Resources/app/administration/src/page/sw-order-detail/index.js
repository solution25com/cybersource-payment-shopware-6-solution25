import template from './sw-order-detail.html.twig';

const { Component, Mixin } = Shopware;

Component.override('sw-order-detail', {
    template,

    mixins: ['notification', Mixin.getByName('api-validation-errors')],

    inject: ['CybersourceOrderService', 'orderService'],

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
            if (this.order === null) return false;
            const transaction = this.order.transactions.first();
            return (
                transaction.paymentMethod.name &&
                transaction.paymentMethod.name == 'CyberSourceCreditCard'
            );
        },
        createdComponent() {
            this.fetchOrderDetails();
            this.$super('createdComponent');
        },
        isElligibleForCyberSourceRefund() {
            return (
                this.order !== null &&
                ['paid', 'refunded_partially'].includes(this.paymentStatus) &&
                +this.order.price.totalPrice != this.previousTotalAmount
            );
        },
        fetchOrderDetails() {
            if (!this.isCyberSourceCreditCardPaymentMethod()) return;
            this.cybersourceTransactionId = null;
            this.paymentStatus = '';
            this.previousTotalAmount = 0;
            this.lineItems = [];
            this.CybersourceOrderService.getOrderByOrderId(this.orderId)
                .then((orderDetailsReponse) => {
                    this.cybersourceTransactionId =
                        orderDetailsReponse['cybersource_transaction_id'];
                    this.paymentStatus = orderDetailsReponse['payment_status'];
                    this.previousTotalAmount = +orderDetailsReponse.amount;
                })
                .catch((error) => {
                    return this.handleError(error.response['data'].errors[0]);
                });
        },
        onSaveEdits() {
            if (this.isOrderEditing && this.isElligibleForCyberSourceRefund()) {
                this.hasPriceIncreased =
                    +this.order.price.totalPrice > this.previousTotalAmount;
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
            this.CybersourceOrderService.refundPayment(
                this.orderId,
                this.cybersourceTransactionId,
                this.order.price.totalPrice,
                lineItems
            )
                .then((responseFromCapture) => {
                    this.createNotificationSuccess({
                        message: this.$tc(
                            'cybersource_shopware6.refund.successMessage'
                        ),
                    });
                    if (
                        Object.prototype.hasOwnProperty.call(
                            responseFromCapture,
                            'id'
                        )
                    ) {
                        this.paymentStatus = 'refunded';
                    }
                    this.buttonLoading = false;
                    this.$super('onSaveEdits');
                })
                .catch((error) => {
                    this.buttonLoading = false;
                    return this.handleError(error.response['data'].errors[0]);
                });
        },
    },
});
