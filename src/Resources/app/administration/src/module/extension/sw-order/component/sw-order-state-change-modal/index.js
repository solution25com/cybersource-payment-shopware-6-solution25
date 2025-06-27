import template from './sw-order-state-change-modal.html.twig';
import { Criteria } from 'shopware-data';

Shopware.Component.register('sw-order-state-change-modal', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        order: { type: Object, required: true },
        isLoading: { type: Boolean, required: true },
        initialSelectedState: { type: String, required: false, default: '' },
        stateType: { type: String, required: false, default: 'order_transaction' },
    },

    data() {
        return {
            selectedState: this.initialSelectedState,
            stateCriteria: this.getStateCriteria(),
        };
    },

    watch: {
        initialSelectedState(newValue) {
            this.selectedState = newValue;
            console.log('initialSelectedState updated:', newValue);
        },
        selectedState(newValue) {
            console.log('selectedState updated:', newValue);
        },
    },

    methods: {
        getStateCriteria() {
            const criteria = new Criteria()
                .addAssociation('stateMachine')
                .addAssociation('translations');
            if (this.stateType === 'order_transaction') {
                criteria.addFilter(Criteria.equals('technicalName', ['authorize', 'paid', 'void', 'refund']));
            } else if (this.stateType === 'order_delivery') {
                criteria.addFilter(Criteria.equals('technicalName', ['open', 'shipped', 'delivered', 'cancelled']));
            } else {
                criteria.addFilter(Criteria.equals('technicalName', ['open', 'in_progress', 'completed', 'cancelled']));
            }
            return criteria;
        },

        openModal() {
            this.selectedState = this.initialSelectedState || '';
            console.log(`Modal opened for ${this.stateType} with selectedState:`, this.selectedState);
            this.debugStateSelect();
        },

        closeModal() {
            console.log('Modal close event emitted');
            this.$emit('modal-close');
        },

        async onChangeState() {
            console.log(`onChangeState triggered for ${this.stateType} with selectedState:`, this.selectedState);
            if (!this.selectedState || this.selectedState.trim() === '') {
                this.createNotificationError({ message: 'Please select a valid state for the transition.' });
                this.closeModal();
                return;
            }

            this.$emit('update:is-loading', true);
            try {
                const attachDocuments = this.$refs.attachDocuments;
                if (attachDocuments) {
                    await attachDocuments.onConfirm({ selectedState: this.selectedState, stateType: this.stateType });
                    this.closeModal();
                }
            } catch (error) {
                console.error('Error in onChangeState:', error);
                this.createNotificationError({ message: 'Transition failed. Please try again.' });
                this.closeModal();
            } finally {
                this.$emit('update:is-loading', false);
            }
        },

        async debugStateSelect() {
            const repository = this.repositoryFactory.create('state_machine_state');
            const result = await repository.search(this.stateCriteria, Shopware.Context.api);
            console.log(`State machine states for ${this.stateType}:`, JSON.stringify(result, null, 2));
        },
    },

    mounted() {
        console.log('sw-order-state-change-modal mounted');
        this.debugStateSelect();
    },
});