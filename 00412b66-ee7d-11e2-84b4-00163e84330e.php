<?php

$dl = array('audiogame', 'free_4_ep', 'bubblegum_explosion', 'no_brain_no_headache', 'smurf_in_usa', 'orange', 'electriclegoland');
$tp_partner = '249fad6e-749f-11e2-bcab-78929c525f0e';
$tp_donation = '4b90a86e-dacb-11e2-ba70-78929c525f0e';
$item = null;

if($step == 'complete') {

    $request = $tp_client->get('metadata/snippet/filter/');
    $request->getQuery()->set('user',           $tp_auth_user);
    $request->getQuery()->set('owner',          $tp_auth_user);
    $request->getQuery()->set('slug_0',         $query['tp_item']);
    $request->getQuery()->set('slug_1',         'exact');
    $request->getQuery()->set('content_nested', '');
    $request->getQuery()->set('type',           'item');
    $request->getQuery()->set('mimetype',       'application/json');
    $request->getQuery()->set('page_size',      1);

    $price           = (float)$pp_result['mc_gross'];
    $partner_share   = $price*0.075;
    $bub_share       = $price - $partner_share - (float)$pp_result['mc_fee'];

    if($query['tp_item'] == 'donation') {


        $request = $tp_client->post("monitor/scope/$tp_scope/update/quantities/add/")
                       ->addPostFields(array('uuid' => $quantity['uuid']))->send();

        $tp_client->post("metadata/snippet/$tp_donation/update/assigned_quantities/add/")
            ->addPostFields(array('uuid' => $quantity['uuid']))->send();

        echo '<p>Merci pour votre don !</p>';

        if(!array_key_exists('anon', $query)) {

            $quantity_dict['user_visibility'] = 'public';
            $request = $tp_client->put($quantity['url'].'update/')
                ->setBody(json_encode($quantity_dict), 'application/json');
            $request->send();
        }

        if(array_key_exists('mess', $query)) {

            $request = $tp_client->post('metadata/snippet/create/')
                           ->addPostFields(array('user'                => $tp_auth_user,
                                                 'visibility'          => 'public',
                                                 'mimetype'            => 'text/plain',
                                                 'slug'                => "liberator_comment",
                                                 'type'                => 'comment',
                                                 'assigned_quantities' => $quantity['uuid'],
                                                 'content'             => $query['mess']));
            $metadata = $request->send()->json();
        }

        $item_price = $price;

    } else {

        $item = $request->send()->json();

        if(!($item['count'])) {

            echo '<p class="error">Article introuvable.</p>';
            exit();
        }

        $item            = $item['results'][0];
        $item['content'] = json_decode($item['content'], true);
        $item_uuid       = $item['uuid'];
        $price_info      = $item['content']['price'][$tp_unit];

        $tp_client->post("metadata/snippet/$item_uuid/update/assigned_quantities/add/")
            ->addPostFields(array('uuid' => $quantity['uuid']))->send();

        if(is_array($price_info)) {

            $item_price = (float)$price_info[0];

            foreach($price_info[1]['scopes'] as $scope => $scope_price) {

                $scope_quantity_dict = array('unit'            => $tp_unit,
                                             'quantity'        => $scope_price,
                                             'user'            => $tp_auth_user,
                                             'user_visibility' => 'private',
                                             'prev'            => $quantity['uuid'],
                                             'status'          => 'present');
                $request = $tp_client->post('value/quantity/create/')->addPostFields($scope_quantity_dict);
                $scope_quantity = $request->send()->json();
                $request = $tp_client->post("monitor/scope/$scope/update/quantities/add/")
                               ->addPostFields(array('uuid' => $scope_quantity['uuid']))->send();
                $bub_share = $bub_share - (float)$scope_price;
            }

        } else {

            $item_price = (float)$price_info;
            $request = $tp_client->post("monitor/scope/$tp_scope/update/quantities/add/")
                           ->addPostFields(array('uuid' => $quantity['uuid']))->send();
        }
    }
    $request = $tp_client->post('value/quantity/create/')
                   ->addPostFields(array('unit'            => $tp_unit,
                                         'quantity'        => $bub_share,
                                         'user'            => $tp_auth_user,
                                         'user_visibility' => 'public',
                                         'prev'            => $quantity['uuid'],
                                         'status'          => 'present'));
    $request->send()->json();

    $request = $tp_client->post('value/quantity/create/')
                   ->addPostFields(array('unit'            => $tp_unit,
                                         'quantity'        => $partner_share,
                                         'user'            => $tp_auth_user,
                                         'user_visibility' => 'public',
                                         'prev'            => $quantity['uuid'],
                                         'status'          => 'present'));
    $partner_share_quantity = $request->send()->json();

    $request = $tp_client->post('value/quantity/create/')
                   ->addPostFields(array('unit'            => $tp_unit,
                                         'quantity'        => $partner_share,
                                         'user'            => $tp_partner,
                                         'user_visibility' => 'public',
                                         'prev'            => $partner_share_quantity['uuid'],
                                         'status'          => 'pending'));
    $request->send()->json();

    if($query['tp_item'] != 'donation' && $price < $item_price) {

        echo '<p class="error">Invalid amount.</p>';
        exit();
    }

    if($query['tp_item'] != 'donation') {

        $email_title = $item['content']['label'];
        $body = "Bonjour ".$user['name'].", ".
                "merci de votre participation au financement de Bubblies 2.0 avec « ".$item['content']['label']." »".
                "\n\n";

        if(in_array($item['slug'], $dl))

            $body .= "Quand vous voudrez de nouveau accéder à l'album à l'avenir, vous aurez peut-être besoin de vous reconnecter via Traceparent. ".
                     "Pour (ré)initialiser votre mot-de-passe Traceparent, vous aurez besoin de visiter cette page (et y entrer ce même ".
                     "email : ".$pp_result['payer_email']."): ".
                     "$tp_url/extra/ui/#/auth/password_reset/\n\n";

    } else {

        $email_title = 'Don';
        $body = "Bonjour ".$user['name'].", ".
                "merci de votre participation au financement de Bubblies 2.0 !".
                "\n\n";
    }

    $body .= "Vous pouvez vérifier les information de votre paiement sur votre compte ".
        "PayPal à l'adresse https://paypal.com/".
        "\n\n".
        "La traçabilité et la transparence sur la utilisation de votre argent ".
        "est suivie par le Logiciel Libre et Open Source Traceparent (http://".
        "traceparent.com/) qui opère le fonctionnement de la jauge de financement ".
        "présente sur notre site.".
        "\n\n".
        "Cordialement,".
        "\n\nLes Bubblies.\n\nhttp://bubblies.net/";

    mail($pp_result['payer_email'],
         '[Bubblies] '.$item['content']['label'], $body,
         'From: '.$admin_mail."\r\n"."Content-Type: text/plain; charset=UTF-8\r\n");

    if($query['tp_item'] != 'donation') {

        if (in_array($item['slug'], $dl)) {

            echo '<p>Commande réussie.</p>';

            $tp_client->post("extra/serve/bucket/$tp_auth_user/".$item['slug']."/update/users_ro/add/")
                ->addPostFields(array('uuid' => $user['uuid']))->send();
        }
    } else '<p>Don OK.</p>';
}

if($query['tp_item'] == 'donation') $redirect_url = 'http://bubblies.net/spip.php?page=y4k_lib';

elseif(in_array($item['slug'], $dl)) {

    if($step == 'transaction_already_registered') {

        $base_url = $tp_url.'/extra/ui/#/auth/login/';
        $amp_mark = '?';

    } else {
        exec('PYTHONPATH='.$tp_virtualenv.'/lib/python'.$tp_virtualenv_python_version.'/site-packages/:'.
             $tp_virtualenv.'/src/traceparent/ '.
             $tp_virtualenv.'/src/traceparent/scripts/auth_user_token.py '.$pp_result['payer_email'],
             $output, $return_var);
        $base_url = $tp_url.'/auth/login/?login_token='.$output[0];
        $amp_mark = '&';
    }

    $redirect_url = $base_url.$amp_mark.'next=http%3A//bubblies.net/spip.php%3Fpage%3Dy4k_album%23'.$item['slug'];

} elseif($item['slug'] == 'vinyl') $redirect_url = 'http://bubblies.net/spip.php?page=y4k_bestof';

else $redirect_url = 'http://bubblies.net/spip.php?page=y4k_shop';

echo '<script type="text/javascript">document.location="'.$redirect_url.'";</script>';
exit();

?>
