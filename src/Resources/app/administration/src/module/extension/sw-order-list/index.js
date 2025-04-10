import template from './sw-order-list.html.twig';

const { Component, Mixin } = Shopware;
const { mapState } = Component.getComponentHelper();

Component.override('sw-order-detail-details', {
    template,

    mixins: ['notification', Mixin.getByName('api-validation-errors')],

    inject: [
        'CybersourceOrderService'
    ],

    props: {
        orderId: {
            required: true,
            type: String
        }
    },
    data() {
        return {
            paymentStatus: '',
            cybersourceTransactionId: '',
            buttonLoading: false,
            totalAmount: 0
        };
    },

    computed: {
        ...mapState('swOrderDetail', [
            'order'
        ]),
    },

    methods: {
        isCyberSourceCreditCardPaymentMethod(){
            const transaction = this.order.transactions.first();

            return (transaction.paymentMethod.name && transaction.paymentMethod.name == 'CyberSourceCreditCard');
        },
        onCaptureAction(cybersourceTransactionId) {
            this.buttonLoading = true;
            this.CybersourceOrderService.capturePayment(
                this.orderId, cybersourceTransactionId
            ).then((responseFromCapture) => {

                this.createNotificationSuccess({
                    message: this.$tc('cybersource_shopware6.capture.successMessage'),
                });
                if (responseFromCapture.hasOwnProperty('id')) {
                    this.paymentStatus = 'paid';
                }

                this.buttonLoading = false;
            }).catch(error => {
                this.buttonLoading = false;
                return this.handleError(error.response['data'].errors[0]);
            });
        },
        onRefundAction(cybersourceTransactionId) {
            this.buttonLoading = true;
            this.CybersourceOrderService.refundPayment(
                this.orderId, cybersourceTransactionId, this.totalAmount
            ).then((responseFromCapture) => {

                this.createNotificationSuccess({
                    message: this.$tc('cybersource_shopware6.refund.successMessage'),
                });
                if (responseFromCapture.hasOwnProperty('id')) {
                    this.paymentStatus = 'refunded';
                }
                this.buttonLoading = false;
            }).catch(error => {
                this.buttonLoading = false;
                return this.handleError(error.response['data'].errors[0]);
            });
        },
        createdComponent() {
            this.$super('createdComponent');
            this.fetchOrderDetails();
        },
        fetchOrderDetails() {
            if (!this.isCyberSourceCreditCardPaymentMethod()) {
                return false;
            }

            this.orderId = this.order.id;
            this.CybersourceOrderService.getOrderByOrderId(this.orderId).then((orderDetailsReponse) => {

                this.cybersourceTransactionId = orderDetailsReponse['cybersource_transaction_id'];
                this.paymentStatus = orderDetailsReponse['payment_status'];
                this.totalAmount = orderDetailsReponse['amount'];
            }).catch(error => {
                return this.handleError(error.response['data'].errors[0]);
            });
        }
    }
})
