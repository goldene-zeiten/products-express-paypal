:navigation-title: Introduction

..  include:: /Includes.rst.txt
..  _introduction:

============
Introduction
============

EXT:products_express_paypal adds PayPal's Smart Payment Buttons as a one-tap express checkout to the
`Products <https://github.com/goldene-zeiten/products-core>`__ shop. It plugs into the core shop's
express-checkout provider seam (:php:`GoldeneZeiten\Products\Core\Express\ExpressCheckoutProviderInterface`),
which inverts the normal checkout order: the button opens PayPal's sheet on the **cart page, before an order
exists**, the buyer approves with their PayPal address, and shipping is computed live.

..  contents:: Table of contents
    :local:

Reusing the PayPal account
==========================

This extension does not configure PayPal itself. It depends on ``products-payment-paypal`` and reuses that
extension's PayPal configuration — the same client id, secret and environment — so a shop that already
offers PayPal at redirect checkout only needs to place the express button. One PayPal account serves both.

The express flow
================

1.  **Create.** When the buyer taps the button, the PayPal JS SDK asks the shop to create a PayPal order for
    the **goods total the shop computes** (never a figure from the client), and PayPal opens its sheet.

2.  **Live shipping.** When the buyer picks a shipping address in the sheet, the shop recomputes shipping for
    that address against its own carriers, **patches the PayPal order** so the sheet total reflects goods
    plus shipping, and remembers the chosen option.

3.  **Capture.** On approval, the shop patches the order once more to the server-computed total — the
    security backstop that makes the captured amount authoritative — **captures** the money via the PayPal
    Orders v2 API, and only then creates the paid order through the same order-creation and finalization
    services the normal checkout runs on. It answers with the thank-you URL the browser is sent to.

Because the amount is patched immediately before capture, the buyer is charged exactly the server-computed
total, and the order is created only after PayPal has actually captured the money.

Live shipping is a single computed option
=========================================

This release patches the PayPal order with **one** shipping option — the first the shop's carriers return
for the address (typically the cheapest / default) — rather than offering a selectable list of carriers
inside the PayPal sheet. The buyer still sees the correct total for their address; a selectable in-sheet
carrier list is a planned later refinement.

Availability
============

The button is only offered when the shared PayPal configuration is complete (client id and secret set) and
the basket currency is one PayPal settles in; otherwise it is hidden rather than failing when tapped.

Scope of this release
=====================

This release covers the **pay flow** only: creating, patching and capturing the PayPal order, and creating
the paid order. Refunds from the backend order module are a planned later phase. The capture-and-create
path is covered end-to-end by a functional test against a PayPal API mock — see :ref:`developer-testing`.
