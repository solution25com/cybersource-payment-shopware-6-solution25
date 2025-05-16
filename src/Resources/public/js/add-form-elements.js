document.addEventListener("DOMContentLoaded", function() {
    const form = document.getElementById('confirmOrderForm');

    // Create hidden input for cybersource_transaction_id
    const transactionIdInput = document.createElement('input');
    transactionIdInput.type = 'hidden';
    transactionIdInput.id = 'cybersource_transaction_id';
    transactionIdInput.name = 'cybersource_transaction_id';
    transactionIdInput.value = '';

    // Create hidden input for cybersource_payment_status
    const paymentStatusInput = document.createElement('input');
    paymentStatusInput.type = 'hidden';
    paymentStatusInput.id = 'cybersource_payment_status';
    paymentStatusInput.name = 'cybersource_payment_status';
    paymentStatusInput.value = '';

    // Create hidden input for cybersource_payment_uniqid
    const paymentUniqidInput = document.createElement('input');
    paymentUniqidInput.type = 'hidden';
    paymentUniqidInput.id = 'cybersource_payment_uniqid';
    paymentUniqidInput.name = 'cybersource_payment_uniqid';
    paymentUniqidInput.value = '';

    // Append the inputs to the form
    form.appendChild(transactionIdInput);
    form.appendChild(paymentStatusInput);
    form.appendChild(paymentUniqidInput);
});