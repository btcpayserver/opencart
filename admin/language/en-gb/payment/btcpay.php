<?php

require_once DIR_EXTENSION . '/btcpay/system/library/btcpay/version.php';

$_['heading_title'] = 'BTCPay Server';
$_['text_edit'] = 'Edit BTCPay Settings';
$_['text_version_info'] = 'Debug info: OpenCart ' . VERSION . ' with BTCPay Extension ' . BTCPAY_OPENCART_EXTENSION_VERSION . ' on PHP ' . phpversion();
$_['text_support_info'] = 'For setup instructions follow our <a href="https://docs.btcpayserver.org/OpenCart" target="_blank" rel="noopener">setup guide</a>. If you run into any problems feel free to <a href="https://github.com/btcpayserver/opencart" target="_blank" rel="noopener">open an issue on Github</a> or come to our <a href="https://chat.btcpayserver.org/" target="_blank" rel="noopener">Mattermost chat</a>.';
$_['text_extension'] = 'Extensions';

$_['entry_status'] = 'Payment Method Enabled';
$_['entry_btcpay_url'] = 'BTCPay Server URL';
$_['entry_api_auth_token'] = 'BTCPay API Key';
$_['entry_btcpay_storeid'] = 'BTCPay Store ID';
$_['entry_webhook'] = 'Webhook Data';
$_['entry_webhook_secret'] = 'Webhook Secret';
$_['entry_webhook_delete'] = 'Delete Webhook';
$_['entry_total'] = 'Total';
$_['entry_geo_zone'] = 'Geo Zone';
$_['entry_sort_order'] = 'Sort Order';
$_['entry_new_status'] = 'New Status';
$_['entry_paid_status'] = 'Paid (unconfirmed) Status';
$_['entry_settled_status'] = 'Settled Status';
$_['entry_settled_paidover_status'] = 'Settled (paid over)';
$_['entry_invalid_status'] = 'Invalid Status';
$_['entry_expired_status'] = 'Expired Status';
$_['entry_expired_partialpayment_status'] = 'Expired (partial payment) Status';
$_['entry_expired_paidlate_status'] = 'Expired (paid late) Status';
$_['entry_refunded_status'] = 'Refunded Status (not supported yet)';
$_['entry_sort_order'] = 'Sort Order';
$_['entry_debug_mode'] = 'Debug mode';

$_['help_btcpay_url'] = 'The public URL of your BTCPay Server instance. e.g. https://demo.mainnet.btcpayserver.org. You need to have a BTCPay Server instance running, see "Requirements" for several options of deployment on our <a href="https://docs.btcpayserver.org/OpenCart" target="_blank" rel="noopener">setup guide</a>.';
$_['help_webhook'] = 'The webhook will get created automatically after you entered BTCPay Server URL, API Key and Store ID. If you see this field filled with data (after you saved the form) all went well.';
$_['help_webhook_delete'] = 'This is useful if you switch hosts or have problems with webhooks. When checked this will delete the webhook on OpenCart (and BTCPay Server if possible). Make sure to delete the webhook on BTCPay Server Store settings too if not done automatically. <strong>ATTENTION:</strong> You need to edit and <strong>save</strong> this settings page again so a new webhook gets created on BTCPay Server.';
$_['help_total'] = 'The checkout total the order must reach before this payment method becomes active.';
$_['help_debug_mode'] = 'If enabled debug output will be saved to the error logs found in System -> Maintenance -> Error logs. Should be disabled after debugging.';

$_['notice_success'] = 'BTCPay Server Payment details have been updated.';
$_['notice_success_delete_webhook'] = 'Successfully deleted webhook. Please save again to create a new one, make sure that on BTCPay Server it does not exist twice.';
$_['notice_success_create_webhook'] = 'Successfully created a webhook on the BTCPay instance.';

$_['error_permission'] = 'Warning: You do not have permission to modify BTCPay Server!';
$_['error_composer'] = 'Unable to load btcpayserver-greenfield-php. Please download a compiled vendor folder or run composer.';
$_['error_store_not_found'] = 'Successfully connected to BTCPay Server but no store with that ID found. Make sure you entered the correct store ID on that corresponding BTCPay Server URL.';
$_['error_connect_to_btcpay'] = 'Error connecting to BTCPay Server instance. Make sure you provided the correct URL, API key.';
$_['error_creating_webhook'] = 'Error creating webhook. Make sure you have a correct store and api key combination with the required permissions. Check OpenCart error logs.';


$_['text_btcpay'] = '<a href="https://btcpayserver.org/" target="_blank" rel="noopener"><img src="/extension/btcpay/admin/view/image/payment/btcpay.png" alt="BTCPay Server" title="BTCPay Server" style="border: 1px solid #EEEEEE;" /></a>';
