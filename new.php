<?php

define('BASEPATH', 'Forum');
require_once('applications/wrapper.php');

if (!$TANGO->perm->check('create_thread')) {
    redirect(SITE_URL);
}//Checks if user has permission to create a thread.

$TANGO->tpl->getTpl('page');

if ($PGET->g('node')) {

    $node = clean($PGET->g('node'));
    $MYSQL->bind('id', $node);
    $query = $MYSQL->query("SELECT * FROM {prefix}forum_node WHERE id = :id");

    if (!empty($query)) {

        $allowed = explode(',', $query['0']['allowed_usergroups']);
        if (!in_array($TANGO->sess->data['user_group'], $allowed)) {
            redirect(SITE_URL . '/404.php');
        }

        if ($query['0']['node_locked'] == 1) {
            if (!$TANGO->perm->check('access_moderation')) {
                redirect(SITE_URL . '/404.php');
            }
        }

        $breadcrumbs = $TANGO->tpl->entity(
            'breadcrumbs_before',
            array(
                'link',
                'name'
            ),
            array(
                SITE_URL . '/forum.php',
                $LANG['bb']['forum']
            )
        );
        if ($query['0']['node_type'] == 2) {
            $parent_node = node($query['0']['parent_node']);
            $ori_cat = category($parent_node['in_category']);

            $breadcrumbs .= $TANGO->tpl->entity(
                'breadcrumbs_before',
                array(
                    'link',
                    'name'
                ),
                array(
                    '#',
                    $ori_cat['category_title']
                )
            );

            $breadcrumbs .= $TANGO->tpl->entity(
                'breadcrumbs_before',
                array(
                    'link',
                    'name'
                ),
                array(
                    SITE_URL . '/node.php/' . $parent_node['name_friendly'] . '.' . $parent_node['id'],
                    $parent_node['node_name']
                )
            );

            $breadcrumbs .= $TANGO->tpl->entity(
                'breadcrumbs_before',
                array(
                    'link',
                    'name'
                ),
                array(
                    SITE_URL . '/node.php/' . $query['0']['name_friendly'] . '.' . $query['0']['id'],
                    $query['0']['node_name']
                )
            );

        } elseif ($query['0']['node_type'] == 1) {

            $ori_cat = category($query['0']['in_category']);

            $breadcrumbs .= $TANGO->tpl->entity(
                'breadcrumbs_before',
                array(
                    'link',
                    'name'
                ),
                array(
                    '#',
                    $ori_cat['category_title']
                )
            );

            $breadcrumbs .= $TANGO->tpl->entity(
                'breadcrumbs_before',
                array(
                    'link',
                    'name'
                ),
                array(
                    SITE_URL . '/node.php/' . $query['0']['name_friendly'] . '.' . $query['0']['id'],
                    $query['0']['node_name']
                )
            );

        }

        $breadcrumbs .= $TANGO->tpl->entity(
            'breadcrumbs_current',
            'name',
            $LANG['bb']['new_thread_breadcrumb']
        );

        $breadcrumbs = $TANGO->tpl->entity(
            'breadcrumbs',
            'bread',
            $breadcrumbs
        );

        $notice = '';
        $content = '';

        if (isset($_POST['create'])) {
            try {
                NoCSRF::check('csrf_token', $_POST);
                $thread_title = clean($_POST['title']);
                $thread_cont = emoji_to_text($_POST['content']);
                $label_id = $_POST['label'];
                $MYSQL->bind('post_user', $TANGO->sess->data['id']);
                $c_query = $MYSQL->query("SELECT * FROM {prefix}forum_posts WHERE post_user = :post_user ORDER BY post_time DESC LIMIT 1");
                $c_query = (empty($c_query)) ? array(array('post_content' => '')) : $c_query;

                if (!$thread_title or !$thread_cont) {
                    throw new Exception ($LANG['global_form_process']['all_fields_required']);
                } elseif ($c_query['0']['post_content'] == $thread_cont) {
                    throw new Exception ($LANG['global_form_process']['different_message_previous']);
                } else {

                    $friendly_url = title_friendly($thread_title);
                    $tags = explode('_', $friendly_url);
                    $tags = implode(',', $tags);
                    $time = time();

                    $MYSQL->bindMore(array(
                        'post_title' => $thread_title,
                        'title_friendly' => $friendly_url,
                        'post_content' => $thread_cont,
                        'post_tags' => $tags,
                        'post_time' => $time,
                        'post_user' => $TANGO->sess->data['id'],
                        'origin_node' => $node,
                        'post_type' => '1',
                        'last_updated' => $time,
                        'watchers' => $TANGO->sess->data['id'],
                        'label' => $label_id
                    ));

                    /*
                     * Mentions
                     */
                    preg_match_all('/@(\w+)/', $thread_cont, $mentions);
                    $mentions = array_filter(array_unique($mentions['1']));
                    if (!empty($mentions['1'])) {
                        foreach ($mentions['1'] as $mention) {
                            if ($TANGO->sess->data['username'] !== $mention) {
                                $user = $TANGO->user($mention);
                                $TANGO->user->notifyUser(
                                    'mention',
                                    $user['id'],
                                    true,
                                    array(
                                        'username' => $TANGO->sess->data['username'],
                                        'link' => SITE_URL . '/thread.php/' . $origin['title_friendly'] . '.' . $origin['id']
                                    )
                                );
                            }
                        }
                    }

                    try {
                        $MYSQL->query("INSERT INTO {prefix}forum_posts (post_title, title_friendly, post_content, post_tags, post_time, post_user, origin_node, post_type, last_updated, watchers, label)
                                         VALUES
                                         (:post_title, :title_friendly, :post_content, :post_tags, :post_time, :post_user, :origin_node, :post_type, :last_updated, :watchers, :label)");

                        $MYSQL->bind('post_time', $time);
                        $tid = $MYSQL->query("SELECT * FROM {prefix}forum_posts WHERE post_time = :post_time;");
                        if (!empty($_POST['question'])) {
                            $MYSQL->bind('thread_id', $tid['0']['id']);
                            $MYSQL->bind('question', $_POST['question']);
                            $MYSQL->query("INSERT INTO {prefix}poll (question, thread_id) VALUES (:question, :thread_id);");
                            $poll_id = $MYSQL->query("SELECT LAST_INSERT_ID(id) AS LAST_ID FROM {prefix}poll ORDER BY id DESC LIMIT 1;");
                            for ($i = 1; ; $i++) {
                                if (!isset($_POST['answer_' . $i])) {
                                    break;
                                }
                                if (!empty($_POST['answer_' . $i])) {
                                    $MYSQL->bind('poll_id', $poll_id['0']['LAST_ID']);
                                    $MYSQL->bind('answer', $_POST['answer_' . $i]);
                                    $MYSQL->query("INSERT INTO {prefix}poll_answers (poll_id, answer) VALUES (:poll_id, :answer);");
                                }
                            }
                        }
                        $notice .= $TANGO->tpl->entity(
                            'success_notice',
                            'content',
                            $LANG['global_form_process']['thread_create_success']
                        );
                        $community = $MYSQL->query("SELECT id FROM {prefix}users");
                        foreach ($community as $user) {
                            $TANGO->node->thread_mark_unread($tid['0']['id'], $user['id'], '0');
                        }
                        redirect(SITE_URL . '/thread.php/' . $friendly_url . '.' . $tid['0']['id']);

                    } catch (mysqli_sql_exception $e) {
                        throw new Exception ($LANG['global_form_process']['error_creating_thread']);
                    }

                }

            } catch (Exception $e) {
                $notice .= $TANGO->tpl->entity(
                    'danger_notice',
                    'content',
                    $e->getMessage()
                );
            }
        }

        define('CSRF_TOKEN', NoCSRF::generate('csrf_token'));
        define('CSRF_INPUT', '<input type="hidden" name="csrf_token" value="' . CSRF_TOKEN . '">');

        $MYSQL->bind('node_id', $node);
        $label_qry = $MYSQL->query("SELECT id,label FROM {prefix}labels WHERE node_id = :node_id");
        $width = 100;
        $labels = "";
        if (!empty($label_qry)) {
            $width_labels = 15;
            $width = $width - $width_labels;
            $labels = '<select name="label" size="1" style="width: ' . $width_labels . '%; float: left;">';
            foreach ($label_qry as $label) {
                $labels .= '<option value="' . $label['id'] . '">' . $label['label'] . '</option>';
            }
            $labels .= '</select>';
        }
        $content .= $TANGO->tpl->entity(
            'create_thread',
            array(
                'breadcrumbs',
                'form_id',
                'csrf_input',
                'create_thread_form_action',
                'title_name',
                'editor_id',
                'textarea_name',
                'submit_name',
                'labels',
                'width'
            ),
            array(
                $breadcrumbs,
                'tango_form',
                CSRF_INPUT,
                SITE_URL . '/new.php/node/' . $node,
                'title',
                'editor',
                'content',
                'create',
                $labels,
                $width
            )
        );
        $icon_package = array();

        foreach ($ICONS as $category => $icons_cat) {
            $icon_package[$category] = '';
            foreach ($icons_cat as $code => $html) {
                $icon_package[$category] .= '<a href="javascript:add_emoji(\'' . $code . '\');"><span style="font-size: 30px;" title="' . $code . '">' . $html . '</span></a> ';
            }
        }
        $content .= $TANGO->tpl->entity(
            'smiley_list',
            array(
                'smilies',
                'misc',
                'food',
                'animals',
                'plants'
            ),
            array(
                $icon_package['smilies'],
                $icon_package['misc'],
                $icon_package['food'],
                $icon_package['animals'],
                $icon_package['plants']
            )
        );
        $content .= $TANGO->tpl->entity(
            'thread_options',
            array(),
            array(

            )
        );


        $TANGO->tpl->addParam(
            array(
                'page_title',
                'content',
                'description'
            ),
            array(
                $LANG['bb']['new_thread_in'] . ' ' . $query['0']['node_name'],
                $notice . $content,
                ''
            )
        );

    } else {
        redirect(SITE_URL);
    }

} else {
    redirect(SITE_URL);
}

echo $TANGO->tpl->output();

?>
