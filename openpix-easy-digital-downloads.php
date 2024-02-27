<?php

/*
Plugin Name: OpenPix Easy Digital Downloads
Plugin URI: https://openpix.com
Description: Accept payments using Pix
Version: 1.0.0
Author: criskell
Author URI: https://github.com/criskell
*/

if (!defined('ABSPATH')) exit;

class EDD_OpenPix
{
    const OPENPIX_PUBLIC_KEY_BASE64 = 'LS0tLS1CRUdJTiBQVUJMSUMgS0VZLS0tLS0KTUlHZk1BMEdDU3FHU0liM0RRRUJBUVVBQTRHTkFEQ0JpUUtCZ1FDLytOdElranpldnZxRCtJM01NdjNiTFhEdApwdnhCalk0QnNSclNkY2EzcnRBd01jUllZdnhTbmQ3amFnVkxwY3RNaU94UU84aWVVQ0tMU1dIcHNNQWpPL3paCldNS2Jxb0c4TU5waS91M2ZwNnp6MG1jSENPU3FZc1BVVUcxOWJ1VzhiaXM1WloySVpnQk9iV1NwVHZKMGNuajYKSEtCQUE4MkpsbitsR3dTMU13SURBUUFCCi0tLS0tRU5EIFBVQkxJQyBLRVktLS0tLQo=';
    
    private static $instance;

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new EDD_OpenPix();
        }

        return self::$instance;
    }

    public function load()
    {
        add_filter('edd_payment_gateways', [$this, 'registerGateway']);
        add_action('edd_openpix_gateway_cc_form', '__return_false');
        
        add_filter('edd_settings_gateways', [$this, 'getSettings'], 1);
        add_filter('edd_settings_sections_gateways', [$this, 'getSettingsSection']);

        add_action('edd_gateway_openpix_gateway', [$this, 'processPayment']);
        add_action('edd_payment_receipt_before_table', [$this, 'showPaymentInstructions']);

        add_action('rest_api_init', [$this, 'registerWebhook']);
    }

    public function getSettings($settings)
    {
        $settings['openpix_gateway'] = [
            [
                'id' => 'openpix_settings',
                'name' => '<strong>Configurações da OpenPix</strong>',
                'desc' => 'Configurar o meio de pagamento',
                'type' => 'header'
            ],
            [
                'id' => 'openpix_app_id',
                'name' => 'App ID',
                'desc' => 'Insira seu App ID encontrado na plataforma OpenPix. URL de webhook: ' . $this->getWebhookUrl(),
                'type' => 'text',
                'size' => 'regular'
            ],
        ];

        return $settings;
    }

    public function getSettingsSection($sections)
    {
        $sections['openpix_gateway'] = 'OpenPix';
        return $sections;
    }

    public function registerGateway($gateways)
    {
        $gateways['openpix_gateway'] = ['admin_label' => 'OpenPix', 'checkout_label' => 'OpenPix'];
        return $gateways;
    }

    public function registerWebhook()
    {
        register_rest_route(
            'edd-openpix-gateway-webhook/v1',
            '/callback',
            [
                'methods'  => 'POST,PUT',
                'callback' => [$this, 'handleWebhook'],
            ]
        );
    }

    public function handleWebhook()
    {
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        $this->validateWebhook($rawBody, $data);

        $event = $data['event'];

        if ($event === 'OPENPIX:CHARGE_COMPLETED') {
            return $this->handleChargeCompletedWebhook($data);
        }
    }

    public function handleChargeCompletedWebhook($data)
    {
        $correlationID = $data['charge']['correlationID'];

        $payment = edd_get_purchase_id_by_key($correlationID);

        if (! $payment) {
            header('HTTP/1.2 400 Bad Request');
            echo json_encode([
                'error' => 'Payment not found.',
            ]);
            exit;
        }

        edd_update_payment_status($payment, 'complete');

        header('HTTP/1.2 200 OK');
        echo json_encode([
            'message' => 'Payment was updated successfully.',
            'paymentID' => $payment,
        ]);
    }

    public function validateWebhook($rawBody, $data)
    {
        $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null;

        if (!$signature || !$this->validateSignature($rawBody, $signature)) {
            header('HTTP/1.2 400 Bad Request');
            echo json_encode([
                'error' => 'Invalid Webhook signature',
            ]);
            exit;
        }
    }

    function validateSignature($payload, $signature)
    {
        $publicKey = base64_decode(self::OPENPIX_PUBLIC_KEY_BASE64);

        $verify = openssl_verify(
            $payload,
            base64_decode($signature),
            $publicKey,
            'sha256WithRSAEncryption'
        );

        return $verify === 1 ? true : false;
    }

    public function getWebhookUrl()
    {
        return rest_url('/edd-openpix-gateway-webhook/v1/callback');
    }

    public function processPayment($purchaseData)
    {
        global $edd_options;

        $errors = edd_get_errors();

        if ($errors) {
            edd_send_back_to_checkout('?payment-mode=' . $purchaseData['post_data']['edd-gateway']);
            return;
        }

        $payment = [
            'price' => $purchaseData['price'],
            'date' => $purchaseData['date'],
            'user_email' => $purchaseData['user_email'],
            'purchase_key' => $purchaseData['purchase_key'],
            'currency' => $edd_options['currency'],
            'downloads' => $purchaseData['downloads'],
            'cart_details' => $purchaseData['cart_details'],
            'user_info' => $purchaseData['user_info'],
            'status' => 'pending',
            'gateway' => 'openpix',
        ];

        $payment = edd_insert_payment($payment);

        if (edds_is_zero_decimal_currency() ) {
			$amount = $purchaseData['price'];
		} else {
			$amount = round($purchaseData['price'] * 100, 0);
		}

        $appID = $edd_options['openpix_app_id'];
        $url = 'https://api.openpix.com.br/api/v1/charge?return_existing=true';
        $correlationID = $purchaseData['purchase_key'];
        $payload = [
            'value' => $amount,
            'correlationID' => $correlationID,
        ];

        $response = wp_safe_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $appID,
                'version' => '1.0.0',
                'platform' => 'EASYDIGITALDOWNLOADS',
            ],
            'body' => json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            exit;
        }

        edd_send_to_success_page();
    }

    public function showPaymentInstructions($payment)
    {
        global $edd_options;

        if ($payment->gateway !== "openpix" || $payment->status !== "pending") {
            return;
        }

        $appID = $edd_options['openpix_app_id'];
        $correlationID = $payment->key;

        $script = "https://plugin.openpix.com.br/v1/openpix.js?appID=" . $appID . "&correlationID=" . $correlationID . "&node=openpix-order";

        ?>

        <div id="openpix-order"></div>
        <script src="<?=$script?>"></script>

        <script>
            window.$openpix.addEventListener(function (e) {
                if (e.type === 'PAYMENT_STATUS') {
                    if (e.data.status === 'COMPLETED') {
                        location.reload();
                    }
                }
            });
        </script>

        <?php
    }
}

add_action('plugins_loaded', function () {
    EDD_OpenPix::instance()->load();
});
