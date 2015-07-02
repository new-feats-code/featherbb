<?php

/**
 * Copyright (C) 2015 FeatherBB
 * based on code by (C) 2008-2012 FluxBB
 * and Rickard Andersson (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */
 
function get_num_ip($ip_stats)
{
    global $db;
    
    $result = $db->query('SELECT poster_ip, MAX(posted) AS last_used FROM '.$db->prefix.'posts WHERE poster_id='.$ip_stats.' GROUP BY poster_ip') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
    $num_ips = $db->num_rows($result);
    
    return $num_ips;
}

function get_ip_stats($ip_stats, $start_from)
{
    global $db;
    
    $ip_data = array();
    
    $result = $db->query('SELECT poster_ip, MAX(posted) AS last_used, COUNT(id) AS used_times FROM '.$db->prefix.'posts WHERE poster_id='.$ip_stats.' GROUP BY poster_ip ORDER BY last_used DESC LIMIT '.$start_from.', 50') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
    if ($db->num_rows($result)) {
        while ($cur_ip = $db->fetch_assoc($result)) {
            $ip_data[] = $cur_ip;
        }
    }
    
    return $ip_data;
}

function get_num_users_ip($ip)
{
    global $db;
    
    $result = $db->query('SELECT DISTINCT poster_id, poster FROM '.$db->prefix.'posts WHERE poster_ip=\''.$db->escape($ip).'\'') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
    $num_users = $db->num_rows($result);
    
    return $num_users;
}

function get_num_users_search($conditions)
{
    global $db;
    
    $result = $db->query('SELECT COUNT(id) FROM '.$db->prefix.'users AS u LEFT JOIN '.$db->prefix.'groups AS g ON g.g_id=u.group_id WHERE u.id>1'.(!empty($conditions) ? ' AND '.implode(' AND ', $conditions) : '')) or error('Unable to fetch user info', __FILE__, __LINE__, $db->error());
    $num_users = $db->result($result);
    
    return $num_users;
}

function get_info_poster($ip, $start_from)
{
    global $db;
    
    $info = array();
    
    $result = $db->query('SELECT DISTINCT poster_id, poster FROM '.$db->prefix.'posts WHERE poster_ip=\''.$db->escape($ip).'\' ORDER BY poster ASC LIMIT '.$start_from.', 50') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
    $info['num_posts'] = $db->num_rows($result);

    if ($info['num_posts']) {
        $poster_ids = array();
        while ($cur_poster = $db->fetch_assoc($result)) {
            $info['posters'][] = $cur_poster;
            $poster_ids[] = $cur_poster['poster_id'];
        }

        $result = $db->query('SELECT u.id, u.username, u.email, u.title, u.num_posts, u.admin_note, g.g_id, g.g_user_title FROM '.$db->prefix.'users AS u INNER JOIN '.$db->prefix.'groups AS g ON g.g_id=u.group_id WHERE u.id>1 AND u.id IN('.implode(',', $poster_ids).')') or error('Unable to fetch user info', __FILE__, __LINE__, $db->error());

        while ($cur_user = $db->fetch_assoc($result)) {
            $info['user_data'][$cur_user['id']] = $cur_user;
        }
    }
    
    return $info;
}

function move_users($feather)
{
    global $db, $lang_admin_users;
    
    confirm_referrer(get_link_r('admin/users/'));
    
    $move = array();

    if ($feather->request->post('users')) {
        $move['user_ids'] = is_array($feather->request->post('users')) ? array_keys($feather->request->post('users')) : explode(',', $feather->request->post('users'));
        $move['user_ids'] = array_map('intval', $move['user_ids']);

        // Delete invalid IDs
        $move['user_ids'] = array_diff($move['user_ids'], array(0, 1));
    } else {
        $move['user_ids'] = array();
    }

    if (empty($move['user_ids'])) {
        message($lang_admin_users['No users selected']);
    }

    // Are we trying to batch move any admins?
    $result = $db->query('SELECT COUNT(*) FROM '.$db->prefix.'users WHERE id IN ('.implode(',', $move['user_ids']).') AND group_id='.PUN_ADMIN) or error('Unable to fetch user info', __FILE__, __LINE__, $db->error());
    if ($db->result($result) > 0) {
        message($lang_admin_users['No move admins message']);
    }

    // Fetch all user groups
    $result = $db->query('SELECT g_id, g_title FROM '.$db->prefix.'groups WHERE g_id NOT IN ('.PUN_GUEST.','.PUN_ADMIN.') ORDER BY g_title ASC') or error('Unable to fetch groups', __FILE__, __LINE__, $db->error());
    while ($row = $db->fetch_row($result)) {
        $move['all_groups'][$row[0]] = $row[1];
    }

    if ($feather->request->post('move_users_comply')) {
        $new_group = $feather->request->post('new_group') && isset($move['all_groups'][$feather->request->post('new_group')]) ? $feather->request->post('new_group') : message($lang_admin_users['Invalid group message']);

        // Is the new group a moderator group?
        $result = $db->query('SELECT g_moderator FROM '.$db->prefix.'groups WHERE g_id='.$new_group) or error('Unable to fetch group info', __FILE__, __LINE__, $db->error());
        $new_group_mod = $db->result($result);

        // Fetch user groups
        $user_groups = array();
        $result = $db->query('SELECT id, group_id FROM '.$db->prefix.'users WHERE id IN ('.implode(',', $move['user_ids']).')') or error('Unable to fetch user groups', __FILE__, __LINE__, $db->error());
        while ($cur_user = $db->fetch_assoc($result)) {
            if (!isset($user_groups[$cur_user['group_id']])) {
                $user_groups[$cur_user['group_id']] = array();
            }

            $user_groups[$cur_user['group_id']][] = $cur_user['id'];
        }

        // Are any users moderators?
        $group_ids = array_keys($user_groups);
        $result = $db->query('SELECT g_id, g_moderator FROM '.$db->prefix.'groups WHERE g_id IN ('.implode(',', $group_ids).')') or error('Unable to fetch group moderators', __FILE__, __LINE__, $db->error());
        while ($cur_group = $db->fetch_assoc($result)) {
            if ($cur_group['g_moderator'] == '0') {
                unset($user_groups[$cur_group['g_id']]);
            }
        }

        if (!empty($user_groups) && $new_group != PUN_ADMIN && $new_group_mod != '1') {
            // Fetch forum list and clean up their moderator list
            $result = $db->query('SELECT id, moderators FROM '.$db->prefix.'forums') or error('Unable to fetch forum list', __FILE__, __LINE__, $db->error());
            while ($cur_forum = $db->fetch_assoc($result)) {
                $cur_moderators = ($cur_forum['moderators'] != '') ? unserialize($cur_forum['moderators']) : array();

                foreach ($user_groups as $group_users) {
                    $cur_moderators = array_diff($cur_moderators, $group_users);
                }

                $cur_moderators = (!empty($cur_moderators)) ? '\''.$db->escape(serialize($cur_moderators)).'\'' : 'NULL';
                $db->query('UPDATE '.$db->prefix.'forums SET moderators='.$cur_moderators.' WHERE id='.$cur_forum['id']) or error('Unable to update forum', __FILE__, __LINE__, $db->error());
            }
        }

        // Change user group
        $db->query('UPDATE '.$db->prefix.'users SET group_id='.$new_group.' WHERE id IN ('.implode(',', $move['user_ids']).')') or error('Unable to change user group', __FILE__, __LINE__, $db->error());

        redirect(get_link('admin/users/'), $lang_admin_users['Users move redirect']);
    }
    
    return $move;
}

function delete_users($feather)
{
    global $db, $lang_admin_users;
    
    confirm_referrer(get_link_r('admin/users/'));

    if ($feather->request->post('users')) {
        $user_ids = is_array($feather->request->post('users')) ? array_keys($feather->request->post('users')) : explode(',', $feather->request->post('users'));
        $user_ids = array_map('intval', $user_ids);

        // Delete invalid IDs
        $user_ids = array_diff($user_ids, array(0, 1));
    } else {
        $user_ids = array();
    }

    if (empty($user_ids)) {
        message($lang_admin_users['No users selected']);
    }

    // Are we trying to delete any admins?
    $result = $db->query('SELECT COUNT(*) FROM '.$db->prefix.'users WHERE id IN ('.implode(',', $user_ids).') AND group_id='.PUN_ADMIN) or error('Unable to fetch user info', __FILE__, __LINE__, $db->error());
    if ($db->result($result) > 0) {
        message($lang_admin_users['No delete admins message']);
    }

    if ($feather->request->post('delete_users_comply')) {
        // Fetch user groups
        $user_groups = array();
        $result = $db->query('SELECT id, group_id FROM '.$db->prefix.'users WHERE id IN ('.implode(',', $user_ids).')') or error('Unable to fetch user groups', __FILE__, __LINE__, $db->error());
        while ($cur_user = $db->fetch_assoc($result)) {
            if (!isset($user_groups[$cur_user['group_id']])) {
                $user_groups[$cur_user['group_id']] = array();
            }

            $user_groups[$cur_user['group_id']][] = $cur_user['id'];
        }

        // Are any users moderators?
        $group_ids = array_keys($user_groups);
        $result = $db->query('SELECT g_id, g_moderator FROM '.$db->prefix.'groups WHERE g_id IN ('.implode(',', $group_ids).')') or error('Unable to fetch group moderators', __FILE__, __LINE__, $db->error());
        while ($cur_group = $db->fetch_assoc($result)) {
            if ($cur_group['g_moderator'] == '0') {
                unset($user_groups[$cur_group['g_id']]);
            }
        }

        // Fetch forum list and clean up their moderator list
        $result = $db->query('SELECT id, moderators FROM '.$db->prefix.'forums') or error('Unable to fetch forum list', __FILE__, __LINE__, $db->error());
        while ($cur_forum = $db->fetch_assoc($result)) {
            $cur_moderators = ($cur_forum['moderators'] != '') ? unserialize($cur_forum['moderators']) : array();

            foreach ($user_groups as $group_users) {
                $cur_moderators = array_diff($cur_moderators, $group_users);
            }

            $cur_moderators = (!empty($cur_moderators)) ? '\''.$db->escape(serialize($cur_moderators)).'\'' : 'NULL';
            $db->query('UPDATE '.$db->prefix.'forums SET moderators='.$cur_moderators.' WHERE id='.$cur_forum['id']) or error('Unable to update forum', __FILE__, __LINE__, $db->error());
        }

        // Delete any subscriptions
        $db->query('DELETE FROM '.$db->prefix.'topic_subscriptions WHERE user_id IN ('.implode(',', $user_ids).')') or error('Unable to delete topic subscriptions', __FILE__, __LINE__, $db->error());
        $db->query('DELETE FROM '.$db->prefix.'forum_subscriptions WHERE user_id IN ('.implode(',', $user_ids).')') or error('Unable to delete forum subscriptions', __FILE__, __LINE__, $db->error());

        // Remove them from the online list (if they happen to be logged in)
        $db->query('DELETE FROM '.$db->prefix.'online WHERE user_id IN ('.implode(',', $user_ids).')') or error('Unable to remove users from online list', __FILE__, __LINE__, $db->error());

        // Should we delete all posts made by these users?
        if ($feather->request->post('delete_posts')) {
            require FEATHER_ROOT.'include/search_idx.php';
            @set_time_limit(0);

            // Find all posts made by this user
            $result = $db->query('SELECT p.id, p.topic_id, t.forum_id FROM '.$db->prefix.'posts AS p INNER JOIN '.$db->prefix.'topics AS t ON t.id=p.topic_id INNER JOIN '.$db->prefix.'forums AS f ON f.id=t.forum_id WHERE p.poster_id IN ('.implode(',', $user_ids).')') or error('Unable to fetch posts', __FILE__, __LINE__, $db->error());
            if ($db->num_rows($result)) {
                while ($cur_post = $db->fetch_assoc($result)) {
                    // Determine whether this post is the "topic post" or not
                    $result2 = $db->query('SELECT id FROM '.$db->prefix.'posts WHERE topic_id='.$cur_post['topic_id'].' ORDER BY posted LIMIT 1') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());

                    if ($db->result($result2) == $cur_post['id']) {
                        delete_topic($cur_post['topic_id']);
                    } else {
                        delete_post($cur_post['id'], $cur_post['topic_id']);
                    }

                    update_forum($cur_post['forum_id']);
                }
            }
        } else {
            // Set all their posts to guest
            $db->query('UPDATE '.$db->prefix.'posts SET poster_id=1 WHERE poster_id IN ('.implode(',', $user_ids).')') or error('Unable to update posts', __FILE__, __LINE__, $db->error());
        }

        // Delete the users
        $db->query('DELETE FROM '.$db->prefix.'users WHERE id IN ('.implode(',', $user_ids).')') or error('Unable to delete users', __FILE__, __LINE__, $db->error());

        // Delete user avatars
        foreach ($user_ids as $user_id) {
            delete_avatar($user_id);
        }

        // Regenerate the users info cache
        if (!defined('FORUM_CACHE_FUNCTIONS_LOADED')) {
            require FEATHER_ROOT.'include/cache.php';
        }

        generate_users_info_cache();

        redirect(get_link('admin/users/'), $lang_admin_users['Users delete redirect']);
    }
    
    return $user_ids;
}

function ban_users($feather)
{
    global $db, $lang_admin_users, $feather_user;
    
    confirm_referrer(get_link_r('admin/users/'));

    if ($feather->request->post('users')) {
        $user_ids = is_array($feather->request->post('users')) ? array_keys($feather->request->post('users')) : explode(',', $feather->request->post('users'));
        $user_ids = array_map('intval', $user_ids);

        // Delete invalid IDs
        $user_ids = array_diff($user_ids, array(0, 1));
    } else {
        $user_ids = array();
    }

    if (empty($user_ids)) {
        message($lang_admin_users['No users selected']);
    }

    // Are we trying to ban any admins?
    $result = $db->query('SELECT COUNT(*) FROM '.$db->prefix.'users WHERE id IN ('.implode(',', $user_ids).') AND group_id='.PUN_ADMIN) or error('Unable to fetch group info', __FILE__, __LINE__, $db->error());
    if ($db->result($result) > 0) {
        message($lang_admin_users['No ban admins message']);
    }

    // Also, we cannot ban moderators
    $result = $db->query('SELECT COUNT(*) FROM '.$db->prefix.'users AS u INNER JOIN '.$db->prefix.'groups AS g ON u.group_id=g.g_id WHERE g.g_moderator=1 AND u.id IN ('.implode(',', $user_ids).')') or error('Unable to fetch moderator group info', __FILE__, __LINE__, $db->error());
    if ($db->result($result) > 0) {
        message($lang_admin_users['No ban mods message']);
    }

    if ($feather->request->post('ban_users_comply')) {
        $ban_message = pun_trim($feather->request->post('ban_message'));
        $ban_expire = pun_trim($feather->request->post('ban_expire'));
        $ban_the_ip = $feather->request->post('ban_the_ip') ? intval($feather->request->post('ban_the_ip')) : 0;

        if ($ban_expire != '' && $ban_expire != 'Never') {
            $ban_expire = strtotime($ban_expire.' GMT');

            if ($ban_expire == -1 || !$ban_expire) {
                message($lang_admin_users['Invalid date message'].' '.$lang_admin_users['Invalid date reasons']);
            }

            $diff = ($feather_user['timezone'] + $feather_user['dst']) * 3600;
            $ban_expire -= $diff;

            if ($ban_expire <= time()) {
                message($lang_admin_users['Invalid date message'].' '.$lang_admin_users['Invalid date reasons']);
            }
        } else {
            $ban_expire = 'NULL';
        }

        $ban_message = ($ban_message != '') ? '\''.$db->escape($ban_message).'\'' : 'NULL';

        // Fetch user information
        $user_info = array();
        $result = $db->query('SELECT id, username, email, registration_ip FROM '.$db->prefix.'users WHERE id IN ('.implode(',', $user_ids).')') or error('Unable to fetch user info', __FILE__, __LINE__, $db->error());
        while ($cur_user = $db->fetch_assoc($result)) {
            $user_info[$cur_user['id']] = array('username' => $cur_user['username'], 'email' => $cur_user['email'], 'ip' => $cur_user['registration_ip']);
        }

        // Overwrite the registration IP with one from the last post (if it exists)
        if ($ban_the_ip != 0) {
            $result = $db->query('SELECT p.poster_id, p.poster_ip FROM '.$db->prefix.'posts AS p INNER JOIN (SELECT MAX(id) AS id FROM '.$db->prefix.'posts WHERE poster_id IN ('.implode(',', $user_ids).') GROUP BY poster_id) AS i ON p.id=i.id') or error('Unable to fetch post info', __FILE__, __LINE__, $db->error());
            while ($cur_address = $db->fetch_assoc($result)) {
                $user_info[$cur_address['poster_id']]['ip'] = $cur_address['poster_ip'];
            }
        }

        // And insert the bans!
        foreach ($user_ids as $user_id) {
            $ban_username = '\''.$db->escape($user_info[$user_id]['username']).'\'';
            $ban_email = '\''.$db->escape($user_info[$user_id]['email']).'\'';
            $ban_ip = ($ban_the_ip != 0) ? '\''.$db->escape($user_info[$user_id]['ip']).'\'' : 'NULL';

            $db->query('INSERT INTO '.$db->prefix.'bans (username, ip, email, message, expire, ban_creator) VALUES('.$ban_username.', '.$ban_ip.', '.$ban_email.', '.$ban_message.', '.$ban_expire.', '.$feather_user['id'].')') or error('Unable to add ban', __FILE__, __LINE__, $db->error());
        }

        // Regenerate the bans cache
        if (!defined('FORUM_CACHE_FUNCTIONS_LOADED')) {
            require FEATHER_ROOT.'include/cache.php';
        }

        generate_bans_cache();

        redirect(get_link('admin/users/'), $lang_admin_users['Users banned redirect']);
    }
    
    return $user_ids;
}

function get_user_search($feather)
{
    global $db, $db_type;
    
    $form = $feather->request->get('form') ? $feather->request->get('form') : array();
    
    $search = array();

    // trim() all elements in $form
    $form = array_map('pun_trim', $form);

    $posts_greater = $feather->request->get('posts_greater') ? pun_trim($feather->request->get('posts_greater')) : '';
    $posts_less = $feather->request->get('posts_less') ? pun_trim($feather->request->get('posts_less')) : '';
    $last_post_after = $feather->request->get('last_post_after') ? pun_trim($feather->request->get('last_post_after')) : '';
    $last_post_before = $feather->request->get('last_post_before') ? pun_trim($feather->request->get('last_post_before')) : '';
    $last_visit_after = $feather->request->get('last_visit_after') ? pun_trim($feather->request->get('last_visit_after')) : '';
    $last_visit_before = $feather->request->get('last_visit_before') ? pun_trim($feather->request->get('last_visit_before')) : '';
    $registered_after = $feather->request->get('registered_after') ? pun_trim($feather->request->get('registered_after')) : '';
    $registered_before = $feather->request->get('registered_before') ? pun_trim($feather->request->get('registered_before')) : '';
    $order_by = $search['order_by'] = $feather->request->get('order_by') && in_array($_GET['order_by'], array('username', 'email', 'num_posts', 'last_post', 'last_visit', 'registered')) ? $feather->request->get('order_by') : 'username';
    $direction = $search['direction'] = $feather->request->get('direction') && $feather->request->get('direction') == 'DESC' ? 'DESC' : 'ASC';
    $user_group = $feather->request->get('user_group') ? intval($feather->request->get('user_group')) : -1;

    $search['query_str'][] = 'order_by='.$order_by;
    $search['query_str'][] = 'direction='.$direction;
    $search['query_str'][] = 'user_group='.$user_group;

    if (preg_match('%[^0-9]%', $posts_greater.$posts_less)) {
        message($lang_admin_users['Non numeric message']);
    }
    
    $search['conditions'] = array();

    // Try to convert date/time to timestamps
    if ($last_post_after != '') {
        $search['query_str'][] = 'last_post_after='.$last_post_after;

        $last_post_after = strtotime($last_post_after);
        if ($last_post_after === false || $last_post_after == -1) {
            message($lang_admin_users['Invalid date time message']);
        }

        $search['conditions'][] = 'u.last_post>'.$last_post_after;
    }
    if ($last_post_before != '') {
        $search['query_str'][] = 'last_post_before='.$last_post_before;

        $last_post_before = strtotime($last_post_before);
        if ($last_post_before === false || $last_post_before == -1) {
            message($lang_admin_users['Invalid date time message']);
        }

        $search['conditions'][] = 'u.last_post<'.$last_post_before;
    }
    if ($last_visit_after != '') {
        $search['query_str'][] = 'last_visit_after='.$last_visit_after;

        $last_visit_after = strtotime($last_visit_after);
        if ($last_visit_after === false || $last_visit_after == -1) {
            message($lang_admin_users['Invalid date time message']);
        }

        $search['conditions'][] = 'u.last_visit>'.$last_visit_after;
    }
    if ($last_visit_before != '') {
        $search['query_str'][] = 'last_visit_before='.$last_visit_before;

        $last_visit_before = strtotime($last_visit_before);
        if ($last_visit_before === false || $last_visit_before == -1) {
            message($lang_admin_users['Invalid date time message']);
        }

        $search['conditions'][] = 'u.last_visit<'.$last_visit_before;
    }
    if ($registered_after != '') {
        $search['query_str'][] = 'registered_after='.$registered_after;

        $registered_after = strtotime($registered_after);
        if ($registered_after === false || $registered_after == -1) {
            message($lang_admin_users['Invalid date time message']);
        }

        $search['conditions'][] = 'u.registered>'.$registered_after;
    }
    if ($registered_before != '') {
        $search['query_str'][] = 'registered_before='.$registered_before;

        $registered_before = strtotime($registered_before);
        if ($registered_before === false || $registered_before == -1) {
            message($lang_admin_users['Invalid date time message']);
        }

        $search['conditions'][] = 'u.registered<'.$registered_before;
    }

    $like_command = ($db_type == 'pgsql') ? 'ILIKE' : 'LIKE';
    foreach ($form as $key => $input) {
        if ($input != '' && in_array($key, array('username', 'email', 'title', 'realname', 'url', 'jabber', 'icq', 'msn', 'aim', 'yahoo', 'location', 'signature', 'admin_note'))) {
            $search['conditions'][] = 'u.'.$db->escape($key).' '.$like_command.' \''.$db->escape(str_replace('*', '%', $input)).'\'';
            $search['query_str'][] = 'form%5B'.$key.'%5D='.urlencode($input);
        }
    }

    if ($posts_greater != '') {
        $search['query_str'][] = 'posts_greater='.$posts_greater;
        $search['conditions'][] = 'u.num_posts>'.$posts_greater;
    }
    if ($posts_less != '') {
        $search['query_str'][] = 'posts_less='.$posts_less;
        $search['conditions'][] = 'u.num_posts<'.$posts_less;
    }

    if ($user_group > -1) {
        $search['conditions'][] = 'u.group_id='.$user_group;
    }
    
    return $search;
}

function print_users($conditions, $order_by, $direction, $start_from)
{
    global $db;
    
    $user_data = array();
    
    $result = $db->query('SELECT u.id, u.username, u.email, u.title, u.num_posts, u.admin_note, g.g_id, g.g_user_title FROM '.$db->prefix.'users AS u LEFT JOIN '.$db->prefix.'groups AS g ON g.g_id=u.group_id WHERE u.id>1'.(!empty($conditions) ? ' AND '.implode(' AND ', $conditions) : '').' ORDER BY '.$db->escape($order_by).' '.$db->escape($direction).' LIMIT '.$start_from.', 50') or error('Unable to fetch user info', __FILE__, __LINE__, $db->error());
    if ($db->num_rows($result)) {
        while ($cur_user = $db->fetch_assoc($result)) {
            $cur_user['user_title'] = get_title($cur_user);

            // This script is a special case in that we want to display "Not verified" for non-verified users
            if (($cur_user['g_id'] == '' || $cur_user['g_id'] == PUN_UNVERIFIED) && $cur_user['user_title'] != $lang_common['Banned']) {
                $cur_user['user_title'] = '<span class="warntext">'.$lang_admin_users['Not verified'].'</span>';
            }
            
            $user_data[] = $cur_user;
        }
    }
    
    return $user_data;
}

function get_group_list()
{
    global $db;
    
    $result = $db->query('SELECT g_id, g_title FROM '.$db->prefix.'groups WHERE g_id!='.PUN_GUEST.' ORDER BY g_title') or error('Unable to fetch user group list', __FILE__, __LINE__, $db->error());

    while ($cur_group = $db->fetch_assoc($result)) {
        echo "\t\t\t\t\t\t\t\t\t\t\t".'<option value="'.$cur_group['g_id'].'">'.pun_htmlspecialchars($cur_group['g_title']).'</option>'."\n";
    }
}
