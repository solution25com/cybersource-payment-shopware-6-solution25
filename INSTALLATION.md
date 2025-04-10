# Installation Guide about Cybersource Connector for Shopware 6

## Setting up Cybersource connection
### Generating keys in Cybersource console
Cybersource has two different portals for Test and Production environments, use the one that you are working with and the steps to generate the keys remain the same.
1. Log into the business center.
2. From the left navigation, under Payment Configuration select **Key Management**.

![image1](https://github.com/user-attachments/assets/89f3a961-fd48-49ee-a3ad-98cdc1121bbf)

4. Click the **+GENERATE KEY** button on the right side of the screen.

![image8](https://github.com/user-attachments/assets/664fb968-57c9-4e0e-b001-7f87dcf32bd1)

5. In the pop-up window, select **REST - Shared Secret Key**.

![image30](https://github.com/user-attachments/assets/f454c1b1-48bc-4b17-98d7-12b9321c50e7)

6. Click **Generate Key** at the bottom of the popup.

7. After the key is generated, you can either download it or use the copy function to save the **Key** and **Shared Secret** values.

### Setting up Cybersource keys in Shopware administrator
1. Log into your Shopware store admin panel and navigate to **Extension >> My Extensions** link from the left navigation.
2. This will list all the plugins you have installed on the Shopware environment, find **Cybersource for Shopware 6**.
3. On that item near to the rightmost, look for the context menu (3 little dots), click and select **Configure**.
4. It will open up a screen that will have the ability to configure keys for test and live. This screen is referred to as the **Configuration Page** in the document from here onwards.

![image10](https://github.com/user-attachments/assets/cd97d4b8-0a72-48ed-a4b4-624197b858ae)

![image9](https://github.com/user-attachments/assets/1163f1bb-f344-405b-9006-2e8b611302bc)

### Switching between test and production environments
The plugin supports testing and validation before activating it for all customers on storefront. Cybersource interface allows us to generate a separate set of credentials for test and real connections which was already explained in preceding sections. In the configuration section, it is possible to switch between test and real connections with the help of the **Environment** setting in the configuration page.

Test connection does not do any real transaction; it just mimics the real transaction while Production connection actually charges the credit card. The setting defaults to test connection credentials, which can be switched to the real connection with the switch button shown below.

![image13](https://github.com/user-attachments/assets/e0ad9ac3-eb19-42d4-98b9-55d089d845a1)
Note: There is a separate section on **testing & validation** later in the guide.

### Setting up transaction mode
The plugin comes with two different transaction modes that can be configured in the configuration page of the plugin:

![image12](https://github.com/user-attachments/assets/7b968d6a-9068-4650-bf05-ed1389ac061b)

- **Auth Only**: This transaction mode places authorization on the credit card when the order is placed from the shopper's end. Capture on that authorization happens when the order is fulfilled at the administrator section of Shopware.
- **Auth and Capture**: This transaction mode places authorization and also captures at the same time when the order is placed. This mode is useful when you are dealing with digital products and there is no actual fulfillment that happens at some later point.

Some caveats to this are:
- When you are in **Auth Only** mode, authorization is placed only for a number of days, depending on your Cybersource settings and credit card provider. You will receive an error message if you try to capture a payment after authorization expires. Order modifications and cancellations do not refund but only modify the authorization that has been placed.
- When you are in **Auth and Capture** mode, payments are captured already on order placement and hence the **Capture** button isn't available in the administration.

### Enabling Cybersource payment method for Storefront
There are a number of steps to follow in order to enable the Cybersource payment method for Storefront.

1. Make sure that the plugin is installed correctly, enabled, and configured.
![image15](https://github.com/user-attachments/assets/a2e5b000-5c7a-445c-9b71-99c8d20a8274)
2. Shopware allows you to configure various settings at the sales channel level and each sales channel can have different configurations depending on how you are planning to use them.
3. Click on the desired sales channel from the left navigation.
4. In the **general** tab, scroll down to the **Payment and shipping** section.
5. In the **Payment methods** multi-select dropdown, select **CreditCard | CyberSource for Shopware6**.
6. You can also mark Cybersource as the default payment method by selecting the same payment method in **Default payment method**.
7. Do not forget to hit **Save** after changes.

![image16](https://github.com/user-attachments/assets/529afbe0-9926-4b67-bddc-ad5202e2e3c7)

## Storefront Features
### Payment Methods
Cybersource for Shopware 6 allows only Credit cards as a payment method for now.

### Guest checkout experience
With guest user checkout, Cybersource payment gateway as a payment option can be selected, and it allows adding information about credit cards. Once an order is placed by the user, the plugin makes a request to Cybersource via API depending on the transaction mode.

Shopware does support the guest checkout experience. However, it is not possible to deliver a personalized experience without knowing who the user is, hence the feature limitation comes into play. 
  Guest checkout does not allow cards to be saved on Cybersource. 
  Since we cannot save cards against guest users, it also limits using the saved card feature during checkout.

![image14](https://github.com/user-attachments/assets/5d16a464-f411-4de3-823e-45defcd02fc6)


### Logged in user experience
Logged in users have a slightly different experience compared to guest users in Shopware with the Cybersource plugin. Now that we know who the user is, additional capabilities are added on checkout. Saving a card on file while checking out is added for logged-in users to allow faster checkout in the future.

The card is tokenized and saved on Cybersource against the user. On subsequent orders, users get the option of paying with a saved credit card along with a new card. All the saved card tokens are stored against the user record in custom fields.

![image14](https://github.com/user-attachments/assets/332c508d-3092-4351-9132-0f244556b686)

![image20](https://github.com/user-attachments/assets/024ab1bf-7c19-4b1e-8088-2b58875670c3)

### Transaction information in custom fields
Various information about the Cybersource transaction is stored in custom fields for a given order. Below is the list of all attributes you might come across:

#### Order level custom fields:
- **cybersource_payment_details**: Used to store all related custom information within custom fields, which are listed below:
  - **transaction_id**: Transaction Id of the order when the first auth/capture was done.
  - **updated_total**: Updated Total Amount when partial/full refund is processed by the plugin.

#### Customer level custom fields:
- **cybersource_customer_id**: Customer id generated using the order's customer information.
- **cybersource_card_details**: Stores all payment instruments associated with Cybersource customers.

## Administration Features
### Payment capture
If the plugin is configured with Auth Only transaction mode, capture happens on order fulfillment. Any order that was placed with Auth Only mode, will have a separate Capture button enabled to capture a payment on authorization placed on credit card. Authorization expires depending on various factors like Cybersource settings and Credit card provider, once authorization that was placed on card expires capture may not be possible and throw an error.

![image18](https://github.com/user-attachments/assets/7b22e429-4dce-4721-902b-7e7042a483e0)

> **Note:** Payment capture is not available on orders where they were placed with **Auth and Capture** transaction mode.

### Partial / Full refunds
**Full Refund**: Go to the **Order Edit > Details** section and click on the **Refund** button to initiate a full refund for the total amount.
**Partial Refund**: If you update the order's total amount or quantity, which reduces the total price, upon clicking the **Save** button, it will confirm whether the price was reduced and whether you want to initiate a partial refund. You can initiate partial refund for the amount difference.

![image29](https://github.com/user-attachments/assets/e522e963-a134-4f9c-b067-9ae8902a3047)

For partial refund, if you update the order's total amount or quantity which reduces the total price, upon clicking the Save button, it will confirm as the price was reduced than original and whether you want to initiate partial refund or not, and by proceeding for refund, you can initiate partial refund of the amount difference.

![image17](https://github.com/user-attachments/assets/dd496c8c-4810-4a0b-91ba-5e98de08a385)

![image31](https://github.com/user-attachments/assets/d5d0d347-3bc0-4be2-b385-baa40bde4c2d)

### Order cancellation
Order cancellation can be initiated in two different ways in Shopware:
1. Customer initiated in **Storefront**.
2. Owner enabled in **Administrator**.

In either of the two scenarios, it does not impact Cybersource integration in any way, meaning no payment adjustments are made when the order is canceled. Order cancellation with Cybersource integration is considered another order transition from one status to another.

### Order modification
Shopware allows order modification in the administration interface. Apart from the pricing, all order update operations will work as usual. If the total pricing is reduced, you can opt for partial refund or ignore. If the price is increased, the admin will be prevented from updating that amount since credit card information is not stored in the database, so it is not possible to capture/authorize any additional amount.
![image2](https://github.com/user-attachments/assets/30b2ec79-79b3-47fa-a3d8-bb84731dd9c5)

## Cybersource interaction
### API flow
![image26](https://github.com/user-attachments/assets/8c5019a3-bade-43eb-a512-20def7e8554d)

> **Note:** Zoom into API Flow Diagram for better understanding & visualization.

### Information captured
On various events, shopware information is captured and posted to Cybersource for identification as well reporting purposes. Subsequent sections are trying to explain what and where the information is captured.


#### Order details
Basic order details information is captured and maintained throughout in Cybersource, that includes Order Id as Merchant Reference Number, also Email and Order Timestamps, screenshot attached below.

There is also partial credit card information available as Account Details.
![image24](https://github.com/user-attachments/assets/f9906e4a-2a8a-49e2-8b4f-52560b429713)

#### Order line items
Along with basic order details, Order line items are also posted to Cybersource from Shopware, screenshot below shows how information is available in Cybersource interface. It shows Quantity, SKU from Shopware, Product Name and Price along with Currency.

These Order line items are also maintained when order is modified in the administrator interface of Shopware and adjusted back into Cybersource. This helps in maintaining consistency between Shopware and Cybersource interface.

Top section of line items shows the total amount that transaction carries, which in turn is sum of all order line items and also maintained for any order modifications.

![image7](https://github.com/user-attachments/assets/769c8b08-675f-48b2-a5e8-8d04ee640fc0)

#### Customer information
Customer information on every transaction is also captured along with Order and Order line items. This information is available in the Billing Section for given order transaction in Cybsersource interface.

Screenshot below shows various customer information like Name, Address and Device Fingerprint is automatically captured.

![image23](https://github.com/user-attachments/assets/4180b6eb-b996-49a0-b454-8ed62d83e954)

#### Saved card tokens
As explained previously, logged in users have the ability to store the credit card information on Cybersource. When a customer opts to save the card on file, a secure token is issued on that card for the user to make future purchases based on the token instead of providing entire credit card information again. Screenshot below shows the tokens issued to a given user.
![image5](https://github.com/user-attachments/assets/1ad3e16d-5850-4fca-a4fd-065757099471)

### Error messages
| **Code**                              | **Error Text**                                                 |
|---------------------------------------|----------------------------------------------------------------|
| API_ERROR                             | Authorization System or issuer system inoperative              |
| ORDER_TRANSACTION_NOT_FOUND           | Order transaction of requested order_id could not be found     |
| SHOPWARE_ORDER_TRANSACTION_NOT_FOUND  | Order transaction of requested order_id could not be found.    |
| CYBERSOURCE_REFUND_AMOUNT_INCORRECT   | Order refund amount is not valid.                              |
| REFUND_TRANSACTION_NOT_ALLOWED        | Refund allowed only for order with Paid payment status         |
| CYBERSOURCE_ORDER_TRANSACTION_NOT_FOUND | Order transaction id from Cybersource could not be found     |
| SAVE_CARD_ERROR                       | Order creation successful, but failed to save card information |
| MISSING_FIELD                         | The request is missing one or more fields                      |
| INVALID_SECURITY_CODE                 | The CVC code you entered is invalid                            |
| INVALID_CARD_NUMBER                   | The card number you entered is invalid                         |
| INVALID_EXPIRY_DATE                   | The expiry date you entered is invalid                         |

## Testing & validation
For testing and validating Cybersource integration with Shopware 6, we have added test cases which can be executed against the working version of Shopware 6 with Cybersource plugin installed and configured correctly.
Before jumping on testing right away, make sure you have the following checklist completed.
Shopware 6 instance up and running.
Cybersource plugin installed and activated.
Cybersource test account and keys generated with that account.
Cybersouce plugin configured with keys generated and production mode disabled.
Cybersource payment method enabled for Sales channel trying to test on.

Once everything is set up correctly, one can start placing orders in various scenarios. There is a Cybersource Testing Guide on mimicking various scenarios and the input it expects for that.

In order to validate on our end while development, the team has developed test cases in standard format which can be used as well.

### Code health
Cybersource plugin was built with Continuous integration that had checks on unit tests and static analysis on code, which is recommended by Shopware and the wider PHP community. It comprises of PHP as primary language and Javascript was added wherever Shopware components were overridden

### Unit test
Unit test was implemented for PHP and Javascript both, below attached are screenshots for code coverage.

If one needs to run unit tests, there are separate commands for PHP & Javascript.
PHP: vendor/bin/phpunit
Javascript: npm test


![image6](https://github.com/user-attachments/assets/610521fa-c768-4725-8a7f-475bb0cba6c2)
![image3](https://github.com/user-attachments/assets/2d648101-7811-45ec-87bb-ad1e621d5892)

### Code linting
Code linting & Static Analysis were added to the continuous integration pipeline to ensure code quality and code formatting.
PHPStan, PHPCS with PSR12 Code Standard and Linters for any syntax errors on PHP were added.
For Javascript, ESLint is added with various configurations to ensure all code follows same standard across the board.

![image4](https://github.com/user-attachments/assets/01abb2c4-e861-4f2f-b2ab-41e29d492365)

