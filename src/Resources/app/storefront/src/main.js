import CyberSourceShopware6CreditCard from './checkout/cybersource-checkout-creditcard-plugin';

const PluginManager = window.PluginManager;

PluginManager.register('CyberSourceShopware6CreditCard', CyberSourceShopware6CreditCard, '[data-cybersource-payment-base]');
