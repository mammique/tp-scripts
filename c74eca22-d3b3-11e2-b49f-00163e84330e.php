<?php

$tp_jocelyne      = '5b1ca8c8-d751-11e2-b8ee-00163e84330e';
$tp_seance        = '5b23c3d8-d751-11e2-b8ee-00163e84330e';
$tp_meta_donation = '02d96c9e-d735-11e2-b49f-00163e84330e';
$tp_meta_vod_fr   = '5a9b5870-d735-11e2-b49f-00163e84330e';
$tp_meta_vod_en   = '33ca2230-d735-11e2-b49f-00163e84330e';

if($step == 'complete') {

    $request = $tp_client->post("monitor/scope/$tp_scope/update/quantities/add/")
                   ->addPostFields(array('uuid' => $quantity['uuid']))->send();

    $price          = (float)$pp_result['mc_gross'];
    $partner_share  = $price*0.075;
    $jocelyne_share = $price - ($partner_share * 2) - (float)$pp_result['mc_fee'];

    $tp_meta = null;

    switch($query['tp_item']) {
    
        case 'movie_fr':

            $tp_meta = $tp_meta_vod_fr;
            break;
    
        case 'movie_en':

            $tp_meta = $tp_meta_vod_en;
            break;
    
        case 'donation':

            $tp_meta = $tp_meta_donation;
            break;
    }

    if($tp_meta)
        $tp_client->post("metadata/snippet/$tp_meta/update/assigned_quantities/add/")
            ->addPostFields(array('uuid' => $quantity['uuid']))->send();

    $request = $tp_client->post('value/quantity/create/')
                   ->addPostFields(array('unit'            => $tp_unit,
                                         'quantity'        => $partner_share,
                                         'user'            => $tp_auth_user,
                                         'user_visibility' => 'public',
                                         'prev'            => $quantity['uuid'],
                                         'status'          => 'present'));
    $tp_share = $request->send()->json();

    $request = $tp_client->post('value/quantity/create/')
                   ->addPostFields(array('unit'            => $tp_unit,
                                         'quantity'        => $partner_share,
                                         'user'            => $tp_auth_user,
                                         'user_visibility' => 'public',
                                         'prev'            => $quantity['uuid'],
                                         'status'          => 'present'));
    $tp_seance_share = $request->send()->json();

    $request = $tp_client->post('value/quantity/create/')
                   ->addPostFields(array('unit'            => $tp_unit,
                                         'quantity'        => $partner_share,
                                         'user'            => $tp_seance,
                                         'user_visibility' => 'public',
                                         'prev'            => $tp_seance_share['uuid'],
                                         'status'          => 'pending'));
    $seance_share = $request->send()->json();

    $request = $tp_client->post('value/quantity/create/')
                   ->addPostFields(array('unit'            => $tp_unit,
                                         'quantity'        => $jocelyne_share,
                                         'user'            => $tp_auth_user,
                                         'user_visibility' => 'public',
                                         'prev'            => $quantity['uuid'],
                                         'status'          => 'present'));
    $tp_jocelyne_share = $request->send()->json();

    $request = $tp_client->post('value/quantity/create/')
                   ->addPostFields(array('unit'            => $tp_unit,
                                         'quantity'        => $jocelyne_share,
                                         'user'            => $tp_jocelyne,
                                         'user_visibility' => 'public',
                                         'prev'            => $tp_jocelyne_share['uuid'],
                                         'status'          => 'pending'));
    $jocelyne_share = $request->send()->json();

    if($query['tp_item'] == 'donation') {

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

    } elseif(($query['tp_item'] == 'movie_fr' || $query['tp_item'] == 'movie_en') && $price < 6) {

        echo '<p class="error">Invalid amount.</p>';
        exit();
    }

    if($tp_meta) {

        $request  = $tp_client->get("metadata/snippet/$tp_meta/content");
        $response = $request->send();
        $tp_meta  = $response->json();

        if($query['tp_item'] == 'movie_en')

            $body = "Hello ".$user['name'].", ".
                "thank you for your participation to the liberation of the movie ".
                "« Me, Finance and Sustainable Development » with:\n\n";

        else

            $body = "Bonjour ".$user['name'].", ".
                "merci de votre participation à la libération du film ".
                "« Moi, la Finance et le Développement Durable » avec :\n\n";

        $body .= $tp_meta['desc']."\n\n";

        if($query['tp_item'] == 'movie_en')

            $body .= "When you'll want to access the movie again later on, you might have to reconnect via Traceparent. ".
                "In order to set or reset your Traceparent password, you'll need to visit this page (and provide this very same ".
                "email: ".$pp_result['payer_email']."): ".
                "$tp_url/extra/ui/#/auth/password_reset/\n\n";

        elseif($query['tp_item'] == 'movie_fr')

            $body .= "Quand vous voudrez de nouveau accéder au film à l'avenir, vous aurez peut-être besoin de vous reconnecter via Traceparent. ".
                "Pour (ré)initialiser votre mot-de-passe Traceparent, vous aurez besoin de visiter cette page (et y entrer ce même ".
                "email : ".$pp_result['payer_email']."): ".
                "$tp_url/extra/ui/#/auth/password_reset/\n\n";

        if($query['tp_item'] == 'movie_en')

            $body .= "You can check information about your payment on your PayPal account at https://paypal.com/".
                "\n\n".
                "Tracability and transparency about the use of your money is monitored ".
                "by the free and Open Source software Traceparent (http://traceparent.com/) ".
                "that is running the funding gauge on our website.".
                "\n\n".
                "Best regards,".
                "\n\nJocelineaste.\n\nhttp://financedurable-lefilm.com/";
        else

            $body .= "Vous pouvez vérifier les information de votre paiement sur votre compte ".
                "PayPal à l'adresse https://paypal.com/".
                "\n\n".
                "La traçabilité et la transparence sur la utilisation de votre argent ".
                "est suivie par le Logiciel Libre et Open Source Traceparent (http://".
                "traceparent.com/) qui opère le fonctionnement de la jauge de financement ".
                "présente sur notre site.".
                "\n\n".
                "Cordialement,".
                "\n\nJocelineaste.\n\nhttp://financedurable-lefilm.com/";


        mail($pp_result['payer_email'],
             '[Jocelineaste] '.$tp_meta['label'], $body,
             'From: '.$admin_mail."\r\n"."Content-Type: text/plain; charset=UTF-8\r\n");

        if($query['tp_item'] == 'movie_fr' || $query['tp_item'] == 'movie_en') {

            if($query['tp_item'] == 'movie_fr') echo '<p>Commande réussie.</p>';
            else echo '<p>Order successful.</p>';

            $tp_client->post("extra/serve/bucket/$tp_jocelyne/financedurable/update/users_ro/add/")
                ->addPostFields(array('uuid' => $user['uuid']))->send();
        }
    }
}

if($query['tp_item'] == 'donation') {

    echo '<script type="text/javascript">document.location="http://financedurable-lefilm.com/";</script>';
    exit();
}

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


if($query['tp_item'] == 'movie_fr') $redirect_url = $base_url.$amp_mark.'next=http%3A//financedurable-lefilm.com/Voir-le-film-en-ligne';
else $redirect_url = $base_url.$amp_mark.'next=http%3A//financedurable-lefilm.com/Watch-online';

echo '<script type="text/javascript">document.location="'.$redirect_url.'";</script>';
exit();

?>
