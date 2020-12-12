<?php

require "../vendor/autoload.php";
use Navari\Instagram\Instagram;

$cookie_string = 'ig_did=8BCCE410-6DF7-44F7-82FA-3D8B72C64108; mid=X9J3jQALAAHCbkkfYpbMAm5H8h_a; ig_nrcb=1; csrftoken=M5li1wB5lwmGTLH2AWeM4WOEHQz0X8zH; ds_user_id=28117258505; sessionid=28117258505%3AXRfelpQQU7X14h%3A27; shbid=7071; shbts=1607714828.1976085; rur=ASH; urlgen="{\"24.133.92.22\": 47524}:1ko3uk:pzAal38f2oR0_w_nfi8D2Qj6Rzc"';
$user_session = '28117258505%3AXRfelpQQU7X14h%3A27';
$user_csrf_token = 'M5li1wB5lwmGTLH2AWeM4WOEHQz0X8zH';

$instagram  = Instagram::withCredentials(new \GuzzleHttp\Client(), $user_session, $user_csrf_token, $cookie_string);

try {
    $me = $instagram->getLoggedUser();
    print_r($me);
// $user = $reelMedia->getUser(); // InstagramScraper\Model\Account
// $mentions = $reelMedia->getMentions(); // InstagramScraper\Model\Account[]
} catch (\Navari\Instagram\Exception\InstagramException | \Psr\Http\Client\ClientExceptionInterface | \Navari\Instagram\Exception\InstagramNotFoundException $e) {
    print_r($e);
}

