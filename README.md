# Orboni for Blesta

Take crypto for your invoices: USDT, USDC, BTC, ETH and more, on the networks your clients use. The money goes straight to the wallets you add in the Orboni dashboard. Orboni never holds it: it watches the chain and tells Blesta when a payment arrives.

## Requirements

- Blesta 6 (tested on 6.0.4)
- An Orboni account (https://orboni.com) with at least one wallet added

## Install

1. Upload the `orboni` folder to `components/gateways/nonmerchant/` of your Blesta.
2. In Blesta's admin, open Settings → Company → Payment Gateways → Available, and install **Orboni (crypto)**. Its settings open.
3. In the Orboni dashboard, Developers → API keys: copy a secret key (`sk_test_…` to try it, `sk_live_…` for real payments) into **Secret key**.
4. In the Orboni dashboard, Developers → Webhooks: add an endpoint at the **Webhook address** shown on the gateway's settings page (`https://<your Blesta>/callback/gw/1/orboni/`), with every event or at least the `checkout.session.*` ones. Copy its signing secret (`whsec_…`) into **Webhook signing secret**.
5. Tick the currencies to offer it in, and save.

Pay a test invoice with a test key first: signed in to the Orboni dashboard, the payment page has a button that simulates the payment. The key decides test or live.

## How a payment moves

- The client chooses Orboni (crypto) when paying one or more invoices, then **Pay with crypto**, and goes to Orboni's payment page for the total, in the invoices' currency. Coming back to that step while the page has more than five minutes left shows the same page.
- The client sends the coin from their wallet. While the network confirms it, Blesta shows a **pending** transaction; once it is final, the transaction is **approved** and applied to the invoices.
- Every webhook and the client's return are checked by reading the payment back from Orboni with your secret key. Orboni retries a webhook that did not get through.
- Part paid, or held for your review in the Orboni dashboard: the transaction stays pending and says why. A page that closed part paid: what arrived is approved and applied, and the rest stays due. Money that left the chain: the transaction is declined and the invoices are due again.

## Good to know

- Orboni takes prices in 37 currencies; the gateway is offered only in those. The least payment is $1.
- An invoice paid twice: the second payment stays the client's credit.
- Refunds: the money is in your wallet, so send it back from there and record the refund in the Orboni dashboard and in Blesta.
- Each step is in Blesta's gateway log (Tools → Logs → Gateway); keys and secrets never are.
- Recurring payments are not supported: renewal invoices are paid like any other.
- Full guide: https://orboni.com/docs/blesta

## Support

support@orboni.com, or the support chat in the Orboni dashboard.

## License

MIT, see LICENSE.
