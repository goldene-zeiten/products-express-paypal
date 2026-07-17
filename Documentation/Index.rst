.. include:: /Includes.rst.txt

..  _start:

================================
Products PayPal Express Checkout
================================

:Extension key:
    products_express_paypal

:Package name:
    goldene-zeiten/products-express-paypal

:Version:
    |release|

:Language:
    en

:Author:
    Markus Hofmann

:License:
    This document is published under the
    `Creative Commons BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`__
    license.

:Rendered:
    |today|

----

PayPal's Smart Payment Buttons as a one-tap express checkout for the
`Products <https://github.com/goldene-zeiten/products-core>`__ shop system. A PayPal button on the cart
page opens PayPal's own sheet, where the buyer approves with their PayPal address; the shop computes
shipping live against that address and captures the order once approved — no multi-step checkout. It
reuses the PayPal account the redirect ``products-payment-paypal`` method is already configured with.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        What this extension adds, and how the create/shipping/capture flow works.

    ..  card:: :ref:`Installation <installation>`

        How to install and activate the extension.

    ..  card:: :ref:`Configuration <configuration>`

        The (shared) PayPal credentials and placing the express button plugin.

    ..  card:: :ref:`Users Manual <users-manual>`

        What the shopper sees on the cart page.

    ..  card:: :ref:`Developer <developer>`

        The provider seam, the create/shipping/confirm endpoints and the button JS.

**Table of Contents:**

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    Configuration/Index
    UsersManual/Index
    Developer/Index
