![416718745-868b6374-f95a-4d73-a33f-41c97a8a5271](https://github.com/user-attachments/assets/1bc30430-6496-4dca-ba59-a4c2d52a1520)

# Cybersource Payment

## Introduction

The Cybersource plugin enables secure credit card payments in Shopware stores. It integrates with Cybersource's payment gateway to process transactions, supports guest checkouts, and allows returning customers to save their card details for faster purchases. The plugin provides real-time transaction tracking, PCI compliance, and flexible refund options.

### Key Features

1. **Secure Transactions**
   - PCI-compliant payments with real-time fraud protection.
2. **Multiple Payment Modes**
   - Choose between Auth Only or Auth & Capture.
3. **Guest Checkout Support**
   - Accept credit card payments without requiring customer accounts.
4. **Saved Payment Methods**
   - Returning customers can save cards for faster checkout.
5. **Transaction Management**
   - View and manage transactions directly in Shopware admin.
6. **Flexible Refund Options**
   - Process full or partial refunds with ease.
7. **Multi-Store Compatibility**
   - Works across multiple Shopware instances.

## Get Started

### Installation & Activation

1. **Download**

## Git

- Clone the Plugin Repository:
```bash
git clone https://github.com/solution25com/cybersource-payment-shopware-6-solution25.git
```

## Packagist
 ```
  composer require solution25/cybersource
  ```


2. **Install the Plugin in Shopware 6**

- Log in to your Shopware 6 Administration panel.
- Navigate to Extensions > My Extensions.
- Locate the Cybersource plugin and click Install.

3. **Activate the Plugin**

- After installation, click Activate to enable the plugin.
- In your Shopware Admin, go to Settings > Shop > Payment Methods.
- Check if the "Cybersource Payment" is active and make sure it's also added to the sales channels.

4. **Verify Installation**

- After activation, you will see Cybersource Payment in the list of installed plugins.
- The plugin name, version, and installation date should appear as shown in the screenshot below.

![Screenshot from 2025-04-18 15-00-40](https://github.com/user-attachments/assets/a6310d2c-eacc-4190-87b5-6898a5030ade)

## Plugin Configuration

### 1. **Access Plugin Settings**

- Go to Extensions > My Extensions.
- Locate Cybersource Payment and click configure to open the plugin settings.

### 2. **General Settings**

#### **Sales Channel**
- Select the sales channel(s) where you want Cybersource to be active.

![Screenshot from 2025-04-18 14-06-54](https://github.com/user-attachments/assets/05e7a361-3444-4d8a-a6e1-024317beb39d)


#### **Environment**
- You can switch to Production environment or not .

![Screenshot from 2025-04-18 14-07-17](https://github.com/user-attachments/assets/5160c2c0-2781-46df-aa6c-987d9d7e0b5a)

#### **Live Account Keys**
- Enter the organization ID , access KEY and the share product Key

![Screenshot from 2025-04-18 14-07-46](https://github.com/user-attachments/assets/a54c889e-2091-49e6-b630-63d31ca973fa)

#### **Sanbox Account Keys**
- Enter the organization ID , access KEY and the share product Key for the sandbox account keys.

![Screenshot from 2025-04-18 14-58-37](https://github.com/user-attachments/assets/164631e8-8e6b-4ec2-ab22-dc60199560a9)

#### **Transaction Type**
- Choose between Auth Only or Auth & Capture.

![Screenshot from 2025-04-18 14-08-39](https://github.com/user-attachments/assets/89e82fa6-9e4a-4f08-bf9e-e4c5157de9be)

![Screenshot from 2025-04-18 14-08-56](https://github.com/user-attachments/assets/fee51fe6-fa31-4dff-b19c-effb5c9665cd)

#### **3D Secure**
- You can enable 3ds.

![Screenshot from 2025-04-18 14-09-13](https://github.com/user-attachments/assets/4a6a447d-5215-4011-86eb-c58ec32de8df)

### 3. **Save Configuration**

- Click Save in the top-right corner to store your settings.



---

# API Documentation
- [API Info](https://github.com/solution25com/cybersource-payment-shopware-6-solution25/wiki/api-documentation)

---

## Programmatic Payment Action Endpoints

The plugin provides API endpoints for programmatic payment actions, ideal for custom integrations with external systems (e.g., ERP). These endpoints allow you to capture, void, refund, and re-authorize payments for specific orders using the parent order ID and an amount.

### Endpoints
- **Capture Payment**
    - **Path**: `POST /api/cybersource/order/{orderId}/capture`
    - **Body**: `{ "amount": <decimal> }`
    - **Description**: Captures the specified amount for the given order. The `orderId` must reference the parent order with a valid CyberSource transaction ID.
    - **Example**:
      ```json
      {
          "amount": 100.00
      }
      ```

- **Void Payment**
    - **Path**: `POST /api/cybersource/order/{orderId}/void`
    - **Body**: `{ "amount": <decimal> }`
    - **Description**: Voids the specified amount for the given order. The `orderId` must reference the parent order with a valid CyberSource transaction ID.
    - **Example**:
      ```json
      {
          "amount": 100.00
      }
      ```

- **Refund Payment**
    - **Path**: `POST /api/cybersource/order/{orderId}/refund`
    - **Body**: `{ "amount": <decimal> }`
    - **Description**: Refunds the specified amount for the given order. The order must be in a `PAID` state. The `orderId` must reference the parent order with a valid CyberSource transaction ID.
    - **Example**:
      ```json
      {
          "amount": 50.00
      }
      ```

- **Re-Authorize Payment**
    - **Path**: `POST /api/cybersource/order/{orderId}/reauthorize`
    - **Body**: `{ "amount": <decimal> }`
    - **Description**: Increments the authorization amount for the given order by the specified amount. The `orderId` must reference the parent order with a valid CyberSource transaction ID. Note that this feature may require activation on the CyberSource side to be fully supported.
    - **Example**:
      ```json
      {
          "amount": 50.00
      }
      ```

### Usage Notes
- **Authentication**: Use Shopware's standard API token authentication for all endpoints.
- **Permissions**: All endpoints require the `order.update` ACL permission.
- **Amount Validation**: The `amount` must be a positive decimal value (e.g., `100.00`). For refunds, it must not exceed the order's total amount.
- **Disabling Admin UI Transitions**: To prevent conflicts with Admin UI payment status changes, disable the `OrderPaymentStatusSubscriber` by commenting out or removing its definition in `Resources/config/services.xml`.
- **Usage Example**:
  ```bash
  curl -X POST \
    https://your-shopware-url/api/cybersource/order/12345/capture \
    -H 'Authorization: Bearer <your-token>' \
    -H 'Content-Type: application/json' \
    -d '{"amount": 100.00}'
  ```

## Webhook Management

Webhooks are automatically installed when the plugin is activated. You can also manage webhooks using the following CLI commands:

- **Create Webhook**:
  ```bash
  bin/console cybersource:create-webhook
  ```

- **Read Webhook**:
  ```bash
  bin/console cybersource:read-webhook
  ```

- **Update Webhook Status**:
  ```bash
  bin/console cybersource:update-status-webhook --active=true
  ```

- **Delete Webhook**:
  ```bash
  bin/console cybersource:delete-webhook
  ```

---



# Troubleshooting
- If you encounter any issues during installation or configuration, please check the following:
  - Ensure that your Shopware version is compatible with the plugin.
  - Verify that all required fields in the plugin settings are filled out correctly.
  - Check your server logs for any error messages related to the plugin.
  - Make sure to use HTTPS 

# FAQ

### How can I configure custom fraud validation rules in CyberSource?

If you're facing transaction issues due to fraud validation (e.g. CVV mismatch, AVS mismatch), these settings can be reviewed and configured in your CyberSource Business Center.

#### Steps to Configure Custom Fraud Rules:

1. Log in to your CyberSource account at: [https://businesscentertest.cybersource.com/ebc2](https://businesscentertest.cybersource.com/ebc2)
2. Navigate to **Fraud Management** in the left-hand menu.
3. Go to **Rule Configuration**.
4. In the **Standard Rules** panel, you'll see predefined rules like:
   - AVS Mismatch
   - CVV Mismatch
   - Invalid Address
   - Billing-Shipping Mismatch
   - Billing-IP Country Mismatch
5. Each rule can be set to one of the following:
   - **Monitor**
   - **Reject**
   - **Review**
6. You can customize these rules based on your business needs. For example:
   - Set "CVV Not Verifiable" to **Reject** to block transactions without valid CVV.
   - Set "AVS Partial Match" to **Disabled** if you want to allow more leniency.

>  Every user or merchant can define their own fraud detection rules in this panel to control transaction flow and reduce false rejections.

#### Why is this important?

Many issues reported by customers regarding rejected payments are due to fraud rule settings. Ensuring these are configured appropriately helps reduce false positives and improves customer experience.

If you're unsure how to proceed, please contact your fraud management team or refer to CyberSource's official [Fraud Management Guide](https://www.cybersource.com) (login may be required).



## Support & Contact

For assistance with the Cybersource Payment plugin:
- **Email:** [info@solution25.com](mailto:info@solution25.com)  
- **Phone:** +49 421 438 1919-0  
- **Website:** [https://www.solution25.com](https://www.solution25.com)
- **GitHub Repository:** [https://github.com/solution25com/cybersource-payment-shopware-6-solution25](https://github.com/solution25com/cybersource-payment-shopware-6-solution25)
