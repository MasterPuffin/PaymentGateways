# Payment Gateways
Integrate payments with just a few lines of code.

## Why does this exist?
There are two main reasons which no other payment library offered which made me write this library:
1. I want a unified approach to process payments from different payment providers. This means I want to always call the same function in the same order regardless of the process of the payment provider.
   This rules out using the libraries of the payment providers on their own. This also rules out (Omnipay)[https://omnipay.thephpleague.com].
2. It should be simple to use and shouldn't require a framework like Laravel or Symfony.
   This rules out (Payum)[https://github.com/Payum/Payum]

## What does it do?
It creates the payment and returns the redirection url to the payment provider.
After the payment provider redirects back to your site, this will verify the payment has been made.
It will also handle incoming webhooks, verify them and return the payment status.

## What does it not do?
Saving the payments. You have to save the payment before redirecting to the payment provider and retrieve it to verify the payment.

## Which gateways are supported?
Currently Stripe Checkout and PayPal REST.

## Can you give me an example?
Sure!

First create a payment and set its data
```
$payment = new Payment();
$payment->setAmount(123.45);
$payment->setCurrencyCode('EUR');
$payment->setDescription('Test payment');
$payment->getCustomer()->setName('John Doe');
$payment->getCustomer()->setEmail('john@doe.com');
```

Then make a gateway.
Some gateways require additional data. If not supplied, this library will throw an exception.

```
$gateway = new PaymentGateway(Provider::Stripe, ['secret_key' => 'abc'], ['payment_method_types' => ['card']]);
$gateway->setSuccessUrl('https://www.example.com/success');
$gateway->setCancelUrl('https://www.example.com/success');

```
Now create the gateway for the payment. This will return the redirection url to the payment provider.
```
$redirectUrl = $gateway->create($payment);
```
Also save the payment somewhere, for example to a database. Creating the gateway also updated the providerId of the payment which will be needed later to verify the payment.
Then redirect to the redirectUrl.

After the user has been redirected back to your site, execute/capture and verify the payment.
```
$gateway = new PaymentGateway(Provider::Stripe, ['secret_key' => 'abc'], ['payment_method_types' => ['card']]);
$result = $gateway->execute($payment);
```
You can now use the result (which can be different eg. succeeded, pending or failed). It is best practice to check if the cart has been modified during the checkout process, by comparing $payment->getAmount() with the amount of the cart.

**Important!**
Some Stripe Payment methods don't allow asynchronous capture (eg. SEPA debit). This library tries to use asynchronous capture, whenever possible. Where not possible, that payment will be created and captured on `create`. The best way to handle this, is either avoiding payment methods which can't be captured asynchronously or to call `$paymentGateway->cancel($payment)` which cancels the payment. This however only works, if the payment state is pending.  
See the [Stripe Docs](https://docs.stripe.com/payments/payment-methods/payment-method-support) for more info.

### Webhooks
Look up the saved payment with the id from the webhook, then let the library verify the event and return the status.
```
$gateway = new PaymentGateway(Provider::Stripe_Checkout, ['secret_key' => 'abc', 'webhook_secret' => 'whsec_abc']);
$providerId = $gateway->getProviderIdFromWebhook($request); // PSR-7 request, not verified yet
//TODO load the payment saved with $providerId
$status = $gateway->getStatusFromWebhook($payment, $request);
```
Stripe events are verified with the webhook secret. PayPal events are not signed reliably, so the library fetches the status of the capture from the PayPal API instead of trusting the payload.


## Should I use this?
If you just want to integrate payments, then yes. If you need any more functionality, like subscriptions or more gateways I would suggest taking a look at [Payum](https://github.com/Payum/Payum).

## Known issues
### PayPal disputes need a direct API call
The PayPal [Server SDK](https://github.com/paypal/PayPal-PHP-Server-SDK) has no disputes API, so the dispute check calls it directly with the SDK's OAuth token (requires `ext-curl`).

### Disputes via PayPal webhooks
Incoming PayPal webhooks can't be securely verified without preventing MITM attacks, so the payload is never trusted. For dispute events the library requests the dispute from the dispute API and only reports `Disputed` if it is unresolved and belongs to the payment's capture. Resolved disputes return the status of the capture.