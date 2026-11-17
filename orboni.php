<?php

/**
 * Orboni for Blesta: crypto payments (USDT, USDC, BTC, ETH and more)
 * straight to the merchant's own wallets.
 *
 * @license MIT, see LICENSE
 */

require_once __DIR__.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'api_exception.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'api.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'currencies.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'signature.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'text.php';

use Orboni\Blesta\Api;
use Orboni\Blesta\ApiException;
use Orboni\Blesta\Currencies;
use Orboni\Blesta\Signature;
use Orboni\Blesta\Text;

/**
 * The client pays on Orboni's payment page, in the coin and network they
 * choose; the money goes to the wallets the merchant added in the Orboni
 * dashboard, and Orboni only watches the chain.
 *
 * Blesta hears of a payment at its callback address twice: Orboni's signed
 * webhook, and the client coming back from the payment page. Neither is
 * believed as it comes: the session is read back from Orboni with the secret
 * key, and Blesta is told what Orboni shows now. Blesta keeps one transaction
 * per session (its transaction id is the session's) and moves it as the
 * payment moves: pending while it confirms or waits for the merchant's
 * review, approved once paid, which applies it to the invoices.
 *
 * PHP 7.2 syntax: Blesta 5 runs on it.
 */
class Orboni extends NonmerchantGateway
{
    public const REVERSED = 'checkout.session.payment_reversed';

    public const EXTRA = 'checkout.session.extra_received';

    /** Idempotency keys tried for one Pay: each payment page that ran out takes the next. */
    private const MAX_TRIES = 10;

    /** Time a payment page must still have to be sent to again: less, and the client gets a new one. */
    private const REUSE_MIN_SECONDS = 300;

    /** Seconds a callback waits for another one about the same session. */
    private const LOCK_WAIT = 10;

    /** Orboni's network keys as people write them; a network added later reads as its key. */
    private const CHAIN_NAMES = [
        'tron' => 'TRON', 'ethereum' => 'Ethereum', 'bsc' => 'BNB Chain', 'solana' => 'Solana', 'ton' => 'TON',
        'polygon' => 'Polygon', 'base' => 'Base', 'arbitrum' => 'Arbitrum', 'optimism' => 'Optimism',
        'avalanche' => 'Avalanche', 'bitcoin' => 'Bitcoin', 'litecoin' => 'Litecoin', 'dogecoin' => 'Dogecoin',
        'bitcoincash' => 'Bitcoin Cash', 'xrp' => 'XRP Ledger', 'stellar' => 'Stellar',
    ];

    /** @var array<string, mixed> */
    private $meta = [];

    /** @var string|null */
    private $currency;

    /** @var Api|null */
    private $api;

    public function __construct()
    {
        $this->loadConfig(__DIR__.DS.'config.json');
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('orboni', null, __DIR__.DS.'language'.DS);
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function setMeta(?array $meta = null)
    {
        $this->meta = $meta ?: [];
    }

    public function getSettings(?array $meta = null)
    {
        $this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', __DIR__.DS));
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta ?: []);
        $this->view->set('webhook_url', $this->callbackUrl());

        return $this->view->fetch();
    }

    public function editSettings(array $meta)
    {
        foreach (['secret_key', 'webhook_secret'] as $field) {
            $meta[$field] = trim((string) ($meta[$field] ?? ''));
        }

        $this->Input->setRules([
            'secret_key' => [
                'format' => [
                    'rule' => ['matches', Api::SECRET_KEY],
                    'message' => Language::_('Orboni.!error.secret_key.format', true),
                ],
            ],
            'webhook_secret' => [
                'format' => [
                    'rule' => ['matches', Api::WEBHOOK_SECRET],
                    'message' => Language::_('Orboni.!error.webhook_secret.format', true),
                ],
            ],
        ]);
        $this->Input->validates($meta);

        return $meta;
    }

    public function encryptableFields()
    {
        return ['secret_key', 'webhook_secret'];
    }

    /**
     * The client chose crypto: a button to Orboni's payment page for the
     * amount. Coming back to this step while that page is still open shows
     * the same page, never a second one for the same payment.
     */
    public function buildProcess(array $contact_info, $amount, ?array $invoice_amounts = null, ?array $options = null)
    {
        $currency = strtoupper((string) $this->currency);
        $clientId = (int) ($contact_info['client_id'] ?? 0);
        if (! Currencies::supports($currency)) {
            return $this->refuse('currency', Language::_('Orboni.!error.currency', true, $currency));
        }
        $minor = Currencies::minor((float) $amount, $currency);
        if ($minor <= 0 || ($currency === 'USD' && $minor < Currencies::MIN_USD_CENTS)) {
            return $this->refuse('amount', Language::_('Orboni.!error.minimum', true));
        }
        if ($clientId <= 0 || $this->secretKey() === '') {
            return $this->refuse('setup', Language::_('Orboni.!error.unavailable', true));
        }

        $invoices = [];
        foreach ((array) $invoice_amounts as $line) {
            if (isset($line['id'], $line['amount']) && (float) $line['amount'] > 0) {
                $invoices[] = ['id' => (int) $line['id'], 'amount' => Currencies::minor((float) $line['amount'], $currency)];
            }
        }
        $serialized = $this->serializeInvoices($invoices, $currency);
        if (strlen($serialized) > 500) {
            return $this->refuse('invoices', Language::_('Orboni.!error.too_many_invoices', true));
        }

        $body = $this->sessionBody($clientId, $minor, $currency, $invoices, $serialized, (array) $options);
        $key = 'bl-'.self::siteKey().'-'.substr(hash('sha256', $clientId.'|'.$serialized.'|'.$minor.$currency), 0, 16);

        try {
            $session = $this->openSession($body, $key);
        } catch (ApiException $e) {
            $this->log('orboni/session', 'Client '.$clientId.': Orboni refused a payment page ('.$e->type.'): '.$e->getMessage(), 'output', false);
            if ($e->type === 'amount_too_small' || ($e->type === 'invalid_request_error' && $e->param === 'amount')) {
                return $this->refuse('amount', Language::_('Orboni.!error.minimum', true));
            }

            return $this->refuse('session', Language::_('Orboni.!error.unavailable', true));
        }

        if ($session === null) {
            // Money is already on its way for this very payment.
            return $this->refuse('session', Language::_('Orboni.!error.in_progress', true));
        }

        $this->log('orboni/session', 'Client '.$clientId.': session '.$session['id'].' for '.Currencies::format($minor, $currency).'.', 'output', true);

        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', __DIR__.DS));
        Loader::loadHelpers($this, ['Html']);
        $this->view->set('url', (string) $session['url']);
        $this->view->set('test', empty($session['livemode']));

        return $this->view->fetch();
    }

    /**
     * A callback: Orboni's webhook (JSON, signed) or the client back from the
     * payment page (`session_id`). The session is read back, and Blesta is
     * given the transaction as it stands now, or nothing for a session that
     * says nothing (another site's, a page that ran out unpaid).
     */
    public function validate(array $get, array $post)
    {
        $raw = (string) file_get_contents('php://input');
        $event = '';
        $returning = $raw === '' && ! isset($_SERVER['HTTP_WEBHOOK_SIGNATURE']);

        if ($returning) {
            $sessionId = is_string($get['session_id'] ?? null) ? $get['session_id'] : '';
            if (preg_match(Api::SESSION_ID, $sessionId) !== 1) {
                return $this->fail('session', 'missing');
            }
        } else {
            $header = is_string($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? null) ? $_SERVER['HTTP_WEBHOOK_SIGNATURE'] : '';
            if (! Signature::verify((string) ($this->meta['webhook_secret'] ?? ''), $raw, $header)) {
                // Orboni retries, then marks the delivery failed in the dashboard: a wrong secret shows there.
                $this->respond(400);

                return $this->fail('signature', 'invalid');
            }
            $body = json_decode($raw, true);
            $object = is_array($body) && is_array($body['data']['object'] ?? null) ? $body['data']['object'] : null;
            $event = is_array($body) && is_string($body['type'] ?? null) ? $body['type'] : '';
            if ($object === null || strpos($event, 'checkout.session.') !== 0 || ! $this->ours($object)) {
                // Orboni sends a project's every event to every endpoint: another
                // site's, a payment link's, or not a payment's at all.
                return null;
            }
            $sessionId = (string) $object['id'];
        }

        if (! $this->lock($sessionId)) {
            $this->respond(503);

            return $this->fail('session', 'busy');
        }

        try {
            $session = $this->api()->getSession($sessionId);
        } catch (ApiException $e) {
            $this->log('orboni/callback', 'Session '.$sessionId.': Orboni did not answer ('.$e->getMessage().').', 'output', false);
            // A webhook is sent again; a client coming back finds it recorded by the webhook.
            $this->respond(503);

            return $this->fail('session', 'unavailable');
        }

        if (! $this->ours($session)) {
            return $this->fail('session', 'foreign');
        }

        $this->log('orboni/callback', ($returning ? 'Client back' : 'Webhook '.$event).': session '.$sessionId.' is '.($session['status'] ?? '?').', '.($session['payment_status'] ?? '?').'.', 'input', true);
        if ($returning) {
            $this->sendBack($session);
        }

        return $this->transaction($session, $event);
    }

    /**
     * The client's page after paying (`pay/received/orboni/?session_id=…`):
     * what the payment is now, read back from Orboni. Recording it is the
     * callback's work, never this page's.
     */
    public function success(array $get, array $post)
    {
        $sessionId = is_string($get['session_id'] ?? null) ? $get['session_id'] : '';
        if (preg_match(Api::SESSION_ID, $sessionId) !== 1) {
            return null;
        }

        try {
            $session = $this->api()->getSession($sessionId);
        } catch (ApiException $e) {
            return $this->fail('session', 'unavailable');
        }

        $metadata = (array) ($session['metadata'] ?? []);
        if (! $this->ours($session) || (isset($get['client_id']) && (string) $get['client_id'] !== (string) ($metadata['blesta_client_id'] ?? ''))) {
            return $this->fail('session', 'foreign');
        }

        $transaction = $this->transaction($session, '', false);
        if ($transaction === null) {
            return $this->fail('session', 'unpaid');
        }

        return $transaction;
    }

    /**
     * The transaction Blesta keeps for a session, as the session is now.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>|null
     */
    private function transaction(array $session, string $event, bool $lookUp = true): ?array
    {
        $id = (string) $session['id'];
        $status = (string) ($session['status'] ?? '');
        $payment = (string) ($session['payment_status'] ?? '');
        $currency = strtoupper((string) ($session['currency'] ?? ''));
        $metadata = (array) ($session['metadata'] ?? []);
        $clientId = (int) $metadata['blesta_client_id'];
        $due = Currencies::minor((float) ($session['amount_due'] ?? 0), $currency);
        $received = Currencies::minor((float) ($session['amount_received'] ?? 0), $currency);
        $invoices = $this->unserializeInvoices((string) ($metadata['blesta_invoices'] ?? ''), $currency);

        $result = [
            'client_id' => $clientId,
            'currency' => $currency,
            'transaction_id' => $id,
            'reference_id' => null,
        ];

        if ($status === 'complete' && $payment === 'paid') {
            $note = $this->paidNote($session);
            if ($event === self::EXTRA) {
                $note = Language::_('Orboni.note.extra', true, Currencies::formatDecimal($session['amount_received'] ?? 0, $currency)).' '.$note;
            }

            return $result + [
                'status' => 'approved',
                'amount' => $this->decimal($due, $currency),
                'invoices' => $this->verifyInvoices($invoices, $clientId, $currency, $due),
                'message' => $this->message($note),
            ];
        }

        if ($status === 'expired' && $payment === 'partially_paid') {
            // Orboni will not complete it: what arrived pays what it can, the rest stays due.
            return $result + [
                'status' => 'approved',
                'amount' => $this->decimal($received, $currency),
                'invoices' => $this->allocate($this->verifyInvoices($invoices, $clientId, $currency, $due), $received, $currency),
                'message' => $this->message(Language::_('Orboni.note.partial_expired', true, Currencies::formatDecimal($session['amount_received'] ?? 0, $currency), Currencies::formatDecimal($session['amount_due'] ?? 0, $currency))),
            ];
        }

        if (in_array($payment, ['processing', 'partially_paid', 'held'], true) && $event !== self::REVERSED) {
            switch ($payment) {
                case 'held':
                    $note = Language::_('Orboni.note.held', true, (string) ($session['review_reason'] ?? '-'));
                    break;
                case 'partially_paid':
                    $note = Language::_('Orboni.note.partial', true, Currencies::formatDecimal($session['amount_received'] ?? 0, $currency), Currencies::formatDecimal($session['amount_due'] ?? 0, $currency));
                    break;
                default:
                    $note = Language::_('Orboni.note.processing', true);
            }

            return $result + [
                'status' => 'pending',
                'amount' => $this->decimal($due, $currency),
                'invoices' => $this->verifyInvoices($invoices, $clientId, $currency, $due),
                'message' => $this->message($note),
            ];
        }

        // Unpaid now: ran out with nothing sent, or money that paid it left the
        // chain. Only a transaction Blesta already keeps is turned down; a page
        // nobody paid leaves no trace.
        if (! $lookUp || ! $this->recorded($id)) {
            return null;
        }

        return $result + [
            'status' => 'declined',
            'amount' => $this->decimal($due, $currency),
            'invoices' => [],
            'message' => $this->message($event === self::REVERSED
                ? Language::_('Orboni.note.reversed', true, Currencies::formatDecimal($session['amount_received'] ?? 0, $currency))
                : Language::_('Orboni.note.expired', true)),
        ];
    }

    /**
     * The session for this payment: one already open under the payment's
     * key, or a new one. A page that ran out moves on to the next key; null
     * when money for this payment is already on its way.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     *
     * @throws ApiException
     */
    private function openSession(array $body, string $key): ?array
    {
        $api = $this->api();
        for ($attempt = 1; $attempt <= self::MAX_TRIES; $attempt++) {
            try {
                $session = $api->createSession($body, $key.'-'.$attempt);
            } catch (ApiException $e) {
                if ($e->type === 'idempotency_error' && $e->status === 400) {
                    // Sent before with other details (the client's language, a new description).
                    continue;
                }

                throw $e;
            }

            if (empty($session['_replayed'])) {
                if (($session['status'] ?? null) === 'open' && is_string($session['url'] ?? null) && is_string($session['id'] ?? null)) {
                    return $session;
                }

                continue;
            }

            $session = $api->getSession((string) ($session['id'] ?? ''));
            if (in_array($session['payment_status'] ?? '', ['processing', 'paid', 'held'], true)) {
                return null;
            }
            if (($session['status'] ?? null) === 'open' && is_string($session['url'] ?? null)
                && (int) ($session['expires_at'] ?? 0) > time() + self::REUSE_MIN_SECONDS) {
                return $session;
            }
        }

        throw new ApiException('No open payment page after '.self::MAX_TRIES.' tries.', 'no_open_session', 0);
    }

    /**
     * @param  list<array{id: int, amount: int}>  $invoices
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function sessionBody(int $clientId, int $minor, string $currency, array $invoices, string $serialized, array $options): array
    {
        Loader::loadModels($this, ['Clients', 'Companies', 'Invoices']);

        $lines = [];
        $numbers = [];
        foreach ($invoices as $line) {
            $invoice = $this->Invoices->get($line['id']);
            if (! $invoice) {
                continue;
            }
            $numbers[] = (string) $invoice->id_code;
            foreach ((array) ($invoice->line_items ?? []) as $item) {
                $name = trim(strip_tags((string) ($item->description ?? '')));
                $quantity = (float) ($item->qty ?? 1);
                $lines[] = $quantity > 1 ? $name.' × '.(floor($quantity) == $quantity ? (int) $quantity : $quantity) : $name;
            }
        }

        $client = $this->Clients->get($clientId);
        $company = $this->Companies->get(Configure::get('Blesta.company_id'));
        $summary = trim((string) ($options['description'] ?? ''));
        $email = (string) ($client->email ?? '');
        $language = (string) ($client->settings['language'] ?? '');
        $return = (string) ($options['return_url'] ?? '');

        $metadata = [
            'blesta_site' => self::siteKey(),
            'blesta_client_id' => (string) $clientId,
        ];
        if ($serialized !== '') {
            $metadata['blesta_invoices'] = $serialized;
        }
        // Where the client goes once the callback has read the payment.
        if (preg_match('#^https?://#i', $return) === 1 && strlen($return) <= 500) {
            $metadata['blesta_return'] = $return;
        }

        $details = $summary !== '' ? $summary : Language::_('Orboni.details.credit', true);
        if (! empty($company->name)) {
            $details = Language::_('Orboni.details.at', true, $details, (string) $company->name);
        }

        return array_filter([
            'amount' => $minor,
            'currency' => strtolower($currency),
            'description' => Text::fit($lines !== [] ? implode(', ', array_filter($lines)) : $summary, 250),
            'details' => Text::fit($details, 500),
            'client_reference_id' => $numbers !== [] ? Text::fit(implode(', ', $numbers), 200) : null,
            'customer_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'locale' => preg_match('/^([a-z]{2,3})_([a-z]{2})$/', $language, $m) === 1 ? $m[1].'-'.strtoupper($m[2]) : null,
            'success_url' => $this->callbackUrl().'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->cancelUrl($return),
            'metadata' => $metadata,
        ], static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * A session made by this Blesta, for one of its clients. Another site
     * on the same Orboni project, or a payment link, is not ours.
     *
     * @param  array<string, mixed>  $session
     */
    private function ours(array $session): bool
    {
        $metadata = (array) ($session['metadata'] ?? []);

        return preg_match(Api::SESSION_ID, (string) ($session['id'] ?? '')) === 1
            && (string) ($metadata['blesta_site'] ?? '') === self::siteKey()
            && ctype_digit((string) ($metadata['blesta_client_id'] ?? ''));
    }

    /**
     * The invoices a session was made for, if they still belong to its
     * client and currency and ask no more than it pays. Otherwise the
     * payment stays the client's credit, applied to nothing.
     *
     * @param  list<array{id: int, amount: int}>  $invoices
     * @return list<array{id: int, amount: string}>
     */
    private function verifyInvoices(array $invoices, int $clientId, string $currency, int $paid): array
    {
        if ($invoices === []) {
            return [];
        }
        Loader::loadModels($this, ['Invoices']);

        $total = 0;
        foreach ($invoices as $line) {
            $invoice = $this->Invoices->get($line['id']);
            if (! $invoice || (int) $invoice->client_id !== $clientId || strcasecmp((string) $invoice->currency, $currency) !== 0 || $line['amount'] <= 0) {
                $this->log('orboni/callback', 'Invoice '.$line['id'].' is not client '.$clientId.'\'s in '.$currency.'; the payment is kept as credit.', 'output', false);

                return [];
            }
            $total += $line['amount'];
        }
        if ($total > $paid) {
            return [];
        }

        $out = [];
        foreach ($invoices as $line) {
            $out[] = ['id' => $line['id'], 'amount' => $this->decimal($line['amount'], $currency)];
        }

        return $out;
    }

    /**
     * What arrived, spread over the invoices in order.
     *
     * @param  list<array{id: int, amount: string}>  $invoices
     * @return list<array{id: int, amount: string}>
     */
    private function allocate(array $invoices, int $received, string $currency): array
    {
        $left = $received;
        $out = [];
        foreach ($invoices as $line) {
            $amount = min(Currencies::minor((float) $line['amount'], $currency), $left);
            if ($amount <= 0) {
                break;
            }
            $out[] = ['id' => $line['id'], 'amount' => $this->decimal($amount, $currency)];
            $left -= $amount;
        }

        return $out;
    }

    /** Whether Blesta already keeps a transaction for this session. */
    private function recorded(string $sessionId): bool
    {
        Loader::loadModels($this, ['Transactions']);

        return (bool) $this->Transactions->getByTransactionId($sessionId, null, $this->gateway_id ?? null);
    }

    /**
     * "Paid 25.00 USDT on TRON (25.00 USD)."
     *
     * @param  array<string, mixed>  $session
     */
    private function paidNote(array $session): string
    {
        $payment = is_array($session['payment'] ?? null) ? $session['payment'] : [];
        $currency = (string) ($session['currency'] ?? 'usd');
        $received = Currencies::formatDecimal($session['amount_received'] ?? 0, $currency);
        $note = isset($payment['coin'], $payment['amount'])
            ? Language::_('Orboni.note.paid', true, (string) $payment['amount'], (string) $payment['coin'], self::CHAIN_NAMES[(string) ($payment['chain'] ?? '')] ?? ucfirst((string) ($payment['chain'] ?? '')), $received)
            : Language::_('Orboni.note.paid_plain', true, $received);

        if (empty($session['livemode'])) {
            $note .= ' '.Language::_('Orboni.note.test', true);
        }
        if (! empty($session['paid_late'])) {
            $note .= ' '.Language::_('Orboni.note.late', true);
        }

        return $note;
    }

    /**
     * The client back from the payment page lands where they left Blesta (the
     * received page, or the order form's), once this callback has recorded
     * the payment: Blesta's own redirect goes only to the client area's.
     *
     * @param  array<string, mixed>  $session
     */
    private function sendBack(array $session): void
    {
        $to = (string) (((array) ($session['metadata'] ?? []))['blesta_return'] ?? '');
        if (preg_match('#^https?://[^\s]+$#i', $to) !== 1) {
            return;
        }
        $to .= (strpos($to, '?') === false ? '?' : '&').'session_id='.rawurlencode((string) $session['id']);

        register_shutdown_function(static function () use ($to) {
            if (! headers_sent()) {
                header('Location: '.$to, true, 302);
            }
        });
    }

    /**
     * Held until this request ends, so the webhook and the client coming back
     * at the same moment are recorded one after the other: the second finds
     * the first's transaction and updates it.
     */
    private function lock(string $sessionId): bool
    {
        $database = (array) Configure::get('Blesta.database_info');
        $name = 'orboni_'.substr(hash('sha256', ($database['database'] ?? '').'|'.$sessionId), 0, 40);

        try {
            $record = new Record;
            $row = $record->query('SELECT GET_LOCK(?, ?) AS got', $name, self::LOCK_WAIT)->fetch();
        } catch (Throwable $e) {
            return true;
        }
        if ((int) ($row->got ?? 0) !== 1) {
            return false;
        }

        register_shutdown_function(static function () use ($record, $name) {
            try {
                $record->query('SELECT RELEASE_LOCK(?)', $name);
            } catch (Throwable $e) {
                // The connection closing releases it as well.
            }
        });

        return true;
    }

    /** Blesta's callback address for this gateway: where the webhook goes. */
    private function callbackUrl(): string
    {
        return Configure::get('Blesta.gw_callback_url').Configure::get('Blesta.company_id').'/orboni/';
    }

    /** Back to where the client chose how to pay: the payment step, or the order form. */
    private function cancelUrl(string $return): ?string
    {
        if ($return === '') {
            return null;
        }
        $at = strpos($return, 'pay/received/');

        return $at === false ? $return : substr($return, 0, $at).'pay/method/';
    }

    /**
     * This Blesta company, among every site that uses the same Orboni project.
     * Keyed on the installation's own secret, so it stays when the address changes.
     */
    public static function siteKey(): string
    {
        return substr(hash_hmac('sha256', 'orboni-site|'.Configure::get('Blesta.company_id'), (string) Configure::get('Blesta.system_key')), 0, 12);
    }

    /**
     * "12=10.00|13=5.50": the invoices and what this payment gives each.
     *
     * @param  list<array{id: int, amount: int}>  $invoices
     */
    private function serializeInvoices(array $invoices, string $currency): string
    {
        $parts = [];
        foreach ($invoices as $line) {
            $parts[] = $line['id'].'='.$this->decimal($line['amount'], $currency);
        }

        return implode('|', $parts);
    }

    /** @return list<array{id: int, amount: int}> */
    private function unserializeInvoices(string $text, string $currency): array
    {
        $out = [];
        foreach ($text === '' ? [] : explode('|', $text) as $pair) {
            $kv = explode('=', $pair, 2);
            if (count($kv) === 2 && ctype_digit($kv[0]) && is_numeric($kv[1])) {
                $out[] = ['id' => (int) $kv[0], 'amount' => Currencies::minor((float) $kv[1], $currency)];
            }
        }

        return $out;
    }

    /** 1250 in euros is "12.50"; 1500 yen is "1500". */
    private function decimal(int $minor, string $currency): string
    {
        $decimals = Currencies::decimals($currency);

        return number_format($minor / (10 ** $decimals), $decimals, '.', '');
    }

    private function message(string $text): string
    {
        return Text::fit($text, 255);
    }

    private function secretKey(): string
    {
        return trim((string) ($this->meta['secret_key'] ?? ''));
    }

    private function api(): Api
    {
        if ($this->api === null) {
            $this->api = new Api($this->secretKey());
        }

        return $this->api;
    }

    /** An error Blesta shows the client instead of a button. */
    private function refuse(string $field, string $message)
    {
        $this->Input->setErrors(['orboni' => [$field => $message]]);

        return null;
    }

    /** An error on a callback, in the gateway log; null for Blesta. */
    private function fail(string $field, string $rule)
    {
        $message = Language::_('Orboni.!error.'.$field.'.'.$rule, true);
        $this->log('orboni/callback', $message, 'output', false);
        $this->Input->setErrors([$field => [$rule => $message]]);

        return null;
    }

    /** The callback's answer to Orboni: anything but 2xx is sent again. */
    private function respond(int $status): void
    {
        if (! headers_sent()) {
            http_response_code($status);
            if ($status === 503) {
                header('Retry-After: 60');
            }
        }
    }
}
