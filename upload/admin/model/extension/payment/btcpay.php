<?php

class ModelExtensionPaymentBTCPay extends Model {
  public function install() {
    $this->db->query("
      CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "btcpay_order` (
        `btcpay_order_id` INT(11) NOT NULL AUTO_INCREMENT,
        `order_id` INT(11) NOT NULL,
        `invoice_id` VARCHAR(120),
        `token` VARCHAR(100) NOT NULL, 
        PRIMARY KEY (`btcpay_order_id`, `invoice_id`, `token`)
      ) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;
    ");

    $this->load->model('setting/setting');

    $defaults = [];

    $defaults['payment_btcpay_new_status_id'] = 1;
    $defaults['payment_btcpay_paid_status_id'] = 1;
    $defaults['payment_btcpay_settled_status_id'] = 2;
    $defaults['payment_btcpay_settled_paidover_status_id'] = 2;
    $defaults['payment_btcpay_invalid_status_id'] = 10;
    $defaults['payment_btcpay_expired_status_id'] = 14;
    $defaults['payment_btcpay_expired_partialpayment_status_id'] = 10;
    $defaults['payment_btcpay_expired_paidlate_status_id'] = 2;
    $defaults['payment_btcpay_refunded_status_id'] = 11;
    $defaults['payment_btcpay_sort_order'] = 0;

    $this->model_setting_setting->editSetting('payment_btcpay', $defaults);
  }

  public function uninstall() {
    $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "btcpay_order`;");
  }
}
