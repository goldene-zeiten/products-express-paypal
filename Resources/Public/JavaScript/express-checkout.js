/**
 * PayPal Express Checkout (Smart Payment Buttons)
 *
 * Renders PayPal's own button for the live basket the button plugin rendered, and drives the express flow
 * against the shop's server:
 *
 *  - `createOrder` asks the shop to create the PayPal order for the goods total (the amount is the shop's,
 *    never the client's);
 *  - `onShippingAddressChange` posts the picked address to the shop, which recomputes shipping, patches the
 *    PayPal order so the sheet total reflects it, and reports the chosen option back;
 *  - `onApprove` posts the approved order plus the buyer's PayPal address to the confirm endpoint, which
 *    captures the money and creates the paid order, then sends the browser to the thank-you URL.
 *
 * The PayPal JS SDK is loaded on demand from the client id and currency the shop configured.
 */
export default class PaypalExpressCheckout {
  constructor(container) {
    this.container = container;
    this.clientId = container.getAttribute('data-client-id');
    this.currency = container.getAttribute('data-currency');
    this.createUrl = container.getAttribute('data-create-url');
    this.shippingUrl = container.getAttribute('data-shipping-url');
    this.confirmUrl = container.getAttribute('data-confirm-url');
    this.orderId = '';
    this.selectedShippingOption = '';
    if (this.clientId) {
      this.loadSdk()
        .then(() => this.render())
        .catch((error) => console.error('PayPal SDK failed to load:', error));
    }
  }

  loadSdk() {
    return new Promise((resolve, reject) => {
      if (window.paypal) {
        resolve();
        return;
      }
      const script = document.createElement('script');
      script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(this.clientId)}&currency=${encodeURIComponent(this.currency)}&intent=capture`;
      script.onload = resolve;
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  render() {
    if (!window.paypal) {
      return;
    }
    window.paypal
      .Buttons({
        createOrder: () => this.createOrder(),
        onShippingAddressChange: (data, actions) => this.onShippingAddressChange(data, actions),
        onApprove: (data, actions) => this.onApprove(data, actions),
      })
      .render(this.container);
  }

  async createOrder() {
    const response = await fetch(this.createUrl, { method: 'POST' });
    const data = await response.json();
    this.orderId = data.orderId || '';
    return this.orderId;
  }

  async onShippingAddressChange(data, actions) {
    const address = data.shippingAddress || {};
    const quote = await this.postForm(this.shippingUrl, {
      orderId: this.orderId,
      country: address.countryCode || '',
      postalCode: address.postalCode || '',
      state: address.state || '',
    });
    if (!quote || !quote.serviceable) {
      return actions.reject();
    }
    this.selectedShippingOption = quote.shippingOption || '';
    return undefined;
  }

  async onApprove(data, actions) {
    const details = await actions.order.get();
    const payload = await this.postForm(this.confirmUrl, this.confirmFields(data.orderID || this.orderId, details));
    if (payload && payload.redirectUrl) {
      window.location.assign(payload.redirectUrl);
    }
  }

  confirmFields(orderId, details) {
    const unit = (details.purchase_units || [])[0] || {};
    const address = (unit.shipping || {}).address || {};
    const payer = details.payer || {};
    const name = payer.name || {};
    return {
      orderId,
      shippingOption: this.selectedShippingOption,
      email: payer.email_address || '',
      firstName: name.given_name || '',
      lastName: name.surname || '',
      street: [address.address_line_1, address.address_line_2].filter(Boolean).join(' '),
      postalCode: address.postal_code || '',
      city: address.admin_area_2 || '',
      country: address.country_code || '',
      state: address.admin_area_1 || '',
    };
  }

  async postForm(url, fields) {
    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(fields),
      });
      return await response.json();
    } catch (error) {
      console.error('PayPal express request failed:', error);
      return null;
    }
  }
}

document
  .querySelectorAll('[data-paypal-express-checkout]')
  .forEach((container) => new PaypalExpressCheckout(container));
