const PaymentModule = (function () {
    let microform = null;
    let config = {};
    let translations = {};
    let salesChannelAccessKey = '';

    // Initialize the module with configuration
    function init(options) {
        // Retrieve data from data-jsData attribute
        const jsDataDiv = document.querySelector('[data-jsData]');
        if (jsDataDiv && jsDataDiv.dataset.jsdata) {
            try {
                const jsData = JSON.parse(jsDataDiv.dataset.jsdata);
                salesChannelAccessKey = jsData.salesChannelAccessKey || '';
                translations = jsData.translations || {};
            } catch (e) {
                console.error('Failed to parse data-jsData:', e);
            }
        } else {
            console.warn('No data-jsData attribute found');
        }

        config = {
            containerId: options.containerId || 'paymentForm',
            newCardFormId: options.newCardFormId || 'newCardForm',
            savedCardsId: options.savedCardsId || 'savedCards',
            payButtonId: options.payButtonId || 'confirmFormSubmit',
            saveCardButtonId: options.saveCardButtonId || 'saveCardButton',
            addCardButtonId: options.addCardButtonId || 'addCardButton',
            saveCardCheckboxId: options.saveCardCheckboxId || 'saveCard',
            isPaymentForm: options.isPaymentForm || false,
            apiEndpoints: {
                captureContext: '/cybersource/capture-context',
                authorizePayment: '/cybersource/authorize-payment',
                proceedAuthentication: '/cybersource/proceed-authentication',
                addCard: '/account/cybersource/add-card',
                getSavedCards: '/cybersource/get-saved-cards'
            },
            ...options
        };

        if (config.isPaymentForm) {
            loadSavedCards();
        }
        setupEventListeners();
        initializeMicroform();
    }

    // Load saved cards for payment form
    function loadSavedCards() {
        fetch(config.apiEndpoints.getSavedCards)
            .then(res => res.json())
            .then(data => {
                const savedCards = data.cards || [];
                const savedCardsSelect = document.getElementById(config.savedCardsId);
                if (savedCards.length === 0) {
                    let savedCardsSection = document.getElementById('saved-cards-section');
                    if (savedCardsSection) {
                        savedCardsSection.style.display = 'none';
                    }
                } else {
                    savedCards.forEach(card => {
                        const option = document.createElement('option');
                        option.value = card.id;
                        option.textContent = `${translations.cardNumber || 'Card Number: '} ${card.cardNumber} (${translations.expiryDate || 'Exp: '} ${card.expirationMonth}/${card.expirationYear})`;
                        if (savedCardsSelect) {
                            savedCardsSelect.appendChild(option);
                        }
                    });
                }
                toggleCardForm();
            })
            .catch(err => console.error('Failed to load saved cards:', err));
    }

    // Toggle new card form visibility
    function toggleCardForm() {
        const savedCardsSelect = document.getElementById(config.savedCardsId);
        const newCardForm = document.getElementById(config.newCardFormId);
        const billingSection = document.getElementById('billingSection');
        if (savedCardsSelect && newCardForm) {
            newCardForm.style.display = savedCardsSelect.value === 'new' ? 'block' : 'none';
            if (billingSection) {
                billingSection.style.display = savedCardsSelect.value === 'new' ? 'block' : 'none';
            }
        }
    }

    // Initialize CyberSource Flex Microform
    function initializeMicroform() {
        fetch(config.apiEndpoints.captureContext)
            .then(res => res.json())
            .then(data => {
                const captureContext = data.captureContext;
                const parts = captureContext.split('.');
                const payload = JSON.parse(atob(parts[1]));
                const libUrl = payload.ctx[0].data.clientLibrary;
                const integrity = payload.ctx[0].data.clientLibraryIntegrity;

                const script = document.createElement('script');
                script.src = libUrl;
                script.integrity = integrity;
                script.crossOrigin = 'anonymous';
                script.onload = () => {
                    const flex = new Flex(captureContext);
                    microform = flex.microform({
                        styles: {
                            input: {
                                'font-size': '14px',
                                'color': '#333',
                                'border': '1px solid #ccc',
                                'border-radius': '4px'
                            }
                        }
                    });

                    microform.createField('number', { placeholder: translations.cardNumberPlaceholder || '1234 1234 1234 1234' }).load('#number-container');
                    microform.createField('securityCode', { placeholder: translations.cvvPlaceholder || 'CVC' }).load('#securityCode-container');
                    document.getElementById('expMonth').value = "";
                    document.getElementById('expYear').value = "";
                };
                document.head.appendChild(script);
            })
            .catch(err => console.error('Failed to load capture context:', err));
    }

    function validateFormInputs(month, year) {
        const errors = [];
        const currentYear = new Date().getFullYear();
        const currentMonth = new Date().getMonth() + 1;
        const monthNum = parseInt(month, 10);
        const yearNum = parseInt(year, 10);

        // Validate expiration date
        if (!month || !year || !/^\d{2}$/.test(month) || !/^\d{4}$/.test(year)) {
            errors.push({ field: 'expiry', message: translations.pleaseEnterValidExpirationDate || 'Please enter a valid expiration date' });
        } else if (monthNum < 1 || monthNum > 12) {
            errors.push({ field: 'expiry', message: translations.monthInvalid || 'Month must be between 01 and 12' });
        } else if (yearNum < currentYear || (yearNum === currentYear && monthNum < currentMonth)) {
            errors.push({ field: 'expiry', message: translations.expirationDatePast || 'Expiration date cannot be in the past' });
        }
        if (!config.isPaymentForm) {
            const firstName = document.getElementById('billingFirstName').value.trim();
            const lastName = document.getElementById('billingLastName').value.trim();
            const email = document.getElementById('billingEmail').value.trim();
            const street = document.getElementById('billingStreet').value.trim();
            const city = document.getElementById('billingCity').value.trim();
            const zip = document.getElementById('billingZip').value.trim();
            const country = document.getElementById('billingCountry').value.trim();
            const state = document.getElementById('billingState').value.trim();

            if (!firstName) {
                errors.push({ field: 'billingFirstName', message: translations.pleaseEnterValidFirstName || 'Please enter a valid first name' });
            }
            if (!lastName) {
                errors.push({ field: 'billingLastName', message: translations.pleaseEnterValidLastName || 'Please enter a valid last name' });
            }
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                errors.push({ field: 'billingEmail', message: translations.pleaseEnterValidEmail || 'Please enter a valid email address' });
            }
            if (!street) {
                errors.push({ field: 'billingStreet', message: translations.pleaseEnterValidStreet || 'Please enter a valid street address' });
            }
            if (!city) {
                errors.push({ field: 'billingCity', message: translations.pleaseEnterValidCity || 'Please enter a valid city' });
            }
            if (!zip) {
                errors.push({ field: 'billingZip', message: translations.pleaseEnterValidZip || 'Please enter a valid zip code' });
            }
            if (!country) {
                errors.push({ field: 'billingCountry', message: translations.pleaseEnterValidCountry || 'Please select a country' });
            }
            if (document.getElementById('billingStateSection').style.display === 'block' && !state) {
                errors.push({ field: 'billingState', message: translations.pleaseEnterValidState || 'Please select a state' });
            }
        }

        const requiredFields = document.querySelectorAll('#confirmOrderForm input[required], #confirmOrderForm select[required], input[form="confirmOrderForm"][required], select[form="confirmOrderForm"][required]');
        requiredFields.forEach(field => {
            if (field.type === 'checkbox' && !field.checked) {
                errors.push({ field: field.id, message: (translations.requiredCheckbox || 'Please check {fieldName}').replace('{fieldName}', field.name) });
            } else if (field.value.trim() === '') {
                errors.push({ field: field.id, message: (translations.requiredField || 'Please fill out {fieldName}').replace('{fieldName}', field.name) });
            }
        });
        return { valid: errors.length === 0, errors };
    }

    function showError(errors) {
        ['expMonth', 'expYear', 'billingFirstName', 'billingLastName', 'billingEmail', 'billingStreet', 'billingCity', 'billingZip', 'billingCountry', 'billingState'].forEach(field => {
            const input = document.getElementById(field);
            let errorDiv = document.getElementById(`${field}-error`);
            if (field === 'expMonth' || field === 'expYear') {
                errorDiv = document.getElementById(`expiry-error`);
            }
            if (input) input.classList.remove('error');
            if (errorDiv) errorDiv.style.display = 'none';
        });

        // Display errors for each invalid field
        errors.forEach(error => {
            if (error.field === 'expiry') {
                const monthInput = document.getElementById('expMonth');
                const yearInput = document.getElementById('expYear');
                const errorMessage = document.getElementById('expiry-error');
                monthInput.classList.add('error');
                yearInput.classList.add('error');
                errorMessage.textContent = error.message;
                errorMessage.style.display = 'block';
                monthInput.focus();
            } else {
                const input = document.getElementById(error.field);
                const errorMessage = document.getElementById(`${error.field}-error`);
                if (input) input.classList.add('error');
                if (errorMessage) {
                    errorMessage.textContent = error.message;
                    errorMessage.style.display = 'block';
                }
                if (input) input.focus();
            }
        });
    }

    // Manage loading button state
    function showLoadingButton(show, buttonId) {
        let button = document.getElementById(buttonId);
        if (button == null) {
            button = document.getElementById(config.payButtonId);
        }
        const confirmOrderForm = document.getElementById('confirmOrderForm');
        if (confirmOrderForm) {
            button = confirmOrderForm.querySelector('button[type="submit"]');
        }
        if (button == null) {
            button = document.getElementById(config.saveCardButtonId);
        }
        if (show) {
            button.disabled = true;
            window.originalBtnText = button.textContent;
            button.textContent = translations.processing || 'Processing...';
        } else {
            button.disabled = false;
            if (window.originalBtnText) {
                button.textContent = window.originalBtnText;
            }
        }
    }

    // Handle payment or card save
    function handlePaymentOrSave(isPayment) {
        const buttonId = isPayment ? config.payButtonId : config.saveCardButtonId;
        showLoadingButton(true, buttonId);

        const month = document.getElementById('expMonth').value.trim();
        const year = document.getElementById('expYear').value.trim();
        const saveCardCheckbox = document.getElementById(config.saveCardCheckboxId);

        if (isPayment) {
            const savedCardsSelect = document.getElementById(config.savedCardsId);
            if (savedCardsSelect) {
                const subscriptionId = savedCardsSelect.value !== 'new' ? savedCardsSelect.value : null;
                if (subscriptionId) {
                    authorizePayment(null, subscriptionId);
                    return;
                }
            }
        }
        // Validate form inputs
        const validation = validateFormInputs(month, year);
        if (!validation.valid) {
            showError(validation.errors);
            showLoadingButton(false, buttonId);
            return;
        }
        if (!microform) {
            alert(translations.cardFieldsNotLoaded || 'Card fields are not loaded yet. Please wait a few seconds.');
            showLoadingButton(false, buttonId);
            return;
        }

        microform.createToken({
            cardExpirationMonth: month,
            cardExpirationYear: year
        }, function (err, token) {
            if (err) {
                console.error('Tokenize failed', err);
                alert(translations.cardVerificationFailed || 'Card information could not be verified.');
                showLoadingButton(false, buttonId);
            } else {
                if (isPayment) {
                    let saveThisCard = saveCardCheckbox && saveCardCheckbox.checked;
                    authorizePayment(token, null, saveThisCard);
                } else {
                    saveCard(token, month, year);
                }
            }
        });
    }

    // Authorize payment
    function authorizePayment(token, subscriptionId, saveCard = false) {
        const month = document.getElementById('expMonth').value.trim();
        const year = document.getElementById('expYear').value.trim();

        const url = window.location.pathname;
        const pattern = /^\/account\/order\/edit\/(.+)$/;
        const match = url.match(pattern);
        let orderId = null;
        if (match) {
            orderId = match[1];
        }
        let bodyParams = {
            token,
            subscriptionId,
            expirationMonth: month,
            expirationYear: year,
            saveCard,
            orderId: orderId
        };
        fetch(config.apiEndpoints.authorizePayment, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(bodyParams)
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message ||  translations.paymentSetupFailed || 'Payment setup failed. Please try again.');
                    showLoadingButton(false, config.payButtonId);
                    return;
                }

                if (data.action === 'setup') {
                    collectDeviceData(data, (callbackData) => {
                        proceedWithAuthentication(token, subscriptionId, saveCard, month, year, data, callbackData);
                    });
                } else {
                    updateHiddenFields(data, token, subscriptionId, month, year);
                    if (data.action === 'complete') {
                        document.getElementById('cybersource_transaction_id').value = data.transactionId;
                        document.getElementById('confirmOrderForm').submit();
                    } else if (data.action === 'notify') {
                        alert(data.message);
                        showLoadingButton(false, config.payButtonId);
                    }
                }
            })
            .catch(err => {
                console.error('Payment setup error:', err);
                alert(translations.paymentSetupError || 'An error occurred during payment setup. Please try again.');
                showLoadingButton(false, config.payButtonId);
            });
    }

    // Save card
    function saveCard(token, month, year) {
        const validation = validateFormInputs(month, year);
        if (!validation.valid) {
            showError(validation.errors);
            showLoadingButton(false, config.saveCardButtonId);
            return;
        }
        let bodyParams = { token, expirationMonth: month, expirationYear: year };
        bodyParams.billingAddress = {
            firstName: document.getElementById('billingFirstName').value,
            lastName: document.getElementById('billingLastName').value,
            email: document.getElementById('billingEmail').value,
            address1: document.getElementById('billingStreet').value,
            locality: document.getElementById('billingCity').value,
            postalCode: document.getElementById('billingZip').value,
            country: document.getElementById('billingCountry').value,
            state: document.getElementById('billingState').value,
        };
        fetch(config.apiEndpoints.addCard, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(bodyParams)
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || translations.saveCardFailed || 'Failed to save card. Please try again.');
                    showLoadingButton(false, config.saveCardButtonId);
                    return;
                }
                window.location.reload();
            })
            .catch(err => {
                console.error('Card save error:', err);
                alert(translations.saveCardError || 'An error occurred while saving the card. Please try again.');
                showLoadingButton(false, config.saveCardButtonId);
            });
    }

    // Collect device data for 3DS
    function collectDeviceData(setupData, callback) {
        const deviceDataCollectionUrl = setupData.consumerAuthenticationInformation.deviceDataCollectionUrl;
        const accessToken = setupData.consumerAuthenticationInformation.accessToken;

        const iframe = document.createElement('iframe');
        iframe.name = 'deviceDataFrame';
        iframe.style.display = 'none';
        document.body.appendChild(iframe);

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = deviceDataCollectionUrl;
        form.target = 'deviceDataFrame';
        form.style.display = 'none';

        const jwtInput = document.createElement('input');
        jwtInput.type = 'hidden';
        jwtInput.name = 'JWT';
        jwtInput.value = accessToken;
        form.appendChild(jwtInput);

        document.body.appendChild(form);
        form.submit();

        window.addEventListener('message', function handler(event) {
            if (event.origin.includes('cardinalcommerce.com')) {
                callback(event.data);
                document.body.removeChild(iframe);
                document.body.removeChild(form);
            }
        }, { once: true });
    }

    // Proceed with 3DS authentication
    function proceedWithAuthentication(token, subscriptionId, saveCard, month, year, setupData, callbackData) {
        let bodyParams = {
            token,
            subscriptionId,
            saveCard,
            expirationMonth: month,
            expirationYear: year,
            setupResponse: setupData,
            callbackData: callbackData,
            uniqid: setupData.uniqid
        };

        fetch(config.apiEndpoints.proceedAuthentication, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(bodyParams)
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || translations.authenticationFailed || 'Authentication failed. Please try again.');
                    showLoadingButton(false, config.payButtonId);
                    return;
                }

                if (data.action === '3ds') {
                    const stepUp = document.getElementById('step_up');
                    stepUp.innerHTML = '';
                    const iframe = document.createElement('iframe');
                    iframe.id = 'step_up_iframe';
                    iframe.name = 'step_up_iframe';
                    iframe.height = '500';
                    iframe.width = '358';
                    iframe.style.display = 'block';

                    stepUp.appendChild(iframe);

                    const stepUpForm = document.createElement('form');
                    stepUpForm.id = 'step_up_form';
                    stepUpForm.target = 'step_up_iframe';
                    stepUpForm.method = 'POST';
                    stepUpForm.action = data.stepUpUrl;
                    stepUpForm.style.display = 'none';

                    const pareqInput = document.createElement('input');
                    pareqInput.type = 'hidden';
                    pareqInput.id = 'step_up_pareq';
                    pareqInput.name = 'JWT';
                    pareqInput.value = data.accessToken;

                    const mdInput = document.createElement('input');
                    mdInput.type = 'hidden';
                    mdInput.id = 'step_up_md';
                    mdInput.name = 'MD';
                    mdInput.value = JSON.stringify({
                        authenticationTransactionId: data.authenticationTransactionId,
                        uniqid: data.uniqid,
                        pareq: data.pareq,
                        cardType: data.cardType,
                        orderInfo: data.orderInfo,
                        saveCard: saveCard,
                        transientTokenJwt: token,
                        subscriptionId: subscriptionId,
                        expirationMonth: month,
                        expirationYear: year,
                        customerId: data.customerId
                    });

                    stepUpForm.appendChild(pareqInput);
                    stepUpForm.appendChild(mdInput);

                    stepUp.appendChild(stepUpForm);
                    stepUpForm.submit();
                } else {
                    updateHiddenFields(data, token, subscriptionId, month, year);
                    if (data.action === 'complete') {
                        document.getElementById('cybersource_transaction_id').value = data.transactionId;
                        document.getElementById('confirmOrderForm').submit();
                    } else if (data.action === 'notify') {
                        alert(data.message);
                        showLoadingButton(false, config.payButtonId);
                    }
                }
            })
            .catch(err => {
                console.error('Authentication error:', err);
                alert(translations.authenticationError || 'An error occurred during authentication. Please try again.');
                showLoadingButton(false, config.payButtonId);
            });
    }

    // Update hidden form fields
    function updateHiddenFields(data, token, subscriptionId, month, year) {
        document.getElementById('cybersource_payment_status').value = data.status;
        document.getElementById('cybersource_payment_uniqid').value = data.uniqid;
        document.getElementById('cybersource_transient_token_jwt').value = token || '';
        document.getElementById('cybersource_subscription_id').value = subscriptionId || '';
        document.getElementById('cybersource_expiration_month').value = month;
        document.getElementById('cybersource_expiration_year').value = year;
    }

    // Setup event listeners
    function setupEventListeners() {
        if (config.isPaymentForm) {
            const confirmOrderForm = document.getElementById('confirmOrderForm');
            if (confirmOrderForm) {
                const submitButton = confirmOrderForm.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.id = 'confirmFormSubmit';
                }
                confirmOrderForm.onsubmit = function (e) {
                    e.preventDefault();
                    const cybersourceTransactionId = document.getElementById('cybersource_transaction_id');
                    if (cybersourceTransactionId && cybersourceTransactionId.value === '') {
                        payButton.click();
                    } else {
                        confirmOrderForm.submit();
                    }
                }
            }

            const savedCardsSelect = document.getElementById(config.savedCardsId);
            if (savedCardsSelect) {
                savedCardsSelect.addEventListener('change', toggleCardForm);
            }
            const payButton = document.getElementById(config.payButtonId);
            if (payButton) {
                payButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    handlePaymentOrSave(true);
                });
            }
        }

        const addCardButton = document.getElementById(config.addCardButtonId);
        if (addCardButton) {
            addCardButton.addEventListener('click', () => {
                const paymentForm = document.getElementById(config.containerId);
                paymentForm.style.display = paymentForm.style.display === 'none' ? 'block' : 'none';
                addCardButton.textContent = paymentForm.style.display === 'none' ? translations.addCard || '+ Add Card' : translations.cancelCard || '- Cancel';
            });
        }

        const saveCardButton = document.getElementById(config.saveCardButtonId);
        if (saveCardButton) {
            saveCardButton.addEventListener('click', (e) => {
                e.preventDefault();
                handlePaymentOrSave(false);
            });
        }
        window.addEventListener('message', (event) => {
            const origin = event.origin;
            const currentDomain = window.location.origin;
            if (!origin.includes('cybersource.com') && !origin.includes(currentDomain)) {
                return;
            }

            if (event.data?.action === 'close_frame') {
                const stepUp = document.getElementById('step_up');
                stepUp.innerHTML = '';

                let data = event.data.data;
                if (typeof data === 'string') {
                    try {
                        data = JSON.parse(data);
                    } catch (e) {
                        console.error('Failed to parse data:', e);
                        return;
                    }
                }

                document.getElementById('cybersource_payment_status').value = data.status;
                document.getElementById('cybersource_payment_uniqid').value = data.uniqid;
                if (data.success) {
                    document.getElementById('cybersource_transaction_id').value = data.transactionId;
                    document.getElementById('confirmOrderForm').submit();
                } else if (data.action === 'notify') {
                    alert(data.message);
                    showLoadingButton(false, config.payButtonId);
                }
            }
        });
    }

    return { init };
})();
window.addEventListener('DOMContentLoaded', function () {
    // Initialize for payment form
    if (document.getElementById('confirmOrderForm')) {
        PaymentModule.init({
            isPaymentForm: true,
            containerId: 'paymentForm',
            newCardFormId: 'newCardForm',
            savedCardsId: 'savedCards',
            payButtonId: 'confirmFormSubmit',
            saveCardCheckboxId: 'saveCard'
        });
    }

    // Initialize for saved cards page
    if (document.getElementById('addCardButton')) {
        PaymentModule.init({
            isPaymentForm: false,
            containerId: 'paymentForm',
            newCardFormId: 'newCardForm',
            saveCardButtonId: 'saveCardButton',
            addCardButtonId: 'addCardButton'
        });
    }
});