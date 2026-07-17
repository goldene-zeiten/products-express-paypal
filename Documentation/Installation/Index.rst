:navigation-title: Installation

..  include:: /Includes.rst.txt
..  _installation:

============
Installation
============

..  _installation-requirements:

Requirements
============

*   TYPO3 13.4 LTS or 14.3
*   PHP 8.2, 8.3, 8.4 or 8.5
*   `goldene-zeiten/products-core`, `goldene-zeiten/products-payment-paypal` (whose PayPal account and
    configuration this extension reuses) and `goldene-zeiten/products-api-client` — all installed
    automatically as dependencies
*   A PayPal account with REST API credentials — configured once in ``products-payment-paypal``

..  _installation-composer:

Installation with Composer
===========================

..  code-block:: bash

    composer require goldene-zeiten/products-express-paypal

Then:

#.  Activate the site set :guilabel:`Products PayPal Express Checkout`
    (``goldene-zeiten/products-express-paypal``) on every site that should offer the express button.
#.  Make sure the PayPal credentials are configured in ``products-payment-paypal`` — see
    :ref:`Configuration <configuration>`. Without them the button is hidden.
#.  Place the :guilabel:`Products: PayPal Express Checkout` content element on the cart page — see
    :ref:`configuration-plugin`.
