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


## Webhook Usages
- Webhooks will install automatically when you active the plugin.
- Also you can manage webhooks using the command line interface (CLI) commands provided by the plugin. These commands allow you to create, read, update, and delete webhooks as needed. 
```
bin/console cybersource:create-webhook
```

```
bin/console cybersource:read-webhook
```

```
bin/console cybersource:update-webhook
```

```
bin/console cybersource:delete-webhook
```

# Troubleshooting
- If you encounter any issues during installation or configuration, please check the following:
  - Ensure that your Shopware version is compatible with the plugin.
  - Verify that all required fields in the plugin settings are filled out correctly.
  - Check your server logs for any error messages related to the plugin.
  - Make sure to use HTTPS 


## Support & Contact

For assistance with the Cybersource Payment plugin:
- **Email:** [info@solution25.com](mailto:info@solution25.com)  
- **Phone:** +49 421 438 1919-0  
- **Website:** [https://www.solution25.com](https://www.solution25.com)
- **GitHub Repository:** [https://github.com/solution25com/cybersource-payment-shopware-6-solution25](https://github.com/solution25com/cybersource-payment-shopware-6-solution25)
