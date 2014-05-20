<?php

$tp_meta_donation = '48496fd2-e02a-11e3-a70d-00163e84330e';
$tp_meta_item     = $tp_meta_donation;

if($step == 'complete') {

    $request = $tp_client->post("monitor/scope/$tp_scope/update/quantities/add/")
                   ->addPostFields(array('uuid' => $quantity['uuid']))->send();

    $price        = (float)$_POST['mc_gross'];
    $pp_share     = (float)$_POST['mc_fee'];
    $metatv_share = $price - $pp_share;

    if($tp_meta_item)
        $tp_client->post("metadata/snippet/$tp_meta_item/update/assigned_quantities/add/")
            ->addPostFields(array('uuid' => $quantity['uuid']))->send();

    $request = $tp_client->post('value/quantity/create/')
                   ->addPostFields(array('unit'            => $tp_unit,
                                         'quantity'        => $metatv_share,
                                         'user'            => $tp_auth_user,
                                         'user_visibility' => 'public',
                                         'prev'            => $quantity['uuid'],
                                         'status'          => 'present'));
    $tp_metatv_share = guzzle_send($request)->json();

    if($query['anon'] != '1') {

        $quantity_dict['user_visibility'] = 'public';
        $request = $tp_client->put($quantity['url'].'update/')
            ->setBody(json_encode($quantity_dict), 'application/json');
        guzzle_send($request);
    }

    $display = array('message' => trim($query['mess'], " \t\n\r\0\x0B"));
    if($query['anon'] != 1) $display['name'] = $user_name;

    if($query['mess'] != '' || $query['anon'] != 1) {

        $request = $tp_client->post('metadata/snippet/create/')
                       ->addPostFields(array('user'                => $tp_auth_user,
                                             'visibility'          => 'public',
                                             'mimetype'            => 'application/json',
                                             'slug'                => "donation_display",
                                             'type'                => 'quantity_display',
                                             'assigned_quantities' => $quantity['uuid'],
                                             'assigned_counters'   => $tp_counter,
                                             'content'             => json_encode($display)));
        $metadata = guzzle_send($request)->json();

    }

    $body_details = '';

    if($query['goody'] == '1') {

        $request = $tp_client->get("metadata/snippet/filter/");
        $request->getQuery()->set('assigned_counters', $tp_counter);
        $request->getQuery()->set('user', $tp_auth_user);
        $request->getQuery()->set('type', 'goody');
        /* $request->getQuery()->set('type_0', 'goody');
        $request->getQuery()->set('type_1', 'exact'); */
        $request->getQuery()->set('mimetype', 'application/json');
        $request->getQuery()->set('content_nested', '');
        $request->getQuery()->set('page_size', 0);

        $goodies = guzzle_send($request)->json();
        $goodict = array();

        for ($i = 0 ; $i < count($goodies) ; $i++) {

            $goody = $goodies[$i];
            $goody['content'] = json_decode($goody['content'], true);
            $goodict[$goody['content']['q_range'][$unit['uuid']][0]] = $goody;
        }

        krsort($goodict);

        foreach($goodict as $q => $goody) {

            if(floatval($quantity['quantity']) >= floatval($q)) {

                $goody_uuid = $goody['uuid'];
                $body_details = "\n\nContrepartie : ".$goody['content']['label'].".";
                $tp_client->post("metadata/snippet/$goody_uuid/update/assigned_quantities/add/")
                    ->addPostFields(array('uuid' => $quantity['uuid']))->send();

                break;
            }
        }
    }

    $body = "Bonjour ".$user['name'].", ".
        "merci de votre participation au financement du MetaTour !".
        $body_details.

/*        $body .= "Quand vous voudrez de nouveau accéder au film à l'avenir, vous aurez peut-être besoin de vous reconnecter via Traceparent. ".
            "Pour (ré)initialiser votre mot-de-passe Traceparent, vous aurez besoin de visiter cette page (et y entrer ce même ".
            "email : ".$_POST['payer_email']."): ".
            "$tp_url/extra/ui/#/auth/password_reset/\n\n"; */

        "\n\n".
        "Vous pouvez vérifier les information de votre paiement sur votre compte ".
        "PayPal à l'adresse https://paypal.com/".
        "\n\n".
        "La traçabilité et la transparence sur la utilisation de votre argent ".
        "est suivie par le Logiciel Libre et Open Source Traceparent (http://".
        "traceparent.com/) qui opère le fonctionnement de la jauge de financement ".
        "présente sur notre site.".
        "\n\n".
        "Cordialement,".
        "\n\nMetaTV.\n\nhttp://metatv.org/";


    mail($_POST['payer_email'],
         '[MetaTV] Financement participatif MetaTour', $body,
         'From: '.$admin_mail."\r\n"."Content-Type: text/plain; charset=UTF-8\r\n");
}

exit();

?>
