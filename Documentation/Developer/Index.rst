:navigation-title: Developer

..  include:: /Includes.rst.txt
..  _developer:

=========
Developer
=========

..  contents:: Table of contents
    :local:

The provider
============

:php:`GoldeneZeiten\Products\Express\Paypal\Express\PaypalExpressCheckoutProvider` implements the core
:php:`GoldeneZeiten\Products\Core\Express\ExpressCheckoutProviderInterface`. It decides availability (the
shared PayPal configuration complete, currency supported) and hands the frontend the client id, currency
and the core shipping-quote endpoint path. It reuses ``products-payment-paypal``'s
:php:`PaypalConfigurationFactory`, so express and redirect PayPal share one account.

The endpoints
=============

:php:`GoldeneZeiten\Products\Express\Paypal\Controller\ExpressCheckoutController` has four actions:

*   :php:`buttonAction()` renders the button mount and issues the per-basket signed token.
*   :php:`createAction()` creates the PayPal order for the goods total and returns its id.
*   :php:`shippingAction()` recomputes shipping for the picked address, patches the PayPal order, and
    reports the chosen option.
*   :php:`confirmAction()` captures the approved order and creates the paid shop order, answering with the
    thank-you URL.

The three JSON actions each run as their own ``typeNum`` PAGE (see :ref:`configuration-page-types`) so they
execute as in-page requests — with the session basket and full configuration available — yet return raw
JSON to the PayPal JS SDK. The orchestration lives in
:php:`GoldeneZeiten\Products\Express\Paypal\Express\PaypalExpressCheckoutService`, and the PayPal Orders v2
create/patch/capture calls in
:php:`GoldeneZeiten\Products\Express\Paypal\Order\ExpressPaypalOrderClient`, which reuses the shared
api-client HTTP + OAuth stack and PayPal's own credentials.

Server-authoritative amount
===========================

The amount is patched onto the PayPal order once more at confirm, immediately before capture, so what is
captured is exactly the server-computed total (goods plus the chosen carrier cost) and never a figure the
client could have influenced — the same "recompute, never trust the client" rule the Stripe express
provider follows.

The button JavaScript
=====================

:file:`Resources/Public/JavaScript/express-checkout.js` is a plain ES module that loads the PayPal JS SDK
on demand from the configured client id and currency, renders :code:`paypal.Buttons`, and wires
:code:`createOrder` / :code:`onShippingAddressChange` / :code:`onApprove` to the create, shipping and
confirm endpoints, then sends the browser to the returned thank-you URL.

..  _developer-testing:

Testing
=======

PayPal's approval window is external and cannot be driven in a headless browser, so there is no browser
test for the button itself. The settlement path — create, patch, capture and paid-order creation, the part
that would break silently — is covered end-to-end by
:php:`PaypalExpressCheckoutServiceTest`, which runs the real Orders v2 HTTP calls against the shared
WireMock mock and asserts a paid order is created (and that a declined capture creates none).
:php:`ExpressPaypalOrderClientTest` covers the client's create/patch/capture and decline paths directly.
