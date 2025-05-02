const PaymentModule = (function () {
    let microform = null;
    let config = {};

    // Initialize the module with configuration
    function init(options) {
        config = {
            containerId: options.containerId || 'paymentForm',
            newCardFormId: options.newCardFormId || 'newCardForm',
            savedCardsId: options.savedCardsId || 'savedCards',
            payButtonId: options.payButtonId || 'confirmOrderButton',
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
                    if(savedCardsSection) {
                        savedCardsSection.style.display = 'none';
                    }
                }
                else {
                    savedCards.forEach(card => {
                        const option = document.createElement('option');
                        option.value = card.id;
                        option.textContent = `Card: ${card.cardNumber} (Exp: ${card.expirationMonth}/${card.expirationYear})`;
                        savedCardsSelect.appendChild(option);
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
        if (savedCardsSelect && newCardForm) {
            newCardForm.style.display = savedCardsSelect.value === 'new' ? 'block' : 'none';
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

                    microform.createField('number', { placeholder: 'Card Number' }).load('#number-container');
                    microform.createField('securityCode', { placeholder: 'CVV' }).load('#securityCode-container');
                    document.getElementById('expMonth').value = "";
                    document.getElementById('expYear').value = "";
                };
                document.head.appendChild(script);
            })
            .catch(err => console.error('Failed to load capture context:', err));
    }

    // Validate expiration date
    function validateExpirationDate(month, year) {
        const currentYear = new Date().getFullYear();
        const currentMonth = new Date().getMonth() + 1;
        const monthNum = parseInt(month, 10);
        const yearNum = parseInt(year, 10);

        if (!month || !year || !/^\d{2}$/.test(month) || !/^\d{4}$/.test(year)) {
            return { valid: false, message: 'Please enter a valid expiration date (MM/YYYY).' };
        }
        if (monthNum < 1 || monthNum > 12) {
            return { valid: false, message: 'Month must be between 01 and 12.' };
        }
        if (yearNum < currentYear || (yearNum === currentYear && monthNum < currentMonth)) {
            return { valid: false, message: 'Expiration date cannot be in the past.' };
        }
        return { valid: true };
    }

    // Show error message
    function showError(message) {
        const monthInput = document.getElementById('expMonth');
        const yearInput = document.getElementById('expYear');
        const errorMessage = document.getElementById('expiry-error');

        monthInput.classList.add('error');
        yearInput.classList.add('error');
        errorMessage.textContent = message;
        errorMessage.style.display = 'block';
        monthInput.focus();
        showLoadingButton(false);
    }

    // Manage loading button state
    function showLoadingButton(show, buttonId) {
        let button = document.getElementById(buttonId);
        if(button == null) {
            button = document.getElementById(config.payButtonId);
            buttonId = config.payButtonId;
        }
        if(button == null) {
            button = document.getElementById(config.saveCardButtonId);
            buttonId = config.saveCardButtonId;
        }
        if (show) {
            button.disabled = true;
            button.textContent = 'Processing...';
        } else {
            button.disabled = false;
            button.textContent = buttonId === config.saveCardButtonId ? 'Save Card' : 'Pay Now';
        }
    }

    // Handle payment or card save
    function handlePaymentOrSave(isPayment) {
        const buttonId = isPayment ? config.payButtonId : config.saveCardButtonId;
        showLoadingButton(true, buttonId);

        const month = document.getElementById('expMonth').value.trim();
        const year = document.getElementById('expYear').value.trim();
        const errorMessage = document.getElementById('expiry-error');
        const saveCardCheckbox = document.getElementById(config.saveCardCheckboxId);

        // Reset error states
        document.getElementById('expMonth').classList.remove('error');
        document.getElementById('expYear').classList.remove('error');
        errorMessage.style.display = 'none';

        if (isPayment) {
            const savedCardsSelect = document.getElementById(config.savedCardsId);
            const subscriptionId = savedCardsSelect.value !== 'new' ? savedCardsSelect.value : null;
            if (subscriptionId) {
                authorizePayment(null, subscriptionId);
                return;
            }
        }

        // Validate expiration date
        const validation = validateExpirationDate(month, year);
        if (!validation.valid) {
            showError(validation.message);
            return;
        }

        if (!microform) {
            alert('Card fields are not loaded yet. Please wait a few seconds.');
            showLoadingButton(false, buttonId);
            return;
        }

        microform.createToken({
            cardExpirationMonth: month,
            cardExpirationYear: year
        }, function (err, token) {
            if (err) {
                console.error('Tokenize failed', err);
                alert('Card information could not be verified.');
                showLoadingButton(false, buttonId);
            } else {
                if (isPayment) {
                    authorizePayment(token, null, saveCardCheckbox.checked);
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

        fetch(config.apiEndpoints.authorizePayment, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token, subscriptionId, expirationMonth: month, expirationYear: year, saveCard })
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Payment setup failed. Please try again.');
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
                alert('An error occurred during payment setup. Please try again.');
                showLoadingButton(false, config.payButtonId);
            });
    }

    // Save card
    function saveCard(token, month, year) {
        fetch(config.apiEndpoints.addCard, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token, expirationMonth: month, expirationYear: year })
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Failed to save card. Please try again.');
                    showLoadingButton(false, config.saveCardButtonId);
                    return;
                }
                window.location.reload();
            })
            .catch(err => {
                console.error('Card save error:', err);
                alert('An error occurred while saving the card. Please try again.');
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
        fetch(config.apiEndpoints.proceedAuthentication, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                token,
                subscriptionId,
                saveCard,
                expirationMonth: month,
                expirationYear: year,
                setupResponse: setupData,
                callbackData: callbackData,
                uniqid: setupData.uniqid
            })
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Authentication failed. Please try again.');
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
                alert('An error occurred during authentication. Please try again.');
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
            const submitOrderBtn = document.getElementById('confirmFormSubmit');
            if (submitOrderBtn) {
                submitOrderBtn.addEventListener('click', (e) => {
                    const cybersourceTransactionId = document.getElementById('cybersource_transaction_id');
                    if (cybersourceTransactionId && cybersourceTransactionId.value === '') {
                        e.preventDefault();
                        payButton.click();
                    }
                    else{
                        document.getElementById('confirmOrderForm').submit();
                    }
                });
            }
        }

        const addCardButton = document.getElementById(config.addCardButtonId);
        if (addCardButton) {
            addCardButton.addEventListener('click', () => {
                const paymentForm = document.getElementById(config.containerId);
                paymentForm.style.display = paymentForm.style.display === 'none' ? 'block' : 'none';
                addCardButton.textContent = paymentForm.style.display === 'none' ? '+ Add Card' : '- Cancel';
            });
        }

        const saveCardButton = document.getElementById(config.saveCardButtonId);
        if (saveCardButton) {
            saveCardButton.addEventListener('click', (e) => {
                e.preventDefault();
                handlePaymentOrSave(false);
            });
        }
        document.onload = function () {
            if(document.getElementById('confirmFormSubmit'))
                document.getElementById('confirmFormSubmit').style.display = 'none';
        };
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

                document.getElementById('cybersource_payment_status').value = data.success;
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

// Initialize for payment form
if (document.getElementById('confirmOrderButton')) {
    PaymentModule.init({
        isPaymentForm: true,
        containerId: 'paymentForm',
        newCardFormId: 'newCardForm',
        savedCardsId: 'savedCards',
        payButtonId: 'confirmOrderButton',
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