import template from './sw-order-update-page-modal.html.twig';

Shopware.Component.register('sw-order-update-page-modal', {
    template: template,
    props: {
        hasPriceIncreased: {
            type: Boolean,
            required: true,
            default: () => { return false; },
        },
        previousTotalAmount: {
            type: Number | String,
            required: true
        },
        
    },
    computed: {
        amountDecreasedDescription() {
            return this.$tc('cybersource_shopware6.orderSaveConfirmationModal.decreased.description', 1,{previousTotalAmount: this.previousTotalAmount})
        },
    },
    methods: {
        onConfirm() {
            this.$emit('page-update-confirm');
        },
        onCancel() {
            this.$emit('page-update-cancel');
        },
        onClose() {
            this.$emit('page-update-close');
        }
    }
});
