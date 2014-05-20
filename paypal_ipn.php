<?php

// http://stackoverflow.com/a/834355

function startsWith($haystack, $needle)
{
     $length = strlen($needle);
     return (substr($haystack, 0, $length) === $needle);
}

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }

    return (substr($haystack, -$length) === $needle);
}

/**
 * http://www.paulund.co.uk/parse-url-querystring-into-array-in-php
 * 
 * Parse out url query string into an associative array
 *
 * $qry can be any valid url or just the query string portion.
 * Will return false if no valid querystring found
 *
 * @param $qry String
 * @return Array
 */
function query_dict($qry) {
    $result = array();
    //string must contain at least one = and cannot be in first position
    if(strpos($qry,'=')) {
     if(strpos($qry,'?')!==false) {
       $q = parse_url($qry);
       $qry = $q['query'];
      }
    } else {
            return false;
    }
    foreach (explode('&', $qry) as $couple) {
            list ($key, $val) = explode('=', $couple);
            $result[$key] = rawurldecode($val);
    }
    return empty($result) ? false : $result;
}

/*
 *
 * Paypal IPN.
 * 
 * https://github.com/paypal/ipn-code-samples/blob/master/paypal_ipn.php
 * 
 */

$raw_post_data = file_get_contents('php://input');
$raw_post_array = explode('&', $raw_post_data);
$myPost = array();
foreach ($raw_post_array as $keyval) {
	$keyval = explode ('=', $keyval);
	if (count($keyval) == 2)
		$myPost[$keyval[0]] = urldecode($keyval[1]);
}

if(!array_key_exists('custom', $myPost)) exit();
$query = query_dict(rawurldecode($myPost['custom']));

if(!array_key_exists('tp_scope', $query)) exit();
$tp_scope = $query['tp_scope'];

preg_match("/[\w]{8}-[\w]{4}-[\w]{4}-[\w]{4}-[\w]{12}/", $tp_scope, $matches);
if(!$matches) exit();

define("LOG_FILE", "./log/$tp_scope.log");
error_log(PHP_EOL . PHP_EOL . "========================================" . PHP_EOL, 3, LOG_FILE);

require('paypal_ipn.'.$tp_scope.'.config.php');

$req = 'cmd=_notify-validate';
if(function_exists('get_magic_quotes_gpc')) {
	$get_magic_quotes_exists = true;
}
foreach ($myPost as $key => $value) {
	if($get_magic_quotes_exists == true && get_magic_quotes_gpc() == 1) {
		$value = urlencode(stripslashes($value));
	} else {
		$value = urlencode($value);
	}
	$req .= "&$key=$value";
}

// Post IPN data back to PayPal to validate the IPN data is genuine
// Without this step anyone can fake IPN data

$ch = curl_init("$pp_url/cgi-bin/webscr");
if ($ch == FALSE) {
	return FALSE;
}

curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
curl_setopt($ch, CURLOPT_CAINFO, __DIR__.'/cacert.pem');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);

// if(DEBUG == true) {
	curl_setopt($ch, CURLOPT_HEADER, 1);
	curl_setopt($ch, CURLINFO_HEADER_OUT, 1);
//}

// Set TCP timeout to 30 seconds
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));

$res = curl_exec($ch);
if (curl_errno($ch) != 0) { // cURL error
    $txt = date('[Y-m-d H:i e] '). "Can't connect to PayPal to validate IPN message: " . curl_error($ch);
    error_log($txt . PHP_EOL, 3, LOG_FILE);
    mail($admin_mail,
         "[Traceparent Scripts] Paypal IPN Error (scope $tp_scope)",
         $txt,
         'From: '.$admin_mail."\r\n");
	curl_close($ch);
	exit;

} else {
    $txt_1 = date('[Y-m-d H:i e] '). "HTTP request of validation request:". curl_getinfo($ch, CURLINFO_HEADER_OUT) ." for IPN payload: $req";
    $txt_2 = date('[Y-m-d H:i e] '). "HTTP response of validation request: $res";

    error_log($txt_1 . PHP_EOL, 3, LOG_FILE);
    error_log($txt_2 . PHP_EOL, 3, LOG_FILE);

    mail($admin_mail,
        "[Traceparent Scripts] Paypal IPN Error (scope $tp_scope)",
         $txt_1 . "\r\n\r\n" . $txt_2,
         'From: '.$admin_mail."\r\n");
        // Split response headers and payload

    list($headers, $res) = explode("\r\n\r\n", $res, 2);
    curl_close($ch);
}

// Inspect IPN validation result and act accordingly
if (!endsWith($res, "VERIFIED")) {
	// log for manual investigation
	// Add business logic here which deals with invalid IPN messages
    $txt = date('[Y-m-d H:i e] '). "Invalid IPN: $req";
    error_log($txt . PHP_EOL, 3, LOG_FILE);
    mail($admin_mail,
         "[Traceparent Scripts] Paypal IPN Error (scope $tp_scope)",
         $txt,
         'From: '.$admin_mail."\r\n");

    exit();
}

error_log(date('[Y-m-d H:i e] '). "Verified IPN: $req ". PHP_EOL, 3, LOG_FILE);

/*
 *
 * Traceparent.
 *
 */

if($_POST['receiver_email'] != $pp_email) {

    $txt = date('[Y-m-d H:i e] '). "Receiver email address mismatch: " . $_POST['receiver_email'];
    error_log($txt . PHP_EOL, 3, LOG_FILE);

    exit();
}

$step = null;

require_once 'vendor/autoload.php';
use Guzzle\Http\Exception\ClientErrorResponseException;

global $tp_client;
$tp_client = new Guzzle\Http\Client($tp_url,
                 array('curl.options' => array(
                    CURLOPT_HTTPHEADER => array(
                        "Authorization: Token $tp_auth_token"))));

$pp_id = $_POST['txn_id'];

function guzzle_send($request) {

    global $admin_mail;
    global $tp_scope;

    try {
        $result = $request->send();
    } catch (ClientErrorResponseException $exception) {

        $responseBody = $exception->getResponse()->getBody(true);

        $txt = date('[Y-m-d H:i e] '). "Guzzle Error: " . PHP_EOL . PHP_EOL . "$responseBody";
        error_log(PHP_EOL . $txt . PHP_EOL . PHP_EOL, 3, LOG_FILE);

        mail($admin_mail,
             "[Traceparent Scripts] Paypal IPN Guzzle Error (scope $tp_scope)",
             $txt,
             'From: '.$admin_mail."\r\n");

        exit();
    }

    return $result;
}

$request = $tp_client->get("value/unit/$tp_unit/");
$unit = guzzle_send($request)->json();

if($_POST['mc_currency'] != $unit['slug']) {

    $txt = date('[Y-m-d H:i e] '). "Currency mismatch: " . $_POST['mc_currency'];
    error_log($txt . PHP_EOL, 3, LOG_FILE);

    mail($admin_mail,
         "[Traceparent Scripts] Paypal IPN currency mismatch (scope $tp_scope)",
         $txt,
         'From: '.$admin_mail."\r\n");

    exit();
}

$request = $tp_client->get('metadata/snippet/filter/');
$request->getQuery()->set('slug_0', "paypal_ipn_$pp_id");
$request->getQuery()->set('slug_1', 'exact');
$request->getQuery()->set('page_size', '0');
$metadata_prev = guzzle_send($request)->json();

if(count($metadata_prev)) {

    $txt = date('[Y-m-d H:i e] '). "Transaction already registered: paypal_ipn_$pp_id";

    error_log($txt . PHP_EOL, 3, LOG_FILE);
    mail($admin_mail,
         "[Traceparent Scripts] Paypal IPN transaction already registered (scope $tp_scope)",
         $txt,
         'From: '.$admin_mail."\r\n");

    $step = 'transaction_already_registered';
    require($tp_scope.'.php');

    exit();
}

function get_or_create_user($email, $name='', $details=false) {

    global $tp_client;

    $request = $tp_client->get('auth/user/filter/');
    $request->getQuery()->set('email', $email);
    $request->getQuery()->set('page_size', 1);

    $response = $request->send();
    $data = $response->json();

    if(count($data['results'])) {

        if(!$details) return $data['results'][0];
        else return $tp_client->get('auth/user/'.$data['results'][0]['uuid'].'/')->send()->json();
    }

    $request = $tp_client->post('auth/user/create/')
                   ->addPostFields(array('email'    => $email,
                                         'name'     => $name,
                                         'password' => ''));
    return guzzle_send($request)->json();
}

$user_name = trim($query['name'], " \t\n\r\0\x0B");

if($user_name == '') {

    $user_name = $_POST['first_name'].' '.$_POST['last_name'];
    $query['anon'] = '1';
}

$user = get_or_create_user($_POST['payer_email'], $user_name);

$quantity_dict = array('unit' => $tp_unit,
                       'quantity'        => $_POST['mc_gross'],
                       'user'            => $user['uuid'],
                       'user_visibility' => 'private',
                       'status'          => 'passed');
$request = $tp_client->post('value/quantity/create/')->addPostFields($quantity_dict);
$quantity = guzzle_send($request)->json();

$request = $tp_client->post('metadata/snippet/create/')
               ->addPostFields(array('user'                => $tp_auth_user,
                                     'visibility'          => 'private',
                                     'mimetype'            => 'application/json',
                                     'slug'                => "paypal_ipn_$pp_id",
                                     'type'                => 'paypal_ipn',
                                     'assigned_quantities' => $quantity['uuid'],
                                     'content'             => json_encode($_POST)));
$metadata = guzzle_send($request)->json();

if(!array_key_exists('mc_fee', $_POST)) $_POST['mc_fee'] = 0;

$request = $tp_client->post('value/quantity/create/')
               ->addPostFields(array('unit'            => $tp_unit,
                                     'quantity'        => $_POST['mc_fee'],
                                     'user'            => $pp_tp_uuid,
                                     'user_visibility' => 'public',
                                     'prev'            => $quantity['uuid'],
                                     'status'          => 'present'));
$pp_quantity = guzzle_send($request)->json();

$step = 'complete';

error_log(date('[Y-m-d H:i e] ') . PHP_EOL . PHP_EOL . "paypal_ipn.php: step 1 completed." . PHP_EOL, 3, LOG_FILE);

require($tp_scope.'.php');

?>
