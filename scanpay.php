<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
use Illuminate\Database\Capsule\Manager as Capsule;

function scanpay_MetaData()
{
    return [
        'DisplayName' => 'Scanpay',
        'APIVersion' => '1.1',
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function scanpay_config($params)
{
    /* create paymentdatadb if not created */
    require_once(__DIR__ . '/scanpay/paymentdatadb.php');
    $paymentdatadb = new \WHMCS\Module\Gateway\Scanpay\PaymentDataDB();

    /* extract last ping time */
    require_once(__DIR__ . '/scanpay/shopseqdb.php');
    $shopSeqDB = new \WHMCS\Module\Gateway\Scanpay\ShopSeqDB();
    $shopid = '';
    if (isset($params['apikey'])) {
        $shopid = explode(':', $params['apikey'])[0];
    }
    if (ctype_digit($shopid)) {
        $localSeqObj = $shopSeqDB->load((int)$shopid);
        if (!$localSeqObj) { $localSeqObj = [ 'mtime' => 0 ]; }
    } else {
        $localSeqObj = [ 'mtime' => 0 ];
    }
    ob_start();
    $pingURL = $params['systemurl'] . 'modules/gateways/callback/scanpay.php';
    $lastPingTime = $localSeqObj['mtime'];
    require(__DIR__ . '/scanpay/pingurl.phtml');
    $pingUrlContent = ob_get_contents();
    ob_end_clean();
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Scanpay',
        ],
        'version' => [
            'FriendlyName' => 'Module version',
            'Type' => null,
            'Description' => '0.1.0',
            'Size' => '20',
            'disabled' => true,
        ],
        'apikey' => [
            'FriendlyName' => 'API-key',
            'Type' => 'text',
            'Size' => '128',
        ],
        'pingurl' => [
            'FriendlyName' => 'Ping URL',
            'Description' => $pingUrlContent,
            'Size' => '1024',
        ],
        'linktext' => [
            'FriendlyName' => 'Payment Link Text',
            'Type' => 'text',
            'Size' => '64',
            'Value' => 'Pay Invoice',
        ],
        'language' => [
            'FriendlyName' => 'Payment Window Language',
            'Type' => 'dropdown',
            'Options' => [
                '' => 'Automatic (browser language',
                'en' => 'English',
                'da' => 'Danish',
                'se' => 'Swedish',
                'no' => 'Norwegian',
            ],
        ],
        'autocapture' => [
            'FriendlyName' => 'Auto-capture',
            'Type' => 'dropdown',
            'Options' => '0,1',
        ],
    ];
}

function scanpay_link($p)
{
    $cl = $p['clientdetails'];
    $data = [
        'orderid'     => $p['invoiceid'],
        'language'    => $p['language'],
        'autocapture' => filter_var($p['autocapture'], FILTER_VALIDATE_BOOLEAN),
        'successurl'  => $p['returnurl'],
        'items'       => [
            [
                'name'     => $p['description'],
                'quantity' => 1,
                'total'    => $p['amount'] . ' ' . $p['currency'],
            ],
        ],
        'billing'     => array_filter([
            'name'    => $cl['firstname'] . ' ' . $CL['lastname'],
            'email'   => $cl['email'],
            'phone'   => preg_replace('/\s+/', '', $cl['phone']),
            'address' => array_filter([$cl['address1'], $cl['address2'],]),
            'city'    => $cl['city'],
            'zip'     => $cl['postcode'],
            'country' => $cl['country'],
            'state'   => $cl['state'],
            'company' => $p['companyname'],
            'vatin'   => '',
            'gln'     => '',
        ]),
        'shipping'    => [],
    ];
    $encdata = json_encode($data);
    if (!$encdata) {
        throw new \Exception('Failed to JSON-encode data');
    }

    require_once(__DIR__ . '/scanpay/paymentdatadb.php');
    $paymentdatadb = new \WHMCS\Module\Gateway\Scanpay\PaymentDataDB();
    $pd = $paymentdatadb->load($p['invoiceid']);
    if ($pd === FALSE) {
        throw new \Exception('Failed to load payment data');
    } else if ($pd === NULL) {
        $key = bin2hex(random_bytes(32));
        if (!$paymentdatadb->insert($p['invoiceid'], $key, $p['apikey'], $encdata)) {
            throw new \Exception('Failed to save payment data');
        }
        $pd = [ 'querykey' => $key ];
    }

    $url = $params['systemurl'] . 'modules/gateways/scanpay/pay.php';
    $url .= '?invoiceid=' . $p['invoiceid'];
    $url .= '&key=' . $pd['querykey'];
    return '<a href="' . $url . '">'. $p['linktext'] . '</a>';
}
