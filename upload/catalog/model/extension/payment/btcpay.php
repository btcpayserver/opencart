<?php

class ModelExtensionPaymentBTCPay extends Model {
  public function addOrder($data) {
    $this->db->query("INSERT INTO `" . DB_PREFIX . "btcpay_order` SET `order_id` = '" . (int)$data['order_id'] . "', `token` = '" . $this->db->escape($data['token']) . "', `invoice_id` = '" . $this->db->escape($data['invoice_id']) . "'");
  }

  public function getOrder($order_id) {
    $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "btcpay_order` WHERE `order_id` = '" . (int)$order_id . "' ORDER BY btcpay_order_id DESC LIMIT 1 ");

    return $query->row;
  }

  public function getOrderByInvoiceId($invoice_id) {
      $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "btcpay_order` WHERE `invoice_id` = '" . $this->db->escape($invoice_id) . "' ORDER BY `btcpay_order_id` DESC LIMIT 1");

      return $query->row;
  }

  public function consumeToken($btcpay_order_id, $token) {
    $this->db->query("UPDATE `" . DB_PREFIX . "btcpay_order` SET `token` = '' WHERE `btcpay_order_id` = '" . (int)$btcpay_order_id . "' AND `token` = '" . $this->db->escape($token) . "'");

    return $this->db->countAffected() === 1;
  }

  public function hasOrderHistoryComment($order_id, $comment) {
    $query = $this->db->query("SELECT `order_history_id` FROM `" . DB_PREFIX . "order_history` WHERE `order_id` = '" . (int)$order_id . "' AND `comment` = '" . $this->db->escape($comment) . "' LIMIT 1");

    return $query->num_rows > 0;
  }

  /**
   * Acquire a connection-scoped MySQL/MariaDB advisory lock.
   *
   * Null means the database adapter does not support advisory locks. Callers
   * may continue without one so existing OpenCart installations keep working.
   */
  public function acquireOrderLock($order_id, $timeout = 5) {
    try {
      $query = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($this->getOrderLockName($order_id)) . "', '" . max(0, (int)$timeout) . "') AS `lock_acquired`");

      if (!array_key_exists('lock_acquired', $query->row)) {
        return null;
      }

      if ($query->row['lock_acquired'] === null) {
        return null;
      }

      return (int)$query->row['lock_acquired'] === 1;
    } catch (\Throwable $e) {
      $message = 'BTCPay advisory locking is unavailable.';
      if ($this->config->get('payment_btcpay_debug_mode')) {
        $message .= ' ' . $e->getMessage();
      }
      $this->log->write($message);
      return null;
    }
  }

  public function releaseOrderLock($order_id) {
    try {
      $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($this->getOrderLockName($order_id)) . "')");
    } catch (\Throwable $e) {
      $message = 'Failed to release BTCPay advisory lock.';
      if ($this->config->get('payment_btcpay_debug_mode')) {
        $message .= ' ' . $e->getMessage();
      }
      $this->log->write($message);
    }
  }

  private function getOrderLockName($order_id) {
    return substr('btcpay_' . hash('sha256', DB_PREFIX . 'order:' . (int)$order_id), 0, 64);
  }

  public function getMethod($address, $total) {
    $this->load->language('extension/payment/btcpay');

    $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_btcpay_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

    if ($this->config->get('payment_btcpay_total') > 0 && $this->config->get('payment_btcpay_total') > $total) {
      $status = false;
    } elseif (!$this->config->get('payment_btcpay_geo_zone_id')) {
      $status = true;
    } elseif ($query->num_rows) {
      $status = true;
    } else {
      $status = false;
    }

    $method_data = [];

    if ($status) {
      $method_data = array(
        'code'		 => 'btcpay',
        'title'		 => $this->language->get('text_title'),
        'terms'		 => '',
        'sort_order' => $this->config->get('payment_btcpay_sort_order')
      );
    }

    return $method_data;
  }
}
