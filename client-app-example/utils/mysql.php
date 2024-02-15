<?php
require_once(__DIR__.'/strings.php');

/* ATTN!! Do not use CURDATE() / CURRENT_TIMESTAMP / NOW() when selecting something, since all those are dynamic, calculated functions. Use static constant, prepared on PHP level!
   Use when needed something like: define('SQLDAY', '"'.date('Y-m-d').'"');
-------------------------------------------
  It's static class, not an object with purpuse. It's not mistake. In >90% cases you don't need to work with more than 1 database.
   If you need to connect to another DB -- just make a sub-class with container of

    class my_another_db extends mdb {
      protected static $db; // container for mysqli object
    }

   Then connect with another credentials, using connect() method.
 */
class mdb {
    /* CONFIGURATION for error processing. Must be provided as $db_access parameter to connect(), for each connect() individually.
       Possible options are:
       // --- logging ---
           $debug_log (string) eg __ROOT__.'/inc/_mysql_query.txt'; // FALSE = disabled.
           $error_log (string) eg __ROOT__.'/inc/_mysql_errors.txt'; // FALSE = disabled.
           $die_on_error (bool)

       // --- email an error ---
           $admin_email (string) used to email mySQL error to admin. Can work simultaneously with "$error_log" setting.
           $add_error_info (callable function) or (string with function name), callback that returns brief info about user that currently logged in. (+ maybe some global environment vars.) ONLY FOR EMAIL! No need to show this info to user.
     */

    // VARS. Feel free to make them public for some specific project, for faster access and possibility to quickly replace the link to "mysqli" object when required.
    protected static $db_access = null; // copy of $db_access used upon last successful connection
    //public static $check_errors = true; // check query results and display mySQL error if it happens. Works after each "query()" or "rquery()" (that also applies to ::insert(), ::update() etc)
    public static $db; // mysqli object. AK: I decided to make it public for faster access, when needed. Sometimes in extra-special cases we need access to pure mysql object. (Case: targettvspots.)
/*
    public static function db($new_db = null) {
        if ($new_db) { // avoid assigning new static mysqli object.
            static::disconnect();
            static::$db = $new_db;
        }
        return static::$db;
    }
*/
    // Funcs to avoid direct access to "$db" in the app code. So the code will not require upgrade in case of switching to PDO or some other engine.
    public static function errno() {
        return static::$db->errno;
    }

    public static function error() {
        return static::$db->error;
    }

    public static function affected_rows() {
        return static::$db->affected_rows;
    }

    public static function ping() {
        return static::$db->ping();
    }

    protected static function db_access() { // OVERRIDEABLE in successors. If not overridden, we'll try to use global $db_access variable on connect() attempt.
        global $db_access;
        return $db_access;
    }

    // returns TRUE if successfully connected or reconnected (if connection estabilished)
    public static function connect($db_access = null) {
        // avoid duplicate connection if already connected
        if (static::$db) {
            if ((null === $db_access) || static::ping()) { // ping if something specified. By default just reuse existing link. Feel free to specify TRUE or FALSE as $db_access to make sure that db is pinging.
                return static::$db; // already connected
            }
            static::disconnect(); // no ping. Disconnect to reconnect again.
        }

        if (!isset($db_access['host'])) $db_access = static::db_access();

        if ((!$new_db_link = @new mysqli($db_access['host'], $db_access['user'], $db_access['pass'], $db_access['dbname'])) ||
            $new_db_link->connect_errno) {

            // Inform moderator about crash. But not more often than once per hour.
            if (!empty($db_access['admin_email'])) {
                if (!empty($db_access['error_log'])) {
                    $lock_fn = $db_access['error_log'];
                }elseif (!empty($db_access['debug_log'])) {
                    $lock_fn = $db_access['debug_log'];
                }

                if (!empty($lock_fn) && ($lock_fn = dirname($lock_fn))) {
                    $lock_fn.= '/_mysql_fail.txt';
                    $lock_ts = file_exists($lock_fn) && ($lock_ts = @file_get_contents($lock_fn))
                        ? explode("\n", $lock_ts, 2)[0]
                        : 0;

                    if ($lock_ts < $_SERVER['REQUEST_TIME'] - 3600) { // not more often than 1 hour = 3600 seconds
                        global $anon_email;

                        $error_reason = 'Error reason: '.($new_db_link ? $new_db_link->connect_errno.', '.$new_db_link->connect_error."\n".var_export(error_get_last(), 1) : 'Unknown. mysqli object just not created.');
                        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'; // AK: this should be more informative than global $site_url.

                        @file_put_contents($lock_fn, "$_SERVER[REQUEST_TIME]\n$error_reason");
                        @chmod($lock_fn, 0666); // for sure. It can be started from console, not by web user

                        if (!function_exists('qmail'))
                            require(__DIR__.'/mail/mail.php');

                        qmail($db_access['admin_email'],
                            'MySQL inacessible on '.$host,
                            "<p><b>No connection with mySQL database $db_access[dbname] on $host!</b></p><p>$error_reason<br />Act immediately! Restart MySQL server if needed. Or log in to investigate possible bottleneck and kill zombie processes.</p><p><i>This is automatic notification. No response needed.</i></p>",
                            $anon_email);
                    }
                }
            }

            $new_db_link = null;

            if (!empty($db_access['stub_on_fail'])) {
                require($db_access['stub_on_fail']); // exit inside.
            }else
                die('Can\'t connect to db.'); // .backtrace()
        }

        self::$db_access = $db_access;
        if (!empty($db_access['sync_timezone']))
            $new_db_link->real_query('SET time_zone="'.date('P').'"');

        if (!empty($db_access['charset']))
            $new_db_link->set_charset($db_access['charset']);

        return static::$db = $new_db_link; // new connection estabilished
    }

    public static function disconnect() { // and release the instance of "mysqli".
        if ($db = static::$db) {
            $db->close();
            static::$db = null;
        }
    }

    public static function query($q, $no_check_errors = null) {
        if (!empty(self::$db_access['debug_log']))
            static::log_append(self::$db_access['debug_log'], $q);

        if ((!$r = static::$db->query($q)) && !$no_check_errors) static::check_error($q);
        return $r;
    }

    // Should be used only for UPDATE/INSERT statements where we don't need result.
    public static function rquery($q, $no_check_errors = null) {
        if (!empty(self::$db_access['debug_log']))
            static::log_append(self::$db_access['debug_log'], $q);

        if ((!$r = static::$db->real_query($q)) && !$no_check_errors) static::check_error($q);
        return $r;
    }

    // returns 1st field of single row of result.
    public static function qquery($q, $def = null) {
        if ($r = static::query($q.' LIMIT 1')) {
            $row = $r->fetch_row();
            $r->free();
        }
        return isset($row[0]) ? $row[0] : $def; // ATTN! It returns "" (not $def) if value in database is empty.
    }

    public static function qint($q, $def = 0) {
        return (int)static::qquery($q, $def);
    }

    private static function qquery_arr($kind, $q, $int_fields = null) {
        if ($r = static::query($q.' LIMIT 1')) {
            try {
                if ($row = 1 === $kind
                        ? $r->fetch_row()
                        : (2 === $kind
                            ? $r->fetch_assoc()
                            : $r->fetch_array())) {

                    if ($int_fields) // only if we have result
                        static::int_fields($row, true === $int_fields ? null : $int_fields); // boolean true converts to (int) all

                    return $row;
                }
            }finally {
                $r->free();
            }
        }
    }

    public static function query_array($q, $int_fields = null) {
        return static::qquery_arr(0, $q, $int_fields);
    }

    public static function query_row($q, $int_fields = null) {
        return static::qquery_arr(1, $q, $int_fields);
    }

    public static function query_assoc($q, $int_fields = null) {
        return static::qquery_arr(2, $q, $int_fields);
    }

    // AK: Returning array may consume too much memory. Avoid using it. (UPD. for small lists is ok.)
    public static function all_assoc($q, $int_fields = null, $key_id = null) { // $key_id is the field with unique IDs. Also it may help to avoid duplicates w/o DISTINCT query
        $i = [];
        if ($r = static::query($q)) {
            while ($row = $r->fetch_assoc()) {
                if ($int_fields)
                    static::int_fields($row, $int_fields === true ? null : $int_fields); // boolean true converts to (int) all

                if ($key_id) { // this is optional, to have all items associated with unique keys. Also it may help to avoid duplicates.
                    $i[$row[$key_id]] = $row;
                }else {
                    $i[] = $row;
                }
            }
            $r->free();
        }
        return $i;
    }

    // ATTN! this func does not avoid duplicates. Use 'SELECT DISTINCT...' query to be sure that array contains only unique values.
    //     * even if you need to GROUP results after ORDER (after sorting), you always can use SQL statements like the following one:
    //           SELECT * FROM
    //               (SELECT ...fields... FROM ...tables... WHERE ...conditions... ORDER BY ...) AS sub
    //           GROUP BY id
    //
    // Also it does not filters 0 and empty values here. Do all filters right in the query. (This is speed-optimized func.)
    public static function all_array($q, $get_keys = false, // result is $key of an array with 1 assigned as value. Or if query contains 2 values, 2nd assigned as $value of $key.
                                     $column = null) { // Which column to get as array. FALSE = all columns. NULL = first column if there is only 1, or ALL columns.
                                                       // TODO: column can be array of 2 values (key => value), if $get_keys used.
        $i = [];
        if ($r = static::query($q)) {
            if ($get_keys) { // FYI: It's better than array_flip(mdb::all_array($q)), cuz we avoiding 0 (key of the first element of array) assigned to flipped key.
                if (!$column) $column = 0;
                $col2 = $column + 1; // TODO: $column can be array of 2 values.

                while ($row = $r->fetch_row())
                    $i[$row[$column]] = isset($row[$col2]) ? $row[$col2] : 1;
            }else {
                while ($row = $r->fetch_row()) {
                    if (null === $column) {
                        $column = 1 === count($row)
                            ? 0
                            : false; // all!
                    }
                    $i[] = false === $column ? $row : $row[$column]; // AK: if you need ASSOCIATIVE array (not indexed) --- use all_assoc() instead!
                }
            }
            $r->free();
        }
        return $i;
    }

    // Use SQL DISTINCT or GROUP statements to avoid duplicates. Use WHERE to get rid of empty and unwanted values.
    public static function all_list($q, $def = null /* we may prefer 0 as empty result*/, $sep = ',') {
        return ($q = static::all_array($q)) ? implode($sep, $q) : $def; // set $def to 0 if you never want this list empty
    }


    // --== DEBUG & ERROR PROCESSING ==--
    protected static function get_user_data() {
        global $uid, $MY_username, $MY_ip, $MY_tz;

        $user_data = $uid ? 'uid: '.$uid.' ('.$MY_username.'), ' : '';
        if ($MY_ip || ($MY_ip = get_real_ip()))
            $user_data.= 'IP: '.$MY_ip.
                ($MY_tz || (!empty($_COOKIE['tz']) && (-1 !== ($MY_tz = (int)$_COOKIE['tz']))) ? ', TZ: '.($MY_tz / 60) : '');

        return $user_data;
    }

    protected static function log_append($fn, $line) {
        // append to error log. We need to check "isset(static::$dblink)" if this called before connection established.
        if ($f = fopen($fn, 'a')) {
            flock($f, LOCK_EX);
            $r = fwrite($f, $line."\n-- ". date('r') .' --- '.static::get_user_data()."\n\n\n");
            flock($f, LOCK_UN);
            fclose($f);
        }
    }

    // This checks not only errors, it's can be used as query log.
    public static function check_error($q) {
        if (($err = static::errno()) && (2006 !== $err)) { // if not 2006 = "MySQL has gone away"
            $db_access = self::$db_access;

            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'; // AK: this should be more informative than global $site_url.
            $cur_url = isset($_SERVER['REQUEST_URI']) ? 'http'.(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '').'://'.$host.$_SERVER['REQUEST_URI'] : 'CLI'; // url or command-line interface.
            $user_info = empty($db_access['add_error_info']) ? '' : call_user_func($db_access['add_error_info']);

            if (!empty($db_access['error_log'])) {
                // append to error log. We need to check "isset(static::$dblink)" if this called before connection established.
                static::log_append($db_access['error_log'], $q."\n\n".static::error()."\n\n".backtrace(1).
                    "\nURL: $cur_url".
                    (count($_POST) ? "\nPOST: ".print_r($_POST, true) : '').
                    ($user_info ? "\n$user_info" : ''));
            }

            // --== FATAL ERROR ==--
            if (400 > http_response_code()) http_response_code(500); // HTTP error code is important for editable bootstrap tables.
                                                                     // However, if output started before the http_response_code(), it does not set correctly.
            if (!empty($db_access['display_errors']) &&
                    (0 > $db_access['display_errors']) && // smart displaying of errors. Display only when it's safe to display.
                // CAUTION! We shouldn't display error reason in case of GTID attacks (https://favor.com.ua/blogs/35833.html), when sensitive db information displayed as error reason.
                    ((preg_match("/(\s|\/)GTID_/i", $q, $m) && $m[0]) || // *Attacker may use /**/ instead of space.
                     (preg_match("/(\(|,|\s|\/)0x[\da-f]/", $q, $m) && $m[0]))) {
                $db_access['display_errors'] = false; // block exposing of potentially sensitive information
            }

            if (!empty($db_access['display_errors']) || !empty($db_access['admin_email'])) {
                $err_reason = static::error();
                $trace = backtrace();

                if (!empty($db_access['display_errors'])) {
?>
        <div style="background-color: #FFD">
            <h4>MySQL error</h4>
            <div><b><?=$err_reason?></b></div>

            <div style="margin-top: 1em"><?=nl2br(htmlspecialchars($q))?></div>

            <div style="margin-top: 1em; color: maroon">Backtrace:</div>
            <?=$trace?>
        </div>
<?php
                }

                if (!empty($db_access['admin_email'])) {
                    global $anon_email;

                    $trace.= "<hr />URL: <b><a href=\"$cur_url\">$cur_url</a></b><br />".static::get_user_data();

                    if (count($_POST))
                        $trace.= '<hr />POST:<br />'.print_r($_POST, true);

                    if (!empty($db_access['add_error_info']))
                        $trace.= '<hr />'.call_user_func($db_access['add_error_info']);

                    if (!function_exists('qmail'))
                        require(__DIR__.'/mail/mail.php');
                    qmail($db_access['admin_email'], 'MySQL error on '.$host,
                        $err_reason.'<br /><br />'.$q.'<br /><br />Backtrace: '.$trace,
                        $anon_email);
                }
            }

            if (!empty($db_access['die_on_error']))
                die(empty($db_access['display_errors']) ? 'DB error' : null);
        }
    }

    // returns array of strings with all table names
    public static function all_tables() {
        $out = [];
        if ($r = static::query('SHOW TABLES')) {
            while ($row = $r->fetch_row()) {
                $out[] = $row[0];
            }
            $r->free();
        }
        return $out;
    }

    // ---------------------
    // debugging of querties
    private static function debug_query($q) {
        echo "<p>\n".nl2br(htmlspecialchars($q))."</p>\n";
    }

    // debug of ::query()
    public static function dquery($q) {
        static::debug_query($q);
        return static::query($q);
    }

    // debug of ::qquery()
    public static function dqquery($q, $def = null) {
        static::debug_query($q);
        return static::qquery($q, $def);
    }

    // debug of ::query_array()
    public static function dquery_array($q) {
        static::debug_query($q);
        return static::query_row($q);
    }

    // Returns an ID of inserted record (always INTEGER value).
    // BUT... It works only if:
    //    - primary key is AUTO-INCREMENTING.
    //    - only 1 row inserted.
    // (* btw, if "ON DUPLICATE KEY" statement used, it still returns ID of updated duplicate record.)
    //
    // If $q is array, it's preparing with ::prepare_set().
    //     If you would like to update fields ON DUPLICATE primary key, list them as array to $ignore variable (OR as $q[''], as sub-array, if you still would like to use $ignore in regular way).
    //     Values same as used upon insert query should be listed as 1-dimension records, custom records, as 2-dimension.
    //     Example: $q[''] = ['firstname', 'lastname', 'email', 'phone', 'notes', 'update_user' => $user_id, 'update_time' => ['CURRENT_TIMESTAMP', 0]];
    //         * "update_user" and "update_time" are custom values, updated only when record is duplicate. All other fields are the same as used upon insert.
    public static function insert($table, $q,
                                  $ignore = null, // or duplicates... Either: add "IGNORE" to INSERT statement OR don't check for errors (if $ignore === -1) OR specify ARRAY of $duplicates.
                                  $high_priority = null) {
        if (is_array($q)) {
            if (isset($q[''])) { // duplicates are coming in $q[''] as sub-array.
                $duplicates = $q[''];
                unset($q['']);
            }elseif (is_array($ignore)) {
                $duplicates = $ignore;
                $ignore = null;
            }

            $q = 'SET '.static::prepare_set($q, true);
            if (isset($duplicates)) {
                $dup = [];
                $dup_custom = [];
                foreach ($duplicates as $k => &$v) {
                   if (is_int($k)) {
                       $dup[] = "`$v`=VALUES(`$v`)";
                   }else
                       $dup_custom[$k] = $v;
                }
                if (count($dup_custom))
                    $dup = array_merge($dup, static::prepare_set($dup_custom)); // AK: not +, we really need to merge.

                if ($dup = implode(',', $dup))
                    $q.= ' ON DUPLICATE KEY UPDATE '.$dup;
            }
        }

        static::rquery('INSERT'.($high_priority ? ' HIGH_PRIORITY' : '').($ignore && -1 !== $ignore ? ' IGNORE' : '').' INTO `'.$table.'` '.$q, -1 === $ignore);

        // affected_rows can be >1 if duplicate key updated
        return static::$db->affected_rows === 0 ? null : static::$db->insert_id; // (int)::qquery('SELECT LAST_INSERT_ID()').
    }

    // sugar func. Try to avoid it unless $q is an array.
    public static function update($table, $q, $where = null, $ignore = null) { // if $where is integer, we're doing where by "id".
        if (is_array($q))
            $q = static::prepare_set($q, true); // I won't to exclude NULL and empty strings here. If you need to filter something, filter outside with ::prepare_set() and its params.

        if ($q) { // it can be empty. And incoming array can be empty
            if ($where)
                $where = ' WHERE '.(is_numeric($where) ? 'id=' : '').$where;

            return static::rquery('UPDATE '.($ignore && -1 !== $ignore ? 'IGNORE ' : '').'`'.$table.'` SET '.$q.$where, -1 === $ignore);
        }
    }

    // escaping
    public static function esc($q) {
        return static::$db->real_escape_string($q);
    }

    public static function quote($q) { // we can't completely migrate from "esc()" to "quote()". Sometimes we need to escape wildcards, LIKE \"%$keyword%\". Or select where alias="atpl_'.$alias.'"'.
        return '"'.static::$db->real_escape_string($q).'"';
    }

    // "esc()" with trim, strip tabs, limit number of characters, perfect quotes etc.
    public static function esc_tags($s, $maxlen = 0,
                                    $check_empty = false, // try to remove all HTML tags to check wether $s empty
                                    $perfect_quotes = false,
                                    $no_trim = false,
                                    $keep_tabs = false) {
        return self::esc(prepare_esc($s, $maxlen, $check_empty, $perfect_quotes, $no_trim, $keep_tabs)); // Requires "prepare_esc()" from "strings.php".
    }

    // accepts arrays. Escapes each item of array and returns it as single string.
    public static function quote_tags($s, $maxlen = 0,
                                      $check_empty = false, // try to remove all HTML tags to check wether $s empty
                                      $perfect_quotes = false,
                                      $no_trim = false,
                                      $keep_tabs = false,
                                      $in_brackets = false) {
        if (is_array($s)) {
            $r = '';
            foreach ($s as $item) {
                if (!$no_trim)
                    $item = mb_trim($item);

                // RECURSION!
                if ($item && ($item = static::quote_tags($item, $maxlen, $check_empty, $perfect_quotes, true/*no need to trim, we did it already*/, $keep_tabs, $in_brackets)))
                    $r.= $item.',';
            }
            return rtrim($r, ',');
        }

        return self::quote(prepare_esc($s, $maxlen, $check_empty, $perfect_quotes, $no_trim, $keep_tabs), $in_brackets);
    }

    // We skipped $no_trim option here. If we removing tags, then we definitely want to trim result.
    public static function esc_notags($s, $maxlen = 0, $perfect_quotes = false, $allowed_tags = null) {
        return static::esc_tags(strip_tags($s, $allowed_tags), $maxlen, false, $perfect_quotes);
    }

    // accepts arrays. Escapes each item of array and returns it as single string.
    public static function quote_notags($s, $maxlen = 0, $perfect_quotes = false, $allowed_tags = null, $in_brackets = false) {
        if (is_array($s)) {
            $r = '';
            foreach ($s as $item) {
                $item = mb_trim($item); // always trim here

                // RECURSION!
                if ($item && ($item = static::quote_notags($item, $maxlen, $perfect_quotes, $allowed_tags, $in_brackets)))
                    $r.= $item.',';
            }

            return rtrim($r, ',');
        }

        return static::quote_tags(strip_tags($s, $allowed_tags), $maxlen, null/*check_empty*/, $perfect_quotes, false/*no_trim*/, false /*remove tabs & garbage*/, $in_brackets);
    }

    // same as ::esc_notags(pgvar(
    public static function esc_notags_post($var, $maxlen = 0, $def = null, $perfect_quotes = false, $allowed_tags = null) {
        return ($r = (empty($_POST[$var]) ? $def : $_POST[$var])) // yes $def will be processed and escaped too.
            ? static::esc_notags($r, $maxlen, $perfect_quotes, $allowed_tags)
            : $r; // null
    }

    // Escape all values of an array. (All items of row.)
    public static function esc_row(&$arr, $nums_only = 1) { // only rows[number]
        foreach ($arr as $key => &$val)
            if (!$nums_only || is_numeric($key))
                $row[$key] = static::$db->real_escape_string($val);
    }

    // reverse mysql_real_escape_string(). Avoid its usage, use for non-mission critical, quick internal code only.
    public static function unesc($s) {
        static $r = [
            '\\\\' => '\\',
            '\\0' => "\0",
            '\\n' => "\n",
            '\\r' => "\r",
            '\Z' => "\x1a",
            '\'' => '\'',
            '\"' => '"'];
        return strtr($s, $r);
    }

    // Convert strings of array to integer values. CAUTION! (double) and (int) types are different. This func converts to (int) type! If you need (float) use your custom implementation.
    // ATTN! It set 0s for the $field_names even if these fields are not exist in original $arr.
    public static function int_fields(&$arr, $field_names = null) {
        if ($field_names) {
            if (!is_array($field_names))
                $field_names = [$field_names];

            foreach ($field_names as &$name)
                $arr[$name] = isset($arr[$name]) ? (int)$arr[$name] : 0; // We expect them as (int)0 even if they are not set or NULL.
        }else {// convert all
            $arr = array_map('intval', $arr);
        }
    }

    // Convert strings of array to float values.
    // ATTN! It set 0s for the $field_names even if these fields are not exist in original $arr.
    public static function float_fields(&$arr, $field_names = null) {
        if ($field_names) {
            if (!is_array($field_names))
                $field_names = [$field_names];

            foreach ($field_names as &$name)
                $arr[$name] = isset($arr[$name]) ? (float)$arr[$name] : 0; // don't change to (float). (float)1 not equals to (int)1 in PHP. UPD. We expect them as numeric even if they are NULL.
        }else { // convert all
            $arr = array_map('floatval', $arr);
        }
    }

    // return empty string if value is '0' or '0.0' or NULL (float in string representation).
    public static function nullify0(&$arr, $field_names = null) {
        if ($field_names) {
            if (!is_array($field_names))
                $field_names = [$field_names];

            foreach ($field_names as &$name)
                if (empty($arr[$name]) || (0.0 === (float)$arr[$name]))
                    $arr[$name] = '';
        }else { // convert all, each array item
            foreach ($arr as &$v)
                if (0.0 === (float)$v)
                    $v = '';
        }
    }

    public static function unset_fields_by_val(&$arr, $val = null) {
        if (!is_array($val))
            $val = [$val];

        foreach ($val as &$vv)
            foreach ($arr as $k => &$v)
                if ($v === $vv)
                    unset($arr[$k]); // btw, we can't unset $v :(
    }

    // fully prepare string value for insertion, with numberious $options, including real_mysql_escape() at the end.
    public static function prepare_string_value(&$val, array &$options) {
        static $field_types = [
            'char' =>       1,
            'tinytext' =>   255,
            'text' =>       65535,
            'mediumtext' => 16777215,
            'longtext' =>   4294967295,
        ];

        $data_length = empty($options['length'])
            ? (isset($options['type']) && isset($field_types[$options['type']])
                ? $field_types[$options['type']] // auto-choose length
                : false)
            : $options['length'];

        // check_empty. Not the same as 'noempty' where we skip the value if it's empty, do not confuse them.
        if (    isset($options['check_empty']) && !rtrim(str_replace('&nbsp;', '', isset($options['keep_tags']) ? strip_tags($val) : $val)) && // test posting without tags.
                (stripos($s, '></iframe>') === false)) { // iframes (embedded youtubes) is okay.
            $val = '';

        }elseif ($val) {
            // MAIN PRINCIPLE: we want to strip tags and all common garbage by default, unless it's prohibited by options (keep_tags, keep_tabs, no_trim etc).
            // ---------------
            // strip_tags
            if (!isset($options['keep_tags']) && (strlen($val) > 2)) // if not keep ALL tags
                $val = strip_tags($val, isset($options['allow_tags']) ? $options['allow_tags'] : null); // arrays accepted only from PHP v7.4. Don't pass arrays if you're using earlier PHP version.

            if (isset($options['replace']))
                $val = str_replace($options['replace'][0], $options['replace'][1], $val);

            if (isset($options['preg_replace']))
                $val = preg_replace($options['preg_replace'][0], $options['preg_replace'][1], $val); // use /u modifier for unicode!

            // right before finalization, before trimming (if not ascii encoding)
            if (isset($options['charset'])) { // usually $options['charset'] is 'ascii', if we want to strip all non-ascii characters, sanitize URL's, emails, aliases etc
                $val = ($is_ascii = ('ascii' === $options['charset'])) // lowercase only with purpose!
                    // it's faster way to convert into 'ascii' than mb_convert_encoding().
                    // HOWEVER, if you still would liek to use mb_convert_encoding() while converting to ASCII, then use uppercase value, 'ASCII'.
                    ? str_to_ascii($val, !isset($options['no_compact_spaces']), !empty($options['multiline']))
                    // alternative is preg_replace('/[[:^ascii:]]/', '', $str), if we need to remove non-ascii characters, instead of replacing them with "?"
                    : ('utf8' === $options['charset'] // utf8[mb3], not utf8mb4 = no non-characters, no emojis
                        ? strip_utf8_non_characters($val)
                        : mb_convert_encoding($val, $options['charset'])); // some other character set, other than ASCII and UTF8? Weird, but let's try...
            }

            // ...AFTER stripping tags, but BEFORE cropping by maximum length...
            if (!isset($options['no_trim'])) {
                $val = mb_trim($val);
            }

            // if not ASCII
            if (empty($is_ascii)) { // no 'perfect_quotes' are possible if we're need result in ASCII encoding. Perfect quotes are unicode characters.
                // perfect_quotes. If used, it's automatically removes all garbage too. We can't use 'perfect_quotes' and 'keep_tabs' together.
                if (isset($options['perfect_quotes'])) { // requires "strings_perfect.php".
                    // set 'perfect_quotes' to ENGLISH_QUOTES to use “” instead of default cyrillic «».
                    $val = html_perfect_quotes($val, $options['perfect_quotes'], false); // FALSE = no need to trim, it’s done above

                }elseif (!isset($options['keep_tabs']) && (strlen($val) > 1)) { // strip all posted garbage with no doubts
                    $val = strip_tabs($val, !isset($options['no_compact_spaces'])); // remove garbage: tabs, "\r"'s, odd spaces, etc. (BTW this function doesn't trim outer spaces.)
                }
            }

            // ---- finalization ----
            if (isset($options['transform'])) {
                switch ($options['transform']) {
                    case 'uppercase': $val = $is_ascii ? strtoupper($val) : mb_strtoupper($val); break;
                    case 'lowercase': $val = $is_ascii ? strtolower($val) : mb_strtolower($val); break;
                    case 'ucwords': $val = $is_ascii ? ucwords($val) : mb_convert_case($val, MB_CASE_TITLE, 'UTF-8'); break;
                }
            }

            if (isset($options['no_last_dot'])) { // good for titles, names and job positions
                $val = remove_last_dot($val, true); // TRUE = convert tripple dots into ellipsis character (it's not ASCII here). And don't worry, dots will not be removed if there some other dots in the middle of string.
            }

            if (isset($options['fix'])) {
                if ('url' === $options['fix']) {
                    $val = fix_url($val);
                }
                // TODO: allow to specify array of fixes first.
                //if ($options['fix'] === 'urlencode') // AK 2023-01-15: this feature could be good, but not used yet.
                //    $val = urlencode($val);
            }

            // post-processing (NOTE: length)
            if (isset($options['call']) && is_callable($options['call'])) {
                $val = $options['call']($val); // legacy way is call_user_func($options['call'], $val);
            }

            // substr to maximum length (after custom CALL)
            if ($data_length && ($data_length < mb_strlen($val))) {
                $val = rtrim($is_ascii
                                ? substr($val, 0, $data_length)
                                : mb_substr($val, 0, $data_length));
            }

            // the very final step
            if (($val === '') && isset($options['default'])) {
                $val = $options['default'];
            }

            // escape at the end, after all processings...
            $val = self::esc($val);
        }

        if (!isset($options['no_quote']))
            $val = '"'.$val.'"';
    }

    /* Prepare array (or set of characters in string, like ::prepare_set('ABC', true)) for INSERT or UPDATE query.
       Returns either array or comma separated string (if $comma_separate is TRUE).
       Incoming $arr value can be either array or string.
           * If string -- it considered as 1 dimensional array of characters. All characters will be quoted and returned as comma-separated string (if $comma_separate is TRUE).
           * If array, then:
                 * 1-dimensional arrays: [value, value, value]. Each value will be quoted and returned as comma-separated string (if $comma_separate is TRUE).
                 * 2-dimensional arrays: [key => value]).
                 * 3-dimensional arrays: [key => [value, maxlength, ...other options...]]. Should be used to limit string length, to specify maximum length of each particular string.
             NOTE: the 'value' can be array. So we'll get value from $value[$key]. It's possible, but do prefer straightforward values w/o arrays, to avoid passing of entire array contents.
             Special usage cases:
                 * MySQL expressions, like CURRENT_TIMESTAMP shouldn't be quoted. Use as:
                   [update_time => ['CURRENT_TIMESTAMP', 'no_quote']] // put value without quotes!

       $exclude_keys can be used to exclude some keys/values from array. For example, when you preparing data for "ON DUPLICATE KEY UPDATE", you should exclude primary key(s) from the updating set.
       $exclude_keys can be either single string value or 1-dimensional array.

       Examples:
           'email' => [$email, 64], // <-- 'email' value will be trimmed to 64 characters.
           'department' => [,120],  // <-- value not specified (null), so we checking $_POST['department']. It's being trimmed to 120 characters.
     */
    public static function prepare_set($arr, $comma_separate = null, $exclude_arr_keys = null, $exclude_arr_vals = null) {
        $set = [];
        if ($arr) {
            if (is_array($arr)) {
                foreach ($arr as $k => $v) {

                    if (null !== $exclude_arr_keys) { // '' is ok to exclude empty keys
                        if (is_array($exclude_arr_keys)) {
                            foreach ($exclude_arr_keys as $ex_key)
                                if ($k === $ex_key)
                                    continue 2; // break this level and continue upper level.

                        }elseif ($k === $exclude_arr_keys)
                            continue;
                    }

                    if (null !== $exclude_arr_vals) { // '' is ok to exclude empty values
                        if (is_array($exclude_arr_vals)) {
                            foreach ($exclude_arr_vals as $ex_key)
                                if ($v === $ex_key)
                                    continue 2; // break this level and continue upper level.

                        }elseif ($v === $exclude_arr_vals)
                            continue;
                    }


                    if (is_int($k)) { // 1-dimensional array w/o keys! values only!
                        $set[] = '"'.self::esc($v).'"'; // BTW, use array_map('intval', $arr) to quickly "escape" arrays with all integer values

                    }else {
                        if (is_array($v)) {
                            /* * By default we TRIMMING + stripping all HTML tags when we using array to specify value. (And if 'keep_tags' option not specified.)
                               Keys:
                                 [0] = value. If it's array, we're getting $array[$k] as value.
                                 [1] = maximum length OR 0/false, do NOT process anything else, put value as is, without trimming, quotes or anything else.
                                 [2 (if callable) || 'call'] = post-processing function
                                 ['no_quote'] = don't take value in quotes. We shouldn't escape mySQL expressions like CURRENT_TIMESTAMP, NOW() and other functions or field names.
                                 ['no_trim'] = don't trim, keep spaces at beginning and at the end.
                                 ['keep_tabs'] = don't strip garbage characters like tabs and duplicate spaces.
                                 ['keep_tags'] = don't strip HTML tags, keep them.
                                 ['allowed_tags'] = (string) tags to keep when stripping tags. Exceptions.
                                 ['perfect_quotes'] = (string) tags to keep when stripping tags. Exceptions.
                                 ['check_empty'] = don't allow to submit empty text, like "<p>&nbsp;</p>". However <iframe>'s are allowed.
                                     ...etc... see the full list of available options in prepare_string_value().

                                 Usage example:
                                     $vars = ['name'      => [$post_users, 80, ucwords], // callable func name
                                              'email'      => [$post_users, 64],
                                              'password'   => make_user_password($_POST['password'])
                                             ];
                                     mdb::insert($table_name, $vars) / mdb::update($table_name, $vars)
                                     ... or direct query ...
                                     mdb::rquery('INSERT/UPDATE `table_name` SET '.mdb::prepare_set($vars, true));
                            */
                            if (is_null($val = $v[0]) && isset($_POST[$k])) {
                                $val = $_POST[$k];
                            }elseif (isset($val[$k])) {
                                $val = $val[$k];
                            }// else $val = $v[0]

                            // process it? If not empty...
                            if ($val) {
                                if (!isset($v[1]) || $v[1]) { // if [1] is set BUT it's 0 or FALSE -- put the value as-is without quotes or anything else.
                                                              // we shouldn't escape expressions, like CURRENT_TIMESTAMP, NOW() and other mySQL functions or field names.
                                    if (isset($v[1])) { // && !isset($v['length']))
                                        $v['length'] = $v[1];

                                        if (isset($v[2]) && is_callable($v[2])) // && !isset($v['call']))
                                            $v['call'] = $v[2];
                                    }

                                    static::prepare_string_value($val, $v);
                                } // ...else publish as is. We shouldn't escape expressions, like CURRENT_TIMESTAMP, NOW() and other mySQL functions or field names.
                            }else {
                                $val = null === $v ? 'NULL' : '""'; // we avoiding NULL's, but can set empty value.
                            }

                        }else { // if we are here, then $v is just plain string/number which doesn't needs processing
                            $val = null === $v ? 'NULL' : self::quote($v); // normal value of 2-dimensional array with [key => value].
                        }

                        $set[] = '`'.$k.'`='.$val; // $k shouldn't contain ` chars.
                    }
                }
            }else { // string, considered as set of characters
                $len = strlen($arr);
                for ($i = 0; $i < $len; ++$i)
                    $set[] = '"'.$arr[$i].'"'; // no need to escape single characters. However (") not allowed.
            }
        }

        return $comma_separate ? implode(',', $set) : $set;
    }

    /* Find the same record within an array.
       Sometimes DB structure itself allows duplicate record (no indices that make the record 100% unique, the DUPLICATE KEY tactic doesn't works), but we really need to hold only unique records.
       This function prepares the SQL query to find the same record with the same fields, excluding some specific keys (field names).
            * Good to be used before ::insert() query.
            * Use table 'indices' instead, whenever it's possible, instead of this method.

        ATTN on $date_feilds!
            It's okay to specify empty string ("") when the record is inserted, then default value is used (most often 0000-00-00 00:00:00, but not necessarily, depends on MySQL configuration!)
            So please do not compare empty date fields. Convert date fields to the meaningful default values, at least 0 before comparing them (if mySQL config allow comparison of INT with DATETIME).
            EG: foreach (['date_field_one', 'date_field_two'] as $field) { if ('' === $spot[$field]) $spot[$field] = 0; }
     */
    public static function prepare_query_find_duplicate(array &$row, array $exclude_keys) {
        $params = [];
        foreach ($row as $key => &$val) {
            if (!in_array($key, $exclude_keys)) {
                $params[] = "`$key`=\"".static::esc($val).'"';
            }
        }
        return implode(' AND ', $params);
    }

    /* Examples:
       // to delete/undelete

         mdb::assign_ids('invoice_campaign_winner', ['id' => $id, 'item' => $_POST['campaign_items']], false, 'int');
         mdb::assign_ids('article_press_mention', ['id' => $id, 'item' => $_POST['mentioned_partners'], 'ref_type' => 'P'], false, 'int');

       // to activate/deactivate/reactivate

         mdb::assign_ids('user_client', ['id' => $user_id, 'client' => $arr], ['bind_time' => 'CURRENT_TIMESTAMP', 'bind_user' => $uid], 'int',
            'unbind_time=CURRENT_TIMESTAMP, unbind_user='.$uid.', active="N"',
            'active="Y"');

       Notes:
         (!) Table should have primary index and don't allow duplicate entries.
         (!) Only 1 incoming data field can be an array.
         All incoming data values can be either numerical OR string types. But string values can be additionally mysql_escaped, if $esc_values == TRUE.
         It's hard to debug, it's ignores possible mySQL errors.

       See also: FAVOR's update_expert_categories(), as much more complicated case we cannot achieve here.
     */
    public static function assign_ids($table_name,
            $data, // $data and $more_data must be arrays. Format: ['id_field' => $val, 'data_field' => [$ARRAY_of_values]].
                   //     IMPORTANT! If you'd like to CLEAR data (delete all indexes) -- provide empty array ([]), but don't set FALSE. Only an array, even empty, required to identify indexes.
                   // All fields here used to identify certain record. If you would like to provide some
                   // additional data that doesn't affects on index and doesn't helps to identify record -- provide it in $more_data parameter.
            $more_data = null, // Additional data that must be assigned to each record with each ID (specified in 'data_field'). First introduced in Nestpt/Theranotes.
                                // $more_data specifies some additional data, that not affects on indexations and don't help to identify the record. Not used to filter/delete dropped records.
            $esc_values = null, // escape and trim values provided with $data. Not only ID's, *all* input data. Negative value (eg -1) still escaping data, but NOT TRIMMING strings.
                                 // Special values:
                                 //    2 = escape non-ID values, but convert all IDs to integer (better than just escape). Negative, -2 is the same, but escaped strings not trimmed.
                                 //    'i' or 'int' = don't escape non-ID values, but convert all IDs to integers.
            $deactivate_query = null, // 'unbind_time=CURRENT_TIMESTAMP, unbind_user=$uid, active="N"'. (No escapes, no error checking. Please provide secure data!)
            $reactivate_query = null, // [ON DUPLICATE KEY ]'active="Y"'. (No escapes, no error checking. Please provide secure data!)
            $priority_field = null) {  // field to save order of records (keys of values provided in "data" array). Must be `quoted`/escaped previously if required. No error checking!

        if ($esc_values) {
            $i = is_string($esc_values) && ('i' !== $esc_values[0]);
            $do_int_ids = $i || (is_int($esc_values) && (2 === abs($esc_values)));
            if ($i) $esc_value = false;
        }else
            $do_int_ids = false;

        $query_del = '';
        $query_ins = '';

        // find an array with assigned ids
        $ids_field = false;
        foreach ($data as $field => $val) {
            // collecting delete/update rules
            if (is_array($val)) { // AUTO-DETECTION of FIELD with ARRAY of IDS!
                if (!$ids_field) // otherwise -- skip it. Currently we can have only 1 array. It's error?
                    $ids_field = $field;

            }else { // Primary non-ID fields, that allow to identify the record within the table.
                $data[$field] = $val = '"'.($esc_values
                    ? self::esc($esc_values > 0 ? mb_trim($val) : $val)
                    : $val).'"';

                $query_del.= "`$field`=$val AND ";
            }

            // collecting insert fields: (field1, field2, ...)
            $query_ins.= "`$field`,"; // primary data fields, that allows to identify record. (To allow to UPDATE or DELETE it.)
        }

        if ($ids_field) { // otherwise -- nothing to do. Field with IDs must be auto-detected in the loop above.

            if ($priority_field)
                $query_ins.= $priority_field.',';

            if ($more_data) // it just must be an array. No error checking.
                foreach ($more_data as $field => $val) {
                    // pre-quote & escape these values too...
                    $more_data[$field] = '"'.($esc_values
                        ? self::esc($esc_values > 0 ? mb_trim($val) : $val)
                        : $val).'"';

                   // continue collecting insert fields: (field1, field2, ...) But don't include them for deletion (no $query_del).
                    $query_ins.= "`$field`,"; // additional fields, with miscellaneous data, that doesn't identify the record.
                }

            $ins_vals = '';
            $keep_vals = '';
            foreach ($data[$ids_field] as $order => $aid) {
                if ($esc_values || $do_int_ids) { // escape IDs
                    $aid = $do_int_ids
                        ? (int)$aid
                        : self::quote($esc_values > 0 ? mb_trim($aid) : $aid);
                }
                $keep_vals.= $aid.',';

                $ln = '';
                foreach ($data as $field => $val) {
                    $ln.= ($field === $ids_field ? $aid : $val).','; // Both $aid and $val already in quotes or have (int) type.
                    if ($priority_field && ($field === $ids_field))
                        $ln.= $order.',';
                }

                if ($more_data)
                    $ln.= implode(',', $more_data);

                $ins_vals.= '('.rtrim($ln, ',').'),';
            }

            if ($keep_vals && !$priority_field) { // this doesn't works when we use priority. Maybe we want to reset our priority.
                $query_del.= $ids_field.' NOT IN ('.substr($keep_vals, 0, -1).')';
            }else
                $query_del = substr($query_del, 0, -5); // strip ' AND '

            // What we're doing with dropped records. Deactivate or Delete?
            static::rquery(($deactivate_query
                    ? "UPDATE `$table_name` SET $deactivate_query"
                    : "DELETE IGNORE FROM `$table_name`").
                ' WHERE '.$query_del);

            if ($ins_vals)
                static::rquery('INSERT IGNORE INTO `'.$table_name.'` ('.substr($query_ins, 0, -1).') VALUES'.substr($ins_vals, 0, -1). // strip ','
                    ($reactivate_query ? ' ON DUPLICATE KEY UPDATE '.$reactivate_query : ''));
        }
    }


    // -------------- NOT SQL -----------------

    // same as FROM_UNIXTIME($_SERVER[REQUEST_TIME]), but PHP should be faster.
    public static function request_timestamp() {
        static $MEM;
        return isset($MEM) ? $MEM : ($MEM = '"'.date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']).'"'); // with quotes already. (Keep PHP5 syntax!)
    }

    // export data to CSV/JSON/XML file. (Perhaps for further caching and redirection into that cached file.)
    // NOTE: data format is auto-detected by file extension. So always use proper extension, either csv, json or xml.
    // Some options can be tweak via global vars:
    //   $csv_delimiter: default is ','
    //   $csv_enclosure: default is '"'
    // Returns the number of bytes written into $filename.
    public static function export_array(string $filename, array &$arr) { // filename is relative to __ROOT__. If not specified or any non-string value, it's static::cached_stores_json().
        // WARNING! We don't waste time to check whether filename specified! It must be specified, or it dies.
        global $csv_delimiter, $csv_enclosure;

        $file_ext = file_ext($filename, 1);

        /* TODO: I would love to add gzip-compression for resulting JSON, but pre-compressed GZIP files not supported in ONE.com :(
           If you will ever decide to pre-compress it -- check out gzip_file() in __DIR__/minifier.php
         */

        // 1. If folder doesn't exists, it will be auto-created!
        // 2. If json_encode() produce empty string -- fix strings encoding outside, not here!! See how to fix it: https://stackoverflow.com/questions/19361282/why-would-json-encode-return-an-empty-string
        return write_file($filename,
            'json' === $file_ext
                ? json_encode($arr, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)
                : decode_html_entities($arr), // convert &nbsp;'s to spaces in all strings of array.
            'w',
            $csv_delimiter ? $csv_delimiter : ',',
            $csv_enclosure ? $csv_enclosure : '"');
    }

    public static function export_array_sitemap(string $filename, // If filename not specified -- we print XML as output (with Content-Type: text/xml header) then exit.
                             array &$arr, // array of URLs OR url can be retrieved from the array item with the further $url_callback function.
                                          // default format of array items is [$url, $time_modified, $priority], but this is also can be associative array, where timestamp field has a name (identified by $timestamp_field_name).
                             callable $url_callback = null,  // function($item, $lang) -- should return URL to item in specific language section
                             $timestamp_field_name = null, // used only for XML output (for site map?). So we can either get timestamp from some field of objects of the array OR use current timestamp if field not specified.
                             array $languages = null) { // array with URL in alternative languages

        if (null === $languages) {
            global $site_languages;
            if (1 < count($site_languages)) {
                $languages = $site_languages;
            }
        }

        static $time_format = "Y-m-d\TH:i:s+00:00";
        $updated = gmdate($time_format, $_SERVER['REQUEST_TIME']); // convert Unix timestamp to GMT time (+00:00). Use today's time, if field not specified

        $out = '';
        foreach ($arr as &$item) {
            // array of [url, time_modified, priority] is default supported format. Priority and/or time_modified can be skipped.
            $is_array_item = is_array($item);
            $priority = !$is_array_item || empty($item[2]) ? '0.80' : $item[2];
            $mod = !$is_array_item || empty($item[1])
                ? ($timestamp_field_name && !empty($item[$timestamp_field_name]) ? gmdate($time_format, strtotime($item[$timestamp_field_name])) : $updated)
                : gmdate($time_format, strtotime($item[1])); // convert to GMT/UTC timezone in either case, even if original item format is correct.

            if ($languages) {
                $alter_links = '';
                foreach ($languages as $lng => &$lng_info) {
                    $url = $url_callback
                        ? $url_callback($item, $lng)
                        : ($is_array_item ? $item[0] : $item);

                    $alter_links.= <<<END
  <xhtml:link
              rel="alternate"
              hreflang="$lng_info[0]"
              href="$url" />

END;
                }

                foreach ($languages as $lng => &$lng_info) {
                    $loc = $url_callback
                        ? $url_callback($item, $lng)
                        : ($is_array_item ? $item[0] : $item);

                    $out.= <<<END
<url>
  <loc>$loc</loc>
$alter_links
  <lastmod>$mod</lastmod>
  <priority>$priority</priority>
</url>

END;
                }
            }else {
                $loc = $url_callback
                    ? $url_callback($item, $lng)
                    : ($is_array_item ? $item[0] : $item);

                $out.= <<<END
<url>
  <loc>$loc</loc>
  <lastmod>$mod</lastmod>
  <priority>$priority</priority>
</url>

END;
            }
        }

        $out = <<<END
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
  xmlns:xhtml="http://www.w3.org/1999/xhtml">
$out</urlset>
END;

         if ($filename)
            return write_file($filename, $out);

        // no $filename
        if (!headers_sent())
            header('Content-Type: text/xml');

        mdb::quit($out); // no need to save it. Just output
    }


    // Disconnect from database, optionally display some message with HTTP response code, then exit.
    public static function quit($str = null, $http_code = null) {
        static::disconnect();
        if (null !== $http_code) http_response_code($http_code);
        if (null !== $str) echo $str; // numeric 0 is good value to return
        exit;
    }

    public static function redirect($url = null, $nofullurl = null, $permanent = null) {
        global $site_url, $lang_url, $lang;

        static::disconnect();
        if (!$nofullurl && (!$url || strpos($url, 'http') !== 0)) // don't change http! It can be both "http" and "https". both or either.
            $url = ((strpos($url, '/'.$lang.'/') === 0) ? $site_url : $site_url.$lang_url).$url;

        if ($permanent) {
            http_response_code(301);
        }
/*
        if (headers_sent()) { // actually this should never happen.
            $url = str_replace('&amp;', '&', $url);
            echo <<<END
<script>
// <![CDATA[
location.href="$url"
// ]]>
</script>

END;
        }else
 */
        header('Location: '.str_replace('&amp;', '&', $url));
        exit;
    }


    // The following is not part of MDB and not even related to db. We just using its namespace.
    // =========================================================================================

    // converts minutes to string in "[+/-]XX:XX" format, used for CONVERT_TZ() function of mySQL.
    // NOTE: in many cases it's easier to just get a regular timestamp (in server time), then convert it to GMT/UTC timezone with standard PHP function gmdate($format, $timestamp).
    //       ...or convert the timestamp to the proper timezone with fixtime() from strings.php.
    public static function timezone_offset($tz = null) { // $tz is minutes. If not speicified, global $MY_tz used. If true, current server's timezone is used. -1 = GMT/UTC.
        if (-1 === $tz) return '+00:00';

        if (null === $tz) {
            global $MY_tz;
            $tz = $MY_tz;

        }elseif (true === $tz) {
            $tz = (int)date('Z') / 60; // Alternative is: timezone_offset_get(timezone_open(date_default_timezone_get()), date_create('now', timezone_open('UTC'))) / 60;
        }

        $abs_tz = abs($tz);
        if (10 > $hr = floor($abs_tz / 60)) {
            $hr = '0'.$hr;
        }
        if (10 > $mn = floor($abs_tz % 60)) {
            $mn = '0'.$mn;
        }

        return (0 > $tz ? '-' : '+').$hr.':'.$mn;
    }

    // uAJAX-compatible AlertifyJS alerts from backend
    public static function out_umbox(&$out, $msg, $icon = null, $title = null, $exit = null) {
        $out['>'] = 'umbox("'.str_replace('"', '\\"', $msg).'", {icon: "'.$icon.'", title: "'.str_replace('"', '\\"', $title).'"})';
        if ($exit) // output immediately
            mdb::quit(json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
    }

    public static function throw_umbox($msg, $icon = null, $title = null) {
        static::out_umbox($out, $msg, $icon, $title, true); // true = exit
    }

    public static function throw_db_error($error) {
        static::throw_umbox('<div class="mb-2"><b>Database error</b></div>'.$error, 'bug-db'); // Don't worry about translation. Anyway error message is in English.
    }
}


// Some data-table
class mdb_data { // AK: I don't want to assign mdb_data to some single database. I want it to be variable and plugable.
    public static $db = 'mdb'; // the name of static class used to access database. Just REPLACE this variable to connect to another provider

    public static function db() {
        return static::$db; // link to mdb::
    }

    protected static function field_name(array &$fieldset, $key) {
        // Each field in set may have its name that different from the array key. If 'name' property not found -- the plain key name will be used.
        return isset($fieldset[$key]['name']) ? $fieldset[$key]['name'] : $key;
    }

    public static function updated_by(array &$fieldset,
                                    $action = 'update', // "update" (default) or "create" or block or whatever.
                                    $time = true) { // boolean TRUE === CURRENT_TIMESTAMP. FALSE/NULL -- don't update the time.
        global $uid;
        return ($time ? static::field_name($fieldset, $action.'_time').'='.(true === $time ? 'CURRENT_TIMESTAMP' : $time).',' : '').
               static::field_name($fieldset, $action.'_user').'='.$uid; // this works even if $uid not specified (robots)
    }

    // prepare single field, identified by $field_key.
    protected static function prepare_field(array &$data_arr, // incoming data. Usually $_POST.
                                            array &$field,    // single field of the $fieldset (used in ::build_update_query())
                                            string $field_key,
                                            array $settings = [] // see build_update_query for the list of valid settings.
                                           ) { // returns prepared and properly escaped SQL assignment, `key`="value".

        if ($field_key = static::field_name($field, $field_key)) {
            $db = static::$db;
            $incoming_field_prefix = isset($settings['form_field_prefix']) ? $settings['form_field_prefix'] : '';
            $incoming_field_key = $incoming_field_prefix.$field_key;

            if (isset($field['noempty']) && ('' === $data_arr[$incoming_field_key])) { // if this filed is empty (eg password) -- we don't changing it, leave it as is.
                return ''; // no change, no update
            }
            $field_type = isset($field['type']) ? $field['type'] : false; // default (false) is string/char.
            $is_point = 'point' === $field_type;

            if (!$has_data_val = isset($data_arr[$incoming_field_key]) || // no data? or POINT value
                                 ($is_point && isset($data_arr[$incoming_field_prefix.$field['lng']]) && isset($data_arr[$incoming_field_prefix.$field['lat']]))) {

                // set $val only if we have any default. Otherwise -- do not set.
                if (isset($field['new_default']) && (isset($settings['new_defaults_only']) || (isset($settings['action']) && 'create' === $settings['action']))) {
                    $val = $field['new_default'];

                }elseif (isset($field['default'])) {
                    $val = $field['default'];
                }
            }

            if (!isset($val)) {
                if (in_array($field_type, ['bool', 'boolint'])) {

                    $val = isset($data_arr[$incoming_field_key]) && ($val = $data_arr[$incoming_field_key]) && ('0' !== $val) && ('N' !== $val);
                    $val = 'bool' === $field_type
                                ? ($val ? '"Y"' : '"N"')
                                : ($val ? 1 : 0);

                }elseif ($has_data_val) {
                    if ($is_point) {
                        // we don't checking existance of $field['lng']. It must be set if type is 'point'.
                        $val = 'POINT('.(float)$data_arr[$incoming_field_prefix.$field['lng']].','.
                                        (float)$data_arr[$incoming_field_prefix.$field['lat']].')';
                    }else {
                        $val = $data_arr[$incoming_field_key];

                        // NULL allowed? 'null' is true?
                        if ('' === $val && !empty($field['null'])) { // don't check as empty($val). 0 considered as empty().
                            $val = 'NULL';

                        }elseif ('int' === $field_type) {
                            $val = (int)$val; // no ""

                        }elseif ('float' === $field_type) {
                            $val = (float)$val; // no ""

                        }elseif ('date' === $field_type || 'datetime' === $field_type) {
                            if ('' === $val) {
                                $val = '0'; // same as '0000-00-00'
                            }elseif ('CURRENT_TIMESTAMP' !== $val) { // don't pass other mySQL statements here. This is only for values from forms.
                                $val = $db::quote($val);
                            }

                        }else { // (string) types
                            $db::prepare_string_value($val, $field);
                        }
                    }
                }else
                    return false; // no update

            // isset($val)
            }elseif (!$is_point && is_string($val) && !isset($field['no_quote'])) { // It's extra-dangerous to use 'no_quote' option! AVOID THIS!
                $val = $db::quote($val);
            }

            return "`$field_key`=$val";
        }
    }

    // This func enumerates field names specified in the $fieldset, assigning the data, specified in $data_arr.
    // can be used for insert too (AK: use case is builtdental.com)
    public static function build_update_query(array &$data_arr, array &$fieldset, array $settings = []) {
        /* Valid $settings are:
               'is_admin': boolean value. TRUE = allow modifying fields with negative 'noupdate' flag.

               'action': either "create" (if $data_arr contains field "id" and it's "0") or "update" (by default, if there is no field "id" OR "id" is not 0), AND if not specified explicitly.
                         So if action is "create", we preparing values for "create_user" and "create_time", if appropriate fields found in the $fieldset.
                         If action is "update", we preparing values for "update_user" and "update_time" fields, if appropriate fields found in the $fieldset.
                         If you just don't want to automatically update anything, explicitly set "action" to FALSE/NULL.

               'new_defaults_only': set value ONLY IF we have a "new_default" value specified for the field.
                                    Other fields than those which have 'new_default' value will be skipped.
                                    ** hint. We automatically detect whether this is new record ("create"), or existing ("update"). See 'action'.
               'form_field_prefix': all form fields are coming with specified prefix. Database fields are as described in $field array.
         */

        // If action is not specified explicitly, then it's either "create_" or "update_". If you don't want ANY action, set it to NULL or FALSE or empty string.
        if (!isset($settings['action'])) { // default action if not set, auto-detect it by "id" in the incoming $data_arr.
            $is_new = !isset($fieldset['id']) || !isset($data_arr['id']) || !(int)$data_arr['id'];
            $settings['action'] = $action = $is_new ? 'create' : 'update';
        }else {
            $is_new = 'create' === $action;  // ** action can be not only "create_" or "update_". It may be "approve_", for example.
        }                                    // But you need to have appropriate field in db table, like "approve_time" and "approve_user".

        $_settings = $settings; // keep original settings, to override incoming from $fieldset

        $query = '';
        foreach ($fieldset as $key => &$field) {
            if ('%settings%' === $key) {
                $_settings = $field + $settings; // array_merge(): $settings specified as argument are more important and may override local settings sepecified in the $fieldset.

            // We should not update "id" field, this can be UNSAFE.
            // CAUTION! Never let unprivileged users to update their ID's, levels and other sensitive and critical information, marked with 'noupdate'!
            }elseif ((empty($field['noupdate']) || (!empty($settings['is_admin']) && (0 > $field['noupdate']))) // Negative 'noupdate' are allowed to update for site admins, identified with $is_admin argument. TRUE not allowed to anyone.

                        // set value ONLY IF we have a "new_default" value specified for the field.
                        && (!isset($settings['new_defaults_only']) || isset($field['new_default']))) {

                $key = static::field_name($field, $key);
                // AK: no problem if $data_arr[$key] is not set! We decide how to deal it in the prepare_field(). Boolean values set to 0/N (FALSE) if no default or new_default.
                if ($i = static::prepare_field($data_arr, $field, $key, $_settings)) {
                    if ('' !== $query) $query.= ',';
                    $query.= $i;
                }
            }
        }

        // append information who created or updated the record
        // ...but only if we have appropriate fields in the $fieldset...
        if ($action && (isset($fieldset[$action.'_user']))) {
            if ('' !== $query) $query.= ',';
            $query.= static::updated_by($fieldset, $action, isset($fieldset[$action.'_time'])); // boolean TRUE will set CURRENT_TIMESTAMP. FALSE -- will leave it unchanged.
        }

        return $query;
    }

    // Abstract method. Must be overriden.
    public static function build_query_info($query_addons = null) {
          /* Array MUST contain keys: "field", "from" + optionally can contain "join", "where" and "order".
             Additional options are:
               high_priority: (self describing for mySQL query)

             WARNING! avoid retrieving of user email's here. Use get_user_email[s]() for that.
           */
        return 'SELECT '.(isset($query_addons['high_priority']) ? 'HIGH_PRIORITY ' : '').$query_addons['field'].
           ' FROM '.$query_addons['from']. // multiple "from" allowed, but they all must be properly escaped.
           (isset($query_addons['join']) ? ' '.$query_addons['join'] : '').
           (isset($query_addons['where']) ? ' WHERE '.$query_addons['where'] : '').
           (isset($query_addons['order']) ? ' ORDER BY '.$query_addons['order'] : '');
    }

    // Returns the comma-separated list of ALL fields described in table.
    public static function list_fields(array &$fieldset,
                                       $prefix_table_name = null,
                                       $include_noupdate = null) {
        $list = '';
        foreach ($fieldset as $key => &$field) {
            if (!isset($field['noupdate']) || $include_noupdate) // CAUTION! Never let unprivileged users to update their ID's, levels and other sensitive and critical information!
                $list.= ($prefix_table_name ? $prefix_table_name.'.' : '').static::field_name($field, $key).',';
        }
        return substr($list, 0, -1);
    }

    // Remove from some $data_array ALL fields (identified by $fieldset), except maybe marked as "noupdate".
    public static function unset_fields(array &$data_arr, array &$fieldset, $include_noupdate = null, $except_arr = null) {
        foreach ($fieldset as $key => &$field) {
            $key = static::field_name($field, $key);
            if (    isset($data_arr[$key]) &&
                    (!isset($field['noupdate']) || $include_noupdate) &&
                    (!$except_arr || !in_array($key, $except_arr)))
                unset($data_arr[$key]);
        }
    }

    // (common utility)
    // Add prefix to all keys of array, keeping the order of keys.
    public static function add_key_prefix(array &$arr, string $prefix) {
        $new_arr = [];
        foreach ($arr as $key => &$val)
            $new_arr[$prefix.$key] = $val;

        return $new_arr;
    }
}


// global vars for auto-connect
global $db_access;
// auto-connect
if (!empty($db_access['auto']))
    mdb::connect($db_access);
// AK I also thought to register_shutdown_function() to gracefully mdb::disconnect() when the script finishes, but it gracefully closes connection already, within mysqli object: https://stackoverflow.com/questions/22354365/does-php-close-a-mysqli-connection-on-an-fatal-error


/* // AK: if you will ever need mysql_real_escape() without active connection.
function mysql_escape_mimic($inp) {
    return is_array($inp)
        ? array_map(__METHOD__, $inp)
        : (!empty($inp) && is_string($inp)
            ? str_replace(['\\', "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $inp)
            : $inp);
}
*/