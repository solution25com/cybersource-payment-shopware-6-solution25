const ApiService = Shopware.Classes.ApiService;

class CybersourceOrderService extends ApiService
{
    constructor(httpClient, loginService, apiEndpoint = 'cybersource') {
        super(httpClient, loginService, apiEndpoint);
    }

    /**
     * @param {String} orderId
     */
    getOrderByOrderId(orderId) {
        const apiRoute = `${this.getApiBasePath()}/order/${orderId}`
        return this.httpClient.get(
            apiRoute,
            {
                headers: this.getBasicHeaders()
            }
        ).then((response => {
            return ApiService.handleResponse(response);
        }))
    }

    /**
     * @param {String} orderId
     * @param {String} cybersourceTransactionId
     */
    capturePayment(orderId, cybersourceTransactionId) {
            const apiRoute = `${this.getApiBasePath()}/order/${orderId}/capture/${cybersourceTransactionId}`
            return this.httpClient.post(
                apiRoute,
                null,
                {
                    headers: this.getBasicHeaders()
                }
            ).then((response => {
                return ApiService.handleResponse(response);
            }))
        }

    /**
     * @param {String} orderId
     * @param {String} cybersourceTransactionId
     * @param {String} newTotalAmount
     * @param {Array<Object>} lineItems
     */
    refundPayment(orderId, cybersourceTransactionId, newTotalAmount, lineItems = []) {
        const apiRoute = `${this.getApiBasePath()}/order/${orderId}/refund/${cybersourceTransactionId}`
        let body = { newTotalAmount }
        if (lineItems?.length) {
            body = {...body, lineItems}
        }
        return this.httpClient.post(
            apiRoute,
            body,
            {
                headers: this.getBasicHeaders()
            }
        ).then((response => {
            return ApiService.handleResponse(response);
        }))
    }
}

export default CybersourceOrderService;
