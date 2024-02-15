<?php // only canonical cross-project utilitiles from AK's toolbox.
if (isset($GLOBALS['lang']))
    require(__DIR__.'/../lang/strings_'.$GLOBALS['lang'].'.php');

// ============================
// LOGIC
// проверяем есть ли поле в массиве, и какое там булевское значение. 0 или N = FALSE, остальное считаем TRUE.
function bool_opt($arr, $field, $def = null) {
    return $def
        // TRUE by default
        ? empty($arr[$field]) || (strtoupper($arr[$field]) !== 'N')
        // FALSE by default
        : !empty($arr[$field]) && (strtoupper($arr[$field]) !== 'N');
}

// Есть ли индекс в массиве. Если нет –– берём по умолчанию. (Используется в сообщениях о привязке к компании.)
function ifset($arr, $index, $def = null) { // если $index нет, мы попробуем взять $def. Если $def нет или '' или 0, мы попробуем взять $arr['0'].
    return empty($arr[$index])
        ? (isset($arr[$def]) ? $arr[$def] : $def)
        : $arr[$index];
}

function if0($val, $def) {
    return $val ? $val : $def;
}


// ============================
// ENCRYPTION
// require GMP library: https://www.php.net/manual/en/book.gmp.php
// convert decimal integer to base 64 integer (actually string with all alphanumeric ANSII characters: /0-9A-Za-z/)
function int62($int) { // aka base62_encode(integer)
    return gmp_strval(gmp_init((int)$int, 10), 62);
}

function unint62($str) { // aka base62_decode(62-base integer)
    return $str ? (int)gmp_strval(gmp_init($str, 62), 10) : 0;
}


// ============================
// STRINGS
// A hack which allows using functions within heredoc syntax. Eg: exit(<<<END
//     <div>$db->querynum queries, {$GLOBALS['heredoc'](sprintf('%01.4f', getmicrotime() - TIME_START))} seconds.</div>
// END
// );
function heredoc($param) {
    return $param;
}
$GLOBALS['heredoc'] = 'heredoc';


// AK: Regular trim() don't trim unicode &nbsp;'s (xC2xA0).
// Secure any input, especially that comes with POST and GET before any mySQL queries. Because ASCII/Latin1 data fields don't accept unicode and error would happen.
// See also strip_tabs();
function mb_trim($str, $chars = null, $add_to_space = null) {
    if (!$chars || $add_to_space)
        $chars = '(\\s|\\xC2\\xA0'.($chars ? '|'.$chars : '').')'; // \\xC2\\xA0 is the same as [\t\x{00A0}]/u

    return preg_replace("/^$chars+|$chars+$/", '', $str);
}

function nowrap_end($s, $n = false) { // make non-wrapable end of the string if it contains less than N characters (default 24)
/* test.php
require('./inc/defs.php');
require(__DIR__.'/strings.php');
$test = [
  'Burgemeester Jozef Nolfplein 1',
  'Gemeentehuis, Alfred Amelotstraat 53',
  'Hoedemakerssquare 10, 1140 Evere',
];
for ($i = 0; $i < count($test); ++$i)
  echo nowrap_end($test[$i])."<br />\n";
 */
    $l = strlen($s);
    if (!$n) $n = 20; // was l > 36 ? 18 : 12 before leaving the first space in trailing text as is. Later 20 : 14 was good. Let's see how it works when just 20, but we watching for the 1st space.
    if ($l > $n) {
        $l-= $n;

        $end = substr($s, $l);
        if ((($first_space = strpos($end, ' ')) !== false) && ($first_space < 7)) { // was 6
            ++$first_space;
            $end = substr($end, 0, $first_space).str_replace(' ', '&nbsp;', substr($end, $first_space));
        }else
            $end = str_replace(' ', '&nbsp;', $end);

        $s = substr($s, 0, $l).preg_replace('/([.,;!?\-\)\]])&nbsp;?/', '$1 ', $end);
    }

    return $s;
}

// non-wrappable space between non-digits and digits. "street 000, apt. 0" => "street&nbsp;000, apt.&nbsp;0". Use *BEFORE* nowrap_end().
function nowrap_digits_in_addr($s) {
    return preg_replace('/(?<=[^\d\s.,;!?\-\)\]]) (?=\d)/', '&nbsp;', $s);
}

// LEGACY: put last 2 words together without word-wrapping
// (AK: I just hate when the last line contains only 1 word.)
function unbreak_last_words($s, $max_block_length = false) {
    return preg_replace_callback('/(\S+?)\s+?(\S+?)$/', function($m) use($max_block_length) {
        if (!$max_block_length) $max_block_length = 40; // default
        return mb_strlen($m[0]) > $max_block_length
            ? $m[0]
            : $m[1].'&nbsp;'.$m[2];
    }, $s);
}

/* Translates "some long string" to "some long <span class="some-class">string</span>".
   Originally used to add "outlink.svg" icon only to the last word, to keep the text wrappable, but still keeps the outer link mark together with the last word.
   (Side effect: the line will be trimmed at the right.)
 */
function last_word_class($s, $css_class) {
    return preg_replace('/^(.*?)(\S+?)\s*?$/', "$1<span class=\"$css_class\">$2</span>", $s);
}

function nl1br($t) { // от многих смежных переносов строки, то останется лишь один <br />.
    return preg_replace("/(\r\n)+|(\n|\r)+/", '<br />', $t);
}

function br2nl($t) {
    return preg_replace('/<br\\s*?\/??>/i', "\n", $t);
}

function strip_nl($t) {
    return str_replace("\r", '', str_replace("\n", '', $t));
}

function is_uppercase($t) {
    return $t === mb_strtoupper($t);
}

function upcase_first_char($t, $ucwords = false) { // see also ucwords()
    return ($fc = mb_substr($t, 0, 1)) &&
           ($fc !== ($uc = mb_strtoupper($fc)))

        ? ($ucwords ? ucwords($t) : $uc.mb_substr($t, 1))
        : $t;
}

// TODO: stop using and get rid of this. It's obsolete.
function is_valid_email($s) { // see also "cemail()" in "common.js". it should work better
    return filter_var($s, FILTER_VALIDATE_EMAIL); // it believes that cyrilic domains are invalid. But commented code below does the same.
    // AK 28.07.2021: + sign used by @gmail for subaddressing.
    // return preg_match("/^[_a-z\d!#$%&'*+\-/=?^_`{|}~]+(\.[_a-z\d!#$%&'*+\-/=?^_`{|}~]+)*@[a-z\d\-]+(\.[a-z\d\-]+)*(\.[a-z]{2,30})$/i", $s); // the longest domain extension in 2015 was ".cancerresearch", and looks like it's not the limit. UPD. how about .travelersinsurance? I set up it the longest domain extension to 30 chars.
}

// RFCs don't allow spaces in tel: links. https://stackoverflow.com/questions/42923371/can-a-html-telephone-link-accept-spaces-in-the-value
function esc_phone($num) {
    return mb_trim(preg_replace('/\-+/', '-', preg_replace('/[^\d\-+]/', '-', $num)),
                   '-', true); // trim all spaces and minuses.
}

function fix_url($url) {
    if (    (!$url = mb_trim($url)) || strpos($url, ' ') ||
            (!$r = preg_replace('/^(https?:)?\/\/$/', '', strtolower($url)))) // we want lowercase in $r.
        return '';

    if (strpos($url, '?') === false && strpos($url, '#') === false)
        $url = preg_replace('/\/+$/', '/', $url); // trim multiple / at the end. Leave only one.

    return (substr($r, 0, 7) !== 'http://') && (substr($r, 0, 8) !== 'https://') && $r[0] !== '/'
        ? 'https://'.$url // in 2023 we started to put https:// by default. If you need http:// -- set it explicitly
        : preg_replace('/(https?:\/\/)+/', '\1', // something like http://http://
          preg_replace('/:\/\/+/', '://', $url)); // something like ":////"
}

// BTW, see also "pure_domain()" on FAVOR.com.ua, which used to detect banned email domains.
function nice_url($url, $domain_only = false, $no_social_domains = false, $add_protocol_prefix = false, $anchor = false) { // to display

    if ($add_protocol_prefix) {
        if (preg_match('/(^(https?|ftp):\/\/)|(#(.*?)$)/i', $url, $q) && isset($q[1])) { // get the string with protocol prefix from URL
            $add_protocol_prefix = $q[1];
            $url = substr($url, strlen($add_protocol_prefix));
        }else
            $add_protocol_prefix = 'http://'; // url has no protocol, so add anything default. Don't detect current protocol. Flat HTTP:// will work for all cases.

    }else // strip protocol prefix
        $url = preg_replace('/(^(https?|ftp):\/\/)|(#(.*?)$)/i', '', $url);

    if ($i = strpos($url, '@')) // good for emails too
        $url = substr($url, $i+1);

    if ($domain_only)
        $url = preg_replace('/^(www\d?|ru|ua|uk|en|de|fr|nl|shop|mail)\./i', '', strtolower($url));
    elseif ((strlen($url) > 20) && (strtolower(substr($url, 0, 4)) === 'www.'))
        $url = substr($url, 4, strlen($url)-4);

    if ($url) {
        if (($j = strpos($url, '/')) && ($domain_only || ((strlen($url)-1) === $j)))
            $url = substr($url, 0, $j);
        if ($no_social_domains)
            $url = preg_replace('/^(vkontakte.ru|vk.com|facebook.com|youtube.com|youtu.be|instagram.com|twitter.com|livejournal.com|narod.ru)$/', '', $url);

        // AK 1.09.2019: if domain don't contain "dot" (.) -- it's not domain at all. (And I don't care of intranet hostnames like localhost.)
        if (strpos($url, '.') < 1) {
            global $is_local;
            if (!$is_local)
                return; // bad URL
        }

        $url = $add_protocol_prefix.$url;
    }

    return $anchor
        ? "<a href=\"$url\">$url</a>"
        : $url;
}

// Splits the name into Firstname and Lastname. The first name may contain more than 1 word. The last name is always 1 word.
function split_fl_name($name, &$first, &$last, $maxlen = null) {
    $last = ($i = mb_strrpos($name = mb_trim($name), ' '))
        ? mb_substr($name, $i+1, $maxlen) // all this can be done without multi-byte functions, except limitation of the name length.
        : '';
    $first = mb_substr($name, 0, !$i || ($maxlen && $i > $maxlen) ? $maxlen : $i);
}

function str_replace_avoid_tags($search, $replace, $t) {
    if (strpos($t, $search) === false)
        return $t;

    if (strpos($t, '<') !== false) { // has html tags
        $search = str_replace('\'', '\\\'', $search);
        return preg_replace_callback('/((?<=^|>)([^><]+?)(?=<|$))/s',
            function($m) use($search, $replace) {
                return str_replace($search, $replace, $m[2]);
            }, $t);
    }
    return str_replace($search, $replace, $t);
}

// amp_str() is improved replacement for "htmlspecialchars()".
// Replaces & to &amp;'s, but avoiding & as the prefix of html-entities.
function amp_str($t, $html_quotes = false, $double_encode = false) {
    if ($html_quotes) // this is not the same as "htmlspecialchars()". It doesn't converts <,>  to &lt;,&gt;.
        $t = str_replace_avoid_tags('\'', '&apos;', // DO NOT set "&rsquo;" here! It’s apostroph, not right-single quote yet! We don’t want to break the passwords!
             str_replace_avoid_tags('"',  '&quot;', $t));

    if (strpos($t, '&') === false) return $t;
    if ($double_encode) return str_replace('&', '&amp;', $t); // for RSS
    return preg_replace('/&(?!([A-Za-z]+|#\d+);)/', '&amp;', $t);
}

// see also "make_url_friendly_alias()".
function leave_numbers($s,
                $allow_extra_chars = false, // if has (string) type this is the list of extra characters to allow. Eg "-".
                $strip_entities = true) {

    if ($allow_extra_chars) {
        $allow_extra_chars = (is_string($allow_extra_chars)
            ? preg_quote($allow_extra_chars, '/') // escape
            : '')
                .'A-Za-z';

    }elseif ($strip_entities) // there is different purpose of this func when we allow characters, so let's leave entities too.
        $s = preg_replace('/&#\d+;/', '', $s);

    return preg_replace('/[^\d'.$allow_extra_chars.']/', '', $s);
}

function my_number_format($n, $decimals = 2, // <0 returns price w/o cents if there's no cents. (Used in Flat Menu.)
                                             // Use -99 to just strip trailing 0's with 5 digits after floating point
        $strip_trailing0 = null,             // set to TRUE if you want to use this function just like "round()" -- format to N digits, but then strip all unnecessary zeros at the end.
                                             // Negative value, like -1 strips zeros only if *ALL* digits after decimal point are zeros.
        $sup_cents = null,
        $thousand_sep = null,                // separate each 3 digits with this character (usually comma). If not specified, no separator is used.
        $dec_point = null) {                 // $dec_point must be ASCII, non-unicode character. If not specified (null or false), default (.) is used.

    if ('' === $n) return ''; // AK 3.11.2018: мы всё-таки вернём "0,00" если значение 0.

    if (0 > $decimals)
        if (-99 >= $decimals) {
            $decimals = 5;
            $strip_trailing0 = true;
        }else
            $decimals = ($n * 100 % 100) ? abs($decimals) : 0; // целое ли число? без плавающей запятой при целом числе.

    if (!$dec_point) { // use global var if not specified
        global $S_DECIMALPOINT;
        $dec_point = $S_DECIMALPOINT;
    }

    $n = number_format($n, $decimals, $dec_point, $thousand_sep); // Floating point in English, Плавающая запятая по-русски & рухома кома українською.

    // has decimal point?
    if (($d = strpos($n, $dec_point)) !== false) {
        if ($strip_trailing0) {
            $l = strlen($n);
            do {
                --$l;
                if (substr($n, $l, strlen($dec_point)) === $dec_point) {
                    --$l;
                    break;
                }
            }while ('0' === substr($n, $l, 1));

            // If we need to strip only when all digts after dot are 0's.
            if ($strip_trailing0 < 0 && ($l === $d-1))
                $n = substr($n, 0, $l+1);
        }

        if ($sup_cents)
            $n = substr($n, 0, $d).'<sup>'.substr($n, $d, strlen($n)).'</sup>';
    }

    return $n;
}

function fin_and($s, // $sep(\n)-separated string or array.
        $and = false, // if $and === -1, it puts $S_ET_AL at the end instead.
        $limit = false, // negative value will allow expanse to full list on click. Positive value will finish with $S_ET_AL.
        $sep = false // \n by default. It's strongly DISCOURAGED TO CHANGE! Don't use "," if comma may appear withing strings! Set custom separator in the "mdb::all_list()" instead! "\t" may be OK although.
    ) {

    global $S_AND, $S_ET_AL;

    if (is_array($s) || ($s = rtrim($s))) {
        if (!$sep) $sep = "\n";

        if (is_array($s)) // todo: crop array if there is limit
            $s = implode($sep, $s);

        if ($limit) {
            $s = explode($sep, $s, $limit+1);

            if (isset($s[$limit])) {
                unset($s[$limit]);
                $and = -1; // put ET AL
            }
            $s = implode($sep, $s);
        }

        // replace last occurance with ...AND...
        if (($and !== -1) && ($spos = mb_strrpos($s, $sep)))
            $s = mb_substr($s, 0, $spos).($and !== false ? $and : ' '.$S_AND.' ').mb_substr($s, $spos + mb_strlen($sep));

        $s = str_replace($sep, ', ', $s);
        if ($and === -1)
            $s.= $S_ET_AL;
    }
    return $s;
}

// see also genRandomKey() in register.js
// UPD 2022-09-29. (If not $digits_only) We watch that it contain at least 1 letter and 1 number.
function gen_password($length = 8, $digits_only = false) { // -1 for $digits only allow digits, but exclude special characters
    static $digits = '1234567890';
    static $letters = 'abcdefghjkmnpqrstuvwxyz'; // look, there is no small "l"! But big "L" is okay.

    $keychars = $digits_only > 0
        ? $digits
        : $letters.strtoupper($letters).'Loi'.($digits_only < 0 ? '' : '@#$^%&*').$digits; // no lOI.

    $rand_max = strlen($keychars)-1;
    $s = '';
    for ($i=0; $i<$length; ++$i)
        $s.= $keychars[mt_rand(0, $rand_max)];

    if (($length < 10) && ($digits_only > 0)) // <10, less than length of maximum integer
        return (int)$s;

    if (!$digits_only) {
        // PCI compliant passwords must contain both alphabetic AND numeric characters.
        // Requirements: https://www.pcidssguide.com/what-are-the-pci-dss-password-requirements/

        // if it contain only digits (even with small chance of "e" character)
        if (is_numeric($s)) {
            $s[mt_rand(0, $length-1)] = $keychars[mt_rand(0, $rand_max-10)]; // no digits anymore

        // if it DOESN'T contain any digit, put it in random position.
        }elseif (!preg_match('/\d/', $s, $m))
            $s[mt_rand(0, $length-1)] = $digits[mt_rand(0, 9)];
    }

    return $s;
}


// ======================
// HTML

/* Конвертирует &#233;, &scaron; и пр. в их реальные символы. Которые, однако, могут быть некорректно показаны в cp1251.
   Прикол в том, что ты можешь просмотреть &#268;/&#269; в странице с кодировкой cp1251, но после вызова decode_html_entities() ты их не увидишь.
   AK 1.10.2018: сводим использование decode_html_entities() к минимуму. Это убивает emoji.
   AK 25.04.2019: после перехода на utf8 используется просто для декодирования html entities. Just a short synonym for html_entity_decode().
    * Если однажды захочешь добавить entities или обрать какой-то символ из декодирования, напр. &amp; или &nbsp;,
      получай массив сущностей, добавляй в него сущности или удаляй лишние, затем конверть с strtr($s, array_flip(get_html_translation_table(HTML_ENTITIES, ...))).
   AK 25.12.2021: WARNING! Sometimes we additionally might need to convert A0 (mb_chr(160)) to spaces. Use preg_replace("/\x{00A0}/u", ' ', $s).
 */
function decode_html_entities($s) {
    if (is_array($s)) {
        foreach ($s as $key => &$val)
            $s[$key] = decode_html_entities($s[$key]); // if array, it will be recursive call

        return $s;
    }

    return false === strpos($s, '&') // let's be smart
        ? $s
        : html_entity_decode($s, ENT_QUOTES|ENT_HTML5, mb_internal_encoding());
}


// ======================
// ESCAPING
function compact_str_spaces($s) {
    return preg_replace("/  +/", ' ', // don't worry, this will not remove "&nbsp; ".
           preg_replace("/( \n+|\n +)/", "\n", $s));
}

// Don't worry, it's preserve \n' and HTML-entities.
// WARNING! It does NOT trims the output string (with purpose!). Please DO NOT TRIM outer spaces! This function may be used in preg_replace_callback() and should preserve outer spaces!
// FYI: it's already used if we're using "perfect_quotes()" (both with do_trim and without).
// See also mb_trim();
function strip_tabs($s, $compact_spaces = false) { // strip also all posted garbage without doubt.
    $s = str_replace('&#65279;', '',
         str_replace('&#160;', '&nbsp;', // ATTN! This keeping &nbsp;'s! Unicode \x{00A0} (same as ASCII \xC2\xA0) is not allowed, but &nbsp; is okay!

         preg_replace('/[\x00-\x08\x0B-\x1F]/', '', // except x09 (\t-tab) and x0A (\n-linebreak)
         preg_replace("/(\t|\xC2\xA0)/", ' ', $s)))); // We don't accept TABs and &nbsp;s! Use custom functionality if you need TABs and HTML-entities.

    return $compact_spaces
        ? compact_str_spaces($s)
        : $s; // IMPORTANT! Do not trim here! We still may need outer spaces!
}

// Not actually strip, but replace all non-characters, including emojis.
//   * Do prefer REPLACEMENT, NOT DELETION, to avoid potentially malicious code points (XSS attacks, see example with JS code on http://unicode.org/reports/tr36/#Deletion_of_Noncharacters)
// Idea: https://stackoverflow.com/questions/8491431/how-to-replace-remove-4-byte-characters-from-a-utf-8-string-in-php
// Should be used at least to replace all non 4+-byte characters from strings which supposed to be inserted into utf8[mb3] data table (not utf8mb4)
function strip_utf8_non_characters($s, $replace_char = "\xEF\xBF\xBD") {
    return preg_replace('/[\x{10000}-\x{10FFFF}]/u', $replace_char, $s);
}

// Sanitize string, convert all non-unicode character into "?" (alhough convert non-breaking space, tabs and linebreaks into regular space).
//   * Do prefer REPLACEMENT (with ?), NOT DELETION, to avoid potentially malicious code points (XSS attacks, see example with JS code on http://unicode.org/reports/tr36/#Deletion_of_Noncharacters)
// If you're converting from HTML, use also html_entity_decode() before the following func.
// NO TRIM here, as well as in strip_tabs().
function str_to_ascii($s, $compact_spaces = false, $multiline = false, $replace_char = '?') { // if $multiline is TRUE, keep \n-linebreaks.
    $s = preg_replace($multiline ? '/[\x00-\x08\x0B-\x1F]/' : '/[\x00-\x1F]/', '', // ...then finally strip all the odd non-printable stuff. Except \n, if multiline.
         preg_replace('/[[:^ascii:]]/u', $replace_char, // * this is proven much faster than mb_convert_encoding($str, 'ascii');
         preg_replace('/(\t'.($multiline ? '' : '|\n').'|\xC2\xA0)/', ' ',  // Convert \t, \n and non-breaking spaces (which is unicode character) into regular spaces.
         str_replace('×', 'x', // &times;
         str_replace('’', "'", // I'd like to keep unicode apostrophes, but convert them to ASCII
         preg_replace('/[“”„‟«»]/u', '"', // quotes
         preg_replace('/[—–]/u', '-', $s))))))); // convert long dash/tiret to the unicode

    return $compact_spaces
        ? compact_str_spaces($s)
        : $s; // IMPORTANT! Do not trim here! We still may need outer spaces!
}

// AK: this is good for unicode. For non-unicode use str_to_ascii()
function prepare_esc($s,
        $maxlen = 0,
        $check_empty = false, // check empty = convert &nbsp;'s to spaces + strip tags to check whether there is no content. If no content (besides tags) -- return ''.
        $perfect_quotes = false,
        $no_trim = false,
        $keep_tabs = false) { // -1 = strip garbage, but don't compact spaces

    if (!$keep_tabs || $keep_tabs < 0) // strip all posted garbage without doubt.
        $s = strip_tabs($s, $keep_tabs < 0); // By default we don't accept TABs and other garbage!

    if (!$no_trim)
        $s = mb_trim($s);

    if (($len = mb_strlen($s)) !== 0) {
        if ($perfect_quotes) // requires "strings_perfect.php".
            $s = html_perfect_quotes($s, $perfect_quotes, false); // FALSE = no need to trim, it’s done above

        if ($check_empty && !rtrim(str_replace('&nbsp;', '', strip_tags($s))) && // test posting without tags.
                (strpos($s, '></iframe>') === false)) // iframes (embedded youtubes) is okay.
            return '';

        if ($maxlen && ($len > $maxlen))
            return mb_substr($s, 0, $maxlen); // TODO: don't we might need to trim again?
    }

    return $s;
}

// It's mdb::esc_notags_post(), but without mysql_real_escape(), which require mySQL.
function postv($var, $maxlen = null, $perfect_quotes = null, $allowed_tags = null, $def = null) {
    return (empty($r = empty($_POST[$var]) ? $def : $_POST[$var])) // yes $def will be processed and escaped too.
        ? $r // $def, null
        : prepare_esc(strip_tags($r, $allowed_tags), $maxlen, null, $perfect_quotes);
}

// POST/GET. See also pgvar() in the "uri.php"
function pvarq($var, $def = null, $perfect_quotes = null) {
    if (empty($r = empty($_POST[$var]) ? $def : $_POST[$var])) return $r;

    if ($perfect_quotes)
        $r = perfect_quotes($r);

    return htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
}



// ======================
// CUTS...
function cut_tags($s, $tag) { // strip_tags() specifies allowed tags. Here we specify tags which should be removed.
    return preg_replace("/<\/?$tag(\s+?[^>]*?)?>/is", '', $s);
}

function cuta($t1, $t2, $t, $replace = null, $ignore_case = null, $limit = null) {
    return preg_replace('/'.preg_quote($t1, '/').'.*?'.preg_quote($t2, '/').'/su'. // always unicode-friendly.
        ($ignore_case ? 'i' : ''), $replace, $t, $limit ? $limit : -1);
}

function parse_if_else($text, $if, $bool) {
    return preg_replace_callback('/%IF_('.$if.')%(.*?)(%ELSE_\\1%(.*?))?%ENDIF_\\1%/s', function($m) use($bool) {
            return $bool ? $m[2] : (isset($m[4]) ? $m[4] : '');
        }, $text);
}


// ============================
// DATE/TIME
function fixtime($time, $timezone = null) { // $timezone in minutes. Provides the offset from GMT/UTC in MINUTES.
    global $MY_tz;

    if (empty($timezone)) { // use default
        if ($MY_tz === null || $MY_tz === -1) {
            if (!isset($_COOKIE['tz']) || !($timezone = $_COOKIE['tz'])) // If no cookie. (Just uncomment in case of any trouble.)
                return $time; // use server time
        }else {
            $timezone = $MY_tz;
        }
    }
    $tz_sec = $timezone * 60; // minutes to seconds
    $tz_server = (int)date('Z'); // Alternative is: timezone_offset_get(timezone_open(date_default_timezone_get()), date_create('now', timezone_open('UTC')))

    return ($tz_sec === $tz_server) || ($timezone > 780/*13*60*/) ? $time : $time + $tz_sec - $tz_server;
}

// AK: don't mix this with last_log(), since last_log() also fixing the time. No need to double shift the time.
function mytime($time = null) {
    global $MY_tz;

    if (1 === $time) $time = time();
    elseif (!$time) $time = $_SERVER['REQUEST_TIME'];
    elseif (!is_numeric($time)) $time = strtotime($time);

    return $MY_tz ? fixtime($time) : $time;
}

// sugar
function quarter_by_month($m) { // see also "last_day_of_quarter()": gmmktime(0, 0, 0, floor($q*3), $q === 1 || $q === 4 ? 31 : 30)
    return ceil($m/3);
}

// sugar
function current_year() {
    static $y; // cache, to avoid double calculation
    return $y ? $y : $y = (int)date('Y', mytime());
}

function mkdate_mmdd($s, $year = false) { // $s is raw date in "MMDD" or "YYYYMMDD" format.
    if (strlen($s) === 8) { // date already includes year
        $year = substr($s, 0, 4);
        $s = substr($s, 4);
    }elseif (!$year) {
        $year = current_year();
    }

    return gmmktime(0, 0, 0, substr($s, 0, 2), substr($s, 2, 2), $year);
}

function htmldate($f, $t, $plain_text = false) {
    $f = date($f, $t);
    if ($plain_text > 0)
        return $f;
    if ($plain_text < 0)
        $f = str_replace(' ', '&nbsp;', $f);
    return '<time datetime="'.date('Y-m-d\TH:i', $t).'">'.$f.'</time>';
}

// $add_time_sep must contain space, to separate time from date.
function full_date_notime($t, $year_postfix = '', $plain_text = false, $full_date = true, $html5 = true, $salt = false, $add_time_sep = false) {
    global $S_FULL_DATE, $S_FULL_TIME, $S_SHORT_DATE, $S_MONTHS;

    if (!$t) return;

    $full_date = $full_date ? $S_FULL_DATE : $S_SHORT_DATE;

    $r = sprintf(date($full_date, $t), $S_MONTHS[date('n', $t)]).$year_postfix;

    // time only after $year_postfix.
    if ($add_time_sep !== false)
        $r.= $add_time_sep.date($S_FULL_TIME, $t);

    if ($plain_text > 0) {
        $r = str_replace('&nbsp;', ' ', $r);

    }elseif ($html5) { // && ($plain_text <= 0)
        if ($plain_text < 0)
            $r = str_replace(' ', '&nbsp;', $r);
        $r = '<time datetime="'.date('Y-m-d\TH:i', $t).'"'.($salt ? ' '.$salt : '').'>'.$r.'</time>';
    }
    return $r;
}

function full_date($t, $postfix = false, $sep = false, $plain_text = false, $html5 = true) {
    if (!$sep) $sep = ', '; // separator between date and time required to add the time
    return full_date_notime($t, $postfix, $plain_text, true, $html5, false, $sep);
}

function full_date_dow($t, $salt = false) {
    global $S_DWEEK, $S_YEARPOSTFIX;
    return '<time datetime="'.date('Y-m-d\TH:i', $t).'"'.($salt ? ' '.$salt : '').'>'.$S_DWEEK[date('w', $t)].', '.full_date_notime($t, $S_YEARPOSTFIX, false, 1, 0).'</time>';
}

function date_noyear($t, $plain_text = false, $html5 = true) {
    return full_date_notime($t, '', $plain_text, false, $html5);
}

function age_by_date($y, $m = false, $d = false,
        $floor = false, // floor: if months is unknown, we're getting minimal age, otherwise -- age that turns in specified year.
        $to_date = false) {

    if (($y = (int)$y) === 0)
        return 0;

    $t = $to_date ? $to_date : mytime();
    $cy = (int)date('Y', $t);
    $cm = (int)date('n', $t);

    $age = $cy-$y;

    if (($m == 0) && $floor)
        --$age;
    elseif ($cm <= $m) {
        $cd = date('j', $t);
        if (($cm < $m) || ($cd < $d))
            --$age;
    }
    return $age;
}

// Converts string in H:M:S format to seconds. Return value is (int).
// Doesn't validate sections, so number of minutes and seconds more than 60, like "1:80:90", are allowed, as well as strings like "::20".
function timecode_to_seconds($time) {
    $time = trim($time);
    if (strpos($time, ':') === false)
        return (int)$time;

    $time = array_reverse(explode(':', $time));

    $r = (int)$time[0];
    if (($time_cnt = count($time)) > 1) {
        $r+= (int)$time[1] * 60;
        if ($time_cnt > 2)
            $r+= (int)$time[2] * 3600;
    }
    return $r; // (int)seconds
}

// Caution! May return any number in Hours secion if number of seconds too high.
// SEE ALSO mdb::timezone_offset() in mysql.php
// SEE ALSO gmdate($format, $timestamp) if you just need to get a time from timestamp in GMT/UTC timezone.
function seconds_to_timecode($s) {
    $s = timecode_to_seconds($s); // just for sure OR for the case if this func used to fix badly formatted timecode.

    $h = floor($s / 3600);
    $m = floor($s / 60) % 60; // correct. First convert to integer, then try to get remainder, as integer value too.
    $s-= $h * 3600 + $m * 60;

    // AK: all these simple if's are faster than str_pad($hours, 2, '0', STR_PAD_LEFT)! Tested.
    if ($h < 10) $h = '0'.$h;
    if ($m < 10) $m = '0'.$m;
    if ($s < 10) $s = '0'.$s;

    return $h.':'.$m.':'.$s;
}

function parse_mysql_time($time, &$h, &$m, &$s) {
    if (is_numeric($time)) // timestamp?
        $time = gmdate('H:i:s', $time);

    $h = (int)substr($time, 0, 2);
    $m = (int)substr($time, 3, 2);
    $s = (int)substr($time, 6, 2);

    return $h * 3600 + $m * 60 + $s;
}

// Usually we're using date($S_DEF_TIME, time()) to get formatted timestamps. But this is for timestamps from mySQL db. For strings in HH:MM:SS format.
// Returned string without leading zero in hours. (You don't really need it.)
// !! For new development use um_user_basics::mysqltime2str() instead!
function mysqltime2str($time, // string or timestamp. If string -- provide TIME ONLY, without date! Use substr($time, 10) to get time from full mysql date.
                       &$sec,
                       $options = []) {
    /* Valid options are:
         'format': (int) 12 or 24, otherwise default.
         'show_seconds': (bool) false by default
         'plain_text': (bool) false by default
         'add_sec': (int) add (or subtract, if negative) some seconds to specified time.
         'add_min': (int) add (or subtract, if negative) some minutes to specified time. Ignored if 'add_sec' used.
     */
    if (isset($options['format'])) {
        $format = (int)$options['format'];
    }else {
        global $MY_INFO;
        $format = empty($MY_INFO['st_time_format']) ? 0 : (int)$MY_INFO['st_time_format'];
    }

    if ($format !== 12 && $format !== 24) {
        global $def_time_format;
        $format = $def_time_format;
    }

    $sec = parse_mysql_time(ltrim($time), $h, $m, $s);
    if (isset($options['add_sec']))
        $add_sec = $options['add_sec'];
    elseif (isset($options['add_min']))
        $add_sec = $options['add_min'] * 60;
    else
        $add_sec = 0;

    if ($add_sec > 0) {
        $sec+= $add_sec;

        $h = floor($sec / 3600);
        $sec-= $h * 3600;
        $m = floor($sec / 60);
        $sec-= $m * 60;
        // parse again
        $sec = parse_mysql_time(gmmktime($h, $m, $s), $h, $m, $s);
    }

    if ($format === 12) {
        $ampm = empty($options['plain_text']) ? '&nbsp;' : ' ';
        if ($h < 12) {
            $ampm.= 'am';
        }else {
            $h-= 12;
            $ampm.= 'pm';
        }
        if ($h === 0) $h = 12;
    }else
        $ampm = '';

    return $h.':'.($m < 10 ? '0' : '').$m.
        (empty($options['show_seconds']) ? '' : ':'.($s < 10 ? '0' : '').$s).$ampm;
}

// it also returns age in $age variable
// !! This func will be obsolete soon. For new development use um_user_basics::user_date_format() instead!
function mysqldate2str($dt,
    &$age, // age returned to this variable
    $options = []) {
 /* Valid options are:

      'plain_text':             return as plain text, no HTML tags.
      'month_as_str':           true/false, or -1 = roman number. (Default is TRUE!)
      'time_salt':              string attributes to add to the <time> tag.
      'default':                default value if the date is empty: if year == 0, or year == 1 and there is no month.

    // related to age:
      'add_age':        add age in years (XX) after the birthdate (or another anniversary date). If "add_age" is string, it's sprintf-formatted string with $age as first parameter. Good value is '(%d<small>&nbsp;y/o</small>)'.
      'birthday_pic':   some text, pictogram or emoji with the balloon can be displayed. (int)1 = emoji baloon.
      'tooltip_title':  custom tooltip title for <date> tag. False or "" = no tooltip. CAUTION! It must be previously escaped, to not contain "-characters.
      'floor_age':      true = floor, false = ceil, if this is year without day/month
      'age_to_date':    age to specific date. FALSE = current date (today). -1 -- take month/day of $dt + current year. UPD. this value can be also in mySQL format, if it's string.
  */

    global $S_BIRTHDAY, $DF_DM, $S_MONTHS, $S_MONTHB;

    // common options
    $_month_as_str = ifset($options, 'month_as_str', true);
    $_default = ifset($options, 'default');
    $_age_to_date = ifset($options, 'age_to_date');
    $_add_age = ifset($options, 'add_age');

    $y = (int)substr($dt, 0, 4);
    $m = (int)substr($dt, 5, 2);
    $d = (int)substr($dt, 8, 2);
    if ($y > 1) {
        if ($_age_to_date === -1)
            $_age_to_date = gmmktime(0, 0, 0, $m, $d, current_year());

        elseif (is_string($_age_to_date)) {
            $_age_to_date = gmmktime(0, 0, 0,
                             (int)substr($_age_to_date, 5, 2),  // month
                             (int)substr($_age_to_date, 8, 2),  // day
                             (int)substr($_age_to_date, 0, 4)); // year
        }

        $age = age_by_date($y, $m, $d, ifset($options, 'floor_age'), $_age_to_date);

        if ($_add_age)
            $_add_age = ' '.(is_string($_add_age) ? sprintf($_add_age, $age) : '('.$age.')');
    }else
        $age = false;

    if (0 === $y)
        return $_default;

    if (($m < 1) || ($m > 12)) {
        return 1 === $y
            ? $_default
            : $y.$_add_age;
    }

    $out = '';
    if (1 === $y) $y = '';
    if ($d && $m && ($_birthday_pic = ifset($options, 'birthday_pic'))) {
        $t = mytime();
        if ((date('j', $t) == $d) && (date('n', $t) == $m)) // the birthday is today? (IMPORTANT! date() returns string, not integer!)
            $out.= $_birthday_pic === 1 ? '🎈&nbsp;' /* emoji balloon */ : $_birthday_pic;
    }else
        $_birthday_pic = false;

    $i = strtolower(substr($DF_DM, 0, 1));
    $is_us_date_format = $i === 'n' || $i === 'm'; // month w/o or with leading zeros = US date format

    if ($_month_as_str > 0) {
        $out.= $d === 0
            ? $S_MONTHB[$m].($y ? ', '.$y : '')
            : ($is_us_date_format
                    ? $S_MONTHS[$m].' '.$d.($y ? ', '.$y : '') // M/D/Y
                    : $d.' '.$S_MONTHS[$m].' '.$y); // D/M/Y

    }else {
        if ($_month_as_str === -1 || (!$d && !$y)) { // month as roman number
            if (!function_exists('roman_number'))
                require(__DIR__.'/roman.php');
            $m = roman_number($m);
        }elseif ($m < 10)
            $m = '0'.$m;

        $out.= $d === 0
            ? $m.($y ? ', '.$y : '')
            : ($is_us_date_format
                    ? $m.'/'.$d.($y? '/'.$y : '')   // M/D/Y
                    : $d.'.'.$m.($y? '.'.$y : '')); // D/M/Y
    }

    if ($_add_age) $out.= $_add_age;

    if ($m < 10)
        $m = '0'.$m;
    if ($d < 10)
        $d = '0'.$d;

    if ($_time_salt = ifset($options, 'time_salt'))
        $_time_salt = ' '.$_time_salt;

    if ($_tooltip_title = isset($options['tooltip_title'])
            ? $options['tooltip_title']
            : ($_birthday_pic ? $S_BIRTHDAY : '')) {
        $_tooltip_title = ' title="'.$_tooltip_title.'"';
    }

    return isset($options['plain_text'])
               ? $out
               : "<time datetime=\"$y-$m-$d\"$_time_salt$_tooltip_title>$out</time>";
}


/* AK 3.06.2019: I would get rid of this stuff in order to use front-end solution of constantly updating time.
   BUT! Sometimes we may need these funcs to send emails or instant messages. Or we need it to display before the JS initialized, to avoid "display of unprocessed content".
 */
function strhrsago($n) {
    global $S_HRAGO1, $S_HRAGO2, $S_HRAGO3, $S_TIME_AGO;

    while ($n >= 100) $n = $n % 100;
    $k = $n;
    while ($k > 10) $k = $k % 10;
    return $n.' '.
        (($n % 10 === 0) || (($n >= 10) && ($n < 21)) || ($k >= 5)
            ? $S_HRAGO3.' '.$S_TIME_AGO
            : ('1' === substr($n, -1) ? $S_HRAGO1 : $S_HRAGO2).' '.$S_TIME_AGO);
}

function last_log_plain($time, // mysql-formatted DATETIME string values accepted too.
        $showtime = 1, // 0 -- never, 1 -- always, 2 -- only for today and yesterday.
        $at = '', // string. Possible separator between date and time. Like "4 January [at] 6:00".
        $dformat = '',
        $tformat = '', // can be either string representation of format, or integer 12 or 24. In case if integer value used, no seconds displayed.
        $online = 0, // number of SECONDS during which user (state) considered as "online". Negative integer (like -1) shows full date without variations like "Today", "Yesterday" etc.
        $justnow = null) { // "online" in minutes. not used if false
    global $S_JUSTNOW, $S_AT, $S_TODAY, $S_YESTERDAY, $S_MIN_AGO,
            $S_DEF_DATE, $S_DEF_TIME, $S_MONTHS,
            // global modifiers
            $MY_INFO,
            $last_log_FULL; // TRUE displays all dates in regular format.

    if (!$time || (!is_numeric($time) && (!$time = strtotime($time))) || ($time < 0))
        return;

    $show_fill_date = $last_log_FULL || $online < 0;

    // defaults. Any FALSE or '' set up defaults. It can't be empty anyway.
    if (!$tformat)
        $tformat = empty($MY_INFO['st_time_format']) ? $S_DEF_TIME : $MY_INFO['st_time_format'];
    if (is_numeric($tformat))
        $tformat = 12 === $tformat ? 'g:i\&\n\b\s\p\;a' : 'G:i';

    if (!$at) $at = str_replace('&nbsp;', ' ', $S_AT);
    if (!$justnow) $justnow = $S_JUSTNOW;

    // before fixing the time. If time is 00:00:00, then time not specified.
    // current time with fixed timezone. $rel_ctime = current time that supposed to be on server, but not necessarily current user's time.
    $ctime = mytime(1); // ATTN! We shouldn't trust to $_SERVER['REQUEST_TIME'] here! Get only fresh time()! Some time could pass since execution started.

    if ((1 === $showtime) && // 2 == show anyway as is.
            ('000000' === gmdate('His', $time))) { // 0 hr 0 min 0 sec
        $showtime = 0;
    }elseif ($showtime) {
        $time  = fixtime($time); // fix the time to user's timezone only if time provided OR we should display the time. Otherwise date is enough.
        //ctime = fixtime($ctime); // current time that supposed to be on server, but not necessarily current user's time. And we don't fix $ctime. It's already as it needed.
    }

    $stime = $showtime ? $at.date($tformat, $time) : '';

    if (!$show_fill_date) {
        if (($online !== 0) && (($time + $online > $ctime) && ($time < $ctime + $online)))
            return $justnow;

        $day = 86400; // 24 * 60 * 60;
        if (($time <= $ctime + 8) && // not the future (but allow few seconds in the future, as possible difference between the PHP and mySQL server times)
            ($time > $ctime - $day * 3)) { // not earlier than 72 hours ago

            if ($showtime) {
                if ($time + 60 > $ctime) // less than minute ago
                    return $justnow;

                if ($time + 3600 > $ctime) // less than hour ago
                    return floor(($ctime - $time) / 60).$S_MIN_AGO;

                if ($time + 3600 * 7 > $ctime) { // less than 7 hours ago
                    $i = floor(($ctime - $time) / 3600);
                    return strhrsago($i);
                }
            }

            $d = date('j', $time);
            if ($d === date('j', $ctime)) // today
                return $S_TODAY.$stime;

            if ($d === date('j', $ctime - $day)) // yesterday
                return $S_YESTERDAY.$stime;
        }
    }

    $fulldate = 2 === $dformat;
    if (!$dformat || $fulldate) $dformat = $S_DEF_DATE;
    return sprintf(date($dformat, $time), $fulldate
                ? $S_MONTHS[date('n', $time)]
                : mb_substr($S_MONTHS[date('n', $time)], 0, 3)).
           (2 === $showtime ? '' : $stime);
}

// last_log RELATIVELY to user's timezone!
// Do prefer um_user_basics::last_log() for new development!
function last_log($time, $showtime = true, $at = '', $dformat = '', $tformat = '', $online = 0, $justnow = null) {
    if (!is_numeric($time))
        $time = strtotime($time);

    return '<time datetime="'.gmdate('Y-m-d\TH:i\Z', $time).'" class="nobr">'.
           last_log_plain($time, $showtime, $at, $dformat, $tformat, $online, $justnow).
           '</time>';

    /* // legacy. Because it's not bad to have <time> tag even for "Just now"'s.
    global $S_JUSTNOW;
    if (!$justnow) $justnow = $S_JUSTNOW;
    return (($i = last_log_plain($time, $showtime, $at, $dformat, $tformat, $online, $justnow)) === $justnow)
        ? $i
        : '<time datetime="'.gmdate('Y-m-d\TH:i\Z', $time).'"'.(is_numeric($i) ? '' : ' class="nobr"').'>'.$i.'</time>';
     */
}

// incoming time must be string
function is_past_time($time, $cur_time = null) {
    if (!$cur_time) {
        static $ct;
        if (!$ct) $ct = date('Y-m-d\TH:i:s', mytime());
        $cur_time = $ct;
    }
    return '-' !== date_diff(date_create($time), date_create($cur_time))->format('%r');
}

// ============================
// IP
/* Returns string representation of IP. It can either IPv6 OR IPv4 format.
   Дилетантские заметки 21.01.2020:
     Кажется тип определяемого IP зависит от системы. Некоторые сети могут оперировать IPv6 адресами, некоторые работают только с IPv4.
     IP результата зависит от хостинга. Один и тот же скрипт pasfotos определяет все адреса в виде IPv6. На palmbeach и favor — IPv4.

     Важно понимать главное: мы НЕ можем корректно преобразовать IPv6 в IPv4. Если мы преобразуем IPv6 в long, никакая его часть от этого не станет IPv4.

     Поддержка IPv6 контролируется HTTP-сервером. Apache, Nginx или WinIIS.
     Если очень хочется использовать только IPv4, или наоборот IPv6, следует менять настройки сервера. С PHP это не связано. Можно лишь посмотреть наличие поддержки IPv6 через phpinfo().

     AK 2022-10-23: there are 2 options of holding IPs in DB: as 45-chars string, or as 16-bit binary (more preferred, but ipv6 functionality not supported by old mySQL versions).
                    For development purposes it's recommended to write everything as 45-chars string (due to human-readable format), then compact the db field to binary later, occasionally.
         IP functions: https://dev.mysql.com/doc/refman/5.6/en/miscellaneous-functions.html
         How to store as binary: https://dev.mysql.com/blog-archive/mysql-8-0-storing-ipv6/
 */
function get_real_ip() {
    if (isset($_SERVER['HTTP_CLIENT_IP']) && ($_SERVER['HTTP_CLIENT_IP'][0] !== 'u') && (strlen($_SERVER['HTTP_CLIENT_IP']) > 4))
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && ($_SERVER['HTTP_X_FORWARDED_FOR'][0] !== 'u') &&
           ($ip = $_SERVER['HTTP_X_FORWARDED_FOR']) && (strlen($ip) > 4) &&
           ($ip = trim(preg_replace('/^(.*?),/', '', $_SERVER['HTTP_X_FORWARDED_FOR']))) && (strlen($ip) > 4)) {
        // ok. Remember of IPs like '192.168.0.108, 193.84.72.90'
    }else
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 0;

    if ($ip) {
        if (($p = strpos($ip, ',')) > 0) // get rid of possible artefacts
            $ip = substr($ip, 0, $p-1);
    }else
        $ip = '0.0.0.0'; // It's '0:0:0:0:0:ffff:0:0' in IPv6, but this is impossible situation, so we don't care.

    return $ip;
}



// =======================
// DEBUG
function backtrace($plain = false) {
    $out = $plain ? "file\tline\tfunction\n" : <<<END
<table cellspacing="0" cellpadding="4" border="1">
    <thead>
        <tr>
            <th>file</th>
            <th>line</th>
            <th>function</th>
        </tr>
    </thead>
END;

    $callstack = debug_backtrace();
    foreach ($callstack as &$call) {
        $func = isset($call['function']) ? $call['function'] : '';
        if ($func !== 'backtrace') { // don't show call of this func.
            $clas = isset($call['class']) ? $call['class'] : '';
            $type = isset($call['type'])  ? $call['type']  : '';
            $file = isset($call['file'])  ? $call['file']  : '[PHP Kernel]';
            $line = isset($call['line'])  ? $call['line']  : '';

            if ($plain) {
                $out.= "$file\t$line\t$clas$type$func\n";
            }else {
                $out.= <<<END
    <tr>
        <td>$file</td>
        <td nowrap>$line</td>
        <td>$clas$type$func</td>
    </tr>
END;
            }
        }
    }

    return $out.($plain ? '' : '</table>');
}
