import Plugin from 'src/plugin-system/plugin.class';

export default class CyberSourceShopware6CreditCard extends Plugin {

    static options = {
        confirmFormId: 'confirmOrderForm',
        submitButtonId: 'confirmFormSubmit',
        cardNumberFieldId: 'cybersource_shopware6_card_no',
        expiryFieldId: 'cybersource_shopware6_expiry_date',
        cvcFieldId: 'cybersource_shopware6_security_code',
        tosFieldId: 'tos',
        errorMsgContainer: 'small',
        cardNumberRegex : /^(?:\d{14}|\d{15}|\d{16}|\d{18}|\d{19})$/,
        cvcNumberRegex : /^(?:\d{3}|\d{4})$/,
    }

    /**
     * The "init" function is responsible for registering elements and events.
     */
    init()
    {
        this._registerElements();
        this._registerEvents();
        this._registerPlaceholdeValidations();
    }

    /**
     * The function registers the order submit button element by retrieving it from the document using
     * its ID.
     */
    _registerElements() {
        this.confirmOrderForm = document.forms[this.options.confirmFormId];
        this.orderSubmitButton = document.getElementById(this.options.submitButtonId);
        this.cardNumberField = document.getElementById(this.options.cardNumberFieldId);
        this.expiryField = document.getElementById(this.options.expiryFieldId);
        this.cvcField = document.getElementById(this.options.cvcFieldId);
        this.tosField = document.getElementById(this.options.tosFieldId);
        this.saveCardonFile = document.getElementById('cybersource_shopware6_save_card');
        this.isSavedCardExists = document.getElementById('saved-cards');
        this.cvcField.value = '';
        this.expiryField.value = '';
        this.cardNumberField.value = '';
        if (this.isSavedCardExists != null) {
            this.saveCardonFile.checked = false;
            this.SavedCardRadio = document.getElementsByName("cybersource_shopware6_saved_card");
            this.newCreditCardFormContainer = document.querySelector('.cybersource_shopware6_container');
            this.newCreditCardContainer = document.querySelector('.cybersource_shopware6_new_card_container');
        }
    }

    /**
     * The function registers an event listener for a click event on the orderSubmitButton element.
     */
    _registerEvents() {
        if (this.isSavedCardExists != null) {
            for(var i=0; i<this.SavedCardRadio.length; i++){
                this.SavedCardRadio[i].addEventListener('change', this._onSavedCardRadioChange.bind(this));
                this.SavedCardRadio[0].checked = true;
            }

            if (this.SavedCardRadio.length <= 1) {
                this.newCreditCardFormContainer.style.display = 'block';
                this.newCreditCardContainer.style.display = 'none';
            } else {
                this.newCreditCardFormContainer.style.display = 'none';
                this.newCreditCardContainer.style.display = 'block';
            }
        }

        this.confirmOrderForm.addEventListener('click', this._onOrderSubmitButtonClick.bind(this));
    }

    _onOrderSubmitButtonClick(event) {
        event.preventDefault();

        if (!this._validateForm()) {
            return false;
        }

        this.confirmOrderForm.submit();

        return true;
    }

    _onSavedCardRadioChange(event) {
        if (event.target.value === 'cybersource_shopware6_new_credit_card'
            && event.target.checked === true) {
            this.newCreditCardFormContainer.style.display = 'block';
        } else {
            this.newCreditCardFormContainer.style.display = 'none';
        }
    }

    /**
     * @return {Boolean}
     *
     */
    _validateForm() {
        if (this.tosField != null
            && !this.tosField.checkValidity()
        ) {
            this.tosField.classList.add('is-invalid');

            return false;
        }

        if (this.isNewCardChecked()
            && !this.checkRequired([this.cardNumberField, this.expiryField, this.cvcField])
        ) {
            return false;
        }

        if (this.isNewCardChecked()
            && !this.isValidCreditCardNumber()) {
            this.addErrorClass(this.cardNumberField, this.getErrorMessage(this.cardNumberField));
            this.cardNumberField.focus();
            return false;
        }

        this.removeErrorClass(this.cardNumberField);

        if (this.isNewCardChecked()
            && !this.isValidExpiryDate()) {
            this.addErrorClass(this.expiryField, this.getErrorMessage(this.expiryField));
            this.expiryField.focus();
            return false;
        }

        this.removeErrorClass(this.expiryField);

        if (this.isNewCardChecked()
            && !this.isValidCVC()) {
            this.addErrorClass(this.cvcField, this.cvcField.getAttribute('data-error-msg'));
            this.cvcField.focus();
            return false;
        }

        this.removeErrorClass(this.cvcField);

        return true;
    }

    isNewCardChecked()
    {
        let newCardRadioChecked = false;
        if (this.isSavedCardExists == null) {
            return true;
        }

        for (let radio of this.SavedCardRadio) {
            if (radio.checked
                && radio.value === 'cybersource_shopware6_new_credit_card'
            ) {
                newCardRadioChecked = true;
            }
        }

        if (this.SavedCardRadio.length === 1
            || newCardRadioChecked === true
        ) {
            return true;
        }

        return false;
    }

    /**
     * The function checks if a credit card number is valid by removing non-digit characters and
     * testing against a regular expression.
     * @returns a boolean value.
     */
    isValidCreditCardNumber() {
        const cleanedCardNumber = this.cardNumberField.value.replace(/\D/g, '');
        return this.options.cardNumberRegex.test(cleanedCardNumber);
    }

    /**
     * The function checks if the expiry date is valid by checking both the expiry month and year.
     * @returns The function `isValidExpiryDate()` is returning the result of the logical expression
     * `isValidExpiryMonth() && isValidExpiryYear()`.
     */
    isValidExpiryDate() {
        return this.isValidExpiryMonth() && this.isValidExpiryYear();
    }

    /**
     * The function checks if the entered expiry month is valid by ensuring it is a number between 1
     * and 12.
     * @returns a boolean value. It returns true if the entered expiry month is valid (between 1 and
     * 12), and false otherwise.
     */
    isValidExpiryMonth() {
        const enteredDate = this.expiryField.value.split('/');
        const enteredMonth = parseInt(enteredDate[0], 10);

        if (
            enteredDate.length !== 2 || isNaN(enteredMonth) || enteredMonth < 1 || enteredMonth > 12
        ) {
            return false;
        }

        return true;
    }

    /**
     * The function checks if the entered expiry month is valid by comparing it with the current year
     * and ensuring it is within a 10-year range.
     * @returns a boolean value. It returns true if the entered expiry month is valid (within the
     * current year and up to 10 years in the future), and false otherwise.
     */
    isValidExpiryYear() {
        const currentDate = new Date();
        const currentYear = currentDate.getFullYear() % 100;

        const enteredDate = this.expiryField.value.split('/');
        const enteredYear = parseInt(enteredDate[1], 10);

        if (
            isNaN(enteredYear) || enteredYear < currentYear || enteredYear > currentYear + 10
        ) {
            return false;
        }

        return true;
    }

    /**
     * The function checks if the value in the cvcField input matches the regular expression defined in
     * options.cvcNumberRegex.
     * @returns the result of the test method being called on the regular expression stored in
     * `this.options.cvcNumberRegex`.
     */
    isValidCVC() {
        return this.options.cvcNumberRegex.test(this.cvcField.value);
    }

    /**
     * The function adds an error class to a form control element and displays an error message.
     * @param input - The input parameter is the HTML element that triggered the error. It is typically
     * an input field or a form control element.
     * @param errorMessage - The errorMessage parameter is a string that represents the error message
     * that will be displayed to the user.
     */
    addErrorClass(input, errorMessage) {
        const formControl = input.parentElement;
        formControl.className = 'form-group error';
        const errorMsgContainer = formControl.querySelector(this.options.errorMsgContainer);
        errorMsgContainer.innerHTML = errorMessage;
    }

    /**
     * The function removes the error class from an input element and clears any error message
     * displayed.
     * @param input - The input parameter is the HTML input element that you want to remove the error
     * class from.
     */
    removeErrorClass(input) {
        const formControl = input.parentElement;
        formControl.className = 'form-group';
        const errorMsgContainer = formControl.querySelector(this.options.errorMsgContainer);
        errorMsgContainer.innerText = "";
    }

    /**
     * The function checks if all the input fields in an array have a value and returns true if they
     * do, otherwise it returns false.
     * @param inputArr - The inputArr parameter is an array of input elements that need to be checked
     * for required values.
     * @returns a boolean value indicating whether the form is valid or not.
     */
    checkRequired(inputArr) {
        let formValid = true;
        let firstInputError = null;
        inputArr.forEach((input) => {
            if (input.value.trim() === '') {
                this.addErrorClass(input, this.getRequiredMessage(input));
                if (!firstInputError) {
                    firstInputError = input;
                }
                formValid = false;
            } else {
                this.removeErrorClass(input);
            }
        });

        if (firstInputError) {
            firstInputError.focus();
        }

        return formValid;
    }

    getErrorMessage(inputElement) {
        return inputElement.getAttribute('data-error-msg');
    }

    getRequiredMessage(inputElement) {
        return inputElement.getAttribute('data-required-msg');
    }

    _registerPlaceholdeValidations() {
        const cleaveZen = window.cleaveZen
        const {
          formatCreditCard,
          formatDate,
          getCreditCardType,
          registerCursorTracker,
          DefaultCreditCardDelimiter,
          DefaultDateDelimiter,
        } = cleaveZen

        registerCursorTracker({
          input: this.cardNumberField,
          delimiter: DefaultCreditCardDelimiter,
        })

        this.cardNumberField.addEventListener('input', (e) => {
          this.cardNumberField.value = formatCreditCard(e.target.value)

        })

        registerCursorTracker({
          input: this.expiryField,
          delimiter: DefaultDateDelimiter,
        })
        this.expiryField.addEventListener('input', (e) => {
          this.expiryField.value = formatDate(e.target.value, {
            datePattern: ['m', 'y'],
          })
        })
    }
}
