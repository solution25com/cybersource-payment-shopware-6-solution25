import template from './sw-order-detail.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.override('sw-order-detail', {
    template,

    inject: ['cybersourceOrderService'],

    data() {
        return {
            isLoading: false,
        };
    },

    methods: {
        openStateChangeModal() {
            this.$refs.orderStateModal.openModal();
        },

        async getPaymentMethod(paymentMethodId) {
            const repository = this.repositoryFactory.create('payment_method');
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('id', paymentMethodId));
            const result = await repository.search(criteria, Shopware.Context.api);
            return result.first();
        },

        async isEligibleForCyberSourceRefund() {
            const transaction = this.order.transactions[0];
            if (!transaction) return false;

            const paymentMethod = await this.getPaymentMethod(transaction.paymentMethodId);
            if (!paymentMethod || paymentMethod.handlerIdentifier !== 'CyberSource\\Shopware6\\Gateways\\CreditCard') {
                return false;
            }

            return ['open', 'paid'].includes(transaction.stateMachineState.technicalName);
        },
    },

    created() {
        console.log('sw-order-detail created');
    },
});