<?php
namespace Opencart\Catalog\Controller\Extension\Btcpay\Payment;
use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Client\Webhook;
use BTCPayServer\Util\PreciseNumber;

require_once DIR_EXTENSION . 'btcpay/system/library/btcpay/autoload.php';
require_once DIR_EXTENSION . 'btcpay/system/library/btcpay/version.php';

class Btcpay extends \Opencart\System\Engine\Controller
{

    public function index(): string
    {
        $this->load->language('extension/btcpay/payment/btcpay');
        $this->load->model('checkout/order');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['action'] = $this->url->link(
          'extension/btcpay/payment/btcpay|checkout',
          '',
          true
        );

        return $this->load->view('extension/btcpay/payment/btcpay', $data);
    }

    public function checkout(): void
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/btcpay/payment/btcpay');

        $debug = $this->config->get('payment_btcpay_debug_mode');

        if ($debug) {
            $this->log->write('Entering checkout() of BTCPay catalog controller.');
        }

        $metadata = [];
        $token = md5(uniqid(rand(), true));


        $order_info = $this->model_checkout_order->getOrder(
          $this->session->data['order_id']
        );

        // Set included tax amount.
        //// $metadata['taxIncluded'] = $order->get_cart_tax();

        // POS metadata.
        ////todo: $metadata['posData'] = $this->preparePosMetadata( $order );

        // Checkout options.
        $checkoutOptions = new InvoiceCheckoutOptions();
        $redirectUrl = $this->url->link(
          'extension/btcpay/payment/btcpay|success',
          ['token' => $token],
          true
        );

        $checkoutOptions->setRedirectURL(htmlspecialchars_decode($redirectUrl));
        if ($debug) {
            $this->log->write( 'Setting redirect url to: ' . $redirectUrl );
        }

        // Calculate total and format it properly.
        $total = number_format(
          $order_info['total'] * $this->currency->getvalue(
            $order_info['currency_code']
          ),
          8,
          '.',
          ''
        );
        $amount = PreciseNumber::parseString(
          $total
        ); // unlike method signature suggests, it returns string.

        // API credentials.
        $apiKey = $this->config->get('payment_btcpay_api_auth_token');
        $host = $this->config->get('payment_btcpay_url');
        $storeId = $this->config->get('payment_btcpay_btcpay_storeid');

        // Create the invoice on BTCPay Server.
        $client = new Invoice($host, $apiKey);
        try {
            $invoice = $client->createInvoice(
              $storeId,
              $order_info['currency_code'],
              $amount,
              $order_info['order_id'],
              null, // this is null here as we handle it in the metadata.
              $metadata,
              $checkoutOptions
            );
        } catch (\Throwable $e) {
            $this->log->write($e->getMessage());
        }

        if ($invoice->getData()['id']) {
            $this->model_extension_btcpay_payment_btcpay->addOrder([
              'order_id' => $order_info['order_id'],
              'token' => $token,
              'invoice_id' => $invoice->getData()['id'],
            ]);

            $this->model_checkout_order->addHistory(
              $order_info['order_id'],
              $this->config->get('payment_btcpay_new_status_id'),
              'BTCPay invoice id: ' . $invoice->getData()['id']
            );

            $this->response->redirect($invoice->getData()['checkoutLink']);
        } else {
            $this->log->write(
              "Order #" . $order_info['order_id'] . " is not valid or something went wrong. Please check BTCPay Server API request logs."
            );
            $this->response->redirect(
              $this->url->link('checkout/checkout', '', true)
            );
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
        }

        if ($order_id = $this->session->data['order_id']) {
            $order = $this->model_extension_btcpay_payment_btcpay->getOrder(
              $order_id
            );

            // Check if token is present and valid.
            if (empty($order) || strcmp(
                $order['token'],
                $this->request->get['token']
              ) !== 0) {
                if ($debug) {
                    $this->log->write('Redirect to success page had no valid token.');
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
              $this->log->write('Redirect to success page valid order id or session expired.');
            }
            $this->response->redirect(
              $this->url->link('common/home', '', true)
            );
        }
    }

    public function callback()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/btcpay/payment/btcpay');

        $json = [];

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

        $btcpay_order = $this->model_extension_btcpay_payment_btcpay->getOrderByInvoiceId(
          $data->invoiceId
        );

        if (empty($btcpay_order)) {
            $this->log->write('Order not found. Aborting.');
            http_response_code(200);
            die();
        }

        $order_info = $this->model_checkout_order->getOrder(
          $btcpay_order['order_id']
        );

        if ($debug) {
            $this->log->write('Order:');
            $this->log->write($btcpay_order);
            $this->log->write('Webhook payload: ');
            $this->log->write(print_r($data, true));
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
                $this->log->write(print_r($invoice, true));
            }

            $invStatus = $invoice->getStatus();
            $invAdditionalStatus = $invoice->getData()['additionalStatus'];

            if ($invoice) {
                $order_status = NULL;
                $order_message = 'Event: ' . $data->type . ': ';
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
                    $this->model_checkout_order->addHistory(
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
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Check webhook signature to be a valid request.
     */
    public function validWebhookRequest(string $signature, string $requestData): bool {
        if ($whData = $this->config->get('payment_btcpay_webhook')) {
            return Webhook::isIncomingWebhookRequestValid($requestData, $signature, $whData['secret']);
        }
        return false;
    }

}
