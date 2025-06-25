const { Application } = Shopware;

export default class CybersourceOrderService {
    constructor(httpClient, loginService) {
        this.httpClient = httpClient;
        this.loginService = loginService;
        this.name = 'CybersourceOrderService';
    }

    getOrderByOrderId(orderId) {
        const headers = this.getBasicHeaders();
        return this.httpClient
            .get(`/cybersource/order/${orderId}`, { headers })
            .then((response) => response.data.data);
    }

    refundPayment(orderId, transactionId, amount, lineItems) {
        const headers = this.getBasicHeaders();
        return this.httpClient
            .post(`/cybersource/refund/${orderId}`, { transactionId, amount, lineItems }, { headers })
            .then((response) => response.data.data);
    }

    transitionOrderPayment(orderId, targetState, currentState) {
        const headers = this.getBasicHeaders();
        return this.httpClient
            .post(`/cybersource/order/${orderId}/transition`, { targetState, currentState }, { headers })
            .then((response) => response.data);
    }

    getBasicHeaders() {
        return {
            Accept: 'application/json',
            Authorization: `Bearer ${this.loginService.getToken()}`,
        };
    }
}