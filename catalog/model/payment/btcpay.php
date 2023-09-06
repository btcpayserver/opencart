<?php

namespace Opencart\Catalog\Model\Extension\Btcpay\Payment;

class Btcpay extends \Opencart\System\Engine\Model
{

    public function addOrder(array $data): bool
    {
        return $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "btcpay_order` SET `order_id` = '" . (int)$data['order_id'] . "', `token` = '" . $this->db->escape(
                $data['token']
            ) . "', `invoice_id` = '" . $this->db->escape(
                $data['invoice_id']
            ) . "'"
        );
    }

    public function getOrder(int $order_id): array
    {
        $query = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "btcpay_order` WHERE `order_id` = '" . $order_id . "' ORDER BY btcpay_order_id DESC LIMIT 1"
        );

        return $query->row;
    }

    public function getOrderByInvoiceId(string $invoice_id): array
    {
        $query = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "btcpay_order` WHERE `invoice_id` = '" . $invoice_id . "' LIMIT 1"
        );

        return $query->row;
    }

    public function getMethods(array $address = []): array
    {
        $this->load->language('extension/btcpay/payment/btcpay');

        $qStr =   "SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . (int)$this->config->get(
                'payment_btcpay_geo_zone_id'
            ) . "'";

        if (isset($address['country_id'])) {
            $qStr .= " AND `country_id` = '" . (int)$address['country_id'] ."'";
        }
        if (isset($address['zone_id'])) {
            $qStr .= " AND (`zone_id` = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')";
        }

        $query = $this->db->query($qStr);

        if (!$this->config->get('payment_btcpay_geo_zone_id')) {
            $status = true;
        } elseif ($query->num_rows) {
            $status = true;
        } else {
            $status = false;
        }

        $method_data = [];

        if ($status) {
            $option_data['btcpay'] = [
                'code' => 'btcpay.btcpay',
                'name' => $this->language->get('text_title')
            ];

            $method_data = [
                'code'       => 'btcpay',
                'name'       => $this->language->get('text_title'),
                'option'     => $option_data,
                'sort_order' => $this->config->get('payment_btcpay_sort_order')
            ];
        }

        return $method_data;
    }
}
