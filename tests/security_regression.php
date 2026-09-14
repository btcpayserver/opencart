<?php

declare(strict_types=1);

namespace Opencart\System\Engine {
    class Controller
    {
        public $config;
        public $currency;
        public $document;
        public $language;
        public $load;
        public $log;
        public $model_localisation_geo_zone;
        public $model_localisation_order_status;
        public $model_setting_setting;
        public $request;
        public $response;
        public $session;
        public $url;
    }

    class Model
    {
        public $cart;
        public $config;
        public $db;
        public $language;
        public $load;
        public $log;
        public $model_setting_setting;
    }
}

namespace {
    final class TestConfig
    {
        private $values;

        public function __construct(array $values = [])
        {
            $this->values = $values;
        }

        public function get(string $key)
        {
            return $this->values[$key] ?? null;
        }
    }

    final class TestCurrency
    {
        public $configuredValue = 9.0;

        public function getValue(string $currencyCode): float
        {
            return $this->configuredValue;
        }
    }

    final class TestLanguage
    {
        public function get(string $key): string
        {
            return $key;
        }
    }

    final class TestLoad
    {
        public $viewData = [];

        public function language(string $route): void
        {
        }

        public function model(string $route): void
        {
        }

        public function controller(string $route): string
        {
            return '';
        }

        public function view(string $route, array $data): string
        {
            $this->viewData = $data;

            return json_encode($data);
        }
    }

    final class TestCart
    {
        public $total = 0.0;

        public function getTotal(): float
        {
            return $this->total;
        }
    }

    final class TestLog
    {
        public function write(string $message): void
        {
        }
    }

    final class TestResponse
    {
        public $headers = [];
        public $output = '';

        public function addHeader(string $header): void
        {
            $this->headers[] = $header;
        }

        public function setOutput(string $output): void
        {
            $this->output = $output;
        }
    }

    final class TestDocument
    {
        public function setTitle(string $title): void
        {
        }
    }

    final class TestUrl
    {
        public function link(string $route, $arguments = '', bool $secure = false): string
        {
            return $route;
        }
    }

    final class TestListModel
    {
        public function getOrderStatuses(): array
        {
            return [];
        }

        public function getGeoZones(): array
        {
            return [];
        }
    }

    final class TestSettingModel
    {
        public $saved = [];

        public function editSetting(string $code, array $data): void
        {
            $this->saved = $data;
        }
    }

    final class TestDatabase
    {
        public $affected = 1;
        public $lastSql = '';
        public $row = [];

        public function escape(string $value): string
        {
            return str_replace("'", "\\'", $value);
        }

        public function query(string $sql)
        {
            $this->lastSql = $sql;

            return (object)[
                'num_rows' => empty($this->row) ? 0 : 1,
                'row' => $this->row,
                'rows' => empty($this->row) ? [] : [$this->row],
            ];
        }

        public function countAffected(): int
        {
            return $this->affected;
        }
    }

    $temporaryExtensionRoot = sys_get_temp_dir() . '/btcpay-oc4-security-' . bin2hex(random_bytes(6));
    if (!mkdir($temporaryExtensionRoot, 0700) ||
        !symlink(dirname(__DIR__), $temporaryExtensionRoot . '/btcpay')) {
        throw new \RuntimeException('Unable to create the temporary extension path.');
    }

    register_shutdown_function(static function () use ($temporaryExtensionRoot): void {
        $link = $temporaryExtensionRoot . '/btcpay';
        if (is_link($link)) {
            unlink($link);
        }
        if (is_dir($temporaryExtensionRoot)) {
            rmdir($temporaryExtensionRoot);
        }
    });

    define('DIR_EXTENSION', $temporaryExtensionRoot . '/');
    define('DB_PREFIX', 'oc_');

    require_once dirname(__DIR__) . '/catalog/controller/payment/btcpay.php';
    require_once dirname(__DIR__) . '/catalog/model/payment/btcpay.php';
    require_once dirname(__DIR__) . '/admin/controller/payment/btcpay.php';
    require_once dirname(__DIR__) . '/admin/model/payment/btcpay.php';

    final class TestInvoiceHttpClient implements \BTCPayServer\Http\ClientInterface
    {
        public static $payload = [];

        public function request(
            string $method,
            string $url,
            array $headers = [],
            string $body = ''
        ): \BTCPayServer\Http\ResponseInterface {
            self::$payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return new \BTCPayServer\Http\Response(200, json_encode([
                'id' => 'invoice-42',
                'status' => 'New',
            ] + self::$payload, JSON_THROW_ON_ERROR), []);
        }
    }

    final class TestBtcpayController extends \Opencart\Catalog\Controller\Extension\Btcpay\Payment\Btcpay
    {
        public function amount($total, string $currency, $currencyValue = null): string
        {
            return (string)$this->prepareOrderTotal($total, $currency, $currencyValue);
        }

        public function matches(
            \BTCPayServer\Result\Invoice $invoice,
            array $order,
            string $expectedInvoiceId = ''
        ): array {
            $reason = '';
            $matches = $this->invoiceMatchesOrder($invoice, $order, $reason, $expectedInvoiceId);

            return [$matches, $reason];
        }

        public function state(\BTCPayServer\Result\Invoice $invoice): ?array
        {
            return $this->getAuthoritativeInvoiceState($invoice);
        }

        public function paymentMethodCode($paymentMethod): string
        {
            return $this->getPaymentMethodCode($paymentMethod);
        }

        public function protectedStatus(int $statusId): bool
        {
            return $this->isProtectedOrderStatus($statusId);
        }

        public function validSignature(string $signature, string $body): bool
        {
            return $this->validWebhookRequest($signature, $body);
        }

        public function invoiceForOrder(array $order): ?\BTCPayServer\Result\Invoice
        {
            return $this->createInvoice($order, 'return-token');
        }
    }

    final class TestBtcpayAdminController extends \Opencart\Admin\Controller\Extension\Btcpay\Payment\Btcpay
    {
        protected function validate($messages): array
        {
            return $messages;
        }
    }

    $assertionCount = 0;
    $assert = static function (bool $condition, string $message) use (&$assertionCount): void {
        $assertionCount++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };

    $invoice = static function (array $changes = []): \BTCPayServer\Result\Invoice {
        return new \BTCPayServer\Result\Invoice(array_merge([
            'id' => 'invoice-42',
            'amount' => '12.50000000',
            'currency' => 'USD',
            'metadata' => ['orderId' => '42'],
            'status' => 'Settled',
            'additionalStatus' => 'None',
        ], $changes));
    };

    $controller = new TestBtcpayController();
    $controller->config = new TestConfig([
        'config_processing_status' => [2, 5],
        'config_complete_status' => [3],
        'payment_btcpay_webhook' => ['secret' => 'regression-test-secret'],
    ]);
    $controller->currency = new TestCurrency();
    $controller->language = new TestLanguage();
    $controller->session = (object)['data' => []];

    $order = [
        'order_id' => 42,
        'total' => '10.00000000',
        'currency_code' => 'USD',
        'currency_value' => '1.25',
    ];

    $assert($controller->amount('10', 'USD', '1.25') === '12.50000000', 'Saved order exchange rate was not used.');
    $assert($controller->amount('1', 'USD', '1.234567895') === '1.23456790', 'Order amount rounding changed.');
    $assert($controller->matches($invoice(), $order, 'invoice-42')[0], 'A correctly bound invoice was rejected.');
    $assert(!$controller->matches($invoice(['id' => 'different']), $order, 'invoice-42')[0], 'Invoice identity mismatch was accepted.');
    $assert(!$controller->matches($invoice(['amount' => '12.49']), $order, 'invoice-42')[0], 'Invoice amount mismatch was accepted.');
    $assert(!$controller->matches($invoice(['currency' => 'EUR']), $order, 'invoice-42')[0], 'Invoice currency mismatch was accepted.');
    $assert(!$controller->matches($invoice(['metadata' => ['orderId' => '43']]), $order, 'invoice-42')[0], 'Invoice order mismatch was accepted.');

    $settledState = $controller->state($invoice(['additionalStatus' => 'PaidOver']));
    $assert($settledState['setting'] === 'payment_btcpay_settled_paidover_status_id', 'Overpayment state mapping regressed.');
    $expiredState = $controller->state($invoice(['status' => 'Expired', 'additionalStatus' => 'None']));
    $assert($expiredState['payment_detected'] === false, 'Unpaid expiration was treated as payment.');
    $assert($controller->state($invoice(['status' => 'New', 'additionalStatus' => 'None'])) === null, 'A new unpaid invoice caused a transition.');

    $assert($controller->paymentMethodCode(['code' => 'btcpay.btcpay']) === 'btcpay.btcpay', 'Array payment method parsing failed.');
    $assert($controller->paymentMethodCode('{"code":"btcpay.btcpay"}') === 'btcpay.btcpay', 'JSON payment method parsing failed.');
    $assert($controller->protectedStatus(2), 'Processing order status was not protected.');
    $assert(!$controller->protectedStatus(1), 'Pending order status was incorrectly protected.');

    $body = '{"invoiceId":"invoice-42"}';
    $secret = 'regression-test-secret';
    $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);
    $assert($controller->validSignature($signature, $body), 'Controller rejected a valid webhook signature.');
    $assert(!$controller->validSignature($signature, $body . ' '), 'Controller accepted a modified webhook body.');
    $assert(
        \BTCPayServer\Client\Webhook::isIncomingWebhookRequestValid($body, $signature, $secret),
        'A valid webhook signature was rejected.'
    );
    $assert(
        !\BTCPayServer\Client\Webhook::isIncomingWebhookRequestValid($body . ' ', $signature, $secret),
        'A modified webhook body was accepted.'
    );
    $webhookSource = file_get_contents(
        dirname(__DIR__) . '/system/library/btcpay/btcpayserver/btcpayserver-greenfield-php/src/Client/Webhook.php'
    );
    $assert(strpos($webhookSource, 'hash_equals($expectedHeader, $btcpaySigHeader)') !== false, 'Timing-safe HMAC comparison is missing.');

    $composerManifest = json_decode(
        file_get_contents(dirname(__DIR__) . '/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $assert(
        $composerManifest['require']['btcpayserver/btcpayserver-greenfield-php'] === '^2.9.1',
        'The BTCPay Greenfield dependency constraint regressed.'
    );
    $composerLock = json_decode(
        file_get_contents(dirname(__DIR__) . '/composer.lock'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $lockedGreenfieldVersion = null;
    foreach ($composerLock['packages'] as $package) {
        if ($package['name'] === 'btcpayserver/btcpayserver-greenfield-php') {
            $lockedGreenfieldVersion = $package['version'];
            break;
        }
    }
    $assert($lockedGreenfieldVersion === 'v2.9.1', 'Composer does not lock the BTCPay Greenfield client to v2.9.1.');
    $assert(
        \Composer\InstalledVersions::getPrettyVersion('btcpayserver/btcpayserver-greenfield-php') === 'v2.9.1',
        'The committed BTCPay Greenfield vendor directory is out of sync with composer.lock.'
    );

    $database = new TestDatabase();
    $model = new \Opencart\Catalog\Model\Extension\Btcpay\Payment\Btcpay();
    $model->db = $database;
    $model->log = new TestLog();

    $model->getOrderByInvoiceId("invoice' OR '1'='1");
    $assert(strpos($database->lastSql, "invoice\\' OR \\'1\\'=\\'1") !== false, 'Invoice ID was not escaped in SQL.');

    $database->row = ['btcpay_order_id' => 9];
    $model->getOrder(42);
    $assert(strpos($database->lastSql, 'ORDER BY `btcpay_order_id` DESC LIMIT 1') !== false, 'Newest invoice is not treated as active.');

    $database->row = ['lock_acquired' => '1'];
    $assert($model->acquireOrderLock(42) === true, 'Available per-order lock was not acquired.');
    $database->row = ['lock_acquired' => '0'];
    $assert($model->acquireOrderLock(42) === false, 'Busy per-order lock was not reported.');
    $database->row = ['lock_acquired' => null];
    $assert($model->acquireOrderLock(42) === null, 'Unavailable advisory lock did not use compatibility fallback.');

    $database->affected = 1;
    $assert($model->consumeToken(9, 'return-token'), 'A fresh return token was not consumed.');
    $database->affected = 0;
    $assert(!$model->consumeToken(9, 'return-token'), 'A consumed return token was accepted again.');

    $model->config = new TestConfig([
        'payment_btcpay_total' => 50,
        'payment_btcpay_geo_zone_id' => 0,
        'payment_btcpay_sort_order' => 1,
    ]);
    $model->cart = new TestCart();
    $model->language = new TestLanguage();
    $model->load = new TestLoad();
    $database->row = [];
    $model->cart->total = 49.99;
    $assert($model->getMethods([]) === [], 'Minimum checkout total was not enforced.');
    $model->cart->total = 50.00;
    $assert($model->getMethods([])['code'] === 'btcpay', 'Payment method was hidden at the configured minimum total.');

    $adminTemplate = file_get_contents(dirname(__DIR__) . '/admin/view/template/payment/btcpay.twig');
    $assert(strpos($adminTemplate, 'payment_btcpay_webhook.secret') === false, 'Webhook secret is still rendered.');
    $assert(strpos($adminTemplate, 'name="payment_btcpay_webhook"') === false, 'Webhook data can still be submitted by the browser.');
    $assert(strpos($adminTemplate, 'name="payment_btcpay_api_auth_token" value=""') !== false, 'Saved API key is still rendered.');

    $savedWebhook = [
        'id' => 'webhook-id',
        'secret' => 'saved-webhook-secret',
        'url' => 'https://shop.example/webhook',
    ];
    $adminController = new TestBtcpayAdminController();
    $adminController->config = new TestConfig([
        'payment_btcpay_api_auth_token' => 'saved-api-key',
        'payment_btcpay_webhook' => $savedWebhook,
        'payment_btcpay_url' => 'https://btcpay.example',
    ]);
    $adminController->document = new TestDocument();
    $adminController->language = new TestLanguage();
    $adminController->load = new TestLoad();
    $adminController->model_localisation_geo_zone = new TestListModel();
    $adminController->model_localisation_order_status = new TestListModel();
    $adminController->model_setting_setting = new TestSettingModel();
    $adminController->request = (object)['post' => [
        'payment_btcpay_url' => 'https://btcpay.example',
        'payment_btcpay_api_auth_token' => '',
        'payment_btcpay_btcpay_storeid' => 'store-id',
    ]];
    $adminController->response = new TestResponse();
    $adminController->session = (object)['data' => ['user_token' => 'admin-session-token']];
    $adminController->url = new TestUrl();

    $adminController->index();
    $assert(
        $adminController->load->viewData['payment_btcpay_api_auth_token'] === '',
        'Saved API key was passed to the admin view.'
    );
    $assert(
        !array_key_exists('secret', $adminController->load->viewData['payment_btcpay_webhook']),
        'Webhook secret was passed to the admin view.'
    );
    $assert(strpos($adminController->response->output, 'saved-api-key') === false, 'API key leaked while rendering settings.');
    $assert(strpos($adminController->response->output, 'saved-webhook-secret') === false, 'Webhook secret leaked while rendering settings.');
    $assert(
        array_key_exists('payment_btcpay_send_customer_email', $adminController->load->viewData) &&
        !$adminController->load->viewData['payment_btcpay_send_customer_email'],
        'Email sharing must default to disabled for existing installations.'
    );

    $adminController->save();
    $assert(
        $adminController->model_setting_setting->saved['payment_btcpay_api_auth_token'] === 'saved-api-key',
        'Blank API key did not retain the saved credential.'
    );
    $assert(
        $adminController->model_setting_setting->saved['payment_btcpay_webhook'] === $savedWebhook,
        'Saving unrelated settings lost the webhook secret.'
    );
    $assert(strpos($adminController->response->output, 'saved-api-key') === false, 'API key leaked in the save response.');
    $assert(strpos($adminController->response->output, 'saved-webhook-secret') === false, 'Webhook secret leaked in the save response.');

    foreach (['1', '0'] as $sendCustomerEmail) {
        $adminController->request->post['payment_btcpay_send_customer_email'] = $sendCustomerEmail;
        $adminController->save();
        $savedSettings = $adminController->model_setting_setting->saved;
        $assert(
            $savedSettings['payment_btcpay_send_customer_email'] === $sendCustomerEmail,
            'Saving the email-sharing setting did not retain the selected value.'
        );
        $adminController->config = new TestConfig($savedSettings);
        $adminController->request->post = [];
        $adminController->index();
        $assert(
            $adminController->load->viewData['payment_btcpay_send_customer_email'] === $sendCustomerEmail,
            'Reloading payment settings lost the saved email-sharing preference.'
        );
        $adminController->request->post = $savedSettings;
    }

    $adminInstallModel = new \Opencart\Admin\Model\Extension\Btcpay\Payment\Btcpay();
    $adminInstallModel->db = new TestDatabase();
    $adminInstallModel->load = new TestLoad();
    $adminInstallModel->model_setting_setting = new TestSettingModel();
    $adminInstallModel->install();
    $assert(
        $adminInstallModel->model_setting_setting->saved['payment_btcpay_send_customer_email'] === 0,
        'New installations must default to disabled email sharing.'
    );

    // Replace only the HTTP transport so the real SDK still builds the invoice JSON.
    if (!class_alias(TestInvoiceHttpClient::class, 'BTCPayServer\\Http\\CurlClient')) {
        throw new \RuntimeException('Unable to replace the invoice HTTP transport.');
    }
    $controller->url = new TestUrl();
    $controller->log = new TestLog();

    foreach ([
        [null, 'buyer@example.test', null],
        [false, 'buyer@example.test', null],
        [0, 'buyer@example.test', null],
        ['0', 'buyer@example.test', null],
        [1, 'buyer@example.test', 'buyer@example.test'],
        ['1', '  buyer@example.test  ', 'buyer@example.test'],
        ['1', null, null],
        ['1', '', null],
        ['1', '   ', null],
    ] as [$sendCustomerEmail, $email, $expectedEmail]) {
        $settings = [
            'payment_btcpay_api_auth_token' => 'test-api-key',
            'payment_btcpay_url' => 'https://btcpay.example',
            'payment_btcpay_btcpay_storeid' => 'store-id',
        ];
        if ($sendCustomerEmail !== null) {
            $settings['payment_btcpay_send_customer_email'] = $sendCustomerEmail;
        }
        $controller->config = new TestConfig($settings);
        $invoiceOrder = $order;
        if ($email !== null) {
            $invoiceOrder['email'] = $email;
        }
        TestInvoiceHttpClient::$payload = [];
        $assert($controller->invoiceForOrder($invoiceOrder) !== null, 'Invoice creation failed.');
        $payload = TestInvoiceHttpClient::$payload;
        $expectedMetadata = ['orderId' => (string)$order['order_id']];
        if ($expectedEmail !== null) {
            $expectedMetadata['buyerEmail'] = $expectedEmail;
        }
        $assert($payload['metadata'] == $expectedMetadata, 'Invoice metadata violated the email-sharing preference.');
        $assert($payload['amount'] === '12.50000000', 'Adding buyer email changed the invoice amount.');
        $assert($payload['currency'] === 'USD', 'Adding buyer email changed the invoice currency.');
    }

    $manifest = json_decode(file_get_contents(dirname(__DIR__) . '/install.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert($manifest['version'] === BTCPAY_OPENCART_EXTENSION_VERSION, 'Manifest and runtime versions differ.');

    $installModel = file_get_contents(dirname(__DIR__) . '/admin/model/payment/btcpay.php');
    $assert(strpos($installModel, 'ENGINE=MyISAM') !== false, 'Compatibility release changed the database engine.');
    $assert(stripos($installModel, 'ALTER TABLE') === false, 'Compatibility release added a schema migration.');

    echo 'Security regression checks passed (' . $assertionCount . " assertions).\n";
}
