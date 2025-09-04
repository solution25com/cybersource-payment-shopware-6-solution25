## [1.5.1] - 2025-09-04

- Refactor CyberSource integration to support sales channel-specific configurations and improve error handling for payment methods

## [1.5.0] - 2025-09-03

- Refactor isProductionActive method to use getBool for improved type safety

## [1.4.9] - 2025-09-03

- Refactor isProductionActive method to return a boolean and simplify condition checks for configuration keys

## [1.4.8] - 2025-08-14

- Handle orders without Cybersource transaction ID in Cybersource payment method
- Saved card design improvements for better user experience

## [1.4.7] - 2025-08-01

- Refactor CyberSource credit card styles and update billing state display; enhance localization for card details.

## [1.4.6] - 2025-07-28

- Handling of payment method changes during checkout has been improved to ensure a smoother user experience.

## [1.4.5] - 2025-07-22

- Add method to retrieve transaction details and update card_last_4 logic for improved payment processing

## [1.4.4] - 2025-07-21

- Introduced a dedicated service class for getAmount, making it easily extendable via decoration where needed.
- Capturing of Request ID from the Cybersource response is now supported.

## [1.4.3] - 2025-07-18

- **Cybersource Transaction Log Enhancements
  Updated logging for the following fields: PaymentId, Card Category, Payment Method Type, Card Last 4, Response Code, Gateway Authorization Code, Gateway Token, Gateway Reference.

## [1.3.9] - 2025-07-02

- **Enhanced Transaction Data & Logging**  
  The transaction data and internal logs now include detailed information about the **amount** and **currency**, providing better transparency for troubleshooting, reporting, and payment audits.


---

## [1.3.8] - 2025-06-30

- **Custom Fields with Cybersource Response Data**  
  Additional Cybersource response data is now stored in custom fields on the order, ensuring better traceability and filling in previously missing values.


---

## [1.3.7] - 2025-06-30

### Added
- **Saved Card Payment Instrument ID Support**  
  The plugin now stores the Cybersource `paymentInstrumentId` when customers choose to save their card, improving vaulted payment handling and future transactions.

### Changed
- **Transaction UI Design Improvements**  
  Visual and structural changes to the Cybersource payment transaction components for a cleaner, more consistent user experience in the storefront and administration.


---

## [1.3.6] - 2025-06-27

### Fixed
- **Error Handling Improvements**  
  Additional fixes and refinements to the handling of failed payment status transitions, ensuring more reliable order processing and improved visibility of errors in the Shopware Admin.

---

## [1.3.4] - 2025-06-20

### Added
- Implemented detailed order logging for Cybersource backend processing.
- Added a new data grid in the Shopware Admin to display order transaction history related to Cybersource payments.

---

## [1.3.3] - 2025-06-10

### Fixed
- Fixed: the issue with address form.
- Fixed: order subscriber handles other status changes correctly.

---

## [1.3.2] - 2025-06-10

### Fixed
- Fixed: the issue with automatic filled form.

---

## [1.3.1] - 2025-06-10

### Fixed
- Fixed the issue with the global css .

---

## [1.3.0] - 2025-06-04

### Fixed
- CSS style fix .

---

## [1.2.8] - 2025-06-01

### Fixed
- Resolved an issue where saved credit cards did not display corresponding card type logos (e.g., VISA, Mastercard, American Express) during checkout.

### Improved
- Enhanced the visual rendering of saved credit card information for a clearer and more user-friendly checkout experience.

---

## [1.2.7] - 2025-05-29
### Fixed
- Resolved an issue where the payment method form would not render properly due to a static payment method name.

---

## [1.2.6] - 2025-05-19
### Added
- Webhooks V2 implementation for more reliable and structured event handling.

### Changed
- Migrated all static strings to the Shopware snippet system for better localization and translation support.
- Codebase refactored for improved clarity and maintainability (CR fixes).

---

## [1.2.5] - 2025-05-14
### Fixed
- Payment.js validation now **only targets** the `confirmOrderForm` to prevent conflicts.

---

## [1.2.4] - 2025-05-08
### Changed
- The **"Save Card"** option is now hidden for guest customers to prevent confusion and ensure only registered users can access stored payment methods.

---


## [1.2.5] - 2025-05-14
### Fixed
- Payment.js validation now **only targets** the `confirmOrderForm` to prevent conflicts.

---

## [1.2.4] - 2025-05-08
### Changed
- The **"Save Card"** option is now hidden for guest customers to prevent confusion and ensure only registered users can access stored payment methods.

---

## [1.2.3] - 2025-05-07
### Changed
- Billing information is now collected **only** for saved cards, streamlining checkout for one-time card use cases.

---

## [1.2.2] - 2025-05-05
### Added
- Full billing information collection for card payments.
- Automatic webhook installation and removal on plugin activation/deactivation.

### Changed
- Updated `README.md` to document webhook automation behavior.

---

## [1.2.1] - 2025-04-28
### Added
- Full compliance with Shopware Extension Verifier.

### Fixed
- Minor configuration optimizations.
- Improved checkout stability for edge cases.

---

## [1.2.0] - 2025-04-25
### Added
- Saved card support with Cybersource tokenization.
- Ability for customers to select and manage saved cards during checkout and in account area.

### Changed
- Adjusted configuration and entity structures for tokenized card handling.
- Improved form UX and validation.

---

## [1.1.0] - 2025-04-22
### Added
- Support for 3D Secure 2.x in the checkout process.
- Enhanced error handling for transactions.

### Changed
- Refactored internal services and request handlers for better performance.


## [1.0.0] - 2025-02-25
### Added
- Initial release for CybersourceSw6Solution25 plugin.
- Full support for Shopware 6.5.x, 6.6.0, and 6.6.1 versions.
- Integrated Cybersource payment gateway with Shopware 6.
- Basic configurations and setup instructions for installation.
