<?php

/** Credits: OpenCart plugin structure and classes inspired by CoinGate payment plugin. */

use BTCPayServer\Client\Store;
use BTCPayServer\Client\Webhook;

require_once DIR_SYSTEM . 'library/btcpay/autoload.php';
require_once DIR_SYSTEM . 'library/btcpay/version.php';

class ControllerExtensionPaymentBTCPay extends Controller
{
    private const WEBHOOK_EVENTS = [
        'InvoiceReceivedPayment',
        'InvoicePaymentSettled',
        'InvoiceProcessing',
        'InvoiceExpired',
        'InvoiceSettled',
        'InvoiceInvalid',
    ];

    private $error = [];

    public function index()
    {
        $this->load->language('extension/payment/btcpay');
        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');
        $this->load->model('localisation/order_status');
        $this->load->model('localisation/geo_zone');

        if ($this->request->server['REQUEST_METHOD'] === 'POST') {
            $this->prepareSensitivePostData();

            if ($this->validate()) {
                $this->prepareWebhookForSave();
            }

            if (!$this->error) {
                unset($this->request->post['payment_btcpay_webhook_delete']);

                $this->model_setting_setting->editSetting(
                  'payment_btcpay',
                  $this->request->post
                );
                $this->session->data['success'] = $this->language->get('notice_success');
                $this->response->redirect(
                  $this->url->link(
                    'marketplace/extension',
                    'user_token=' . $this->session->data['user_token'] . '&type=payment',
                    true
                  )
                );
                return;
            }
        }

        $data['action'] = $this->url->link(
          'extension/payment/btcpay',
          'user_token=' . $this->session->data['user_token'],
          true
        );
        $data['cancel'] = $this->url->link(
          'marketplace/extension',
          'user_token=' . $this->session->data['user_token'] . '&type=payment',
          true
        );
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
        $data['error_warning'] = $this->error['warning'] ?? '';

        $data['breadcrumbs'] = [
            [
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link(
                  'common/dashboard',
                  'user_token=' . $this->session->data['user_token'],
                  true
                ),
            ],
            [
                'text' => $this->language->get('text_extension'),
                'href' => $this->url->link(
                  'marketplace/extension',
                  'user_token=' . $this->session->data['user_token'] . '&type=payment',
                  true
                ),
            ],
            [
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link(
                  'extension/payment/btcpay',
                  'user_token=' . $this->session->data['user_token'],
                  true
                ),
            ],
        ];

        $fields = [
            'payment_btcpay_status',
            'payment_btcpay_url',
            'payment_btcpay_btcpay_storeid',
            'payment_btcpay_modal_mode',
            'payment_btcpay_new_status_id',
            'payment_btcpay_paid_status_id',
            'payment_btcpay_settled_status_id',
            'payment_btcpay_settled_paidover_status_id',
            'payment_btcpay_invalid_status_id',
            'payment_btcpay_expired_status_id',
            'payment_btcpay_expired_partialpayment_status_id',
            'payment_btcpay_expired_paidlate_status_id',
            'payment_btcpay_refunded_status_id',
            'payment_btcpay_total',
            'payment_btcpay_geo_zone_id',
            'payment_btcpay_debug_mode',
            'payment_btcpay_sort_order',
        ];

        foreach ($fields as $field) {
            $data[$field] = isset($this->request->post[$field])
              ? $this->request->post[$field]
              : $this->config->get($field);
        }

        // Secrets must never be sent back to the browser. A blank API-key field
        // means "keep the saved key" on subsequent submissions.
        $data['payment_btcpay_api_auth_token'] = '';
        $data['payment_btcpay_api_auth_token_set'] =
          !empty($this->config->get('payment_btcpay_api_auth_token'));

        $webhook = $this->config->get('payment_btcpay_webhook');
        $data['payment_btcpay_webhook'] = [
            'id' => is_array($webhook) ? ($webhook['id'] ?? '') : '',
            'url' => is_array($webhook) ? ($webhook['url'] ?? '') : '',
        ];
        $data['payment_btcpay_webhook_delete'] = false;
        $data['payment_btcpay_insecure_http'] = stripos(
          trim((string)$data['payment_btcpay_url']),
          'http://'
        ) === 0;
        $data['payment_btcpay_insecure_callback'] = stripos(
          $this->webhookCallbackUrl(),
          'http://'
        ) === 0;

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput(
          $this->load->view('extension/payment/btcpay', $data)
        );
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/btcpay')) {
            $this->setError('error_permission');
        }

        if (!class_exists('BTCPayServer\Client\Health')) {
            $this->setError('error_composer');
        }

        $host = (string)($this->request->post['payment_btcpay_url'] ?? '');
        $apiKey = (string)($this->request->post['payment_btcpay_api_auth_token'] ?? '');
        $storeId = (string)($this->request->post['payment_btcpay_btcpay_storeid'] ?? '');

        if (!$this->isValidHttpUrl($host)) {
            $this->setError('error_url');
        }

        if ($apiKey === '' || $storeId === '') {
            $this->setError('error_configuration');
        }

        if (!$this->error) {
            try {
                $client = new Store($host, $apiKey);
                $store = $client->getStore($storeId);

                if (empty($store->getId())) {
                    $this->setError('error_store_not_found');
                }
            } catch (\Throwable $e) {
                $this->setError('error_connect_to_btcpay');
                if ($this->config->get('payment_btcpay_debug_mode')) {
                    $this->log->write('BTCPay connection validation failed: ' . $e->getMessage());
                }
            }
        }

        return !$this->error;
    }

    public function install()
    {
        $this->load->model('extension/payment/btcpay');
        $this->model_extension_payment_btcpay->install();
    }

    public function uninstall()
    {
        $this->load->model('extension/payment/btcpay');
        $this->model_extension_payment_btcpay->uninstall();
    }

    private function prepareSensitivePostData(): void
    {
        $host = trim((string)($this->request->post['payment_btcpay_url'] ?? ''));
        $this->request->post['payment_btcpay_url'] = rtrim($host, '/');

        $storeId = trim(
          (string)($this->request->post['payment_btcpay_btcpay_storeid'] ?? '')
        );
        $this->request->post['payment_btcpay_btcpay_storeid'] = $storeId;

        $apiKey = trim(
          (string)($this->request->post['payment_btcpay_api_auth_token'] ?? '')
        );
        if ($apiKey === '') {
            $apiKey = (string)$this->config->get('payment_btcpay_api_auth_token');
        }
        $this->request->post['payment_btcpay_api_auth_token'] = $apiKey;
    }

    private function prepareWebhookForSave(): void
    {
        $host = $this->request->post['payment_btcpay_url'];
        $apiKey = $this->request->post['payment_btcpay_api_auth_token'];
        $storeId = $this->request->post['payment_btcpay_btcpay_storeid'];

        $oldHost = (string)$this->config->get('payment_btcpay_url');
        $oldApiKey = (string)$this->config->get('payment_btcpay_api_auth_token');
        $oldStoreId = (string)$this->config->get('payment_btcpay_btcpay_storeid');
        $webhook = $this->config->get('payment_btcpay_webhook');
        $hasWebhook = $this->webhookExists($webhook);
        $connectionChanged =
          rtrim($oldHost, '/') !== $host || $oldStoreId !== $storeId;
        $deleteRequested =
          $hasWebhook && !empty($this->request->post['payment_btcpay_webhook_delete']);

        if ($hasWebhook && ($connectionChanged || $deleteRequested)) {
            $this->webhookDelete(
              $oldHost,
              $oldApiKey,
              $oldStoreId,
              $webhook
            );
            $webhook = null;
            $hasWebhook = false;
        }

        if ($deleteRequested) {
            $this->request->post['payment_btcpay_webhook'] = null;
            return;
        }

        if (!$hasWebhook) {
            $webhook = $this->webhookSetup($host, $apiKey, $storeId);

            if (!$this->webhookExists($webhook)) {
                $this->setError('error_webhook_setup');
                return;
            }
        }

        $this->request->post['payment_btcpay_webhook'] = $webhook;
    }

    private function webhookExists($data): bool
    {
        return
          is_array($data) &&
          !empty($data['id']) &&
          !empty($data['secret']) &&
          !empty($data['url']);
    }

    private function webhookSetup(
      string $host,
      string $apiKey,
      string $storeId
    ): ?array {
        try {
            $client = new Webhook($host, $apiKey);
            $webhook = $client->createWebhook(
              $storeId,
              $this->webhookCallbackUrl(),
              self::WEBHOOK_EVENTS,
              null
            );
            $webhookData = $webhook->getData();

            return [
                'id' => $webhookData['id'] ?? '',
                'secret' => $webhookData['secret'] ?? '',
                'url' => $webhookData['url'] ?? '',
            ];
        } catch (\Throwable $e) {
            $this->log->write('BTCPay webhook creation failed.');
            return null;
        }
    }

    private function webhookDelete(
      string $host,
      string $apiKey,
      string $storeId,
      array $webhook
    ): void {
        if (
            $host === '' ||
            $apiKey === '' ||
            $storeId === '' ||
            empty($webhook['id'])
        ) {
            return;
        }

        try {
            $client = new Webhook($host, $apiKey);
            $client->deleteWebhook($storeId, $webhook['id']);
        } catch (\Throwable $e) {
            $this->log->write('BTCPay webhook deletion failed.');
        }
    }

    private function webhookCallbackUrl(): string
    {
        $catalogUrl = defined('HTTPS_CATALOG')
          ? HTTPS_CATALOG
          : (defined('HTTP_CATALOG') ? HTTP_CATALOG : '');

        return rtrim($catalogUrl, '/') .
          '/index.php?route=extension/payment/btcpay/callback';
    }

    private function isValidHttpUrl(string $url): bool
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

    private function setError(string $languageKey): void
    {
        if (empty($this->error['warning'])) {
            $this->error['warning'] = $this->language->get($languageKey);
        }
    }
}
