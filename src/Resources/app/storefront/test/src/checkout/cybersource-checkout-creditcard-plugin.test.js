import CyberSourceShopware6CreditCard from '../../../src/checkout/cybersource-checkout-creditcard-plugin';
import fs from 'fs';
import path from 'path';

const { JSDOM } = require('jsdom');
const jsdomConfig = {
  resources: 'usable',
  runScripts: 'dangerously',
};
const jsdom = new JSDOM('<html lang="en-GB"></html>', { ...jsdomConfig });
const language = jsdom.window.document.documentElement.getAttribute('lang');
let messagesPath = '';

if (language === 'en-GB') {
    messagesPath = path.resolve('./src/Resources/snippet/en-GB', 'messages.en-GB.json');
} else if (language === 'de-DE') {
    messagesPath = path.resolve('./src/Resources/snippet/de-DE', 'messages.de-DE.json');
}

// Load messages from messages.json
const messages = JSON.parse(fs.readFileSync(messagesPath, 'utf-8'));

// Get current year
const currentDate = new Date();
const currentYear = currentDate.getFullYear() % 100;

describe('CyberSourceShopware6CreditCard', () => {

    let plugin;
    let mockNewCreditCardFormContainer;

    beforeEach(() => {
        // Initialize a new instance of the class before each test
        plugin = new CyberSourceShopware6CreditCard();
        plugin.confirmOrderForm = 'confirmOrderForm';
        plugin.orderSubmitButton = 'confirmFormSubmit'
        plugin.cardNumberFieldId = 'cybersource_shopware6_card_no'
        plugin.expiryFieldId = 'cybersource_shopware6_expiry_date'
        plugin.cvcFieldId = 'cybersource_shopware6_security_code'
        plugin.tosFieldId = 'tos'
        plugin.confirmFormId = 'confirmFormId',
            plugin.cardNumberField = {
                value: '',
                getAttribute: jest.fn().mockReturnValue('data-required-msg')
            };
        plugin.cvcField = {
            value: 123
        };

        plugin.confirmOrderForm = {
            submit: jest.fn(),
        };

        global.document = {
            forms: jest.fn,
            getElementById: jest.fn(),
            getElementsByName: jest.fn(),
            querySelector: jest.fn()
        }

        mockNewCreditCardFormContainer = jsdom.window.document.createElement('div');
        mockNewCreditCardFormContainer.classList.add('cybersource_shopware6_container');
        jsdom.window.document.body.appendChild(mockNewCreditCardFormContainer);
    });

    afterEach(() => {
        delete global.document;
        jsdom.window.document.body.innerHTML = '';
    });

    it('should initialize properly', () => {
        expect(plugin.confirmOrderForm).toBeDefined();
        expect(plugin.orderSubmitButton).toBeDefined();
        expect(plugin.cardNumberFieldId).toBeDefined();
        expect(plugin.expiryFieldId).toBeDefined();
        expect(plugin.cvcFieldId).toBeDefined();
        expect(plugin.tosFieldId).toBeDefined();
    });

    /**
     * @testcase init
     */
    it('should register elements and events on initialization', () => {

        plugin._registerElements = jest.fn();
        plugin._registerEvents = jest.fn();
        plugin._registerPlaceholdeValidations = jest.fn();
    
        plugin.init();

        expect(plugin._registerElements).toHaveBeenCalled();
        expect(plugin._registerEvents).toHaveBeenCalled();
        expect(plugin._registerPlaceholdeValidations).toHaveBeenCalled();
    });

    /**
     * @testcase _registerElements
     */
    it('should register elements correctly', () => {

        plugin.options = {
            confirmFormId: 'confirmOrderForm',
            submitButtonId: 'confirmFormSubmit',
            cardNumberFieldId: 'cybersource_shopware6_card_no',
            expiryFieldId: 'cybersource_shopware6_expiry_date',
            cvcFieldId: 'cybersource_shopware6_security_code',
            tosFieldId: 'tos',
        };

        document.getElementById.mockReturnValue({ disabled: true });
        plugin._registerElements();
        expect(global.document.getElementById).toHaveBeenCalledWith('confirmFormSubmit');
        expect(global.document.getElementById).toHaveBeenCalledWith('cybersource_shopware6_card_no');
        expect(global.document.getElementById).toHaveBeenCalledWith('cybersource_shopware6_expiry_date');
        expect(global.document.getElementById).toHaveBeenCalledWith('cybersource_shopware6_security_code');
        expect(global.document.getElementById).toHaveBeenCalledWith('tos');
        expect(global.document.getElementsByName).toHaveBeenCalledWith('cybersource_shopware6_saved_card');
    });

    /**
     * @testcase _registerEvents
     */
    it('should register click event on orderSubmitButton', () => {

        plugin.isSavedCardExists = true;
        const mockRadio = jsdom.window.document.createElement('input');
        const mockNewCreditCardFormContainer = jsdom.window.document.createElement('div');
        const mockNewCreditCardContainer = jsdom.window.document.createElement('div');
        mockRadio.addEventListener = jest.fn();

        plugin.confirmOrderForm = jsdom.window.document.createElement('form');
        plugin.SavedCardRadio = [mockRadio];
        plugin.newCreditCardFormContainer = mockNewCreditCardFormContainer;
        plugin.newCreditCardContainer = mockNewCreditCardContainer;

        plugin.confirmOrderForm.addEventListener = jest.fn();
    
        document.getElementById.mockReturnValue({ disabled: true });


        plugin._registerEvents();

        expect(mockRadio.addEventListener).toHaveBeenCalledWith('change', expect.any(Function));

        expect(mockNewCreditCardFormContainer.style.display).toBe('block');
        expect(mockNewCreditCardContainer.style.display).toBe('none');

        const mockAnotherRadio = jsdom.window.document.createElement('input');
        plugin.SavedCardRadio.push(mockAnotherRadio);

        expect(plugin.confirmOrderForm.addEventListener).toHaveBeenCalledWith('click', expect.any(Function));

        plugin._registerEvents();

        expect(mockNewCreditCardFormContainer.style.display).toBe('none');
        expect(mockNewCreditCardContainer.style.display).toBe('block');
    });

    /**
     * Credit card payment form details are not valid
     *
     * @testcase _onOrderSubmitButtonClick
     */
    it('should prevent default action when form is not valid', () => {

        plugin._validateForm = jest.fn().mockReturnValue(false);
    
        const event = {
            preventDefault: jest.fn(),
        };
        const result = plugin._onOrderSubmitButtonClick(event);

        expect(event.preventDefault).toHaveBeenCalled();
        expect(plugin.confirmOrderForm.submit).not.toHaveBeenCalled();
        expect(result).toBe(false);
    });
    
    
    /**
     * Credit card payment form details are valid
     *
     * @testcase _onOrderSubmitButtonClick
     */
    it('should submit form when form is valid', () => {

        plugin._validateForm = jest.fn().mockReturnValue(true);
    
        const event = {
            preventDefault: jest.fn(),
        };
        const result = plugin._onOrderSubmitButtonClick(event);
    
        expect(event.preventDefault).toHaveBeenCalled();
        expect(plugin.confirmOrderForm.submit).toHaveBeenCalled();
        expect(result).toBe(true);
    });

    it('should display newCreditCardFormContainer when new credit card is selected', () => {
        // Create a mock event object
        const mockEvent = {
            target: {
                value: 'cybersource_shopware6_new_credit_card',
                checked: true
            }
        };

        plugin._onSavedCardRadioChange.call({
            newCreditCardFormContainer: mockNewCreditCardFormContainer
        }, mockEvent);

        expect(mockNewCreditCardFormContainer.style.display).toBe('block');
    });

    it('should hide newCreditCardFormContainer when new credit card is not selected', () => {
        const mockEvent = {
            target: {
                value: 'cybersource_shopware6_saved_credit_card',
                checked: true
            }
        };

        plugin._onSavedCardRadioChange.call({
            newCreditCardFormContainer: mockNewCreditCardFormContainer
        }, mockEvent);

        expect(mockNewCreditCardFormContainer.style.display).toBe('none');
    });

    /**
     * Credit card number is not valid
     *
     * @testcase isValidCreditCardNumber
     */
    it('should return true if valid credit card number', () => {
        plugin.options = {
            cardNumberRegex: {
                test: jest.fn().mockReturnValue(false)
            },
        }

        const result = plugin.isValidCreditCardNumber();

        expect(result).toBe(false);
    });

    /**
     * Expiry date of credit card is valid
     *
     * @testcase isValidExpiryDate
     */
    it('should return true if valid expiry date of credit card', () => {
        plugin.isValidExpiryMonth = jest.fn().mockReturnValue(true);
        plugin.isValidExpiryYear = jest.fn().mockReturnValue(true)

        const result = plugin.isValidExpiryDate();

        expect(result).toBe(true);
    });

    /**
     * Expiry month of credit card is not valid
     *
     * @testcase isValidExpiryMonth
     */
    it('should return false if invalid expiry month of credit card', () => {
        plugin.expiryField = {
            value: '13/99'
        }

        const result = plugin.isValidExpiryMonth();

        expect(result).toBe(false);
    });

    /**
     * Expiry month of credit card is valid
     *
     * @testcase isValidExpiryMonth
     */
    it('should return true if valid expiry month of credit card', () => {
        plugin.expiryField = {
            value: '05/99'
        }

        const result = plugin.isValidExpiryMonth();

        expect(result).toBe(true);
    });

    /**
     * Expiry year of credit card is not valid
     *
     * @testcase isValidExpiryYear
     */
    it('should return false if invalid expiry year of credit card', () => {
        plugin.expiryField = {
            value: '05/' + (currentYear - 2)
        }

        const result = plugin.isValidExpiryYear();

        expect(result).toBe(false);
    });

    /**
     * Expiry year of credit card is valid
     *
     * @testcase isValidExpiryYear
     */
    it('should return true if valid expiry year of credit card', () => {

        plugin.expiryField = {
            value: '05/'+ (currentYear + 10)
        }

        const result = plugin.isValidExpiryYear();

        expect(result).toBe(true);
    });

    /**
     * CVC number of credit card is valid
     *
     * @testcase isValidCVC
     */
    it('should return true if valid credit card cvv number', () => {

        plugin.options = {
            cvcNumberRegex: {
                test: jest.fn().mockReturnValue(true)
            },
        }

        const result = plugin.isValidCVC();

        expect(result).toBe(true);
    });

    /**
     * Terms & conditions are not accepted
     *
     * @testcase _validateForm
     */
    it('should return false if term & condition checkbox not marked', () => {

        plugin.tosField = {
            checkValidity: jest.fn().mockReturnValue(false),
            classList: { add: jest.fn(), remove: jest.fn() }
        }

        const result = plugin._validateForm()

        expect(result).toBe(false)
        expect(plugin.tosField.classList.add).toHaveBeenCalledWith('is-invalid');
    })

    /**
     * Credit card number is not valid
     *
     * @testcase _validateForm
     */
    it('should return false if credit card fields are not valid', () => {

        plugin.isNewCardChecked = jest.fn().mockReturnValue(true);

        plugin.cardNumberField = {
            value: '',
            getAttribute: jest.fn().mockReturnValue('data-required-msg')
        };
        plugin.cvcField = {
            value: 456
        };

        plugin.checkRequired = jest.fn(() => false);

        plugin.getAttribute = jest.fn('data-required-msg');
        plugin.tosField = {
            checkValidity: jest.fn().mockReturnValue(true),
        }

        const result = plugin._validateForm()

        expect(result).toBe(false)
    })

    /**
     * Expiry date of credit card is not valid
     *
     * @testcase _validateForm
     */
    it('should return false if credit card number is not valid', () => {

        plugin.isNewCardChecked = jest.fn().mockReturnValue(true);

        plugin.cardNumberField = {
            value: '',
            getAttribute: jest.fn().mockReturnValue('data-required-msg'),
            focus: jest.fn()
        };

        plugin.isValidCreditCardNumber = jest.fn(() => false);
        plugin.checkRequired = jest.fn(() => true);

        plugin.addErrorClass = jest.fn();

        plugin.getErrorMessage = jest.fn(
            () => messages.cybersource_shopware6.credit_card.cardNumberErrorMessage
        )

        plugin.tosField = {
            checkValidity: jest.fn().mockReturnValue(true),
        }
        const result = plugin._validateForm()

        expect(result).toBe(false)
        expect(plugin.addErrorClass).toHaveBeenCalledWith(
            plugin.cardNumberField,
            messages.cybersource_shopware6.credit_card.cardNumberErrorMessage
        );
        expect(plugin.cardNumberField.focus).toHaveBeenCalled()
        expect(plugin.getErrorMessage).toHaveBeenCalledWith(plugin.cardNumberField)
    })

    /**
     * Expiry date of credit card is not valid
     *
     * @testcase _validateForm
     */
    it('should return false if credit card expiry date is not valid', () => {

        plugin.isNewCardChecked = jest.fn().mockReturnValue(true);

        plugin.cardNumberField = {
            value: '',
            getAttribute: jest.fn().mockReturnValue('data-required-msg'),
        };

        plugin.expiryField = {
            value: '05/' + (currentYear - 5),
            focus: jest.fn()
        }

        plugin.checkRequired = jest.fn(() => true);
        plugin.isValidCreditCardNumber = jest.fn(() => true);
        plugin.removeErrorClass = jest.fn();
        plugin.isValidExpiryDate = jest.fn(() => false);

        plugin.addErrorClass = jest.fn();

        plugin.getErrorMessage = jest.fn(
            () => messages.cybersource_shopware6.credit_card.expiryDateErrorMessage
        )

        plugin.tosField = {
            checkValidity: jest.fn().mockReturnValue(true),
        }

        const result = plugin._validateForm()

        expect(result).toBe(false)
        expect(plugin.addErrorClass).toHaveBeenCalledWith(
            plugin.expiryField,
            messages.cybersource_shopware6.credit_card.expiryDateErrorMessage
        );
        expect(plugin.expiryField.focus).toHaveBeenCalled()
        expect(plugin.getErrorMessage).toHaveBeenCalledWith(plugin.expiryField)
    })

    /**
     * Credit card cvv number is not valid
     *
     * @testcase _validateForm
     */
    it('should return false if credit card cvv number is not valid', () => {

        plugin.isNewCardChecked = jest.fn().mockReturnValue(true);

        plugin.cardNumberField = {
            value: '',
            getAttribute: jest.fn().mockReturnValue('data-required-msg'),
        };
        plugin.cvcField = {
            value: 456,
            getAttribute: jest.fn(() => 'data-error-msg'),
            focus: jest.fn()
        };
        plugin.expiryField = {
            value: '05/' + (currentYear + 5)
        }
        plugin.checkRequired = jest.fn(() => true);
        plugin.isValidCreditCardNumber = jest.fn(() => true);
        plugin.removeErrorClass = jest.fn();
        plugin.isValidExpiryDate = jest.fn(() => true);
        plugin.isValidCVC = jest.fn(() => false);
        plugin.addErrorClass = jest.fn();
        plugin.getErrorMessage = jest.fn(() => messages.cybersource_shopware6.credit_card.securityErrorMessage)
        plugin.tosField = {
            checkValidity: jest.fn().mockReturnValue(true),
        }

        const result = plugin._validateForm()

        expect(result).toBe(false)
        expect(plugin.cvcField.focus).toHaveBeenCalled()
    })

    /**
     * Credit card cvv number is not valid
     *
     * @testcase _validateForm
     */
    it('should return false if credit card cvv number is not valid', () => {

        plugin.isNewCardChecked = jest.fn().mockReturnValue(true);

        plugin.cardNumberField = {
            value: '',
            getAttribute: jest.fn().mockReturnValue('data-required-msg'),
        };
        plugin.cvcField = {
            value: 456
        };
        plugin.expiryField = {
            value: '05/'+ (currentYear + 10)
        }
        plugin.checkRequired = jest.fn(() => true);
        plugin.isValidCreditCardNumber = jest.fn(() => true);
        plugin.removeErrorClass = jest.fn();
        plugin.isValidExpiryDate = jest.fn(() => true);
        plugin.isValidCVC = jest.fn(() => true);
        plugin.tosField = {
            checkValidity: jest.fn().mockReturnValue(true),
        }

        const result = plugin._validateForm()

        expect(result).toBe(true)
    })


    /**
     * addErrorClass testcase
     *
     * @testcase addErrorClass
     */
    it('should add error class and error message to the input element', () => {

        plugin.options = {
            errorMsgContainer: 'small'
        }

        const mockErrorMsgContainer = { innerHTML: '' };
        const mockFormControl = { className: 'form-group error', querySelector: jest.fn(() => mockErrorMsgContainer) };
        const mockInput = { parentElement: mockFormControl };
        const errorMessage = messages.cybersource_shopware6.credit_card.expiryDateRequiredMessage;
    
        plugin.addErrorClass(mockInput, errorMessage);
    
        expect(mockErrorMsgContainer.innerHTML).toBe(errorMessage);
    });

    /**
     * removeErrorClass testcase
     *
     * @testcase removeErrorClass
     */
    it('should remove error class and error message to the input element', () => {

        plugin.options = {
            errorMsgContainer: 'small'
        }

        const mockErrorMsgContainer = { innerHTML: '' };
        const mockFormControl = { className: 'form-group error', querySelector: jest.fn(() => mockErrorMsgContainer) };
        const mockInput = { parentElement: mockFormControl };
        const errorMessage = '';
    
        plugin.removeErrorClass(mockInput);

        expect(mockErrorMsgContainer.innerText).toBe(errorMessage);
    });

    /**
     * Get invalid input field error message from data-error-msg attribute of input element
     *
     * @testcase getErrorMessage
     */
    it('should get error message from data attribute of input element', () => {

        const inputElement = jsdom.window.document.createElement('input');
        inputElement.setAttribute('data-error-msg', messages.cybersource_shopware6.credit_card.cardNumberErrorMessage);
    
        const errorMessage = plugin.getErrorMessage(inputElement);

        expect(errorMessage).toBe(messages.cybersource_shopware6.credit_card.cardNumberErrorMessage);
    });

    /**
     * Get require input field error message from data-required-msg attribute of input element
     *
     * @testcase getRequiredMessage
     */
    it('should get require field error message from data attribute of input element', () => {

        const inputElement = jsdom.window.document.createElement('input');
        inputElement.setAttribute('data-required-msg', messages.cybersource_shopware6.credit_card.cardNumberRequiredMessage);

        const errorMessage = CyberSourceShopware6CreditCard.prototype.getRequiredMessage(inputElement);

        expect(errorMessage).toBe(messages.cybersource_shopware6.credit_card.cardNumberRequiredMessage);
    });

    /**
     * Check input field is valid or not else attach error message 
     *
     * @testcase checkRequired
     */
    it('should check require field and add error message for invalid input to input element', () => {

        const mockInputElements = [
            {
                value: '',
                focus: jest.fn()
            }
        ]

        plugin.addErrorClass = jest.fn();
        plugin.getRequiredMessage = jest.fn();

        const isCheckRequired = plugin.checkRequired(mockInputElements);

        expect(isCheckRequired).toBe(false);
    });

    /**
     * Check input field is valid or not else remove error message 
     *
     * @testcase checkRequired
     */
    it('should check require field and remove error message if input element is valid', () => {

        const mockInputElements = [
            {
                value: '345',
                focus: jest.fn()
            }
        ]

        plugin.removeErrorClass = jest.fn();

        const isCheckRequired = plugin.checkRequired(mockInputElements);

        expect(isCheckRequired).toBe(true);
    });

    it('should return false when no radio button is checked', () => {
        plugin.isSavedCardExists = true;
        plugin.SavedCardRadio = [];

        expect(plugin.isNewCardChecked()).toBe(false);
    });

    it('should return false when no new card radio button is checked', () => {
        plugin.isSavedCardExists = true;
        plugin.SavedCardRadio = [
            { checked: true, value: 'cybersource_shopware6_saved_credit_card' },
            { checked: false, value: 'cybersource_shopware6_saved_credit_card' }
        ];
        expect(plugin.isNewCardChecked()).toBe(false);
    });

    it('should return true when one new card radio button is checked', () => {
        plugin.SavedCardRadio = [
            { checked: true, value: 'cybersource_shopware6_new_credit_card' },
            { checked: false, value: 'cybersource_shopware6_saved_credit_card' }
        ];
        expect(plugin.isNewCardChecked()).toBe(true);
    });

    it('should return true when only one radio button is present', () => {
        plugin.SavedCardRadio = [{ checked: true, value: 'cybersource_shopware6_new_credit_card' }];
        expect(plugin.isNewCardChecked()).toBe(true);
    });

    it('should return true when multiple radio buttons are present and new card radio button is checked', () => {
        plugin.SavedCardRadio = [
            { checked: false, value: 'cybersource_shopware6_saved_credit_card' },
            { checked: true, value: 'cybersource_shopware6_new_credit_card' }
        ];
        expect(plugin.isNewCardChecked()).toBe(true);
    });

    it('should return false when multiple radio buttons are present but no new card radio button is checked', () => {
        plugin.isSavedCardExists = true;
        plugin.SavedCardRadio = [
            { checked: false, value: 'cybersource_shopware6_saved_credit_card' },
            { checked: false, value: 'cybersource_shopware6_new_credit_card' }
        ];
        expect(plugin.isNewCardChecked()).toBe(false);
    });
});
