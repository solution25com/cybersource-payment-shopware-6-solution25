import CybersourceOrderService from '../core/service/api/cybersource-order.service';

const { Application } = Shopware;

const initContainer = Application.getContainer('init');

Application.addServiceProvider(
    'CybersourceOrderService',
    (container) => new CybersourceOrderService(initContainer.httpClient, container.loginService),
);
