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
You can now use the result (which can be different eg. succeeded, pending or failed).

**Important!**
Some Stripe Payment methods don't allow asynchronous capture (eg. SEPA debit). This library tries to use asynchronous capture, whenever possible. Where not possible, that payment will be created and captured on `create`. The best way to handle this, is either avoiding payment methods which can't be captured asynchronously or to call `$paymentGateway->cancel($payment)` which cancels the payment. This however only works, if the payment state is pending.  
See the [Stripe Docs](https://docs.stripe.com/payments/payment-methods/payment-method-support) for more info.


## Should I use this?
If you just want to integrate payments, then yes. If you need any more functionality, like subscriptions or more gateways I would suggest taking a look at [Payum](https://github.com/Payum/Payum).

## Known issues
### The library uses the outdated PayPal
This is correct, however the new [Server SDK](https://github.com/paypal/PayPal-PHP-Server-SDK) is still in beta and does not support all required methods.

### Disputes via PayPal webhooks are not supported
This library won't act on disputes via webhooks from PayPal. The issue is that incoming PayPal webhooks can't be securely verified without preventing MITM attacks. To mitigate this, the library will request the payment from PayPal on an incoming webhook request. The payment object however does not contains if the payment is disputed. A possible workaround would be to request the disputes via the dispute API and check if there is a match. This is however not implemented yet.