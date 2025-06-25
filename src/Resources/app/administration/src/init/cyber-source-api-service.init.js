import CybersourceOrderService from '../core/service/api/cybersource-order.service';

const { Application } = Shopware;

const initContainer = Application.getContainer('init');

Application.addServiceProvider('cybersourceOrderService', (container) => {
    const loginService = container.loginService || initContainer.loginService;
    const httpClient = initContainer.httpClient;
    if (!httpClient) {
        console.error('initContainer.httpClient not found. Available services in initContainer:', Object.keys(initContainer));
        throw new Error('HTTP client service is required and could not be found in initContainer for Shopware 6.6.10.x');
    }
    return new CybersourceOrderService(httpClient, loginService);
});