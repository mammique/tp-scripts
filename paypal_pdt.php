<?php

if(!array_key_exists('cm', $_GET)) exit();
$query = query_dict(rawurldecode($_GET['cm']));

if(!array_key_exists('tp_scope', $query)) exit(); // FIXME: redirect with message.
$tp_scope = $query['tp_scope'];

require('paypal_pdt.'.$tp_scope.'.config.php');

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
    }else {
            return false;
    }
    foreach (explode('&', $qry) as $couple) {
            list ($key, $val) = explode('=', $couple);
            $result[$key] = rawurldecode($val);
    }
    return empty($result) ? false : $result;
}

$step = null;

preg_match("/[\w]{8}-[\w]{4}-[\w]{4}-[\w]{4}-[\w]{12}/", $tp_scope, $matches);
if(!$matches) exit();

require_once 'vendor/autoload.php';

$pp_result = array();
$req = 'cmd=_notify-synch';
$tx_token = $_GET['tx'];
$req .= "&tx=$tx_token&at=$pp_auth_token";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$pp_url/cgi-bin/webscr");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
curl_setopt($ch, CURLOPT_CAINFO, __DIR__.'/cacert.pem');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
//curl_setopt($ch, CURLOPT_HTTPHEADER, array("Host: $pp_url"));
//curl_setopt($ch, CURLOPT_HTTPHEADER, array("Host: www.sandbox.paypal.com"));
$res = curl_exec($ch);

$error_el = '<p class="error">'.
            "Something went wrong, our staff is working on it, sorry for the inconvenience.".
            '</p>';

if(!$res) {

    echo $error_el;
    mail($admin_mail,
         '[Traceparent Scripts] Error',
         'curl_error: '.curl_error($ch)."\n\nreq: ".$req."\n\nres: ".$res, 'From: '.$admin_mail."\r\n");    
    curl_close($ch);
    exit();
}

curl_close($ch);
$lines = explode("\n", $res);

if (strcmp($lines[0], "SUCCESS") != 0) {

    echo $error_el;
    mail($admin_mail,
         '[Traceparent Scripts] Error',
         "req: ".$req."\n\nres: ".$res, 'From: '.$admin_mail."\r\n");
    exit();
}

for ($i = 1 ; $i < count($lines) ; $i++){

    if(strlen($lines[$i])) {

        list($key, $val) = explode("=", $lines[$i]);
        $pp_result[urldecode($key)] = urldecode($val);
    }
}

if($pp_result['receiver_email'] != $pp_email) {

    echo '<p class="error">Receiver email address mismatch.</p>';
    exit();
}

global $tp_client;
$tp_client = new Guzzle\Http\Client($tp_url,
                 array('curl.options' => array(
                    CURLOPT_HTTPHEADER => array(
                        "Authorization: Token $tp_auth_token"))));

$pp_id = $pp_result['txn_id'];

$request = $tp_client->get("value/unit/$tp_unit/");
$unit = $request->send()->json();

if($pp_result['mc_currency'] != $unit['slug']) {

    echo '<p class="error">'.
         "Currency mismatch.".
         '</p>';
    exit();
}

$request = $tp_client->get('metadata/snippet/filter/');
$request->getQuery()->set('slug_0', "paypal_pdt_$pp_id");
$request->getQuery()->set('slug_1', 'exact');
$request->getQuery()->set('page_size', '0');
$metadata_prev = $request->send()->json();

if(count($metadata_prev)) {

    echo '<p class="error">'.
         "Transaction already registered.".
         '</p>';
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
    return $request->send()->json();
}

$user = get_or_create_user($pp_result['payer_email'], $pp_result['first_name'].' '.$pp_result['last_name']);

$quantity_dict = array('unit' => $tp_unit,
                       'quantity'        => $pp_result['mc_gross'],
                       'user'            => $user['uuid'],
                       'user_visibility' => 'private',
                       'status'          => 'passed');
$request = $tp_client->post('value/quantity/create/')->addPostFields($quantity_dict);
$quantity = $request->send()->json();

$request = $tp_client->post('metadata/snippet/create/')
               ->addPostFields(array('user'                => $tp_auth_user,
                                     'visibility'          => 'private',
                                     'mimetype'            => 'application/json',
                                     'slug'                => "paypal_pdt_$pp_id",
                                     'type'                => 'paypal_pdt',
                                     'assigned_quantities' => $quantity['uuid'],
                                     'content'             => json_encode($pp_result)));
$metadata = $request->send()->json();

if(!array_key_exists('mc_fee', $pp_result)) $pp_result['mc_fee'] = 0;

$request = $tp_client->post('value/quantity/create/')
               ->addPostFields(array('unit'            => $tp_unit,
                                     'quantity'        => $pp_result['mc_fee'],
                                     'user'            => $pp_tp_uuid,
                                     'user_visibility' => 'public',
                                     'prev'            => $quantity['uuid'],
                                     'status'          => 'present'));
$pp_quantity = $request->send()->json();

$step = 'complete';
require($tp_scope.'.php');

?>
