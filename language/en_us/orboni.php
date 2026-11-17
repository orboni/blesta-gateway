<?php

// Gateway
$lang['Orboni.name'] = 'Orboni (crypto)';
$lang['Orboni.description'] = 'Crypto payments (USDT, USDC, BTC, ETH and more) straight to your own wallets. Orboni never holds your money.';

// Settings
$lang['Orboni.meta.secret_key'] = 'Secret key';
$lang['Orboni.meta.secret_key_note'] = 'In the Orboni dashboard, Developers → API keys. A test key (sk_test_…) makes test payments only: the key decides, so try the whole flow with one first.';
$lang['Orboni.meta.webhook_secret'] = 'Webhook signing secret';
$lang['Orboni.meta.webhook_secret_note'] = 'Under Developers → Webhooks, add an endpoint at the address below with every event, or at least the checkout.session.* ones, and paste its signing secret (whsec_…) here.';
$lang['Orboni.webhook'] = 'Webhook address';
$lang['Orboni.webhook_note'] = 'Orboni tells Blesta of each payment at this address. Payments still show once the client comes back from the payment page, but one confirmed later is recorded only by the webhook.';
$lang['Orboni.wallets_note'] = 'Payments go to the wallets you set under Wallets in the Orboni dashboard.';

// Payment step
$lang['Orboni.process.pay'] = 'Pay with crypto';
$lang['Orboni.process.test'] = 'Test mode: no real money is taken.';

// Session texts
$lang['Orboni.details.at'] = '%1$s at %2$s'; // %1$s is "Invoice #1001", %2$s the company's name
$lang['Orboni.details.credit'] = 'Account credit';

// Transaction notes
$lang['Orboni.note.paid'] = 'Paid %1$s %2$s on %3$s (%4$s).'; // amount, coin, network, worth in the invoice's currency
$lang['Orboni.note.paid_plain'] = 'Paid (%1$s).';
$lang['Orboni.note.test'] = 'Test payment: no real money moved.';
$lang['Orboni.note.late'] = 'Paid after the payment page ran out.';
$lang['Orboni.note.extra'] = 'More arrived after it was paid: %1$s in all; send the extra back from your wallet if the client asks.';
$lang['Orboni.note.processing'] = 'Confirming on the network.';
$lang['Orboni.note.partial'] = 'Part paid: %1$s of %2$s; waiting for the rest.';
$lang['Orboni.note.partial_expired'] = 'The payment page closed part paid: %1$s of %2$s arrived in your wallet and is applied; the rest stays due.';
$lang['Orboni.note.held'] = 'The money is in your wallet but held for your review in the Orboni dashboard (%1$s). Accept it there and the payment is approved.';
$lang['Orboni.note.reversed'] = 'Money that paid it left the chain (%1$s remains). It is approved again if the payment completes again.';
$lang['Orboni.note.expired'] = 'The payment page ran out unpaid.';

// Errors
$lang['Orboni.!error.secret_key.format'] = 'Enter an Orboni secret key: sk_live_… or sk_test_….';
$lang['Orboni.!error.webhook_secret.format'] = 'Enter the webhook endpoint\'s signing secret: whsec_….';
$lang['Orboni.!error.currency'] = 'Crypto payments are not taken in %1$s.';
$lang['Orboni.!error.minimum'] = 'This payment is below the minimum for a crypto payment ($1).';
$lang['Orboni.!error.too_many_invoices'] = 'Too many invoices for one crypto payment. Pay them in smaller groups.';
$lang['Orboni.!error.unavailable'] = 'Crypto payment is not available right now. Please try again in a minute or choose another payment method.';
$lang['Orboni.!error.in_progress'] = 'A crypto payment for this is already on its way. It shows here once it is confirmed.';
$lang['Orboni.!error.signature.invalid'] = 'Orboni webhook refused: its signature does not match the webhook signing secret.';
$lang['Orboni.!error.session.missing'] = 'The request names no Orboni payment.';
$lang['Orboni.!error.session.busy'] = 'Another request is recording this Orboni payment; Orboni sends the webhook again.';
$lang['Orboni.!error.session.unavailable'] = 'Orboni could not be reached to confirm the payment; it is checked again when Orboni retries the webhook.';
$lang['Orboni.!error.session.foreign'] = 'This Orboni payment was not made by this Blesta.';
$lang['Orboni.!error.session.unpaid'] = 'No payment has arrived for this payment page.';
