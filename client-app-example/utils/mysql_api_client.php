<?php
require_once(__DIR__.'/mysql.php');

// AK: first introduced for LockLizard/Redshelf integration on actechbooks.com
class mdb_api_client extends mdb_data {

        public static $request_timeout = 30; // in seconds. Set to 0 to make unlimited timeout. (But also don't forget to increase PHP execution time with `set_time_limit(0)`.)
        public static $max_redirects = 5; // The maximum number of redirects to follow, if http server gives redirection responce

        // @protected, override me
        protected static function prepare_query_headers($is_post) {
            return ['Accept-Encoding: gzip, deflate']; // , br TODO: support brotli compression!
        }


        // @private internal helpers
        private static function _init_curl(&$url,
                                            $post_fields, // can be either array or JSON string
                                            $request_method,
                                            // This func is internal anyway, so let's accept the most we can as vars. No defaults.
                                            &$add_headers,
                                            &$need_response_headers,
                                            &$no_ssl) {
            global $is_local;

            if (!$ch = curl_init($url)) new Exception('Failed to initialize curl.');

            if ($is_local || $no_ssl) { // if local then w/o ssl too
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            }

            if ($need_response_headers) {
                curl_setopt($ch, CURLOPT_HEADER, true); // want to receive response headers
            }

            curl_setopt_array($ch, [
                    CURLOPT_FOLLOWLOCATION => true, // follow redirects
                    CURLOPT_MAXREDIRS => static::$max_redirects, // if http server gives redirection responce
                    CURLOPT_TIMEOUT => static::$request_timeout // seconds. No need to wait more.
                ]);


            // detect request method. We do POST if POST fields present OR if POST method is used explicitly.
            $has_post_fields = !empty($post_fields);
            $is_post_method = 'POST' === $request_method;

            // POST/PATCH/DELETE request occurs only if method specified explicitly OR if there are any data to POST.
            if ($is_non_get = ($has_post_fields || $is_post_method)) {
                if (!$request_method || $is_post_method) {
                    curl_setopt($ch, CURLOPT_POST, true);
                }else {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_method); // PUT, PATCH, DELETE etc
                }

                if ($has_post_fields) { // We're still not obligated
                    curl_setopt($ch, CURLOPT_POSTFIELDS,
                        is_array($post_fields)
                            ? http_build_query($post_fields) // there is can be array within $post_fields, which can't be passed as string. So let's "build query"...
                            : $post_fields); // string, maybe json encoded... Use json_encode($post_fields, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) prior to this func.
                }
            }

            $_std_headers = static::prepare_query_headers($is_non_get);
            curl_setopt($ch, CURLOPT_HTTPHEADER, empty($add_headers) ? $_std_headers : static::merge_headers($_std_headers, $add_headers)); // send headers

            return $ch;
        }


        /* Returns integer (positive HTTP STATUS CODE on success
                         OR negative cURL ERROR CODE on error), if $need_headers is FALSE,
             ...or array [$http_status, $headers], if $need_headers is TRUE.
           --
           CAUTION! When $is_need_headers is FALSE it returns integer value in both cases, in case of success (HTTP STATUS) or in case of error (cURL error code).
           Error codes are NEGATIVE representation of standard cURL error codes, described on https://curl.se/libcurl/c/libcurl-errors.html
           Additionally you should expect that successful HTTP status codes starts from 200. All values less than 200 are errors anyway.
                                                                                             -------------------------------------------
           Alternatively, either request headers as result (so successfull result will be an array, int is error) OR use different implementation.
             * Keep this method simple. In most cases we just need it to check whether it retuns certain HTTP status.
           --
           Use case example: check (following all redirects), whether some downloadable file uploaded / whether web page exists and returns certain HTTP STATUS, like (int)200.
        */
        public static function query_url_head($url,
                                              $add_request_headers = [],
                                              $need_response_headers = false,
                                              $no_ssl = false) {
            try {
                $ch = static::_init_curl($url, null, null, $add_request_headers, $need_response_headers, $no_ssl);

                // This changes the request method to HEAD
                curl_setopt($ch, CURLOPT_NOBODY, true);

                // Execute the request.
                $response = curl_exec($ch);  // AK: $response can be empty string. While it's not FALSE it's okay. It's valid to respond with empty body, especially if response status is 204 No Content.

                // Check if cURL request was successful
                if (false === $response) { // FAILURE. Return negative error code
                    return -curl_errno($ch); // possible error codes: https://curl.se/libcurl/c/libcurl-errors.html ATTN! Use abs() to get unsigned error code.

                    /* // How to handle errors:
                    if (!is_array($result) && ($error_code = abs($result))) {
                        switch ($error_code) {
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
                                echo 'Failure with receiving network data. Connection lost?';
                                break;
                
                            default:
                                echo "ERROR #$r. See what this error mean on https://curl.se/libcurl/c/libcurl-errors.html";
                        }
                    } */
                }

                $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                // Fetch the HTTP-code (HTTP status). Eg 200 = success, 404 = not found.
                return $need_response_headers // no worries, finalization will occur before actual return
                           ? [$http_status, $response]
                           : $http_status;

            }catch(Exception $e) {
                echo "ERROR:\n".print_r($e, true); // AK: Error shouldn't happen, but if it's occured, then reason is extremely serious (but still can be fixed). Let's display it, so dev will be aware.

            }finally {
                if (is_resource($ch))
                    curl_close($ch);
            }
        }

        /* *use prepare_get_params to append GET-parameters to $url.
           Returns either
               on Success: array of [http_status, response_body[, response_headers]].
               on Failure: result of curl_errno(). Eg if it equals to CURLE_OPERATION_TIMEDOUT, then connection timed out. More error codes: https://curl.se/libcurl/c/libcurl-errors.html
        */
        public static function query_url_status($url, // if you need GET parameters, use static::prepare_get_params($get_params)
                                                $post_fields = [], // can be either array or JSON string
                                                $request_method = null, // POST (default), PUT or PATCH. (HEAD not supported for simplity. Use different implementation, eg query_url_head_status().)
                                                                        // However GET method is used if $post_fields are empty, nothing to post/patch && $post_fields are not provided
                                                $add_request_headers = [],
                                                $need_response_headers = false, // set TRUE if needed.
                                                $no_ssl = false) {
            $http_status = 0;
            $response_header = '';
            $response_body = '';
            try {
                $ch = static::_init_curl($url, $post_fields, $request_method, $add_request_headers, $need_response_headers, $no_ssl);

                // Set CURLOPT_RETURNTRANSFER so that the content is returned as a variable.
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_ENCODING, 'gzip'); // the page encoding

                // proxy
                //curl_setopt($ch, CURLOPT_PROXY, '161.8.174.48:1080');     // PROXY details with port
                //curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyauth);   // Use if proxy have username and password
                //curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5); // If expected to call with specific PROXY type

                // Execute the request.
                $response_body = curl_exec($ch); // AK: $response_body can be empty string. While it's not FALSE it's okay. It's valid to respond with empty body, especially if response status is 204 No Content.

                // Check if cURL request was successful
                if (false === $response_body) {
                    return curl_errno($ch); // possible error codes: https://curl.se/libcurl/c/libcurl-errors.html

                    /* // How to handle errors:
                    if (!is_array($result)) {
                        if (CURLE_OPERATION_TIMEDOUT === $result) {
                            echo 'The request timed out.';
                        }else { // Handle other cURL errors
                            echo 'cURL error: ' . $result;
                        }
                    } */
                }

                $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); // integer
                if ($need_response_headers) {
                    $response_header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); // to split header & body
                    $response_header = substr($response_body, 0, $response_header_size); // cut header
                    $response_body = substr($response_body, $response_header_size); // cut body
                }

                return $need_response_headers // it always returns array. With 3 items or 2, depending on whether we need respnse headers.
                    ? [$http_status, $response_body, $response_header]
                    : [$http_status, $response_body];

            }catch(Exception $e) {
                echo "ERROR:\n".print_r($e, true); // AK: Error shouldn't happen, but if it's occured, then reason is extremely serious (but still can be fixed). Let's display it, so dev will be aware.

            }finally {
                if (is_resource($ch))
                    curl_close($ch);
            }
        }

        // Returns either
        //     on Success: (string) the request body.
        //     on Failure: (int) error code, result of curl_errno(). Eg if it equals to CURLE_OPERATION_TIMEDOUT, then connection timed out. More error codes: https://curl.se/libcurl/c/libcurl-errors.html
        public static function query_url($url, $post_fields = [], $request_method = null, $add_headers = [], $no_ssl = null) {
            $r = static::query_url_status($url, $post_fields, $request_method, $add_headers, false, $no_ssl);
            return isset($r[1])
                    ? $r[1] // string, request body
                    : $r;   // int, error code. Possible error codes: https://curl.se/libcurl/c/libcurl-errors.html
        }


        // ATTN! key values must contain only latin letters! They are not URL encoded!
        public static function prepare_get_params($get_params = []) {
            if (empty($get_params)) return '';

            $query = [];
            foreach ($get_params as $k => $v)
                if ($v) $query[] = $k.'='.urlencode($v);

            return '?'.implode('&', $query);
        }

        public static function split_header($header, $lowercase_name = null) { // if $lowercase_name is negative, it will return ONLY lowercase name.
            @list($name, $val) = explode(':', $header, 2);
            if ($lowercase_name) $name = strtolower($name); // no need multi-byte (mb_xxx) operations here. All must be in ASCII encoding.
            return 0 > $lowercase_name // negative?
                ? $name
                : [$name, ltrim($val)];
        }

        public static function get_response_header_value(&$response_headers, $key_name) {
            $key_name = strtolower($key_name);
            $headers = explode("\n", $response_headers);
            foreach ($headers as $s)
                if ($s) {
                    $s = static::split_header($s, true);
                    if ($s[0] === $key_name)
                        return $s[1];
                }
        }

        public static function merge_headers($headers_arr1, $headers_arr2) { // AK: allow inline parameters (not only variables for both $headers)
            foreach ($headers_arr1 as $key1 => &$header1) {
                $name1 = self::split_header($header1, -1);

                foreach ($headers_arr2 as &$header2) {
                    if ($name1 === self::split_header($header2, -1)) {
                        unset($headers_arr1[$key1]); // discard $name1. It will be replaced during array_merge()
                    }
                }
            }
            return array_merge($headers_arr1, $headers_arr2);
        }


        // Just check for db error and die on error.
        public static function check_db_error() {
            $db = static::$db; // php5 support, for targettvspots
            if ($db::errno())
                myexit('<div style="color: red;"><b>DB ERROR:</b> '.$db::error()."</div>\n"); // Die. No sense to continue.
        }
}