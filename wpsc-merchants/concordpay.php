<?php
/**
 *
 * Payment module
 * For plugin : E-commerce
 * Payment system : ConcordPay
 * Cards : Visa, Mastercard, Privat24, Terminal, etc.
 *
 * Ver 1.0
 */

$nzshpcrt_gateways[$num]['name'] = 'Concord Pay';
$nzshpcrt_gateways[$num]['internalname'] = 'concordpay';
$nzshpcrt_gateways[$num]['function'] = 'gateway_concordpay';
$nzshpcrt_gateways[$num]['form'] = "form_concordpay";
$nzshpcrt_gateways[$num]['submit_function'] = "submit_concordpay";
$nzshpcrt_gateways[$num]['payment_type'] = "credit_card";
$nzshpcrt_gateways[$num]['display_name'] = 'Concord Pay';

/**
 * @param $separator
 * @param $sessionid
 */
function gateway_concordpay($separator, $sessionid)
{
    global $wpdb;
    $purchase_log_sql = "SELECT * FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` WHERE `sessionid`= " . $sessionid . " LIMIT 1";
    $purchase_log = $wpdb->get_results($purchase_log_sql, ARRAY_A);

    $cart_sql = "SELECT * FROM `" . WPSC_TABLE_CART_CONTENTS . "` WHERE `purchaseid`='" . $purchase_log[0]['id'] . "'";
    $cart = $wpdb->get_results($cart_sql, ARRAY_A);

    // User details
    $cData = $_POST['collected_data'];
    $keys = "'" . implode("', '", array_keys($cData)) . "'";

    $que = "SELECT `id`,`type`, `unique_name` FROM `" . WPSC_TABLE_CHECKOUT_FORMS . "` WHERE `id` IN ( " . $keys . " ) AND `active` = '1'";
    $dat_name = $wpdb->get_results($que, ARRAY_A);

    $transactid = $_SERVER["HTTP_HOST"] . '_' . md5("concordpay_" . $purchase_log[0]['id']);

    $forSend = array(
        'order_id' => $purchase_log[0]['id'],
        'currency_iso' => 'UAH',
        'operation' => 'Purchase',
        'add_params' => ['merchantAccount', 'orderReference', 'transactionId', 'transactionStatus', 'reason'],
        'description' => 'Оплата картой VISA или Mastercard на сайте  '.$_SERVER["HTTP_HOST"],

        'language' => get_option('concordpay_language')
    );

    // url по умолчанию
    $defaultUrl = $_SERVER["REQUEST_SCHEME"]."://".$_SERVER["HTTP_HOST"];

    $forSend['approve_url'] = (get_option('concordpay_approveUrl') != "") ? get_option('concordpay_approveUrl')."&sessionid=$sessionid" : $defaultUrl . "/?concordpay_results=true&sessionid=$sessionid";
    $forSend['callback_url'] = (get_option('concordpay_callbackUrl') != "") ? get_option('concordpay_callbackUrl') : $defaultUrl . "/?concordpay_callback=true";
    $forSend['decline_url'] = (get_option('concordpay_declineUrl') != "") ? get_option('concordpay_declineUrl') : $defaultUrl . "/?concordpay_checkout=true";
    $forSend['cancel_url'] = (get_option('concordpay_cancelUrl') != "") ? get_option('concordpay_cancelUrl') : $defaultUrl . "/?concordpay_checkout=true";


    foreach ($cart as $item) {
        $forSend['productName'][] = $item['name'];
        $forSend['productCount'][] = $item['quantity'];
        $forSend['productPrice'][] = $item['price'];
    }
    $forSend['amount'] = $purchase_log['0']['totalprice'];


    foreach ($dat_name as $v) {
        $billData[$v['unique_name']] = $v['id'];
    }

    $array = array(
        "clientFirstName" => 'billingfirstname',
        "clientLastName" => 'billinglastname',
        "clientAddress" => 'billingaddress',
        "clientCity" => 'billingcity',
        "clientPhone" => 'billingphone',
        "clientEmail" => 'billingemail',
        "clientCountry" => 'billingcountry',
        "clientZipCode" => 'billingpostcode',
        "deliveryFirstName" => 'shippingfirstname',
        "deliveryLastName" => 'shippinglastname',
        "deliveryAddress" => 'shippingaddress',
        "deliveryCity" => 'shippingcity',
        "deliveryCountry" => 'shippingcountry',
        "deliveryZipCode" => 'shippingpostcode'
    );


    foreach ($array as $k => $val) {
        $val = $billData[$val];
        if (!empty($_POST['collected_data'][$val])) {
            $val = $_POST['collected_data'][$val];
            if ($k == 'clientPhone') {
                $val = str_replace(array('+', ' ', '(', ')'), array('', '', '', ''), $val);
                if (strlen($val) == 10) {
                    $val = '38' . $val;
                } elseif (strlen($val) == 11) {
                    $val = '3' . $val;
                }
            } else if (($k == 'clientCountry' || $k == 'deliveryCountry') && is_array($val)) {
                $val = 'UKR';
            }
            $forSend[$k] = str_replace("\n", ', ', $val);
        }
    }

    if (($_POST['collected_data'][get_option('email_form_field')] != null) && ($forSend['clientEmail'] == null)) {
        $forSend['clientEmail'] = $_POST['collected_data'][get_option('email_form_field')];
    }


    $img = WPSC_URL . '/images/indicator.gif';

    $button = "<img style='position:absolute; top:50%; left:47%; margin-top:-125px; margin-left:-60px;' src='$img' >
	<script>
		function submitConcordPayForm()
		{
			document.getElementById('form_concordpay').submit();
		}
		setTimeout( submitConcordPayForm, 200 );
	</script>";


    $pay = ConcordPay::getInst()->fillPayForm($forSend);
    echo $button;
    echo $pay;

    $data = array(
        'processed' => 2,
        'transactid' => $transactid,
        'date' => time()
    );

    $where = array('sessionid' => $sessionid);
    $format = array('%d', '%s', '%s');
    $wpdb->update(WPSC_TABLE_PURCHASE_LOGS, $data, $where, $format);

    transaction_results($sessionid, false, $transactid);

    exit();
}

function nzshpcrt_concordpay_callback()
{
    #Callback url : http://yoursite.com/?concordpay_callback=true

    if (!isset($_GET['concordpay_callback']) || ($_GET['concordpay_callback'] != 'true')) return;

    $data = json_decode(file_get_contents("php://input"), true);

    echo ConcordPay::getInst()->checkResponse($data);
    exit();
}

function nzshpcrt_concordpay_results()
{

    if (!isset($_GET['concordpay_results']) || ($_GET['concordpay_results'] != 'true') && !empty($_GET['sessionid'])) return;

     do_action('wpsc_payment_successful');
     $transaction_url_with_sessionid = add_query_arg( 'sessionid', $_GET['sessionid'], get_option( 'transact_url' ) );
     wp_redirect( $transaction_url_with_sessionid );
     exit();


}

function return_concordpay_to_checkout() {
    global $wpdb;

    if (!isset($_GET['concordpay_checkout']) || ($_GET['concordpay_checkout'] != 'true')) return;

    wp_redirect( get_option( 'shopping_cart_url' ) );

    exit(); // follow the redirect with an exit, just to be sure.
}

function submit_concordpay()
{

    $array = array(
        'concordpay_merchant_id',
        'concordpay_SecretKey',
        'concordpay_url',
        'concordpay_approveUrl',
        'concordpay_declineUrl',
        'concordpay_cancelUrl',
        'concordpay_callbackUrl',
        'concordpay_language'
    );

    foreach ($array as $val) {
        if (isset($_POST[$val])) update_option($val, $_POST[$val]);
    }

    return true;
}


function form_concordpay()
{

    $blLang = (get_bloginfo('language', "Display") !== "ru-RU") ? "en-US" : "ru-RU";
    $Cells = getConcordPayCells();

    $otp = "";
    foreach ($Cells as $key => $val) {
        $dat = $val[$blLang];
        $otp .= "<div><label>$dat[name]</label>" .
            ((!$val['isInput']) ? $val['code'] : "<input type='text' size='40' value='" . get_option($key) . "' name='$key' />") .
            "<div class='subtext'>" . (($dat['subText'] == "") ? "&nbsp;" : $dat['subText']) . "</div>
				</div>";
    }


    $output = "<style>
		#concordpayoptions label{ width:150px; font-weight:bold; display: inline-block; }
		#concordpayoptions .subtext{ margin-left:160px; font-size:12px; font-style:italic; margin-bottom:12px;}
		#concordpayoptions{ border:1px dotted #aeaeae; padding:5px; }
		</style>
		<div id='concordpayoptions'>$otp</div>";
    return $output;
}

function getConcordPayCells()
{

    $concordpay_lang[get_option('concordpay_language')] = "selected='selected'";
    return array(
        'concordpay_merchant_id' => array(
            "en-US" => array(
                'name' => 'Merchant Account',
                'subText' => 'Your merchant account at Concord Pay'
            ),
            "ru-RU" => array(
                'name' => 'Идентификатор продавца',
                'subText' => 'Ваш идентификатор мерчанта в Concord Pay'
            ),
            'isInput' => true,
            'code' => ""
        ),
        'concordpay_SecretKey' => array(
            "en-US" => array(
                'name' => 'Secret key',
                'subText' => ''
            ),
            "ru-RU" => array(
                'name' => 'Секретный ключ',
                'subText' => ''
            ),
            'isInput' => true,
            'code' => ""
        ),
        'concordpay_url' => array(
            "en-US" => array(
                'name' => 'System url',
                'subText' => 'Default url - https://pay.concord.ua/api/'
            ),
            "ru-RU" => array(
                'name' => 'Адрес отправки запроса',
                'subText' => 'По умолчанию - https://pay.concord.ua/api/'
            ),
            'isInput' => true,
            'code' => ""
        ),
        'concordpay_approveUrl' => array(
            "en-US" => array(
                'name' => 'Successful payment redirect URL',
                'subText' => 'Default url - ' . $_SERVER["REQUEST_SCHEME"]."://".$_SERVER["HTTP_HOST"]."/?concordpay_results=true"

            ),
            "ru-RU" => array(
                'name' => 'URL переадресации успешного платежа',
                'subText' => 'По умолчанию - ' . $_SERVER["REQUEST_SCHEME"]."://".$_SERVER["HTTP_HOST"]."/?concordpay_results=true"

            ),
            'isInput' => true,
            'code' => ""
        ),
        'concordpay_declineUrl' => array(
            "en-US" => array(
                'name' => 'Redirect URL failed to pay',
                'subText' => 'Default url - ' . $_SERVER["REQUEST_SCHEME"]."://".$_SERVER["HTTP_HOST"]."/?concordpay_checkout=true"

            ),
            "ru-RU" => array(
                'name' => 'URL переадресации не успешного платежа',
                'subText' => 'По умолчанию - ' . $_SERVER["REQUEST_SCHEME"]."://".$_SERVER["HTTP_HOST"]."/?concordpay_checkout=true"
            ),
            'isInput' => true,
            'code' => ""
        ),
        'concordpay_cancelUrl' => array(
            "en-US" => array(
                'name' => 'Redirect URL in case of failure to make payment',
                'subText' => 'Default url - ' . $_SERVER["REQUEST_SCHEME"]."://".$_SERVER["HTTP_HOST"]."/?concordpay_checkout=true"
            ),
            "ru-RU" => array(
                'name' => 'URL переадресации в случае отказа совершить оплату',
                'subText' => 'По умолчанию - ' . $_SERVER["REQUEST_SCHEME"]."://".$_SERVER["HTTP_HOST"]."/?concordpay_checkout=true"
            ),
            'isInput' => true,
            'code' => ""
        ),
        'concordpay_callbackUrl' => array(
            "en-US" => array(
                'name' => 'URL of the result information',
                'subText' => 'The URL to which will receive information about the result of the payment ('. $_SERVER["REQUEST_SCHEME"]."://".$_SERVER["HTTP_HOST"] .'/?concordpay_callback=true)'
            ),
            "ru-RU" => array(
                'name' => 'URL на который придет информация о результате',
                'subText' => 'URL на который придет информация о результате выполнения платежа ('. $_SERVER["REQUEST_SCHEME"]."://".$_SERVER["HTTP_HOST"] .'/?concordpay_callback=true)'
            ),
            'isInput' => true,
            'code' => ""
        ),
    );
}

add_action('init', 'nzshpcrt_concordpay_callback');
add_action('init', 'nzshpcrt_concordpay_results');
add_action('init', 'return_concordpay_to_checkout');

?>

