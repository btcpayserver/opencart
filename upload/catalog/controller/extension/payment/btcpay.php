<?php

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Util\PreciseNumber;

require DIR_SYSTEM . 'library/btcpay/autoload.php';
require DIR_SYSTEM . 'library/btcpay/version.php';

class ControllerExtensionPaymentBTCPay extends Controller
{
    private const PAYMENT_METHOD_CODE = 'btcpay';
    private const MAX_WEBHOOK_FIELD_LENGTH = 120;

    public function index()
    {
        $this->load->language('extension/payment/btcpay');

        $useModal = (bool)$this->config->get('payment_btcpay_modal_mode');
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['invoice_failed_text'] = $this->language->get('invoice_failed_text');
        $data['action'] = $this->url->link(
          'extension/payment/btcpay/checkout',
          '',
          true
        );

        if ($useModal) {
            $host = rtrim((string)$this->config->get('payment_btcpay_url'), '/');
            $data['btcpay_host'] = $host;
            $data['modal_url'] = $host . '/modal/btcpay.js';
            $data['success_link'] = $this->url->link('checkout/success', '', true);
            $data['invoice_expired_text'] = $this->language->get('invoice_expired_text');
            $data['invoice_closed_text'] = $this->language->get('invoice_closed_text');
            return $this->load->view('extension/payment/btcpay_modal', $data);
        }

        return $this->load->view('extension/payment/btcpay', $data);
    }

    public function checkout()
    {
        $this->load->language('extension/payment/btcpay');
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/btcpay');

        $debug = (bool)$this->config->get('payment_btcpay_debug_mode');
        $useModal = (bool)$this->config->get('payment_btcpay_modal_mode');
        $requestedResponse = $this->request->post['response'] ?? '';
        $respondWithJson = $useModal ||
          (is_string($requestedResponse) && $requestedResponse === 'json');

        if ($debug) {
            $this->log->write('Entering BTCPay checkout.');
            $this->log->write(
              'Checkout order id: ' .
              (isset($this->session->data['order_id'])
                ? (int)$this->session->data['order_id']
                : 'none') .
              '; payment method: ' .
              $this->getPaymentMethodCode($this->session->data['payment_method'] ?? null)
            );
        }

        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->addHeader('HTTP/1.1 405 Method Not Allowed');
            $this->response->addHeader('Allow: POST');
            $this->checkoutError($this->language->get('error_request_method'), $respondWithJson);
            return;
        }

        if (empty($this->session->data['order_id'])) {
            $this->checkoutError($this->language->get('error_order'), $respondWithJson);
            return;
        }

        $orderId = (int)$this->session->data['order_id'];
        $orderInfo = $this->model_checkout_order->getOrder($orderId);
        $validationError = $this->getCheckoutValidationError($orderInfo);

        if ($validationError !== '') {
            $this->checkoutError($validationError, $respondWithJson);
            return;
        }

        $lockAcquired = $this->model_extension_payment_btcpay->acquireOrderLock($orderId);
        if ($lockAcquired === false) {
            $this->checkoutError($this->language->get('error_checkout_busy'), $respondWithJson);
            return;
        }

        $invoice = null;
        try {
            // Re-read after obtaining the lock so a concurrent checkout cannot
            // create another invoice between validation and invoice reuse.
            $orderInfo = $this->model_checkout_order->getOrder($orderId);
            $validationError = $this->getCheckoutValidationError($orderInfo);
            if ($validationError !== '') {
                $this->checkoutError($validationError, $respondWithJson);
                return;
            }

            $invoice = $this->orderHasExistingInvoice($orderInfo);
            if (!$invoice) {
                $token = bin2hex(random_bytes(32));
                $invoice = $this->createInvoice($orderInfo, $token);

                if ($invoice) {
                    // Keep historical rows for late-payment review. The newest
                    // row is the only invoice considered active for this order.
                    $this->model_extension_payment_btcpay->addOrder([
                      'order_id' => $orderInfo['order_id'],
                      'token' => $token,
                      'invoice_id' => $invoice->getId(),
                    ]);
                }
            } elseif ($debug) {
                $this->log->write(
                  'Reusing BTCPay invoice ' . $invoice->getId() .
                  ' for order #' . $orderId . '.'
                );
            }
        } catch (\Throwable $e) {
            $message = 'BTCPay checkout failed for order #' . $orderId . '.';
            if ($debug) {
                $message .= ' ' . $e->getMessage();
            }
            $this->log->write($message);
            $this->checkoutError($this->language->get('invoice_failed_text'), $respondWithJson);
            return;
        } finally {
            if ($lockAcquired === true) {
                $this->model_extension_payment_btcpay->releaseOrderLock($orderId);
            }
        }

        if (!$invoice || !$this->isValidHttpUrl((string)$invoice->getCheckoutLink())) {
            $this->log->write('BTCPay checkout could not obtain a valid invoice for order #' . $orderId . '.');
            $this->checkoutError($this->language->get('invoice_failed_text'), $respondWithJson);
            return;
        }

        if ($useModal) {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['invoiceId' => $invoice->getId()]));
            return;
        }

        if ($respondWithJson) {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode([
              'redirect' => $invoice->getCheckoutLink(),
            ]));
            return;
        }

        $this->response->redirect($invoice->getCheckoutLink());
    }

    public function cancel()
    {
        $this->response->redirect($this->url->link('checkout/cart', '', true));
    }

    public function success()
    {
        $this->load->model('extension/payment/btcpay');

        $debug = (bool)$this->config->get('payment_btcpay_debug_mode');
        $orderId = isset($this->session->data['order_id'])
          ? (int)$this->session->data['order_id']
          : 0;
        $requestToken = isset($this->request->get['token']) && is_string($this->request->get['token'])
          ? $this->request->get['token']
          : '';

        if ($orderId <= 0 || $requestToken === '') {
            $this->redirectToHome();
            return;
        }

        $mapping = $this->model_extension_payment_btcpay->getOrder($orderId);
        $storedToken = isset($mapping['token']) && is_string($mapping['token'])
          ? $mapping['token']
          : '';

        // The random return token is consumed exactly once. Payment status is
        // still established only by the signed webhook, not by this redirect.
        if (
            $storedToken === '' ||
            !hash_equals($storedToken, $requestToken) ||
            !$this->model_extension_payment_btcpay->consumeToken(
              (int)$mapping['btcpay_order_id'],
              $requestToken
            )
        ) {
            if ($debug) {
                $this->log->write('BTCPay success redirect rejected: invalid or consumed token.');
            }
            $this->redirectToHome();
            return;
        }

        $this->response->redirect($this->url->link('checkout/success', '', true));
    }

    public function callback()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/btcpay');

        $debug = (bool)$this->config->get('payment_btcpay_debug_mode');
        if ($debug) {
            $this->log->write('Entering BTCPay webhook callback.');
        }

        $rawData = file_get_contents('php://input');
        $signature = $this->getWebhookSignature();

        if (!is_string($rawData) || !$this->validWebhookRequest($signature, $rawData)) {
            $this->log->write('BTCPay webhook rejected: invalid signature.');
            $this->webhookResponse(403, 'Invalid webhook signature.');
            return;
        }

        try {
            $data = json_decode($rawData, false, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->log->write('BTCPay webhook rejected: invalid JSON.');
            $this->webhookResponse(400, 'Invalid webhook payload.');
            return;
        }

        if (!$this->validWebhookPayload($data)) {
            $this->log->write('BTCPay webhook rejected: invalid payload fields.');
            $this->webhookResponse(400, 'Invalid webhook payload.');
            return;
        }

        $invoiceId = $data->invoiceId;
        $storeId = (string)$this->config->get('payment_btcpay_btcpay_storeid');
        $webhook = $this->config->get('payment_btcpay_webhook');

        // Older BTCPay versions did not always include these fields. Validate
        // them whenever present without breaking existing installations.
        if (
            isset($data->storeId) &&
            (!is_string($data->storeId) || !hash_equals($storeId, $data->storeId))
        ) {
            $this->webhookResponse(403, 'Webhook identity mismatch.');
            return;
        }
        if (
            isset($data->webhookId) &&
            is_array($webhook) &&
            isset($webhook['id']) &&
            (!is_string($data->webhookId) || !hash_equals((string)$webhook['id'], $data->webhookId))
        ) {
            $this->webhookResponse(403, 'Webhook identity mismatch.');
            return;
        }

        $mapping = $this->model_extension_payment_btcpay->getOrderByInvoiceId($invoiceId);
        if (empty($mapping['order_id'])) {
            if ($debug) {
                $this->log->write('No OpenCart order mapping for BTCPay invoice ' . $invoiceId . '.');
            }
            $this->webhookResponse(200);
            return;
        }

        $orderId = (int)$mapping['order_id'];
        $lockAcquired = $this->model_extension_payment_btcpay->acquireOrderLock($orderId);
        if ($lockAcquired === false) {
            $this->webhookResponse(503, 'Order is currently being updated.');
            return;
        }

        try {
            // Historical mappings remain in the existing table. The newest row
            // is active; older non-payment events cannot overwrite the order.
            $mapping = $this->model_extension_payment_btcpay->getOrderByInvoiceId($invoiceId);
            $activeMapping = $this->model_extension_payment_btcpay->getOrder($orderId);
            $orderInfo = $this->model_checkout_order->getOrder($orderId);

            if (empty($mapping) || empty($activeMapping) || empty($orderInfo)) {
                $this->webhookResponse(200);
                return;
            }
            if ((int)$mapping['order_id'] !== $orderId) {
                $this->webhookResponse(503, 'Invoice mapping changed during processing.');
                return;
            }

            if ($debug) {
                $this->log->write(
                  'BTCPay order mapping: order ' . $orderId . ', invoice ' . $invoiceId . '.'
                );
                $this->log->write('Webhook payload: ' . print_r($data, true));
            }

            // Keep the pre-existing single invoice request inside the order
            // lock so concurrent callbacks cannot apply stale snapshots in the
            // opposite order.
            $invoice = $this->loadInvoice($invoiceId);
            if (!$invoice) {
                $this->webhookResponse(503, 'Unable to verify invoice.');
                return;
            }

            if ($debug) {
                $this->log->write('Invoice data: ' . print_r($invoice, true));
            }

            if (!$this->invoiceHasExpectedId($invoice, $invoiceId)) {
                $this->webhookResponse(503, 'Unable to verify invoice identity.');
                return;
            }

            $state = $this->getAuthoritativeInvoiceState($invoice);
            if ($state === null) {
                $this->webhookResponse(200);
                return;
            }

            if ((string)$activeMapping['invoice_id'] !== $invoiceId) {
                if ($state['payment_detected']) {
                    $this->addManualReview(
                      $orderInfo,
                      $invoiceId,
                      'payment was detected for an older, superseded invoice (' .
                        $state['description'] . ')'
                    );
                } elseif ($debug) {
                    $this->log->write(
                      'Ignored non-payment event for superseded BTCPay invoice ' . $invoiceId . '.'
                    );
                }

                $this->webhookResponse(200);
                return;
            }

            $mismatchReason = '';
            if (!$this->invoiceMatchesOrder($invoice, $orderInfo, $mismatchReason, $invoiceId)) {
                $this->addManualReview($orderInfo, $invoiceId, $mismatchReason);
                $this->webhookResponse(200);
                return;
            }

            if (($orderInfo['payment_code'] ?? '') !== self::PAYMENT_METHOD_CODE) {
                $this->addManualReview(
                  $orderInfo,
                  $invoiceId,
                  'the order payment method is no longer BTCPay'
                );
                $this->webhookResponse(200);
                return;
            }

            // An active partial payment is authenticated and recorded in debug
            // logs, but must not receive a status configured for full payment.
            if (empty($state['setting'])) {
                if ($debug) {
                    $this->log->write('BTCPay invoice state requires no OpenCart status change.');
                }
                $this->webhookResponse(200);
                return;
            }

            $targetStatus = (int)$this->config->get($state['setting']);
            if ($targetStatus <= 0) {
                $this->webhookResponse(500, 'Invalid order status configuration.');
                return;
            }

            if ((int)$orderInfo['order_status_id'] === $targetStatus) {
                $this->webhookResponse(200);
                return;
            }

            $currentStatus = (int)$orderInfo['order_status_id'];
            if ($this->shouldPreventOrderStatusRegression($state, $currentStatus, $targetStatus)) {
                $reason = !$state['payment_detected']
                  ? 'a non-payment invoice state was not allowed to regress a processing or completed order'
                  : 'a paid invoice state was not allowed to move an already advanced order backward';
                $this->addManualReview(
                  $orderInfo,
                  $invoiceId,
                  $reason
                );
                $this->webhookResponse(200);
                return;
            }

            $this->model_checkout_order->addOrderHistory(
              $orderId,
              $targetStatus,
              'Payment status update: ' . $state['description'] .
                ' Invoice id: ' . $invoiceId,
              $state['notify']
            );

            if ($debug) {
                $this->log->write('Successfully updated BTCPay order status: ' . $state['description']);
            }
        } catch (\Throwable $e) {
            $message = 'Failed to process BTCPay invoice ' . $invoiceId . '.';
            if ($debug) {
                $message .= ' ' . $e->getMessage();
            }
            $this->log->write($message);
            $this->webhookResponse(503, 'Unable to process invoice update.');
            return;
        } finally {
            if ($lockAcquired === true) {
                $this->model_extension_payment_btcpay->releaseOrderLock($orderId);
            }
        }

        $this->webhookResponse(200);
    }

    /**
     * Check the signature against the configured webhook secret.
     */
    protected function validWebhookRequest(string $signature, string $requestData): bool
    {
        $webhook = $this->config->get('payment_btcpay_webhook');
        if (!is_array($webhook) || empty($webhook['secret']) || $signature === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $requestData, (string)$webhook['secret']);

        return hash_equals($expected, $signature);
    }

    protected function createInvoice(array $orderInfo, string $token): ?\BTCPayServer\Result\Invoice
    {
        $host = (string)$this->config->get('payment_btcpay_url');
        $debug = (bool)$this->config->get('payment_btcpay_debug_mode');

        if (!$this->isValidHttpUrl($host)) {
            $this->log->write('BTCPay invoice creation refused: invalid configured server URL.');
            return null;
        }

        $client = new Invoice(
          $host,
          (string)$this->config->get('payment_btcpay_api_auth_token')
        );
        $checkoutOptions = new InvoiceCheckoutOptions();
        $redirectUrl = $this->url->link(
          'extension/payment/btcpay/success',
          ['token' => $token],
          true
        );
        $checkoutOptions->setRedirectURL(htmlspecialchars_decode($redirectUrl));

        if ($debug) {
            $this->log->write(
              'Configured the BTCPay return URL for order #' . (int)$orderInfo['order_id'] . '.'
            );
        }

        try {
            $invoice = $client->createInvoice(
              (string)$this->config->get('payment_btcpay_btcpay_storeid'),
              (string)$orderInfo['currency_code'],
              $this->prepareOrderTotal($orderInfo),
              (string)$orderInfo['order_id'],
              null,
              [],
              $checkoutOptions
            );

            $mismatchReason = '';
            if (!$this->invoiceMatchesOrder(
                $invoice,
                $orderInfo,
                $mismatchReason,
                (string)$invoice->getId()
            )) {
                $this->log->write(
                  'BTCPay returned an invoice that did not match order #' .
                  (int)$orderInfo['order_id'] . ': ' . $mismatchReason . '.'
                );
                return null;
            }

            return $invoice;
        } catch (\Throwable $e) {
            $message = 'Failed to create BTCPay invoice for order #' .
              (int)$orderInfo['order_id'] . '.';
            if ($debug) {
                $message .= ' ' . $e->getMessage();
            }
            $this->log->write($message);
        }

        return null;
    }

    /**
     * Reuse only the newest active invoice while its complete binding matches.
     */
    protected function orderHasExistingInvoice(array $orderInfo): ?\BTCPayServer\Result\Invoice
    {
        $mapping = $this->model_extension_payment_btcpay->getOrder($orderInfo['order_id']);
        if (empty($mapping['invoice_id'])) {
            return null;
        }

        $invoice = $this->loadInvoice((string)$mapping['invoice_id']);
        $mismatchReason = '';

        if (
            $invoice &&
            !$invoice->isExpired() &&
            !$invoice->isInvalid() &&
            $this->invoiceMatchesOrder(
              $invoice,
              $orderInfo,
              $mismatchReason,
              (string)$mapping['invoice_id']
            )
        ) {
            return $invoice;
        }

        if ($mismatchReason !== '' && $this->config->get('payment_btcpay_debug_mode')) {
            $this->log->write('Existing BTCPay invoice was not reused: ' . $mismatchReason . '.');
        }

        return null;
    }

    /**
     * Use the exchange rate snapshot stored with the OpenCart order.
     */
    protected function prepareOrderTotal(array $orderInfo): PreciseNumber
    {
        $total = (string)$orderInfo['total'];
        $currencyValue = (string)$orderInfo['currency_value'];
        $decimalPlaces = max(
          0,
          min(8, (int)$this->currency->getDecimalPlace((string)$orderInfo['currency_code']))
        );

        if (function_exists('bcmul')) {
            // Match OpenCart's displayed order total and BTCPay's currency
            // normalization without introducing binary floating-point drift.
            $unrounded = bcmul($total, $currencyValue, $decimalPlaces + 1);
            $increment = $decimalPlaces === 0
              ? '0.5'
              : '0.' . str_repeat('0', $decimalPlaces) . '5';
            $amount = isset($unrounded[0]) && $unrounded[0] === '-'
              ? bcsub($unrounded, $increment, $decimalPlaces)
              : bcadd($unrounded, $increment, $decimalPlaces);
        } else {
            $amount = number_format(
              (float)$total * (float)$currencyValue,
              $decimalPlaces,
              '.',
              ''
            );
        }

        return PreciseNumber::parseString($amount);
    }

    protected function invoiceMatchesOrder(
        \BTCPayServer\Result\Invoice $invoice,
        array $orderInfo,
        string &$mismatchReason,
        string $expectedInvoiceId = ''
    ): bool {
        if (!$this->invoiceHasExpectedId($invoice, $expectedInvoiceId)) {
            $mismatchReason = 'the BTCPay invoice identity was invalid';
            return false;
        }

        if (!$this->amountsEqual(
            (string)$this->prepareOrderTotal($orderInfo),
            (string)$invoice->getAmount()
        )) {
            $mismatchReason = 'the invoice amount does not match the OpenCart order';
            return false;
        }

        if (
            strtoupper((string)$invoice->getCurrency()) !==
            strtoupper((string)$orderInfo['currency_code'])
        ) {
            $mismatchReason = 'the invoice currency does not match the OpenCart order';
            return false;
        }

        $invoiceData = $invoice->getData();
        $metadata = isset($invoiceData['metadata']) && is_array($invoiceData['metadata'])
          ? $invoiceData['metadata']
          : [];
        if (
            !array_key_exists('orderId', $metadata) ||
            (string)$metadata['orderId'] !== (string)$orderInfo['order_id']
        ) {
            $mismatchReason = 'the invoice metadata order id does not match the OpenCart order';
            return false;
        }

        return true;
    }

    protected function invoiceHasExpectedId(
        \BTCPayServer\Result\Invoice $invoice,
        string $expectedInvoiceId = ''
    ): bool {
        $actualInvoiceId = (string)$invoice->getId();

        return $actualInvoiceId !== '' &&
          ($expectedInvoiceId === '' || hash_equals($expectedInvoiceId, $actualInvoiceId));
    }

    protected function amountsEqual(string $expected, string $actual): bool
    {
        if (!is_numeric($expected) || !is_numeric($actual)) {
            return false;
        }

        if (function_exists('bccomp')) {
            return bccomp($expected, $actual, 8) === 0;
        }

        return abs((float)$expected - (float)$actual) < 0.00000001;
    }

    /**
     * Derive state from the fetched invoice rather than the webhook event name.
     */
    protected function getAuthoritativeInvoiceState(
        \BTCPayServer\Result\Invoice $invoice
    ): ?array {
        $invoiceData = $invoice->getData();
        $status = (string)$invoice->getStatus();
        $additionalStatus = isset($invoiceData['additionalStatus'])
          ? (string)$invoiceData['additionalStatus']
          : '';

        switch ($status) {
            case 'New':
                if ($additionalStatus === 'PaidPartial') {
                    return [
                      'setting' => '',
                      'description' => 'A partial payment was received, but the invoice is not fully paid.',
                      'notify' => false,
                      'payment_detected' => true,
                    ];
                }
                if ($additionalStatus === 'PaidOver') {
                    return [
                      'setting' => 'payment_btcpay_paid_status_id',
                      'description' => 'Full payment with overpayment received; waiting for settlement.',
                      'notify' => false,
                      'payment_detected' => true,
                    ];
                }
                return null;

            case 'Processing':
                return [
                  'setting' => 'payment_btcpay_paid_status_id',
                  'description' => $additionalStatus === 'PaidOver'
                    ? 'Full payment with overpayment received; waiting for settlement.'
                    : 'Full payment received; waiting for settlement.',
                  'notify' => false,
                  'payment_detected' => true,
                ];

            case 'Settled':
                if ($additionalStatus === 'PaidOver') {
                    return [
                      'setting' => 'payment_btcpay_settled_paidover_status_id',
                      'description' => 'Overpaid and settled. Please check the transaction for the refund amount.',
                      'notify' => true,
                      'payment_detected' => true,
                    ];
                }
                return [
                  'setting' => 'payment_btcpay_settled_status_id',
                  'description' => 'Fully paid and settled.',
                  'notify' => true,
                  'payment_detected' => true,
                ];

            case 'Expired':
                if ($additionalStatus === 'PaidLate') {
                    return [
                      'setting' => 'payment_btcpay_expired_paidlate_status_id',
                      'description' => 'Invoice was fully paid after expiration. Manual review is recommended.',
                      'notify' => true,
                      'payment_detected' => true,
                    ];
                }
                if ($additionalStatus === 'PaidPartial') {
                    return [
                      'setting' => 'payment_btcpay_expired_partialpayment_status_id',
                      'description' => 'Invoice expired after receiving a partial payment. Manual review is required.',
                      'notify' => false,
                      'payment_detected' => true,
                    ];
                }
                return [
                  'setting' => 'payment_btcpay_expired_status_id',
                  'description' => 'Invoice expired without payment.',
                  'notify' => false,
                  'payment_detected' => false,
                ];

            case 'Invalid':
                return [
                  'setting' => 'payment_btcpay_invalid_status_id',
                  'description' => 'Invoice payment is invalid or was manually marked invalid.',
                  'notify' => false,
                  'payment_detected' => true,
                ];
        }

        return null;
    }

    protected function validWebhookPayload($data): bool
    {
        return
          is_object($data) &&
          isset($data->invoiceId, $data->type) &&
          is_string($data->invoiceId) &&
          is_string($data->type) &&
          $data->invoiceId !== '' &&
          strlen($data->invoiceId) <= self::MAX_WEBHOOK_FIELD_LENGTH &&
          preg_match('/[\x00-\x1F\x7F]/', $data->invoiceId) === 0 &&
          $data->type !== '' &&
          strlen($data->type) <= self::MAX_WEBHOOK_FIELD_LENGTH &&
          preg_match('/[\x00-\x1F\x7F]/', $data->type) === 0;
    }

    protected function loadInvoice(string $invoiceId): ?\BTCPayServer\Result\Invoice
    {
        $host = (string)$this->config->get('payment_btcpay_url');
        $debug = (bool)$this->config->get('payment_btcpay_debug_mode');

        if (!$this->isValidHttpUrl($host)) {
            $this->log->write('BTCPay invoice lookup refused: invalid configured server URL.');
            return null;
        }

        $client = new Invoice(
          $host,
          (string)$this->config->get('payment_btcpay_api_auth_token')
        );

        try {
            return $client->getInvoice(
              (string)$this->config->get('payment_btcpay_btcpay_storeid'),
              $invoiceId
            );
        } catch (\Throwable $e) {
            $message = 'Failed to load BTCPay invoice ' . $invoiceId . '.';
            if ($debug) {
                $message .= ' ' . $e->getMessage();
            }
            $this->log->write($message);
        }

        return null;
    }

    protected function getCheckoutValidationError($orderInfo): string
    {
        if (!$this->config->get('payment_btcpay_status')) {
            return $this->language->get('error_payment_disabled');
        }

        if (
            $this->getPaymentMethodCode($this->session->data['payment_method'] ?? null) !==
            self::PAYMENT_METHOD_CODE
        ) {
            return $this->language->get('error_payment_method');
        }

        if (
            !is_array($orderInfo) ||
            empty($orderInfo['order_id']) ||
            ($orderInfo['payment_code'] ?? '') !== self::PAYMENT_METHOD_CODE
        ) {
            return $this->language->get('error_payment_method');
        }

        if ($this->isProtectedOrderStatus((int)($orderInfo['order_status_id'] ?? 0))) {
            return $this->language->get('error_order_status');
        }

        if (
            !isset($orderInfo['total'], $orderInfo['currency_value'], $orderInfo['currency_code']) ||
            !is_numeric($orderInfo['total']) ||
            !is_numeric($orderInfo['currency_value']) ||
            (float)$orderInfo['total'] <= 0 ||
            (float)$orderInfo['currency_value'] <= 0 ||
            $orderInfo['currency_code'] === ''
        ) {
            return $this->language->get('error_order');
        }

        $minimumTotal = (float)$this->config->get('payment_btcpay_total');
        if ($minimumTotal > 0 && (float)$orderInfo['total'] < $minimumTotal) {
            return $this->language->get('error_minimum_total');
        }

        return '';
    }

    protected function getPaymentMethodCode($paymentMethod): string
    {
        if (is_array($paymentMethod)) {
            return isset($paymentMethod['code']) && is_string($paymentMethod['code'])
              ? $paymentMethod['code']
              : '';
        }

        if (is_string($paymentMethod)) {
            $decoded = json_decode($paymentMethod, true);
            if (is_array($decoded) && isset($decoded['code']) && is_string($decoded['code'])) {
                return $decoded['code'];
            }

            return $paymentMethod;
        }

        return '';
    }

    protected function isProtectedOrderStatus(int $statusId): bool
    {
        $protected = array_map(
          'intval',
          array_merge(
            (array)$this->config->get('config_processing_status'),
            (array)$this->config->get('config_complete_status')
          )
        );

        return in_array($statusId, $protected, true);
    }

    protected function shouldPreventOrderStatusRegression(
        array $state,
        int $currentStatus,
        int $targetStatus
    ): bool {
        if (!$this->isProtectedOrderStatus($currentStatus)) {
            return false;
        }

        // An authoritative Invalid state may require reversing a previously
        // accepted payment and retains the extension's existing behavior.
        if (($state['setting'] ?? '') === 'payment_btcpay_invalid_status_id') {
            return false;
        }

        if (empty($state['payment_detected'])) {
            return true;
        }

        // Never move a completed order back to another payment status. A
        // processing order may still advance within the protected states.
        return $this->isCompleteOrderStatus($currentStatus) ||
          !$this->isProtectedOrderStatus($targetStatus);
    }

    protected function isCompleteOrderStatus(int $statusId): bool
    {
        $complete = array_map(
          'intval',
          (array)$this->config->get('config_complete_status')
        );

        return in_array($statusId, $complete, true);
    }

    protected function addManualReview(array $orderInfo, string $invoiceId, string $reason): void
    {
        $comment = 'BTCPay manual review required for invoice ' . $invoiceId .
          ': ' . $reason . '.';
        $this->log->write($comment);

        $statusId = (int)($orderInfo['order_status_id'] ?? 0);
        if (
            $statusId > 0 &&
            !$this->model_extension_payment_btcpay->hasOrderHistoryComment(
              (int)$orderInfo['order_id'],
              $comment
            )
        ) {
            $this->model_checkout_order->addOrderHistory(
              (int)$orderInfo['order_id'],
              $statusId,
              $comment,
              false
            );
        }
    }

    protected function getWebhookSignature(): string
    {
        if (isset($this->request->server['HTTP_BTCPAY_SIG'])) {
            return (string)$this->request->server['HTTP_BTCPAY_SIG'];
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strtolower($key) === 'btcpay-sig') {
                        return (string)$value;
                    }
                }
            }
        }

        return '';
    }

    protected function isValidHttpUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        return
          is_array($parts) &&
          in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) &&
          !empty($parts['host']) &&
          !isset($parts['user']) &&
          !isset($parts['pass']);
    }

    protected function checkoutError(string $message, bool $respondWithJson): void
    {
        $this->log->write('BTCPay checkout rejected: ' . $message);

        if ($respondWithJson) {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['error' => $message]));
            return;
        }

        // OpenCart renders this one-time message on either the checkout page
        // or the cart page if checkout validation redirects there first.
        $this->session->data['error'] = $message;
        $this->response->redirect($this->url->link('checkout/checkout', '', true));
    }

    protected function redirectToHome(): void
    {
        $this->response->redirect($this->url->link('common/home', '', true));
    }

    protected function webhookResponse(int $statusCode, string $message = ''): void
    {
        $statusTexts = [
          200 => 'OK',
          400 => 'Bad Request',
          403 => 'Forbidden',
          500 => 'Internal Server Error',
          503 => 'Service Unavailable',
        ];
        $statusText = $statusTexts[$statusCode] ?? 'OK';

        $this->response->addHeader('HTTP/1.1 ' . $statusCode . ' ' . $statusText);
        $this->response->addHeader('Content-Type: application/json');
        if ($statusCode === 503) {
            $this->response->addHeader('Retry-After: 5');
        }
        $this->response->setOutput(
          json_encode($message === '' ? [] : ['message' => $message])
        );
    }
}
