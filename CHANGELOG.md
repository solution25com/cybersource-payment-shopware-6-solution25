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
