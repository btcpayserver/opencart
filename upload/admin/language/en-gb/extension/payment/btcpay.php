<?php

require_once DIR_SYSTEM . 'library/btcpay/version.php';

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
$_['entry_webhook_delete'] = 'Delete Webhook';
$_['entry_modal_mode'] = 'Modal/iFrame mode';
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

$_['help_btcpay_url'] = 'The public URL of your BTCPay Server instance, for example https://demo.mainnet.btcpayserver.org. See the "Requirements" section of our <a href="https://docs.btcpayserver.org/OpenCart" target="_blank" rel="noopener">setup guide</a>.';
$_['help_api_auth_token'] = 'For security, a saved API key is never displayed. Use a store-specific key with only the permissions required to view this store, view/create invoices, and manage its webhooks.';
$_['help_api_auth_token_saved'] = 'An API key is stored. Leave this field blank to keep it, or enter a replacement. The saved value is never displayed.';
$_['help_webhook'] = 'The webhook is created automatically after you enter the BTCPay Server URL, API Key and Store ID. Its secret is retained server-side and is never displayed on this page.';
$_['help_webhook_delete'] = 'When checked, this deletes the webhook from OpenCart and from BTCPay Server if possible. Save the settings again to create a replacement webhook.';
$_['help_modal_mode'] = 'If enabled, the invoice is shown in a modal/overlay. This loads JavaScript from the configured BTCPay host into the checkout page, so use modal mode only with a trusted, preferably self-hosted instance. Redirect mode provides stronger isolation for third-party hosts.';
$_['help_total'] = 'The checkout total the order must reach before this payment method becomes active.';
$_['help_debug_mode'] = 'If enabled, detailed webhook and invoice data is saved to System -> Maintenance -> Error logs. It may contain customer or payment data. Disable debug mode and remove the logs after troubleshooting.';
$_['warning_insecure_http'] = 'The configured BTCPay URL or OpenCart webhook callback uses unencrypted HTTP. API keys, invoice data, or webhook traffic may be exposed in transit. Use HTTP only on a network whose transport security you control.';

$_['notice_success'] = 'BTCPay Server Payment details have been successfully updated.';
$_['notice_success_webhook_renew'] = 'BTCPay Server Payment details have been successfully updated and successfully deleted webhook data.';

$_['error_permission'] = 'Warning: You do not have permission to modify BTCPay Server!';
$_['error_composer'] = 'Unable to load btcpayserver-greenfield-php. Please download a compiled vendor folder or run composer.';
$_['error_store_not_found'] = 'Successfully connected to BTCPay Server but no store with that ID found. Make sure you entered the correct store ID on that corresponding BTCPay Server URL.';
$_['error_connect_to_btcpay'] = 'Error connecting to BTCPay Server instance. Make sure you provided the correct URL, API key.';
$_['error_url'] = 'The BTCPay Server URL must be a valid HTTP or HTTPS URL without embedded credentials.';
$_['error_configuration'] = 'BTCPay Server URL, API key, and Store ID are required.';
$_['error_webhook_setup'] = 'Connected to BTCPay Server, but could not create a secure webhook. The settings were not saved.';

$_['text_btcpay'] = '<a href="https://btcpayserver.org/" target="_blank" rel="noopener"><img src="view/image/payment/btcpay.png" alt="BTCPay Server" title="BTCPay Server" style="border: 1px solid #EEEEEE;" /></a>';
