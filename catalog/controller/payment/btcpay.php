<?php
namespace Opencart\Catalog\Controller\Extension\Btcpay\Payment;
use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Util\PreciseNumber;

require_once DIR_EXTENSION . 'btcpay/system/library/btcpay/autoload.php';
require_once DIR_EXTENSION . 'btcpay/system/library/btcpay/version.php';

class Btcpay extends \Opencart\System\Engine\Controller
{
    private const PAYMENT_METHOD_CODE = 'btcpay.btcpay';
    private const MAX_INVOICE_ID_LENGTH = 120;

    public function index(): string
    {
        $this->load->language('extension/btcpay/payment/btcpay');
        $this->load->model('checkout/order');

        $useModal = $this->config->get('payment_btcpay_modal_mode');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['action'] = $this->url->link(
          'extension/btcpay/payment/btcpay|checkout',
          '',
          true
        );

        if (isset($this->session->data['error_warning'])) {
          $data['error_warning'] = $this->session->data['error_warning'];
          unset($this->session->data['error_warning']);
        } else {
          $data['error_warning'] = '';
        }

        if ($useModal) {
            $host = $this->config->get('payment_btcpay_url');
            $data['btcpay_host'] = $host;
            $data['modal_url'] = $host . '/modal/btcpay.js';
            $data['success_link'] = $this->url->link('checkout/success', '', true);
            $data['invoice_expired_text'] = $this->language->get('invoice_expired_text');

            return $this->load->view('extension/btcpay/payment/btcpay_modal', $data);
        } else {
            // Redirect.
            return $this->load->view('extension/btcpay/payment/btcpay', $data);
        }
    }

    public function checkout(): void
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/btcpay/payment/btcpay');
        $this->load->language('extension/btcpay/payment/btcpay');

        $debug = $this->config->get('payment_btcpay_debug_mode');
        $useModal = $this->config->get('payment_btcpay_modal_mode');

        if ($debug) {
            $this->log->write('Entering checkout() of BTCPay catalog controller.');
            $this->log->write(
                'Checkout order id: ' . (isset($this->session->data['order_id']) ? (int)$this->session->data['order_id'] : 'none') .
                '; payment method: ' . $this->getPaymentMethodCode($this->session->data['payment_method'] ?? null)
            );
        }

        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->addHeader('HTTP/1.1 405 Method Not Allowed');
            $this->response->addHeader('Allow: POST');
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['error' => $this->language->get('error_request_method')]));
            return;
        }

        if (!isset($this->session->data['order_id'])) {
            $this->checkoutError($this->language->get('error_order'), $useModal);
            return;
        }

        $order_info = $this->model_checkout_order->getOrder(
          $this->session->data['order_id']
        );

        if (empty($order_info)) {
            $this->checkoutError($this->language->get('session_checkout_order_error'), $useModal);
            return;
        }

        $validationError = $this->getCheckoutValidationError($order_info);
        if ($validationError !== '') {
            $this->checkoutError($validationError, $useModal);
            return;
        }

        $invoiceId = '';
        $checkoutLink = '';
        $orderId = (int)$order_info['order_id'];
        $lockAcquired = $this->model_extension_btcpay_payment_btcpay->acquireOrderLock($orderId);

        if ($lockAcquired === false) {
            $this->checkoutError($this->language->get('error_checkout_busy'), $useModal);
            return;
        }

        try {
            // Re-read after obtaining the lock so a concurrent checkout cannot
            // create another invoice between validation and invoice reuse.
            $order_info = $this->model_checkout_order->getOrder($orderId);
            $validationError = $this->getCheckoutValidationError($order_info);
            if ($validationError !== '') {
                $this->checkoutError($validationError, $useModal);
                return;
            }

            // First, check if we have an existing and not expired invoice and
            // do not create a new one.
            if ($existingInvoice = $this->orderHasExistingInvoice($order_info)) {
                $invoiceId = $existingInvoice->getId();
                $checkoutLink = $existingInvoice->getCheckoutLink();
                if ($debug) {
                    $this->log->write('Found existing and not yet expired invoice: ' . $invoiceId);
                }
            } else {
                $token = bin2hex(random_bytes(32));
                if ($newInvoice = $this->createInvoice($order_info, $token)) {
                    $invoiceId = $newInvoice->getId();
                    $checkoutLink = $newInvoice->getCheckoutLink();

                    // Keep historical rows for late-payment review. The newest
                    // row is the only invoice considered active for checkout.
                    $this->model_extension_btcpay_payment_btcpay->addOrder([
                      'order_id' => $order_info['order_id'],
                      'token' => $token,
                      'invoice_id' => $invoiceId,
                    ]);

                    $this->model_checkout_order->addHistory(
                      $order_info['order_id'],
                      $this->config->get('payment_btcpay_new_status_id'),
                      'BTCPay invoice id: ' . $newInvoice->getId()
                    );

                    /* TODO: wip have BTCPay Server invoice link in customer comments, needs option.
                // Add user facing comment with a link to BTCPay Server invoice:
                $this->model_checkout_order->addHistory(
                  $order_info['order_id'],
                  $this->config->get('payment_btcpay_new_status_id'),
                  $this->language->get(
                    'order_payment_link'
                  ) . '<a href="' . $newInvoice->getCheckoutLink() . '" target="_blank">' . $newInvoice->getCheckoutLink() . '</a>',
                  true
                );
                */
                }
            }
        } catch (\Throwable $e) {
            $this->log->write('BTCPay checkout failed: ' . $e->getMessage());
            $this->checkoutError($this->language->get('invoice_failed_text'), $useModal);
            return;
        } finally {
            if ($lockAcquired === true) {
                $this->model_extension_btcpay_payment_btcpay->releaseOrderLock($orderId);
            }
        }

        if (empty($invoiceId)) {
            $this->log->write(
              "Order #" . $order_info['order_id'] . " is not valid or something went wrong. Please check BTCPay Server API request logs."
            );
            $this->checkoutError($this->language->get('invoice_failed_text'), $useModal);
            return;
        }

        // Handle invoice in modal or redirect to BTCPay Server.
        if ($useModal) {
            // Return JSON data for Javascript to process.
            $data['invoiceId'] = $invoiceId;
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($data));
        } else {
            // Redirect to BTCPay Server.
            $this->response->redirect($checkoutLink);
        }
    }

    public function cancel(): void
    {
        $this->response->redirect($this->url->link('checkout/cart', ''));
    }

    public function success(): void
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/btcpay/payment/btcpay');

        $debug = $this->config->get('payment_btcpay_debug_mode');

        if ($debug) {
            $this->log->write('Entering success callback / redirect page.');
            $this->log->write(
                'Success callback order id: ' . (isset($this->session->data['order_id']) ? (int)$this->session->data['order_id'] : 'none')
            );
        }

        if (isset($this->session->data['order_id'])) {
            $order = $this->model_extension_btcpay_payment_btcpay->getOrder(
              $this->session->data['order_id']
            );

            $requestToken = isset($this->request->get['token']) && is_string($this->request->get['token']) ?
                $this->request->get['token'] : '';
            $storedToken = isset($order['token']) && is_string($order['token']) ? $order['token'] : '';

            // Check that the token is present, valid, and consumed exactly once.
            if ($requestToken === '' || $storedToken === '' || !hash_equals($storedToken, $requestToken) ||
                !$this->model_extension_btcpay_payment_btcpay->consumeToken(
                    (int)$order['btcpay_order_id'],
                    $requestToken
                )) {
                if ($debug) {
                    $this->log->write('Redirect to home page, had no valid token.');
                }
                $this->response->redirect(
                  $this->url->link('common/home', '', true)
                );
            } else {
                $this->response->redirect(
                  $this->url->link('checkout/success', '', true)
                );
            }

        } else {
            if ($debug) {
              $this->log->write('Redirect to home page, no valid order id or session expired.');
            }
            $this->response->redirect(
              $this->url->link('common/home', '', true)
            );
        }
    }

    public function callback(): void
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/btcpay/payment/btcpay');

        $debug = (bool)$this->config->get('payment_btcpay_debug_mode');
        if ($debug) {
            $this->log->write('Entering BTCPay webhook callback.');
        }

        $signature = '';
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!is_array($headers)) {
            $headers = [];
        }
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'btcpay-sig') {
                $signature = (string)$value;
                break;
            }
        }
        if ($signature === '' && isset($this->request->server['HTTP_BTCPAY_SIG'])) {
            $signature = (string)$this->request->server['HTTP_BTCPAY_SIG'];
        }

        $rawData = file_get_contents('php://input');
        if (!is_string($rawData) || !$this->validWebhookRequest($signature, $rawData)) {
            $message = 'Failed to validate signature of the webhook request.';
            $this->log->write($message);
            $this->webhookResponse(403, $message);
            return;
        }

        try {
            $data = json_decode($rawData, false, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->log->write('Invalid BTCPay webhook JSON: ' . $e->getMessage());
            $this->webhookResponse(400, 'Invalid webhook payload.');
            return;
        }

        if (!is_object($data) || !isset($data->invoiceId, $data->type) ||
            !is_string($data->invoiceId) || !is_string($data->type) ||
            $data->invoiceId === '' || strlen($data->invoiceId) > self::MAX_INVOICE_ID_LENGTH ||
            $data->type === '' || strlen($data->type) > self::MAX_INVOICE_ID_LENGTH) {
            $this->webhookResponse(400, 'Invalid webhook payload.');
            return;
        }

        $invoiceId = $data->invoiceId;
        $storeId = (string)$this->config->get('payment_btcpay_btcpay_storeid');
        $webhookData = $this->config->get('payment_btcpay_webhook');

        // Older BTCPay versions did not always include all identity fields. To
        // retain compatibility, validate them whenever they are present.
        if (isset($data->storeId) && (!is_string($data->storeId) || !hash_equals($storeId, $data->storeId))) {
            $this->log->write('BTCPay webhook store id did not match the configured store.');
            $this->webhookResponse(403, 'Webhook identity mismatch.');
            return;
        }
        if (isset($data->webhookId) && is_array($webhookData) && isset($webhookData['id']) &&
            (!is_string($data->webhookId) || !hash_equals((string)$webhookData['id'], $data->webhookId))) {
            $this->log->write('BTCPay webhook id did not match the configured webhook.');
            $this->webhookResponse(403, 'Webhook identity mismatch.');
            return;
        }

        $btcpayOrder = $this->model_extension_btcpay_payment_btcpay->getOrderByInvoiceId($invoiceId);
        if (empty($btcpayOrder)) {
            if ($debug) {
                $this->log->write('No OpenCart order mapping for BTCPay invoice ' . $invoiceId . '.');
            }
            $this->webhookResponse(200);
            return;
        }

        $orderId = (int)$btcpayOrder['order_id'];
        $orderInfo = $this->model_checkout_order->getOrder($orderId);
        if (empty($orderInfo)) {
            $this->webhookResponse(200);
            return;
        }

        if ($debug) {
            $this->log->write(
                'BTCPay order mapping: order ' . $orderId . ', invoice ' . $invoiceId . '.'
            );
            $this->log->write('Webhook payload: ' . print_r($data, true));
        }

        $client = new Invoice(
            (string)$this->config->get('payment_btcpay_url'),
            (string)$this->config->get('payment_btcpay_api_auth_token')
        );
        try {
            $invoice = $client->getInvoice($storeId, $invoiceId);
        } catch (\Throwable $e) {
            $this->log->write('Failed to load BTCPay invoice ' . $invoiceId . ': ' . $e->getMessage());
            // A transient API failure should be retried by BTCPay.
            $this->webhookResponse(503, 'Unable to verify invoice.');
            return;
        }

        if ($debug) {
            $this->log->write('Invoice data: ' . print_r($invoice, true));
        }

        try {
            if (!$this->invoiceHasExpectedId($invoice, $invoiceId)) {
                $this->log->write('BTCPay API returned an unexpected invoice identity for ' . $invoiceId . '.');
                $this->webhookResponse(503, 'Unable to verify invoice identity.');
                return;
            }

            $state = $this->getAuthoritativeInvoiceState($invoice);
        } catch (\Throwable $e) {
            $this->log->write('Invalid BTCPay invoice response for ' . $invoiceId . ': ' . $e->getMessage());
            $this->webhookResponse(503, 'Unable to verify invoice state.');
            return;
        }

        if ($state === null) {
            if ($debug) {
                $this->log->write('No OpenCart transition for authoritative BTCPay invoice state.');
            }
            $this->webhookResponse(200);
            return;
        }

        $lockAcquired = $this->model_extension_btcpay_payment_btcpay->acquireOrderLock($orderId);
        if ($lockAcquired === false) {
            $this->webhookResponse(503, 'Order is currently being updated.');
            return;
        }

        try {
            // Re-read the mappings after taking the lock. The highest id is the
            // one active invoice; historical rows remain available to detect
            // late payments without allowing stale status regressions.
            $btcpayOrder = $this->model_extension_btcpay_payment_btcpay->getOrderByInvoiceId($invoiceId);
            $activeOrder = $this->model_extension_btcpay_payment_btcpay->getOrder($orderId);
            $orderInfo = $this->model_checkout_order->getOrder($orderId);

            if (empty($btcpayOrder) || empty($activeOrder) || empty($orderInfo)) {
                $this->webhookResponse(200);
                return;
            }

            if ((string)$activeOrder['invoice_id'] !== $invoiceId) {
                if ($state['payment_detected']) {
                    $this->addManualReview(
                        $orderInfo,
                        $invoiceId,
                        'payment was detected for an older, superseded invoice (' . $state['description'] . ')'
                    );
                } elseif ($debug) {
                    $this->log->write('Ignored non-payment event for superseded BTCPay invoice ' . $invoiceId . '.');
                }

                $this->webhookResponse(200);
                return;
            }

            // The order can be edited while the remote invoice is being
            // fetched. Check the current order immediately before accepting a
            // payment transition. Superseded non-payment events were already
            // ignored above and cannot affect the order.
            $mismatchReason = '';
            if (!$this->invoiceMatchesOrder($invoice, $orderInfo, $mismatchReason, $invoiceId)) {
                $this->addManualReview($orderInfo, $invoiceId, $mismatchReason);
                $this->webhookResponse(200);
                return;
            }

            if ($this->getPaymentMethodCode($orderInfo['payment_method'] ?? null) !== self::PAYMENT_METHOD_CODE) {
                $this->addManualReview($orderInfo, $invoiceId, 'the order payment method is no longer BTCPay');
                $this->webhookResponse(200);
                return;
            }

            $targetStatus = (int)$this->config->get($state['setting']);
            if ($targetStatus <= 0 || (int)$orderInfo['order_status_id'] === $targetStatus) {
                if ($debug) {
                    $this->log->write('BTCPay webhook produced no order status change.');
                }
                $this->webhookResponse(200);
                return;
            }

            // Do not undo a fulfilled/manually completed order merely because
            // its active invoice expired without receiving payment.
            if (!$state['payment_detected'] && $this->isProtectedOrderStatus((int)$orderInfo['order_status_id'])) {
                $this->addManualReview(
                    $orderInfo,
                    $invoiceId,
                    'a non-payment invoice state was not allowed to regress a processing or completed order'
                );
                $this->webhookResponse(200);
                return;
            }

            $this->model_checkout_order->addHistory(
                $orderId,
                $targetStatus,
                'Payment status update: ' . $state['description'] . ' Invoice id: ' . $invoiceId,
                $state['notify']
            );

            if ($debug) {
                $this->log->write('Successfully updated BTCPay order status: ' . $state['description']);
            }
        } catch (\Throwable $e) {
            $this->log->write('Failed to process BTCPay invoice ' . $invoiceId . ': ' . $e->getMessage());
            $this->webhookResponse(503, 'Unable to process invoice update.');
            return;
        } finally {
            if ($lockAcquired === true) {
                $this->model_extension_btcpay_payment_btcpay->releaseOrderLock($orderId);
            }
        }

        $this->webhookResponse(200);
    }

    protected function checkoutError(string $message, bool $useModal): void
    {
        $this->log->write('BTCPay checkout rejected: ' . $message);

        if ($useModal) {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['error' => $message]));
        } else {
            $this->session->data['error_warning'] = $message;
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }
    }

    protected function getCheckoutValidationError(array $orderInfo): string
    {
        if (!$this->config->get('payment_btcpay_status')) {
            return $this->language->get('error_payment_disabled');
        }

        if ($this->getPaymentMethodCode($this->session->data['payment_method'] ?? null) !== self::PAYMENT_METHOD_CODE) {
            return $this->language->get('error_payment_method');
        }

        if (empty($orderInfo) ||
            $this->getPaymentMethodCode($orderInfo['payment_method'] ?? null) !== self::PAYMENT_METHOD_CODE) {
            return $this->language->get('error_payment_method');
        }

        if ($this->isProtectedOrderStatus((int)($orderInfo['order_status_id'] ?? 0))) {
            return $this->language->get('error_order_status');
        }

        $minimumTotal = (float)$this->config->get('payment_btcpay_total');
        if ($minimumTotal > 0 && (float)($orderInfo['total'] ?? 0) < $minimumTotal) {
            return $this->language->get('error_minimum_total');
        }

        return '';
    }

    protected function getPaymentMethodCode($paymentMethod): string
    {
        if (is_array($paymentMethod)) {
            return isset($paymentMethod['code']) && is_string($paymentMethod['code']) ? $paymentMethod['code'] : '';
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

    protected function isProtectedOrderStatus(int $orderStatusId): bool
    {
        if ($orderStatusId <= 0) {
            return false;
        }

        $protectedStatuses = array_map(
            'intval',
            array_merge(
                (array)$this->config->get('config_processing_status'),
                (array)$this->config->get('config_complete_status')
            )
        );

        return in_array($orderStatusId, $protectedStatuses, true);
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

        $expectedAmount = $this->prepareOrderTotal(
            $orderInfo['total'],
            $orderInfo['currency_code'],
            $orderInfo['currency_value'] ?? null
        );
        if (!$this->amountsEqual((string)$expectedAmount, (string)$invoice->getAmount())) {
            $mismatchReason = 'the invoice amount does not match the current OpenCart order';
            return false;
        }

        if (strtoupper((string)$invoice->getCurrency()) !== strtoupper((string)$orderInfo['currency_code'])) {
            $mismatchReason = 'the invoice currency does not match the OpenCart order';
            return false;
        }

        $invoiceData = $invoice->getData();
        $metadata = isset($invoiceData['metadata']) && is_array($invoiceData['metadata']) ? $invoiceData['metadata'] : [];
        if (!array_key_exists('orderId', $metadata) || (string)$metadata['orderId'] !== (string)$orderInfo['order_id']) {
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

    protected function getAuthoritativeInvoiceState(\BTCPayServer\Result\Invoice $invoice): ?array
    {
        $invoiceData = $invoice->getData();
        $status = (string)$invoice->getStatus();
        $additionalStatus = isset($invoiceData['additionalStatus']) ? (string)$invoiceData['additionalStatus'] : '';

        switch ($status) {
            case 'New':
                if (in_array($additionalStatus, ['PaidPartial', 'PaidOver'], true)) {
                    return [
                        'setting' => 'payment_btcpay_paid_status_id',
                        'description' => 'Payment received but the invoice is not fully settled.',
                        'notify' => false,
                        'payment_detected' => true,
                    ];
                }
                return null;

            case 'Processing':
                return [
                    'setting' => 'payment_btcpay_paid_status_id',
                    'description' => $additionalStatus === 'PaidOver' ?
                        'Full payment with overpayment received; waiting for settlement.' :
                        'Full payment received; waiting for settlement.',
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
                    // Invalid invoices commonly contain an unconfirmed payment;
                    // older invalid invoices should therefore remain visible.
                    'payment_detected' => true,
                ];
        }

        return null;
    }

    protected function addManualReview(array $orderInfo, string $invoiceId, string $reason): void
    {
        $comment = 'BTCPay manual review required for invoice ' . $invoiceId . ': ' . $reason . '.';
        $this->log->write($comment);

        $orderStatusId = (int)($orderInfo['order_status_id'] ?? 0);
        if ($orderStatusId > 0 &&
            !$this->model_extension_btcpay_payment_btcpay->hasOrderHistoryComment(
                (int)$orderInfo['order_id'],
                $comment
            )) {
            $this->model_checkout_order->addHistory(
                (int)$orderInfo['order_id'],
                $orderStatusId,
                $comment,
                false
            );
        }
    }

    protected function webhookResponse(int $statusCode, string $message = ''): void
    {
        $statusTexts = [
            200 => 'OK',
            400 => 'Bad Request',
            403 => 'Forbidden',
            503 => 'Service Unavailable',
        ];
        $statusText = $statusTexts[$statusCode] ?? 'OK';

        $this->response->addHeader('HTTP/1.1 ' . $statusCode . ' ' . $statusText);
        $this->response->addHeader('Content-Type: application/json');
        if ($statusCode === 503) {
            $this->response->addHeader('Retry-After: 5');
        }
        $this->response->setOutput(json_encode($message === '' ? [] : ['message' => $message]));
    }

    /**
     * Check webhook signature to be a valid request.
     */
    protected function validWebhookRequest(string $signature, string $requestData): bool {
        $whData = $this->config->get('payment_btcpay_webhook');
        if (is_array($whData) && !empty($whData['secret']) && $signature !== '') {
            $expectedSignature = 'sha256=' . hash_hmac(
                'sha256',
                $requestData,
                (string)$whData['secret']
            );

            return hash_equals($expectedSignature, $signature);
        }
        return false;
    }

    protected function createInvoice(array $order_info, string $token): ?\BTCPayServer\Result\Invoice {
        // API credentials.
        $apiKey = $this->config->get('payment_btcpay_api_auth_token');
        $apiHost = $this->config->get('payment_btcpay_url');
        $apiStoreId = $this->config->get('payment_btcpay_btcpay_storeid');
        $debug = $this->config->get('payment_btcpay_debug_mode');

        $client = new Invoice($apiHost, $apiKey);

        // Checkout options.
        $checkoutOptions = new InvoiceCheckoutOptions();
        $redirectUrl = $this->url->link(
          'extension/btcpay/payment/btcpay|success',
          ['token' => $token],
          true
        );

        $checkoutOptions->setRedirectURL(htmlspecialchars_decode($redirectUrl));
        if ($debug) {
            $this->log->write('Configured the BTCPay return URL for order ' . (int)$order_info['order_id'] . '.');
        }

        // Metadata.
        $metadata = [];

        $amount = $this->prepareOrderTotal(
            $order_info['total'],
            $order_info['currency_code'],
            $order_info['currency_value'] ?? null
        );

        // Create the invoice on BTCPay Server.
        try {
            $invoice = $client->createInvoice(
              $apiStoreId,
              $order_info['currency_code'],
              $amount,
              $order_info['order_id'],
              null, // this is null here as we handle it in the metadata.
              $metadata,
              $checkoutOptions
            );

            return $invoice;
        } catch (\Throwable $e) {
            $this->log->write($e->getMessage());
        }

        return null;
    }

    /**
     * Check if the order already has an invoice id and it is still not expired.
     */
    protected function orderHasExistingInvoice(array $order_info): ?\BTCPayServer\Result\Invoice {
        // API credentials.
        $apiKey = $this->config->get('payment_btcpay_api_auth_token');
        $apiHost = $this->config->get('payment_btcpay_url');
        $apiStoreId = $this->config->get('payment_btcpay_btcpay_storeid');
        $debug = $this->config->get('payment_btcpay_debug_mode');

        $client = new Invoice($apiHost, $apiKey);

        $btcpay_order = $this->model_extension_btcpay_payment_btcpay->getOrder(
          $order_info['order_id']
        );

        if ($debug) {
            $this->log->write(__FUNCTION__);
            $this->log->write(
                empty($btcpay_order['invoice_id']) ?
                    'No existing BTCPay invoice mapping.' :
                    'Existing BTCPay invoice mapping: ' . (string)$btcpay_order['invoice_id']
            );
        }

        if (!empty($btcpay_order['invoice_id'])) {
            $existingInvoice = $client->getInvoice($apiStoreId, $btcpay_order['invoice_id']);
            $mismatchReason = '';

            if ($existingInvoice->isExpired() === false &&
              $this->invoiceMatchesOrder(
                  $existingInvoice,
                  $order_info,
                  $mismatchReason,
                  (string)$btcpay_order['invoice_id']
              )
            ) {
                return $existingInvoice;
            }

            if ($debug && $mismatchReason !== '') {
                $this->log->write('Existing BTCPay invoice will not be reused: ' . $mismatchReason);
            }
        }

        return null;
    }

    protected function prepareOrderTotal($total, $currencyCode, $currencyValue = null): \BTCPayServer\Util\PreciseNumber {
        // Use the exchange rate stored with the OpenCart order. Falling back to
        // the configured rate retains compatibility with callers that do not
        // yet provide currency_value.
        if ($currencyValue === null || !is_numeric($currencyValue) || (float)$currencyValue <= 0) {
            $currencyValue = $this->currency->getValue($currencyCode);
        }

        if (function_exists('bcmul')) {
            // Preserve the extension's existing eight-decimal, half-up amount
            // rounding while avoiding a floating-point comparison.
            $unroundedTotal = bcmul((string)$total, (string)$currencyValue, 9);
            $roundingIncrement = '0.000000005';
            $convertedTotal = isset($unroundedTotal[0]) && $unroundedTotal[0] === '-' ?
                bcsub($unroundedTotal, $roundingIncrement, 8) :
                bcadd($unroundedTotal, $roundingIncrement, 8);
        } else {
            // The BTCPay client requires BCMath, but keep a graceful fallback
            // so the extension settings page and error handling remain usable.
            $convertedTotal = number_format((float)$total * (float)$currencyValue, 8, '.', '');
        }

        return PreciseNumber::parseString($convertedTotal);
    }

}
