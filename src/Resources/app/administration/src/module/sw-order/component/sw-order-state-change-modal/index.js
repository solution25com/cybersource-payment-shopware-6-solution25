import template from './sw-order-state-change-modal.html.twig';

const { Component, Mixin } = Shopware;

Component.override('sw-order-state-change-modal', {
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