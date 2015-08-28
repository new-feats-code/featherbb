<?php

/**
 * Copyright (C) 2015 FeatherBB
 * based on code by (C) 2008-2012 FluxBB
 * and Rickard Andersson (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */



//
// Return current timestamp (with microseconds) as a float
//
function get_microtime()
{
    list($usec, $sec) = explode(' ', microtime());
    return ((float)$usec + (float)$sec);
}


//
// Fetch admin IDs
//
function get_admin_ids()
{
    // Get Slim current session
    $feather = \Slim\Slim::getInstance();
    if (!$feather->cache->isCached('admin_ids')) {
        $feather->cache->store('admin_ids', \model\cache::get_admin_ids());
    }

    return $feather->cache->retrieve('admin_ids');
}


//
// Check username
//
function check_username($username, $errors, $exclude_id = null)
{
    global $feather, $errors, $feather_bans;

    // Include UTF-8 function
    require_once FEATHER_ROOT.'include/utf8/strcasecmp.php';

    load_textdomain('featherbb', FEATHER_ROOT.'lang/'.$feather->user->language.'/register.mo');
    load_textdomain('featherbb', FEATHER_ROOT.'lang/'.$feather->user->language.'/prof_reg.mo');

    // Convert multiple whitespace characters into one (to prevent people from registering with indistinguishable usernames)
    $username = preg_replace('%\s+%s', ' ', $username);

    // Validate username
    if (feather_strlen($username) < 2) {
        $errors[] = __('Username too short');
    } elseif (feather_strlen($username) > 25) { // This usually doesn't happen since the form element only accepts 25 characters
        $errors[] = __('Username too long');
    } elseif (!strcasecmp($username, 'Guest') || !utf8_strcasecmp($username, __('Guest'))) {
        $errors[] = __('Username guest');
    } elseif (preg_match('%[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}%', $username) || preg_match('%((([0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){6}:[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){5}:([0-9A-Fa-f]{1,4}:)?[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){4}:([0-9A-Fa-f]{1,4}:){0,2}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){3}:([0-9A-Fa-f]{1,4}:){0,3}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){2}:([0-9A-Fa-f]{1,4}:){0,4}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){6}((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|(([0-9A-Fa-f]{1,4}:){0,5}:((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|(::([0-9A-Fa-f]{1,4}:){0,5}((\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b)\.){3}(\b((25[0-5])|(1\d{2})|(2[0-4]\d)|(\d{1,2}))\b))|([0-9A-Fa-f]{1,4}::([0-9A-Fa-f]{1,4}:){0,5}[0-9A-Fa-f]{1,4})|(::([0-9A-Fa-f]{1,4}:){0,6}[0-9A-Fa-f]{1,4})|(([0-9A-Fa-f]{1,4}:){1,7}:))%', $username)) {
        $errors[] = __('Username IP');
    } elseif ((strpos($username, '[') !== false || strpos($username, ']') !== false) && strpos($username, '\'') !== false && strpos($username, '"') !== false) {
        $errors[] = __('Username reserved chars');
    } elseif (preg_match('%(?:\[/?(?:b|u|s|ins|del|em|i|h|colou?r|quote|code|img|url|email|list|\*|topic|post|forum|user)\]|\[(?:img|url|quote|list)=)%i', $username)) {
        $errors[] = __('Username BBCode');
    }

    // Check username for any censored words
    if ($feather->forum_settings['o_censoring'] == '1' && censor_words($username) != $username) {
        $errors[] = __('Username censor');
    }

    // Check that the username (or a too similar username) is not already registered
    $query = (!is_null($exclude_id)) ? ' AND id!='.$exclude_id : '';

    $result = \DB::for_table('online')->raw_query('SELECT username FROM '.$feather->forum_settings['db_prefix'].'users WHERE (UPPER(username)=UPPER(:username1) OR UPPER(username)=UPPER(:username2)) AND id>1'.$query, array(':username1' => $username, ':username2' => ucp_preg_replace('%[^\p{L}\p{N}]%u', '', $username)))->find_one();

    if ($result) {
        $busy = $result['username'];
        $errors[] = __('Username dupe 1').' '.feather_escape($busy).'. '.__('Username dupe 2');
    }

    // Check username for any banned usernames
    foreach ($feather_bans as $cur_ban) {
        if ($cur_ban['username'] != '' && utf8_strtolower($username) == utf8_strtolower($cur_ban['username'])) {
            $errors[] = __('Banned username');
            break;
        }
    }

    return $errors;
}


//
// Outputs markup to display a user's avatar
//
function generate_avatar_markup($user_id)
{
    $feather = \Slim\Slim::getInstance();

    $filetypes = array('jpg', 'gif', 'png');
    $avatar_markup = '';

    foreach ($filetypes as $cur_type) {
        $path = $feather->config['o_avatars_dir'].'/'.$user_id.'.'.$cur_type;

        if (file_exists(FEATHER_ROOT.$path) && $img_size = getimagesize(FEATHER_ROOT.$path)) {
            $avatar_markup = '<img src="'.feather_escape($feather->url->base(true).'/'.$path.'?m='.filemtime(FEATHER_ROOT.$path)).'" '.$img_size[3].' alt="" />';
            break;
        }
    }

    return $avatar_markup;
}


//
// Generate browser's title
//
function generate_page_title($page_title, $p = null)
{
    if (!is_array($page_title)) {
        $page_title = array($page_title);
    }

    $page_title = array_reverse($page_title);

    if ($p > 1) {
        $page_title[0] .= ' ('.sprintf(__('Page'), forum_number_format($p)).')';
    }

    $crumbs = implode(__('Title separator'), $page_title);

    return $crumbs;
}


//
// Save array of tracked topics in cookie
//
function set_tracked_topics($tracked_topics = null)
{
    global $cookie_name;

    // Get Slim current session
    $feather = \Slim\Slim::getInstance();

    if (!empty($tracked_topics)) {
        // Sort the arrays (latest read first)
        arsort($tracked_topics['topics'], SORT_NUMERIC);
        arsort($tracked_topics['forums'], SORT_NUMERIC);
    } else {
        $tracked_topics = array('topics' => array(), 'forums' => array());
    }

    return $feather->setCookie($cookie_name . '_track', json_encode($tracked_topics), time() + $feather->config['o_timeout_visit']);
}


//
// Extract array of tracked topics from cookie
//
function get_tracked_topics()
{
    global $cookie_name;

    // Get Slim current session
    $feather = \Slim\Slim::getInstance();

    $cookie_raw = $feather->getCookie($cookie_name.'_track');

    if (isset($cookie_raw)) {
        $cookie_data = json_decode($cookie_raw, true);
        return $cookie_data;
    }
    return array('topics' => array(), 'forums' => array());
}


//
// Update posts, topics, last_post, last_post_id and last_poster for a forum
//
function update_forum($forum_id)
{
    $stats_query = \DB::for_table('topics')
                    ->where('forum_id', $forum_id)
                    ->select_expr('COUNT(id)', 'total_topics')
                    ->select_expr('SUM(num_replies)', 'total_replies')
                    ->find_one();

    $num_topics = intval($stats_query['total_topics']);
    $num_replies = intval($stats_query['total_replies']);

    $num_posts = $num_replies + $num_topics; // $num_posts is only the sum of all replies (we have to add the topic posts)

    $select_update_forum = array('last_post', 'last_post_id', 'last_poster');

    $result = \DB::for_table('topics')->select_many($select_update_forum)
        ->where('forum_id', $forum_id)
        ->where_null('moved_to')
        ->order_by_desc('last_post')
        ->find_one();

    if ($result) {
        // There are topics in the forum
        $insert_update_forum = array(
            'num_topics' => $num_topics,
            'num_posts'  => $num_posts,
            'last_post'  => $result['last_post'],
            'last_post_id'  => $result['last_post_id'],
            'last_poster'  => $result['last_poster'],
        );
    } else {
        // There are no topics
        $insert_update_forum = array(
            'num_topics' => $num_topics,
            'num_posts'  => $num_posts,
            'last_post'  => 'NULL',
            'last_post_id'  => 'NULL',
            'last_poster'  => 'NULL',
        );
    }
        \DB::for_table('forums')
            ->where('id', $forum_id)
            ->find_one()
            ->set($insert_update_forum)
            ->save();
}


//
// Deletes any avatars owned by the specified user ID
//
function delete_avatar($user_id)
{
    global $feather_config;

    $filetypes = array('jpg', 'gif', 'png');

    // Delete user avatar
    foreach ($filetypes as $cur_type) {
        if (file_exists(FEATHER_ROOT.$feather_config['o_avatars_dir'].'/'.$user_id.'.'.$cur_type)) {
            @unlink(FEATHER_ROOT.$feather_config['o_avatars_dir'].'/'.$user_id.'.'.$cur_type);
        }
    }
}


//
// Delete a topic and all of its posts
//
function delete_topic($topic_id)
{
    // Delete the topic and any redirect topics
    $where_delete_topic = array(
            array('id' => $topic_id),
            array('moved_to' => $topic_id)
        );

    \DB::for_table('topics')
        ->where_any_is($where_delete_topic)
        ->delete_many();

    // Delete posts in topic
    \DB::for_table('posts')
        ->where('topic_id', $topic_id)
        ->delete_many();

    // Delete any subscriptions for this topic
    \DB::for_table('topic_subscriptions')
        ->where('topic_id', $topic_id)
        ->delete_many();
}


//
// Delete a single post
//
function delete_post($post_id, $topic_id)
{
    $result = \DB::for_table('posts')
                  ->select_many('id', 'poster', 'posted')
                  ->where('topic_id', $topic_id)
                  ->order_by_desc('id')
                  ->limit(2)
                  ->find_many();

    $i = 0;
    foreach ($result as $cur_result) {
        if ($i == 0) {
            $last_id = $cur_result['id'];
        }
        else {
            $second_last_id = $cur_result['id'];
            $second_poster = $cur_result['poster'];
            $second_posted = $cur_result['posted'];
        }
        ++$i;
   }

    // Delete the post
    \DB::for_table('posts')
        ->where('id', $post_id)
        ->find_one()
        ->delete();

    $search = new \FeatherBB\Search();

    $search->strip_search_index($post_id);

    // Count number of replies in the topic
    $num_replies = \DB::for_table('posts')->where('topic_id', $topic_id)->count() - 1;

    // If the message we deleted is the most recent in the topic (at the end of the topic)
    if ($last_id == $post_id) {
        // If there is a $second_last_id there is more than 1 reply to the topic
        if (isset($second_last_id)) {
             $update_topic = array(
                'last_post'  => $second_posted,
                'last_post_id'  => $second_last_id,
                'last_poster'  => $second_poster,
                'num_replies'  => $num_replies,
            );
            \DB::for_table('topics')
                ->where('id', $topic_id)
                ->find_one()
                ->set($update_topic)
                ->save();
        } else {
            // We deleted the only reply, so now last_post/last_post_id/last_poster is posted/id/poster from the topic itself
            \DB::for_table('topics')
                ->where('id', $topic_id)
                ->find_one()
                ->set_expr('last_post', 'posted')
                ->set_expr('last_post_id', 'id')
                ->set_expr('last_poster', 'poster')
                ->set('num_replies', $num_replies)
                ->save();
        }
    } else {
        // Otherwise we just decrement the reply counter
        \DB::for_table('topics')
            ->where('id', $topic_id)
            ->find_one()
            ->set('num_replies', $num_replies)
            ->save();
    }
}


//
// Replace censored words in $text
//
function censor_words($text)
{
    static $search_for, $replace_with;

    $feather = \Slim\Slim::getInstance();

    if (!$feather->cache->isCached('search_for')) {
        $feather->cache->store('search_for', \model\cache::get_censoring('search_for'));
        $search_for = $feather->cache->retrieve('search_for');
    }

    if (!$feather->cache->isCached('replace_with')) {
        $feather->cache->store('replace_with', \model\cache::get_censoring('replace_with'));
        $replace_with = $feather->cache->retrieve('replace_with');
    }

    if (!empty($search_for) && !empty($replace_with)) {
        return substr(ucp_preg_replace($search_for, $replace_with, ' '.$text.' '), 1, -1);
    } else {
        return $text;
    }
}


//
// Determines the correct title for $user
// $user must contain the elements 'username', 'title', 'posts', 'g_id' and 'g_user_title'
//
function get_title($user)
{
    global $feather_bans;
    static $ban_list;

    // If not already built in a previous call, build an array of lowercase banned usernames
    if (empty($ban_list)) {
        $ban_list = array();
        foreach ($feather_bans as $cur_ban) {
            $ban_list[] = utf8_strtolower($cur_ban['username']);
        }
    }

    // If the user has a custom title
    if ($user['title'] != '') {
        $user_title = feather_escape($user['title']);
    }
    // If the user is banned
    elseif (in_array(utf8_strtolower($user['username']), $ban_list)) {
        $user_title = __('Banned');
    }
    // If the user group has a default user title
    elseif ($user['g_user_title'] != '') {
        $user_title = feather_escape($user['g_user_title']);
    }
    // If the user is a guest
    elseif ($user['g_id'] == FEATHER_GUEST) {
        $user_title = __('Guest');
    }
    // If nothing else helps, we assign the default
    else {
        $user_title = __('Member');
    }

    return $user_title;
}


//
// Display a message
//
function message($msg, $http_status = null, $no_back_link = false)
{
    // Did we receive a custom header?
    if (!is_null($http_status)) {
        header('HTTP/1.1 ' . $http_status);
    }

    // Get Slim current session
    $feather = \Slim\Slim::getInstance();

    $http_status = (int) $http_status;
    if ($http_status > 0) {
        $feather->response->setStatus($http_status);
    }

    // Overwrite existing body
    $feather->response->setBody('');

    if (!defined('FEATHER_HEADER')) {
        $feather->view2->setPageInfo(array(
            'title' => array(feather_escape($feather->config['o_board_title']), __('Info')),
        ));
    }

    $feather->view2->setPageInfo(array(
        'message'    =>    $msg,
        'no_back_link'    => $no_back_link,
        ))->addTemplate('message.php')->display();

    // Don't display anything after a message
    $feather->stop();
}


//
// Format a time string according to $time_format and time zones
//
function format_time($timestamp, $date_only = false, $date_format = null, $time_format = null, $time_only = false, $no_text = false)
{
    global $forum_date_formats, $forum_time_formats;

    if ($timestamp == '') {
        return __('Never');
    }

    // Get Slim current session
    $feather = \Slim\Slim::getInstance();

    $diff = ($feather->user->timezone + $feather->user->dst) * 3600;
    $timestamp += $diff;
    $now = time();

    if (is_null($date_format)) {
        $date_format = $forum_date_formats[$feather->user->date_format];
    }

    if (is_null($time_format)) {
        $time_format = $forum_time_formats[$feather->user->time_format];
    }

    $date = gmdate($date_format, $timestamp);
    $today = gmdate($date_format, $now+$diff);
    $yesterday = gmdate($date_format, $now+$diff-86400);

    if (!$no_text) {
        if ($date == $today) {
            $date = __('Today');
        } elseif ($date == $yesterday) {
            $date = __('Yesterday');
        }
    }

    if ($date_only) {
        return $date;
    } elseif ($time_only) {
        return gmdate($time_format, $timestamp);
    } else {
        return $date.' '.gmdate($time_format, $timestamp);
    }
}


//
// A wrapper for PHP's number_format function
//
function forum_number_format($number, $decimals = 0)
{
    return is_numeric($number) ? number_format($number, $decimals, __('lang_decimal_point'), __('lang_thousands_sep')) : $number;
}


//
// Generate a random key of length $len
//
function random_key($len, $readable = false, $hash = false)
{
    if (!function_exists('secure_random_bytes')) {
        include FEATHER_ROOT.'include/srand.php';
    }

    $key = secure_random_bytes($len);

    if ($hash) {
        return substr(bin2hex($key), 0, $len);
    } elseif ($readable) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

        $result = '';
        for ($i = 0; $i < $len; ++$i) {
            $result .= substr($chars, (ord($key[$i]) % strlen($chars)), 1);
        }

        return $result;
    }

    return $key;
}


//
// Validate the given redirect URL, use the fallback otherwise
//
function validate_redirect($redirect_url, $fallback_url)
{
    $referrer = parse_url(strtolower($redirect_url));
    $feather = \Slim\Slim::getInstance();

    // Make sure the host component exists
    if (!isset($referrer['host'])) {
        $referrer['host'] = '';
    }

    // Remove www subdomain if it exists
    if (strpos($referrer['host'], 'www.') === 0) {
        $referrer['host'] = substr($referrer['host'], 4);
    }

    // Make sure the path component exists
    if (!isset($referrer['path'])) {
        $referrer['path'] = '';
    }

    $valid = parse_url(strtolower($feather->url->base()));

    // Remove www subdomain if it exists
    if (strpos($valid['host'], 'www.') === 0) {
        $valid['host'] = substr($valid['host'], 4);
    }

    // Make sure the path component exists
    if (!isset($valid['path'])) {
        $valid['path'] = '';
    }

    if ($referrer['host'] == $valid['host'] && preg_match('%^'.preg_quote($valid['path'], '%').'/(.*?)\.php%i', $referrer['path'])) {
        return $redirect_url;
    } else {
        return $fallback_url;
    }
}


//
// Generate a random password of length $len
// Compatibility wrapper for random_key
//
function random_pass($len)
{
    return random_key($len, true);
}


//
// Compute a hash of $str
//
function feather_hash($str)
{
    return sha1($str);
}


//
// Calls htmlspecialchars with a few options already set
//
function feather_escape($str)
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}


//
// Calls htmlspecialchars_decode with a few options already set
//
function feather_htmlspecialchars_decode($str)
{
    if (function_exists('htmlspecialchars_decode')) {
        return htmlspecialchars_decode($str, ENT_QUOTES);
    }

    static $translations;
    if (!isset($translations)) {
        $translations = get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES);
        $translations['&#039;'] = '\''; // get_html_translation_table doesn't include &#039; which is what htmlspecialchars translates ' to, but apparently that is okay?! http://bugs.php.net/bug.php?id=25927
        $translations = array_flip($translations);
    }

    return strtr($str, $translations);
}


//
// A wrapper for utf8_strlen for compatibility
//
function feather_strlen($str)
{
    return utf8_strlen($str);
}


//
// Convert \r\n and \r to \n
//
function feather_linebreaks($str)
{
    return str_replace(array("\r\n", "\r"), "\n", $str);
}


//
// A wrapper for utf8_trim for compatibility
//
function feather_trim($str, $charlist = false)
{
    return is_string($str) ? utf8_trim($str, $charlist) : '';
}

//
// Checks if a string is in all uppercase
//
function is_all_uppercase($string)
{
    return utf8_strtoupper($string) == $string && utf8_strtolower($string) != $string;
}


//
// Display $message and redirect user to $destination_url
//
function redirect($destination_url, $message = null)
{
    $feather = \Slim\Slim::getInstance();

    // Add a flash message
    $feather->flash('message', $message);

    $feather->redirect($destination_url);
}


//
// Converts the file size in bytes to a human readable file size
//
function file_size($size)
{
    $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB');

    for ($i = 0; $size > 1024; $i++) {
        $size /= 1024;
    }

    return sprintf(__('Size unit '.$units[$i]), round($size, 2));
}


//
// Fetch a list of available styles
//
function forum_list_styles()
{
    $styles = array();

    $d = dir(FEATHER_ROOT.'style');
    while (($entry = $d->read()) !== false) {
        if ($entry{0} == '.') {
            continue;
        }

        if (substr($entry, -4) == '.css') {
            $styles[] = substr($entry, 0, -4);
        }
    }
    $d->close();

    natcasesort($styles);

    return $styles;
}


//
// Fetch a list of available language packs
//
function forum_list_langs()
{
    $languages = array();

    $d = dir(FEATHER_ROOT.'lang');
    while (($entry = $d->read()) !== false) {
        if ($entry{0} == '.') {
            continue;
        }

        if (is_dir(FEATHER_ROOT.'lang/'.$entry) && file_exists(FEATHER_ROOT.'lang/'.$entry.'/common.po')) {
            $languages[] = $entry;
        }
    }
    $d->close();

    natcasesort($languages);

    return $languages;
}

//
// Replace string matching regular expression
//
// This function takes care of possibly disabled unicode properties in PCRE builds
//
function ucp_preg_replace($pattern, $replace, $subject, $callback = false)
{
    if ($callback) {
        $replaced = preg_replace_callback($pattern, create_function('$matches', 'return '.$replace.';'), $subject);
    } else {
        $replaced = preg_replace($pattern, $replace, $subject);
    }

    // If preg_replace() returns false, this probably means unicode support is not built-in, so we need to modify the pattern a little
    if ($replaced === false) {
        if (is_array($pattern)) {
            foreach ($pattern as $cur_key => $cur_pattern) {
                $pattern[$cur_key] = str_replace('\p{L}\p{N}', '\w', $cur_pattern);
            }

            $replaced = preg_replace($pattern, $replace, $subject);
        } else {
            $replaced = preg_replace(str_replace('\p{L}\p{N}', '\w', $pattern), $replace, $subject);
        }
    }

    return $replaced;
}

//
// Replace four-byte characters with a question mark
//
// As MySQL cannot properly handle four-byte characters with the default utf-8
// charset up until version 5.5.3 (where a special charset has to be used), they
// need to be replaced, by question marks in this case.
//
function strip_bad_multibyte_chars($str)
{
    $result = '';
    $length = strlen($str);

    for ($i = 0; $i < $length; $i++) {
        // Replace four-byte characters (11110www 10zzzzzz 10yyyyyy 10xxxxxx)
        $ord = ord($str[$i]);
        if ($ord >= 240 && $ord <= 244) {
            $result .= '?';
            $i += 3;
        } else {
            $result .= $str[$i];
        }
    }

    return $result;
}

//
// Generate a cache ID based on the last modification time for all stopwords files
//
function generate_stopwords_cache_id()
{
    $files = glob(FEATHER_ROOT.'lang/*/stopwords.txt');
    if ($files === false) {
        return 'cache_id_error';
    }

    $hash = array();

    foreach ($files as $file) {
        $hash[] = $file;
        $hash[] = filemtime($file);
    }

    return sha1(implode('|', $hash));
}

// Generate breadcrumbs from an array of name and URLs
function breadcrumbs_admin(array $links)
{
    foreach ($links as $name => $url) {
        if ($name != '' && $url != '') {
            $tmp[] = '<span><a href="' . $url . '">'.feather_escape($name).'</a></span>';
        } else {
            $tmp[] = '<span>'.__('Deleted').'</span>';
            return implode(' » ', $tmp);
        }
    }
    return implode(' » ', $tmp);
}

