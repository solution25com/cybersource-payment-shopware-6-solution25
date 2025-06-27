import template from './sw-order-general-info.html.twig';

const { Component, Mixin } = Shopware;

Component.override('sw-order-general-info', {
template,
props: {
    order: {
        type: Object,
        required: true,
    },

    isLoading: {
        type: Boolean,
        required: true,
    },

    technicalName: {
        type: String,
        required: true,
    },
    actionName: String,
},
})