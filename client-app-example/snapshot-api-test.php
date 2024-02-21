<?php
require(__DIR__.'/utils/mysql_api_client.php');

// CONFIG
$is_local = (__DIR__)[0] !== '/';
//mdb_api_client::$request_timeout = 20;

//$api_url = 'https://utilmind.com/';
$api_url = 'http://localhost:3000/';
//$snapshot_url = 'http://totallycertainlyunexistingdomainname/';
//$snapshot_url = 'http://silkcards.com/';
//$snapshot_url = 'https://google.com/';
$snapshot_url = 'http://utilmind.com/demos/2024/redirect-to-appcontrols';
$authorization_token = 'LeT9Lc9wsqnLZPJ2mX7MYVk2mPHExRm5'; // This is temporary token. To guaranties that exactly this token will work in the future.


// PRIVATE FUNCS
function hostname_by_url(string $url): string {
    $url_parts = parse_url($url);
    return $url_parts['host'].($url_parts['port'] ? ':'.$url_parts['port'] : '');
}


// GO!
$r = mdb_api_client::query_url_status($api_url . 'snapshot/', // 'remove/',
        json_encode([
            //'id' => 1, // for /remove example

            'url' => $snapshot_url . '/554', // required parameter
            'width' => 1800, //px
            'format' => 'jpg',
            'overwrite' => 1,

        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE),
        'POST', [
                'Content-Type: application/json',
                'Authorization: Bearer '.$authorization_token, // required header
            ], true);

// These are errors on our side, during connection with an API.
if (is_int($r)) { // error
    switch ($r) {
        case CURLE_OPERATION_TIMEDOUT:
            echo 'Request timed out.';
            break;
        case CURLE_COULDNT_RESOLVE_HOST:
            printf('Failed to resolve hostname \'%s\'.', hostname_by_url($api_url));
            break;
        case CURLE_COULDNT_CONNECT:
            printf('Host \'%s\' is unreachable.', hostname_by_url($api_url));
            break;
        case CURLE_RECV_ERROR:
            echo 'Failure with receiving network data. Server dropped connection or Internet connection lost.';
            break;
        default:
            echo "ERROR #$r. See what this error mean on https://curl.se/libcurl/c/libcurl-errors.html";
    }
}else {
    print_r($r);
}
