# TYPO3 extension `products_express_paypal`

> **This repository is READ-ONLY.**
> It is split automatically out of the [goldene-zeiten/products](https://github.com/goldene-zeiten/products)
> monorepo, which is the single source of truth. Pull requests and commits made
> here are overwritten by the next split — please open them in the monorepo instead.

PayPal's Smart Payment Buttons as a one-tap express checkout for the
[Products](https://github.com/goldene-zeiten/products-core) shop system. The button opens PayPal's sheet on
the cart page, the buyer approves with their PayPal address, the shop recomputes shipping live for that
address, and the money is captured through the PayPal Orders v2 API for the total the shop computed itself
before the paid order is created.

It plugs into the core shop's express-checkout provider seam and reuses the PayPal configuration of
`products-payment-paypal`, so one PayPal account serves both the redirect checkout and the express button.

## Installation

```shell
composer require goldene-zeiten/products-express-paypal
```

Add the "Products PayPal Express Checkout" site set to your site, place the express checkout plugin on the
cart page and make sure the PayPal credentials are configured in `products-payment-paypal` — see the
documentation. Without them the button is hidden.

## Documentation

See `Documentation/` (rendered on the TYPO3 documentation site) for installation, configuration, the express
flow and the public extension points.

## Requirements

- TYPO3 13.4 LTS or 14.3 LTS
- PHP 8.2 or newer
- `goldene-zeiten/products-core`, `goldene-zeiten/products-payment-paypal` and
  `goldene-zeiten/products-api-client`
- A PayPal business account with REST API credentials

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
