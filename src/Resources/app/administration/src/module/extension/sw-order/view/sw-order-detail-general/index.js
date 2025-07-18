import template from './sw-order-detail-general.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.override('sw-order-detail-general', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            orderTransactions: null,
            cybersourceColumns: [
                { property: 'paymentId', label: 'Payment ID' },
                { property: 'type', label: 'Type' },
                { property: 'cardCategory', label: 'Card Category' },
                { property: 'paymentMethodType', label: 'Payment Method Type' },
                { property: 'amount', label: 'Amount' },
                { property: 'currency', label: 'Currency' },
                { property: 'expiryMonth', label: 'Expiry Month' },
                { property: 'expiryYear', label: 'Expiry Year' },
                { property: 'cardLast4', label: 'Card Last 4' },
                { property: 'gatewayAuthCode', label: 'Gateway Authorization Code' },
                { property: 'statusCode', label: 'Response Code' },
                { property: 'gatewayToken', label: 'Gateway Token' },
                { property: 'gatewayReference', label: 'Gateway Reference' },
                { property: 'lastUpdate', label: 'Last Update' }
            ],
            isCyberSourcePayment: false
        };
    },

    computed: {
        showTable() {
            return this.isCyberSourcePayment;
        },
        cybersourceTransactions() {
            const transaction = this.orderTransactions?.first();
            const details = transaction?.customFields?.cybersource_payment_details;

            if (!details || !Array.isArray(details.transactions)) {
                return [];
            }

            return details.transactions
                .slice()
                .sort((a, b) => new Date(b.last_update) - new Date(a.last_update))
                .map((entry) => ({
                    paymentId: entry.payment_id || '-',
                    type: entry.type || '-',
                    cardCategory: entry.card_category,
                    paymentMethodType: entry.payment_method_type || '-',
                    amount: entry.amount ? Number(entry.amount).toFixed(2) : '-',
                    currency: entry.currency ? `${entry.currency}` : '-',
                    expiryMonth: entry.expiry_month ? String(parseInt(entry.expiry_month, 10)) : '-',
                    expiryYear: entry.expiry_year || '-',
                    cardLast4: entry.card_last_4 || '-',
                    gatewayAuthCode: entry.gateway_authorization_code || '-',
                    statusCode: entry.status_code || '-',
                    gatewayToken: entry.gateway_token || '-',
                    gatewayReference: entry.transaction_id || '-',
                    lastUpdate: this.formatDate(entry.last_update)
                }));
        }
    },

    async created() {
        await this.fetchOrderTransactions();
        await this.checkPaymentMethod();
    },

    methods: {
        async fetchOrderTransactions() {
            if (!this.order || !this.order.id) {
                console.warn('Order not loaded yet.');
                return;
            }

            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('orderId', this.order.id));
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

            const orderTransactionRepository = this.repositoryFactory.create('order_transaction');

            try {
                this.orderTransactions = await orderTransactionRepository.search(criteria, Shopware.Context.api);
            } catch (error) {
                console.error('Error fetching order transactions:', error);
            }
        },
        async checkPaymentMethod() {
            const transaction = this.orderTransactions?.first();
            if (!transaction) {
                this.isCyberSourcePayment = false;
                return;
            }

            const paymentMethod = await this.getPaymentMethod(transaction.paymentMethodId);
            this.isCyberSourcePayment = !(!paymentMethod || paymentMethod.handlerIdentifier !== 'CyberSource\\Shopware6\\Gateways\\CreditCard');
        },
        formatDate(dateString) {
            if (!dateString) return '-';

            const date = new Date(dateString);

            return new Intl.DateTimeFormat('en-GB', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            }).format(date);
        },
        async getPaymentMethod(paymentMethodId) {
            const repository = this.repositoryFactory.create('payment_method');
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('id', paymentMethodId));
            const result = await repository.search(criteria, Shopware.Context.api);
            return result.first();
        },
    }
});