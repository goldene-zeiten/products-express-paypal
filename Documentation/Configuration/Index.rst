:navigation-title: Configuration

..  include:: /Includes.rst.txt
..  _configuration:

=============
Configuration
=============

..  contents:: Table of contents
    :local:

..  _configuration-credentials:

PayPal credentials (shared)
===========================

This extension has **no credentials of its own**. It reuses the PayPal configuration of the
``products-payment-paypal`` extension — the client id, secret and environment set under its site settings
(``products.payment.paypal.*``) or extension configuration. Configure PayPal once there; both the redirect
PayPal payment method and this express button then use the same account. See that extension's
documentation for the credential fields.

The express button is offered only once that configuration is complete (client id and secret set).

..  _configuration-site-set:

Site set
========

Activate the :guilabel:`Products PayPal Express Checkout` site set
(``goldene-zeiten/products-express-paypal``) on every site that should offer the express button. It depends
on the core and the ``products-payment-paypal`` site sets, which are pulled in automatically.

..  _configuration-plugin:

Placing the express button
==========================

The express button is a content element, :guilabel:`Products: PayPal Express Checkout`. Place it on the
**cart page**, typically above the :guilabel:`Proceed to checkout` button. It renders for the current
session basket and hides itself when the basket is empty, when PayPal is not configured, or when the basket
currency is not one PayPal settles in.

..  _configuration-page-types:

The endpoint page types
=======================

The PayPal JS SDK posts to three dedicated ``typeNum`` PAGEs — create, shipping and confirm — that run only
their one action and return its JSON verbatim. Their type numbers default to ``1784220820`` /
``1784220821`` / ``1784220822`` and can be changed via the TypoScript constants
:typoscript:`plugin.tx_productsexpresspaypal.settings.{create,shipping,confirm}.pageType` if they collide
with another page type on the site.
