<?php return array(
    'root' => array(
        'name' => 'btcpayserver/opencart3',
        'pretty_version' => '1.0.0+no-version-set',
        'version' => '1.0.0.0',
        'reference' => NULL,
        'type' => 'opencart-extension',
        'install_path' => __DIR__ . '/../../../btcpay/',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'btcpayserver/btcpayserver-greenfield-php' => array(
            'pretty_version' => 'v1.3.3',
            'version' => '1.3.3.0',
            'reference' => 'aff6ab92151431c2faa63c72805aa60736b0deea',
            'type' => 'library',
            'install_path' => __DIR__ . '/../btcpayserver/btcpayserver-greenfield-php',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'btcpayserver/opencart3' => array(
            'pretty_version' => '1.0.0+no-version-set',
            'version' => '1.0.0.0',
            'reference' => NULL,
            'type' => 'opencart-extension',
            'install_path' => __DIR__ . '/../../../btcpay/',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
