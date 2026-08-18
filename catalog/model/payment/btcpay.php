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
            "SELECT * FROM `" . DB_PREFIX . "btcpay_order` WHERE `order_id` = '" . (int)$order_id . "' ORDER BY `btcpay_order_id` DESC LIMIT 1"
        );

        return $query->row;
    }

    public function getOrderByInvoiceId(string $invoice_id): array
    {
        $query = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "btcpay_order` WHERE `invoice_id` = '" . $this->db->escape(
                $invoice_id
            ) . "' LIMIT 1"
        );

        return $query->row;
    }

    public function consumeToken(int $btcpay_order_id, string $token): bool
    {
        $this->db->query(
            "UPDATE `" . DB_PREFIX . "btcpay_order` SET `token` = '' WHERE `btcpay_order_id` = '" . (int)$btcpay_order_id . "' AND `token` = '" . $this->db->escape(
                $token
            ) . "'"
        );

        return $this->db->countAffected() === 1;
    }

    public function hasOrderHistoryComment(int $order_id, string $comment): bool
    {
        $query = $this->db->query(
            "SELECT `order_history_id` FROM `" . DB_PREFIX . "order_history` WHERE `order_id` = '" . (int)$order_id . "' AND `comment` = '" . $this->db->escape(
                $comment
            ) . "' LIMIT 1"
        );

        return $query->num_rows > 0;
    }

    /**
     * Acquire a connection-scoped MySQL/MariaDB advisory lock.
     *
     * A null result means the database does not support advisory locks. The
     * caller may continue without one to retain compatibility with unusual
     * OpenCart database adapters.
     */
    public function acquireOrderLock(int $order_id, int $timeout = 5): ?bool
    {
        try {
            $query = $this->db->query(
                "SELECT GET_LOCK('" . $this->db->escape($this->getOrderLockName($order_id)) . "', '" . max(
                    0,
                    $timeout
                ) . "') AS `lock_acquired`"
            );

            if (!isset($query->row['lock_acquired'])) {
                return null;
            }

            return (int)$query->row['lock_acquired'] === 1;
        } catch (\Throwable $e) {
            $this->log->write('BTCPay advisory locking is unavailable: ' . $e->getMessage());
            return null;
        }
    }

    public function releaseOrderLock(int $order_id): void
    {
        try {
            $this->db->query(
                "SELECT RELEASE_LOCK('" . $this->db->escape($this->getOrderLockName($order_id)) . "')"
            );
        } catch (\Throwable $e) {
            $this->log->write('Failed to release BTCPay advisory lock: ' . $e->getMessage());
        }
    }

    private function getOrderLockName(int $order_id): string
    {
        return substr('btcpay_' . hash('sha256', DB_PREFIX . 'order:' . (int)$order_id), 0, 64);
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

        $minimumTotal = (float)$this->config->get('payment_btcpay_total');

        if ($minimumTotal > 0 && $this->cart->getTotal() < $minimumTotal) {
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
