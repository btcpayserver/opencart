<?php
namespace Opencart\Admin\Controller\Extension\Btcpay\Payment;

use BTCPayServer\Client\Store;
use BTCPayServer\Client\Webhook;

require_once DIR_EXTENSION . 'btcpay/system/library/btcpay/autoload.php';
require_once DIR_EXTENSION . 'btcpay/system/library/btcpay/version.php';

class Btcpay extends \Opencart\System\Engine\Controller {
  private $error = [];

  public function index(): void {
    $this->load->language('extension/btcpay/payment/btcpay');
    $this->document->setTitle($this->language->get('heading_title'));

    $this->load->model('setting/setting');
    $this->load->model('localisation/order_status');
    $this->load->model('localisation/geo_zone');

    $data['save'] = $this->url->link('extension/btcpay/payment/btcpay|save', 'user_token=' . $this->session->data['user_token']);
    $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');
    $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);
    $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

    $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

    if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

    if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
      unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}

    $data['breadcrumbs'] = [];
    $data['breadcrumbs'][] = array(
        'text' => $this->language->get('text_home'),
        'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
    );
    $data['breadcrumbs'][] = array(
        'text' => $this->language->get('text_extension'),
        'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
    );
    $data['breadcrumbs'][] = array(
        'text' => $this->language->get('heading_title'),
        'href' => $this->url->link('extension/btcpay/payment/btcpay', 'user_token=' . $this->session->data['user_token'], true)
    );

    $fields = [
        'payment_btcpay_status',
        'payment_btcpay_url',
        'payment_btcpay_api_auth_token',
        'payment_btcpay_btcpay_storeid',
        'payment_btcpay_webhook',
        'payment_btcpay_webhook_delete',
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
    ];

    // Process our fields to be sure they are displayed.
    foreach ($fields as $field) {
      if (isset($this->request->post[$field])) {
  			$data[$field] = $this->request->post[$field];
  		} else {
  			$data[$field] = $this->config->get($field);
  		}
    }

    $data['payment_btcpay_sort_order'] = isset($this->request->post['payment_btcpay_sort_order']) ?
            $this->request->post['payment_btcpay_sort_order'] :  $this->config->get('payment_btcpay_sort_order');

    $data['header'] = $this->load->controller('common/header');
    $data['column_left'] = $this->load->controller('common/column_left');
    $data['footer'] = $this->load->controller('common/footer');

    $this->response->setOutput($this->load->view('extension/btcpay/payment/btcpay', $data));
  }

  protected function validate($messages): array {

      $this->load->language('extension/btcpay/payment/btcpay');
    if (!$this->user->hasPermission('modify', 'extension/btcpay/payment/btcpay')) {
      $messages['error'] = $this->language->get('error_permission');
    }

    if (!class_exists('BTCPayServer\Client\Health')) {
      $messages['error'] = $this->language->get('error_composer');
    }

    if (!isset($messages['error'])) {
        $host = $this->request->post['payment_btcpay_url'];
        $apiKey = $this->request->post['payment_btcpay_api_auth_token'];
        $storeId = $this->request->post['payment_btcpay_btcpay_storeid'];

        try {
            $client = new Store($host, $apiKey);
            $store = $client->getStore($storeId);
            if (empty($store->getId())) {
                $messages['error'] = $this->language->get('error_store_not_found');
            }
        } catch (\Throwable $e) {
            $messages['error'] = $this->language->get('error_connect_to_btcpay');
            $this->log->write($e->getMessage());
        }
    }

    return $messages;
  }

    public function save(): void {
        $this->load->language('extension/btcpay/payment/btcpay');
        $this->load->model('setting/setting');

        $json = [];
        $redirect = false;

        $json = $this->validate($json);

        if (empty($json['error'])) {
            $host = $this->request->post['payment_btcpay_url'];
            $apiKey = $this->request->post['payment_btcpay_api_auth_token'];
            $storeId = $this->request->post['payment_btcpay_btcpay_storeid'];

            // On saving we create a webhook if there is none yet.
            if ($this->webhookExists() === false) {
                if ($whData = $this->webhookSetup($host, $apiKey, $storeId)) {
                    $this->request->post['payment_btcpay_webhook'] = $whData;
                    $json['success'] = $this->language->get('notice_success_create_webhook');
                    $redirect = true;
                } else {
                    $json['error'] = $this->language->get('error_creating_webhook');
                }
            } else {
                // Check if the user wants to delete an existing webhook.
                if (isset($this->request->post['payment_btcpay_webhook_delete']) &&
                  $this->request->post['payment_btcpay_webhook_delete'] === '1'
                ) {
                    // Try to delete the webhook on the provided host.
                    $this->webhookDelete($host, $apiKey, $storeId);
                    unset($this->request->post['payment_btcpay_webhook']);
                    unset($this->request->post['payment_btcpay_webhook_delete']);
                    $json['success'] = $this->language->get('notice_success_delete_webhook');
                    $redirect = true;
                } else {
                    // Need to convert existing webhook values back to array for storage.
                    if (isset($this->request->post['payment_btcpay_webhook'])) {
                        $whString = $this->request->post['payment_btcpay_webhook'];
                        $whString = str_replace(['ID: ', 'SECRET: ', 'URL: '], '', $whString);
                        $whArr = explode(' | ', $whString);
                        if (count($whArr) === 3) {
                            $whData = [
                              'id' => $whArr[0],
                              'secret' => $whArr[1],
                              'url' => $whArr[2]
                            ];
                            $this->request->post['payment_btcpay_webhook'] = $whData;
                        }
                    }
                }
            }
        }

        if (empty($json['error'])) {
            if (!empty($json['success'])) {
                $json['success'] = $this->language->get('notice_success') . ' ' . $json['success'];
            } else {
                $json['success'] = $this->language->get('notice_success');
            }
            $this->model_setting_setting->editSetting('payment_btcpay', $this->request->post);
        }

        if ($redirect) {
            $this->session->data['success'] = $json['success'];
            unset($json['success']);
            $json['redirect'] = $this->url->link('extension/btcpay/payment/btcpay', 'user_token=' . $this->session->data['user_token'], true);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

	public function install(): void {
    $this->load->model('extension/btcpay/payment/btcpay');
		$this->model_extension_btcpay_payment_btcpay->install();
	}

	public function uninstall(): void {
		$this->load->model('extension/btcpay/payment/btcpay');
		$this->model_extension_btcpay_payment_btcpay->uninstall();
	}

  private function webhookExists(): bool {
      // Check if the config is any value set at all.
      $data = $this->config->get('payment_btcpay_webhook');
      if (empty($data) || !is_array($data)) {
          return false;
      }

      // todo: load webhook form BTCPay to check if the callback url domain is the same

      return true;
  }

  private function webhookSetup($host, $apiKey, $storeId): ?array {
      $whEvents = [
        'InvoiceReceivedPayment',
        'InvoicePaymentSettled',
        'InvoiceProcessing',
        'InvoiceExpired',
        'InvoiceSettled',
        'InvoiceInvalid'
      ];

      try {
          $whClient = new Webhook( $host, $apiKey );
          $webhook = $whClient->createWebhook(
            $storeId,
            $this->webhookCallbackUrl(),
            $whEvents,
            null
          );

          // Prepare data for settings storage.
         $whData = [
            'id' => $webhook->getData()['id'],
            'secret' => $webhook->getData()['secret'],
            'url' => $webhook->getData()['url']
         ];

          return $whData;
      } catch (\Throwable $e) {
          $this->log->write($e->getMessage());
      }

      return NULL;
  }

  private function webhookDelete($host, $apiKey, $storeId): void {
      $data = $this->config->get('payment_btcpay_webhook');

      $client = new \BTCPayServer\Client\Webhook($host, $apiKey);
      try {
          $client->deleteWebhook($storeId, $data['id']);
      } catch (\Throwable $e) {
          $this->log->write('Error deleting webhook: ' . $e->getMessage());
      }
  }

  private function webhookCallbackUrl(): string {
      $url = $this->url->link('extension/btcpay/payment/btcpay|callback', '', true);

      // As we are in admin controller context we need to strip out the admin
      // path to receive the correct frontend callback url.
      $adminPathParts = explode('/', DIR_APPLICATION);
      end($adminPathParts); // Last array item is empty.
      $adminPath = prev($adminPathParts);
      if (!empty($adminPath)) {
          $url = str_replace($adminPath . '/', '', $url);
      }

      return $url;
  }
}
