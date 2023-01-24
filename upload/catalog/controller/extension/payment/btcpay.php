<?php

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Client\Webhook;
use BTCPayServer\Util\PreciseNumber;

require DIR_SYSTEM . 'library/btcpay/autoload.php';
require DIR_SYSTEM . 'library/btcpay/version.php';

class ControllerExtensionPaymentBTCPay extends Controller
{

    public function index()
    {
        $this->load->language('extension/payment/btcpay');
        $this->load->model('checkout/order');


        $useModal = $this->config->get('payment_btcpay_modal_mode');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['action'] = $this->url->link(
          'extension/payment/btcpay/checkout',
          '',
          true
        );

        if ($useModal) {
            $host = $this->config->get('payment_btcpay_url');
            $data['btcpay_host'] = $host;
            $data['modal_url'] = $host . '/modal/btcpay.js';
            $data['success_link'] = $this->url->link('checkout/success', '', true);
            $data['invoice_expired_text'] = $this->language->get('invoice_expired_text');

            return $this->load->view('extension/payment/btcpay_modal', $data);
        } else {
            // Redirect.
            return $this->load->view('extension/payment/btcpay', $data);
        }
    }

    public function checkout()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/btcpay');

        $debug = $this->config->get('payment_btcpay_debug_mode');
        $useModal = $this->config->get('payment_btcpay_modal_mode');

        if ($debug) {
            $this->log->write('Entering checkout() of BTCPay catalog controller.');
            $this->log->write('Session data:');
            $this->log->write(print_r($this->session->data, true));
        }

        if (!isset($this->session->data['order_id'])) {
            $this->log->write('No session data order_id present, aborting.');
            return false;
        }

        $order_info = $this->model_checkout_order->getOrder(
          $this->session->data['order_id']
        );

        $invoiceId = '';
        $checkoutLink = '';

        // First, check if we have an existing and not expired wallet and do not create a new one.
        if ($existingInvoice = $this->orderHasExistingInvoice($order_info)) {
            $invoiceId = $existingInvoice->getId();
            $checkoutLink = $existingInvoice->getCheckoutLink();

            if ($debug) {
                $this->log->write('Found existing and not yet expired invoice: ' . $invoiceId);
            }
        } else {
            // Create the invoice on BTCPay Server.
            $token = md5(uniqid(rand(), true));
            if ($newInvoice = $this->createInvoice($order_info, $token)) {
                $invoiceId = $newInvoice->getId();
                $checkoutLink = $newInvoice->getCheckoutLink();

                // Add invoiceId to the btcpay order table.
                $this->model_extension_payment_btcpay->addOrder([
                      'order_id' => $order_info['order_id'],
                      'token' => $token,
                      'invoice_id' => $invoiceId,
                    ]);
            }
        }

        if (empty($invoiceId)) {
            $this->log->write(
              "Order #" . $order_info['order_id'] . " is not valid or something went wrong. Please check BTCPay Server API request logs."
            );
            $this->response->redirect(
              $this->url->link('checkout/checkout', '', true)
            );
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

    public function cancel()
    {
        $this->response->redirect($this->url->link('checkout/cart', ''));
    }

    public function success()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/btcpay');

        $debug = $this->config->get('payment_btcpay_debug_mode');

        if ($debug) {
            $this->log->write('Entering success callback / redirect page.');
        }

        if ($order_id = $this->session->data['order_id']) {
            $order = $this->model_extension_payment_btcpay->getOrder(
              $order_id
            );

            // Check if token is present and valid.
            if (empty($order) || strcmp(
                $order['token'],
                $this->request->get['token']
              ) !== 0) {
                if ($debug) {
                    $this->log->write('Redirect to home page, request had no valid token.');
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

    public function callback()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/btcpay');

        $debug = $this->config->get('payment_btcpay_debug_mode');

        if ($debug) {
            $this->log->write('Entering webhook callback:');
        }

        // Validate webhook request.
        // Note: getallheaders() CamelCases all headers for PHP-FPM/Nginx but
        // for others maybe not, so "BTCPay-Sig" may become "Btcpay-Sig".
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'btcpay-sig') {
                $signature = $value;
            }
        }

        $rawData = file_get_contents('php://input');
        $data = json_decode($rawData);

        // Validate webhook payload.
        if (!isset($signature) || !$this->validWebhookRequest($signature, $rawData)) {
            $whMessage = 'Failed to validate signature of the webhook request.';
            http_response_code(403);
            $this->log->write($whMessage);
            die($whMessage);
        }

        $btcpay_order = $this->model_extension_payment_btcpay->getOrderByInvoiceId(
          $data->invoiceId
        );

        $order_info = $this->model_checkout_order->getOrder(
          $btcpay_order['order_id']
        );

        if ($debug) {
            $this->log->write('Order:');
            $this->log->write($btcpay_order);
            $this->log->write('Webhook payload: ');
            $this->log->write($data);
        }

        if (!empty($order_info) && !empty($btcpay_order)) {
            // API credentials.
            $apiKey = $this->config->get('payment_btcpay_api_auth_token');
            $host = $this->config->get('payment_btcpay_url');
            $storeId = $this->config->get('payment_btcpay_btcpay_storeid');
            if ($debug) {
                $this->log->write('Loading invoice from BTCPay:');
            }
            // Create the invoice on BTCPay Server.
            $client = new Invoice($host, $apiKey);
            try {
                $invoice = $client->getInvoice($storeId, $data->invoiceId);
            } catch (\Throwable $e) {
                $this->log->write('Failed to load invoice: ');
                $this->log->write($e->getMessage());
            }

            if ($debug) {
                $this->log->write('Invoice data: ');
                $this->log->write($invoice);
            }

            $invStatus = $invoice->getStatus();
            $invAdditionalStatus = $invoice->getData()['additionalStatus'];

            if ($invoice) {
                $order_status = NULL;
                $order_message = 'Event: ' . $data->type;
                $notify = false;
                switch ($data->type) {
                    case "InvoiceReceivedPayment":
                        $order_status = 'payment_btcpay_paid_status_id';
                        $order_message .= ' Received (partial) payment but waiting for settlement. Invoice id: ' . $data->invoiceId;
                        break;

                    case "InvoicePaymentSettled":
                        // Only settled if the full invoice is paid. Can happen
                        // with expired invoices that get paid afterwards.
                        if ($invStatus === 'Expired' && $invoice->isPaidLate()) {
                            $order_status = 'payment_btcpay_settled_status_id';
                            $order_message = 'Already expired invoice now fully paid and settled.';
                            $notify = true;
                        } else {
                            $order_status = 'payment_btcpay_paid_status_id';
                            $order_message .= ' (Partial) payment now settled.';
                        }
                        break;

                    case "InvoiceProcessing":
                        $order_status = 'payment_btcpay_paid_status_id';
                        $order_message .= ' Received full payment but waiting for settlement.';
                        break;

                    case "InvoiceSettled":
                        if ($invAdditionalStatus === 'PaidOver') {
                            $order_status = 'payment_btcpay_settled_paidover_status_id';
                            $order_message = 'Overpaid and settled. Please check transaction for refund amount.';
                            $notify = true;
                        } else {
                            $order_status = 'payment_btcpay_settled_status_id';
                            $order_message = 'Fully paid and settled.';
                            $notify = true;
                        }
                        break;

                    case "InvoiceExpired":
                        if ($data->partiallyPaid) {
                            $order_status = 'payment_btcpay_expired_partialpayment_status_id';
                            $order_message .= ' Invoice expired but received partial payment. Please check transaction details of invoice on BTCPay Server.';
                        } else {
                            $order_status = 'payment_btcpay_expired_status_id';
                        }
                        break;

                    case "InvoiceInvalid":
                        $order_status = 'payment_btcpay_invalid_status_id';
                        break;

                    default:
                        $order_status = NULL;
                }

                if (!is_null($order_status)) {
                    $this->model_checkout_order->addOrderHistory(
                      $btcpay_order['order_id'],
                      $this->config->get($order_status),
                      'Payment status update: ' . $order_message,
                      $notify
                    );
                    if ($debug) {
                        $this->log->write('Sucessfully updated order status: ' . $order_message);
                    }
                } else {
                    if ($debug) {
                        $this->log->write('No matching webhook event, did not update order. Event: ' . $data->type);
                    }
                }
            }
        }

        $this->response->addHeader('HTTP/1.1 200 OK');
    }

    /**
     * Check webhook signature to be a valid request.
     */
    protected function validWebhookRequest(string $signature, string $requestData): bool {
        if ($whData = $this->config->get('payment_btcpay_webhook')) {
            return Webhook::isIncomingWebhookRequestValid($requestData, $signature, $whData['secret']);
        }
        return false;
    }

    protected function createInvoice(array $order_info, string $token): ?\BTCPayServer\Result\Invoice {
        // API credentials.
        $apiKey = $this->config->get('payment_btcpay_api_auth_token');
        $apiHost = $this->config->get('payment_btcpay_url');
        $apiStoreId = $this->config->get('payment_btcpay_btcpay_storeid');
        $client = new Invoice($apiHost, $apiKey);

        $debug = $this->config->get('payment_btcpay_debug_mode');

        // Checkout options.
        $checkoutOptions = new InvoiceCheckoutOptions();
        $redirectUrl = $this->url->link(
          'extension/payment/btcpay/success',
          ['token' => $token],
          true
        );

        $checkoutOptions->setRedirectURL(htmlspecialchars_decode($redirectUrl));
        if ($debug) {
            $this->log->write( 'Setting redirect url to: ' . $redirectUrl );
        }

        // Metadata.
        $metadata = [];

        $amount = $this->prepareOrderTotal($order_info['total'], $order_info['currency_code']);

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
    protected function orderHasExistingInvoice(array $order_info): ? \BTCPayServer\Result\Invoice {
        // API credentials.
        $apiKey = $this->config->get('payment_btcpay_api_auth_token');
        $apiHost = $this->config->get('payment_btcpay_url');
        $apiStoreId = $this->config->get('payment_btcpay_btcpay_storeid');
        $client = new Invoice($apiHost, $apiKey);

        // Calculate order total.
        $total = $this->prepareOrderTotal($order_info['total'], $order_info['currency_code']);
        // Round to 2 decimals to avoid mismatch.
        $totalRounded = round((float) $total->__toString(), 2);

        $btcpay_order = $this->model_extension_payment_btcpay->getOrder(
          $order_info['order_id']
        );

        $this->log->write(__FUNCTION__);
        $this->log->write(print_r($btcpay_order, true));

        if (!empty($btcpay_order['invoice_id'])) {
            $existingInvoice = $client->getInvoice($apiStoreId, $btcpay_order['invoice_id']);
            $invoiceAmount = $existingInvoice->getAmount();
            $isExpired = $existingInvoice->isExpired();
            $sameTotal = $totalRounded === (float) $invoiceAmount->__toString();

            if ($existingInvoice->isExpired() === false &&
              $totalRounded === (float) $invoiceAmount->__toString()
            ) {
                return $existingInvoice;
            }
        }

        return null;
    }

    protected function prepareOrderTotal($total, $currencyCode): \BTCPayserver\Util\PreciseNumber {
        // Calculate total and format it properly.
        $total = number_format(
          $total * $this->currency->getvalue(
            $currencyCode
          ),
          8,
          '.',
          ''
        );
        return PreciseNumber::parseString($total);
    }
}
