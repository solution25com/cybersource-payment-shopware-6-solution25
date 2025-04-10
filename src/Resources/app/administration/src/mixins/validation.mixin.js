const { Mixin } = Shopware;

Mixin.register('api-validation-errors', {
    methods: {
        handleError(errorResponse) {
            switch (errorResponse.code) {
                case 'API_ERROR':
                    this.createNotificationError({
                        message: this.$tc('cybersource_shopware6.exception.API_ERROR'),
                        autoClose: true,
                    });
                    break;
                case 'ORDER_TRANSACTION_NOT_FOUND':
                    this.createNotificationError({
                        message: errorResponse.detail,
                        autoClose: true,
                    });
                    break;
                case 'REFUND_TRANSACTION_NOT_ALLOWED':
                    this.createNotificationError({
                        message: this.$tc('cybersource_shopware6.exception.REFUND_TRANSACTION_NOT_ALLOWED'),
                        autoClose: true,
                    });
                    break;
            }
        }
    }
});
