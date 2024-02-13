<?php
require(__DIR__.'/utils/mysql_api_client.php');

// CONFIG
$is_local = (__DIR__)[0] !== '/';
//mdb_api_client::$request_timeout = 20;

//$api_url = 'https://utilmind.com/snapshot/';
$api_url = 'http://localhost:3000/snapshot/';
//$snapshot_url = 'http://totallycertainlyunexistingdomainname/';
//$snapshot_url = 'http://silkcards.com/';
$snapshot_url = 'http://appcontrols.com/';


// PRIVATE FUNCS
function hostname_by_url(string $url): string {
    $url_parts = parse_url($url);
    return $url_parts['host'].($url_parts['port'] ? ':'.$url_parts['port'] : '');
}


// GO!
$r = mdb_api_client::query_url_status($api_url,
        json_encode([
            'url' => $snapshot_url,
            'width' => 1800, //px
            'format' => 'png',

        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE),
        'POST', ['Content-Type: application/json'], true);

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
            echo 'Failure with receiving network data.';
            break;
        default:
            echo "ERROR #$r. See what this error mean on https://curl.se/libcurl/c/libcurl-errors.html";
    }
}else {
    print_r($r);
}
