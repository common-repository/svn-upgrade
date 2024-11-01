<?php
/*
Plugin Name: SVN Upgrade
Plugin URI: http://www.anorgan.com/
Description: Don't have access to your hosting provider so you can do subversion update? Or your hosting doesn't have subversion installed. Well, this is where my plug-in comes in!
Author: Marin Crnković (marin.crnkovic@gmail.com)
Version: 1.0
Author URI: http://www.anorgan.com/
*/





if( ! function_exists('svn_upgrade_page') ) {
    function svn_upgrade_page() {
        global $wpdb;

        $table_name = $wpdb->prefix . "svn";

        require(ABSPATH . 'wp-content/plugins/wp-svn/definitions.php');
        require(ABSPATH . 'wp-content/plugins/wp-svn/http.php');
        require(ABSPATH . 'wp-content/plugins/wp-svn/xml_parser.php');
        require(ABSPATH . 'wp-content/plugins/wp-svn/phpsvnclient.php');

        /**
        *  Creating new phpSVNClient Object.
        */
        $svn  = new phpSVNclient;
        $svn->verbose = false;
        $svn_repository = get_option('svn_repository');
        $svn_username = get_option('svn_username');
        $svn_password = get_option('svn_password');
        $svn->setRepository($svn_repository);
        $svn->setAuth($svn_username, $svn_password);
        /**
        *  Get HEAD version
        */
        $head = $svn->getVersion();

        $svn_upgrade .= '
        <div class="wrap">
        <h2>Repository: '.$svn->getRepository().'</h2>
        ';

        /**
        * Get system version
        */
        $system_version = $wpdb->get_var("SELECT revision FROM $table_name ORDER BY revision DESC LIMIT 1");

        /**
        * If system is older than the repository, update the system (rocket science :/ )
        */
        if($head != $system_version) {
            if(($system_version + 1) == $head) {
                $logs = $svn->getRepositoryLogs($head);
            } else {
                $logs = $svn->getRepositoryLogs(($system_version+1), $head);
            }
        } else {
            $svn_upgrade .= '<h2>System is allready on the HEAD version: '.$head.'</h2>';
        }

        /**
        * Show changes and comments and update the system if it is not allready at the HEAD version
        * Reversed the array so i can firstly update the newest file, mark it, and then skip it should it occure in the previous verson
        */
        if(is_array($logs)) {
            $fcnt = 0;
            set_time_limit(0);
            krsort($logs);
            $processed_files = array();
            foreach($logs as $key => $vars) {

                $version    = $vars['version'];
                $date       = $vars['date'];
                $comment    = $vars['comment'];
                $files      = $vars['files'];

                $svn_upgrade .= '
                <table class="widefat">
                <thead>
            	<tr>
            	<th scope="col" colspan="2">System updated to version '.$version.'('.$date.')</th>
                <th scope="col" colspan="2">Comment: '.$comment.'</th>
            	</tr>
            	</thead>
                <tbody>
                ';

                /**
                * Files comments and content
                */
                if(!is_array($files)) $files = array($files);
                foreach ($files as $svn_file) {
                    $file_content           = $svn->getFile($svn_file);

                    /**
                    * Remove the /trunk/ from the filename
                    */
                    $file = str_replace("/trunk/", "", $svn_file);
                    $file = preg_replace("#/branches/([0-9\.]+)/#","",$file);

                    /**
                    * If file has been processed in previous higher version, don't process it again...
                    */
                    if(in_array($file, $processed_files)) continue;
                    $processed_files[]      = $file;

                    $fcnt++;


                    /**
                    * Get file mime type
                    */
                    $path_info = pathinfo(ABSPATH.$file);

                    $file_info              = $svn->getFileLogs($svn_file,  $version - 1, $version);
                    if(is_array($file_info)){
                        $svn_upgrade .= '
                        <tr>
                            <td>' . $fcnt . '</td>
                            <td colspan=2">' . $file . '</td>
                            <td>' . $file_info[0]['date'] . '</td>
                        </tr>
                        ';
                    }

                    /**
                    * Write files, ergo update WordPress :)
                    */
                    //  Create directory if needed
                    if($path_info['extension'] == '' && !is_dir(dirname(ABSPATH.$file))) {
                        mkdir(dirname(ABSPATH.$file).'/', 0777, true);
                    }

                    //  Special case if directory is empty (and $file is in fact a directory)
                    if($path_info['extension'] == '' && !is_dir(dirname(ABSPATH.$file.'/.'))) {
                        mkdir(dirname(ABSPATH.$file.'/.'), 0777, true);
                    }

                    if($path_info['extension'] != '' && $file_content != 'DELETE'){
                        $fh = fopen(ABSPATH.$file, 'w');
                        fwrite($fh, $file_content);
                        fclose($fh);
                    } elseif($path_info['extension'] != '' && $file_content == 'DELETE') {
                        unlink(ABSPATH.$file);
                    } elseif($path_info['extension'] == '' && $file_content == 'DELETE') {
                        rmdir(ABSPATH.$file);
                    }


                }
                $svn_upgrade .= '
                </tbody>
                </table>';

                /**
                * On update, write database
                */
                global $userdata;
                get_currentuserinfo();
                $files = implode(',', $processed_files);
                $insert = "INSERT INTO " . $table_name . "
                SET
                    revision    = '".$version."',
                    comment     = '".$wpdb->escape($comment)."',
                    files       = '".$wpdb->escape($files)."',
                    updated_by  = '".$userdata->ID."',
                    date        = NOW()
                ";
                $wpdb->query( $insert );
            }
        }
        echo $svn_upgrade.'</div>';

    }
}

if( ! function_exists('svn_report_page') ) {
    function svn_report_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . "svn";
        $users = $wpdb->prefix .'users';
        $svn_report = '
        <div class="wrap">
        <table class="widefat">
        <thead>
    	<tr>
    	<th scope="col">'._('Version').'</th>
    	<th scope="col">'._('Upgraded by').'</th>
    	<th scope="col">'._('SVN comment and files:').'</th>
    	<th scope="col">'._('Update date:').'</th>
    	</tr>
    	</thead>
        <tbody>
        ';
        $svn_report_data = $wpdb->get_results("SELECT $table_name.*, $users.user_login, $users.user_email FROM $table_name LEFT JOIN $users ON $table_name.updated_by = $users.ID ORDER BY $table_name.revision DESC");
        foreach ($svn_report_data as $data) {
            $files = explode(',', $data->files);
            $version_files = '';
            foreach($files as $file) {
                $version_files .= '<li>'.$file.'</li>
                ';
            }
            $svn_report .= '
            <tr>
                <td><b>'.$data->revision.'</b></td>
                <td><a href="mailto:'.$data->user_email.'">'.$data->user_login.'</a></td>
                <td>
                    <b>'.$data->comment.'</b>
                    <ul>
                        '.$version_files.'
                    </ul>
                </td>
                <td><b>'.mysql2date(__('Y/m/d H:i:s'), $data->date).'</b></td>
            </tr>
            ';

        }
        $svn_report .= '
        </tbody>
        </table>
        ';
        echo $svn_report.'</div>';
    }
}

if( ! function_exists('svn_admin_options') ) {
function svn_admin_options() {
    if( $_POST[ 'update_svn_repository' ] == '1' ) {
        update_option( 'svn_repository', $_POST[ 'svn_repository' ] );
        update_option( 'svn_username', $_POST[ 'svn_username' ] );
        update_option( 'svn_password', $_POST[ 'svn_password' ] );
    }
?>
    <div class="wrap">
    <h2><?php _e('SVN Repository Options') ?></h2>
    <p>Default repository is <b>http://svn.automattic.com/wordpress/</b> without username and password</p>
    <form method="post" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>">
    <?php wp_nonce_field('update-options') ?>
    <table class="form-table">
    <tr valign="top">
        <th scope="row">
            <label for="svn_repository"><?php _e('Repository') ?></label>
        </th>
        <td>
            <input type="text" id="svn_repository" name="svn_repository" class="code" size="40" value="<?php echo get_option('svn_repository'); ?>" />
            <input type="hidden" name="update_svn_repository" value="1" />
        </td>
    </tr>
    <tr valign="top">
        <th scope="row">
            <label for="svn_username"><?php _e('Username') ?></label>
        </th>
        <td>
            <input type="text" id="svn_username" name="svn_username" class="code" size="40" value="<?php echo get_option('svn_username'); ?>" />
        </td>
    </tr>
    <tr valign="top">
        <th scope="row">
            <label for="svn_password"><?php _e('Password') ?></label>
        </th>
        <td>
            <input type="text" id="svn_password" name="svn_password" class="code" size="40" value="<?php echo get_option('svn_password'); ?>" />
        </td>
    </tr>
    </table>

    <p class="submit"><input type="submit" name="Submit" value="<?php _e('Save Changes') ?>" />
    <input type="hidden" name="action" value="update" />
    <input type="hidden" name="page_options" value="svn_repository" />
    </p>
    </form>

    </div>
<?php
}
}

function svn_upgrade_menu(){
    add_menu_page('SVN upgrade', 'SVN upgrade', 9, __FILE__, 'svn_report_page');
    add_submenu_page(__FILE__, 'SVN report', 'SVN report', 9, __FILE__, 'svn_report_page');
    add_submenu_page(__FILE__, 'SVN upgrade', 'SVN upgrade', 9, __FILE__.'?svn=upgrade', 'svn_upgrade_page');
    add_options_page('SVN upgrade', 'SVN upgrade', 9, basename(__FILE__), 'svn_admin_options');

}
add_action('admin_menu', 'svn_upgrade_menu');

function svn_upgrade_install () {
   global $wpdb;
   $table_name = $wpdb->prefix . "svn";

   if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $sql = "CREATE TABLE " . $table_name . " (
                svn_id int(10) unsigned NOT NULL auto_increment,
                revision int(10) unsigned default NULL,
                comment text character set utf8 collate utf8_slovenian_ci,
                files text character set utf8 collate utf8_slovenian_ci,
                updated_by bigint(20) unsigned default NULL,
                date datetime default NULL,
                PRIMARY KEY  (svn_id),
                UNIQUE KEY revision (revision)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        sleep(1);
        $wpdb->query("INSERT INTO $table_name(revision,comment,files,updated_by,date) VALUES ('8060', '', '', '1' ,NOW())");
        die( "INSERT INTO $table_name(revision,comment,files,updated_by,date) VALUES ('8060', '', '', '1' ,NOW())");
        add_option('svn_repository', $wpdb->escape('http://svn.automattic.com/wordpress/'));
        add_option('svn_username', '');
        add_option('svn_password', '');
   }
}
register_activation_hook(__FILE__,'svn_upgrade_install');
?>