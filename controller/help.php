<?php

/**
 * Copyright (C) 2015 FeatherBB
 * based on code by (C) 2008-2012 FluxBB
 * and Rickard Andersson (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

namespace controller;

class help
{
    public function __construct()
    {
        $this->feather = \Slim\Slim::getInstance();
        $this->db = $this->feather->db;
        $this->start = $this->feather->start;
        $this->config = $this->feather->config;
        $this->user = $this->feather->user;
        $this->request = $this->feather->request;
        $this->header = new \controller\header();
        $this->footer = new \controller\footer();
        $this->model = new \model\help();
    }

    public function __autoload($class_name)
    {
        require FEATHER_ROOT . $class_name . '.php';
    }
    
    public function display()
    {
        global $lang_common;

        if ($this->user['g_read_board'] == '0') {
            message($lang_common['No view'], false, '403 Forbidden');
        }


        // Load the help.php language file
        require FEATHER_ROOT.'lang/'.$this->user['language'].'/help.php';


        $page_title = array(pun_htmlspecialchars($this->config['o_board_title']), $lang_help['Help']);

        define('FEATHER_ACTIVE_PAGE', 'help');

        $this->header->display();

        $this->feather->render('help.php', array(
                            'lang_help' => $lang_help,
                            'lang_common' => $lang_common,
                            )
                    );

        $this->footer->display();
    }
}
