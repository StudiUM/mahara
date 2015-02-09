<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This script creates config.php file during installation.
 *
 * @package    core
 * @subpackage install
 * @copyright  2009 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (isset($_REQUEST['admin'])) {
    $admin = preg_replace('/[^A-Za-z0-9_-]/i', '', $_REQUEST['admin']);
} else {
    $admin = 'admin';
}

$CFG = new StdClass;
$CFG->docroot = dirname(__FILE__) . DIRECTORY_SEPARATOR;

require('lib/errors.php');
require_once('lib/config-defaults.php');

// Set up error handling
$errorlevel = $CFG->error_reporting;
error_reporting($errorlevel);
set_error_handler('error', $errorlevel);
// core libraries
require('mahara.php');
ensure_sanity();

$installconfig = new stdClass();
$installconfig->lang = $lang;

if (!empty($_POST)) {
    if (install_ini_get_bool('magic_quotes_gpc')) {
        $_POST = array_map('stripslashes', $_POST);
    }

    $installconfig->stage = (int)$_POST['stage'];

    if (isset($_POST['previous'])) {
        $installconfig->stage--;
        if (INSTALL_DATABASETYPE and !empty($distro->dbtype)) {
            $installconfig->stage--;
        }
        if ($installconfig->stage == INSTALL_ENVIRONMENT or $installconfig->stage == INSTALL_DOWNLOADLANG) {
            $installconfig->stage--;
        }
    } else if (isset($_POST['next'])) {
        $installconfig->stage++;
    }

    $installconfig->dbtype   = trim($_POST['dbtype']);
    $installconfig->dbhost   = trim($_POST['dbhost']);
    $installconfig->dbuser   = trim($_POST['dbuser']);
    $installconfig->dbpass   = trim($_POST['dbpass']);
    $installconfig->dbname   = trim($_POST['dbname']);
    $installconfig->prefix   = trim($_POST['prefix']);
    $installconfig->dbport   = (int)trim($_POST['dbport']);
    $installconfig->dbsocket = trim($_POST['dbsocket']);

    if ($installconfig->dbport <= 0) {
        $installconfig->dbport = '';
    }

    $installconfig->admin    = empty($_POST['admin']) ? 'admin' : trim($_POST['admin']);

    $installconfig->dataroot = trim($_POST['dataroot']);

} else {
    $installconfig->stage    = INSTALL_WELCOME;

    $installconfig->dbtype   = '';
    $installconfig->dbhost   = '';
    $installconfig->dbuser   = '';
    $installconfig->dbpass   = '';
    $installconfig->dbname   = 'mahara';
    $installconfig->prefix   = '';
    $installconfig->dbport   = '';
    $installconfig->dbsocket = '';

    $installconfig->admin    = 'admin';

    $installconfig->dataroot = null;
}

// Require all needed libs
require_once('lib/web.php');
require('lib/version.php');
$CFG->target_release = $config->release;

$hint_dataroot = '';
$hint_admindir = '';
$hint_database = '';

//first time here? find out suitable dataroot
if (is_null($CFG->dataroot)) {
    $CFG->dataroot = dirname(dirname(__FILE__)).DIRECTORY_SEPARATOR.'maharadata';

    $i = 0; //safety check - dirname might return some unexpected results
    while(is_dataroot_insecure()) {
        $parrent = dirname($CFG->dataroot);
        $i++;
        if ($parrent == '/' or $parrent == '.' or preg_match('/^[a-z]:\\\?$/i', $parrent) or ($i > 100)) {
            $CFG->dataroot = ''; //can not find secure location for dataroot
            break;
        }
        $CFG->dataroot = dirname($parrent).DIRECTORY_SEPARATOR.'maharadata';
    }
    $installconfig->dataroot = $CFG->dataroot;
    $installconfig->stage    = INSTALL_WELCOME;
}

// now let's do the stage work
if ($installconfig->stage < INSTALL_WELCOME) {
    $installconfig->stage = INSTALL_WELCOME;
}
if ($installconfig->stage > INSTALL_SAVE) {
    $installconfig->stage = INSTALL_SAVE;
}



if ($installconfig->stage == INSTALL_SAVE) {
    $CFG->early_install_lang = false;

    $database = moodle_database::get_driver_instance($installconfig->dbtype, 'native');
    if (!$database->driver_installed()) {
        $installconfig->stage = INSTALL_DATABASETYPE;
    } else {
        if (function_exists('distro_pre_create_db')) { // Hook for distros needing to do something before DB creation
            $distro = distro_pre_create_db($database, $installconfig->dbhost, $installconfig->dbuser, $installconfig->dbpass, $installconfig->dbname, $installconfig->prefix, array('dbpersist'=>0, 'dbport'=>$installconfig->dbport, 'dbsocket'=>$installconfig->dbsocket), $distro);
        }
        $hint_database = install_db_validate($database, $installconfig->dbhost, $installconfig->dbuser, $installconfig->dbpass, $installconfig->dbname, $installconfig->prefix, array('dbpersist'=>0, 'dbport'=>$installconfig->dbport, 'dbsocket'=>$installconfig->dbsocket));

        if ($hint_database === '') {
            $installconfigphp = install_generate_configphp($database, $CFG);

            umask(0137);
            if (($fh = @fopen($installconfigfile, 'w')) !== false) {
                fwrite($fh, $installconfigphp);
                fclose($fh);
            }

            if (file_exists($installconfigfile)) {
                // config created, let's continue!
                redirect("$CFG->wwwroot/$installconfig->admin/index.php?lang=$installconfig->lang");
            }

            install_print_header($installconfig, 'config.php',
                                          get_string('configurationcompletehead', 'install'),
                                          get_string('configurationcompletesub', 'install').get_string('configfilenotwritten', 'install'));
            echo '<div class="configphp"><pre>';
            echo p($installconfigphp);
            echo '</pre></div>';

            install_print_footer($installconfig);
            die;

        } else {
            $installconfig->stage = INSTALL_DATABASE;
        }
    }
}



if ($installconfig->stage == INSTALL_DOWNLOADLANG) {
    if (empty($CFG->dataroot)) {
        $installconfig->stage = INSTALL_PATHS;

    } else if (is_dataroot_insecure()) {
        $hint_dataroot = get_string('pathsunsecuredataroot', 'install');
        $installconfig->stage = INSTALL_PATHS;

    } else if (!file_exists($CFG->dataroot)) {
        $a = new stdClass();
        $a->parent = dirname($CFG->dataroot);
        $a->dataroot = $CFG->dataroot;
        if (!is_writable($a->parent)) {
            $hint_dataroot = get_string('pathsroparentdataroot', 'install', $a);
            $installconfig->stage = INSTALL_PATHS;
        } else {
            if (!install_init_dataroot($CFG->dataroot, $CFG->directorypermissions)) {
                $hint_dataroot = get_string('pathserrcreatedataroot', 'install', $a);
                $installconfig->stage = INSTALL_PATHS;
            }
        }

    } else if (!install_init_dataroot($CFG->dataroot, $CFG->directorypermissions)) {
        $hint_dataroot = get_string('pathserrcreatedataroot', 'install', array('dataroot' => $CFG->dataroot));
        $installconfig->stage = INSTALL_PATHS;
    }

    if (empty($hint_dataroot) and !is_writable($CFG->dataroot)) {
        $hint_dataroot = get_string('pathsrodataroot', 'install');
        $installconfig->stage = INSTALL_PATHS;
    }

    if ($installconfig->admin === '' or !file_exists($CFG->dirroot.'/'.$installconfig->admin.'/environment.xml')) {
        $hint_admindir = get_string('pathswrongadmindir', 'install');
        $installconfig->stage = INSTALL_PATHS;
    }
}



if ($installconfig->stage == INSTALL_DOWNLOADLANG) {
    // no need to download anything if en lang selected
    if ($CFG->lang == 'en') {
        $installconfig->stage = INSTALL_DATABASETYPE;
    }
}



if ($installconfig->stage == INSTALL_DATABASETYPE) {
    // skip db selection if distro package supports only one db
    if (!empty($distro->dbtype)) {
        $installconfig->stage = INSTALL_DATABASE;
    }
}


if ($installconfig->stage == INSTALL_DOWNLOADLANG) {
    $downloaderror = '';

    // download and install required lang packs, the lang dir has already been created in install_init_dataroot
    $installer = new lang_installer($CFG->lang);
    $results = $installer->run();
    foreach ($results as $langcode => $langstatus) {
        if ($langstatus === lang_installer::RESULT_DOWNLOADERROR) {
            $a       = new stdClass();
            $a->url  = $installer->lang_pack_url($langcode);
            $a->dest = $CFG->dataroot.'/lang';
            $downloaderror = get_string('remotedownloaderror', 'error', $a);
        }
    }

    if ($downloaderror !== '') {
        install_print_header($installconfig, get_string('language'), get_string('langdownloaderror', 'install', $CFG->lang), $downloaderror);
        install_print_footer($installconfig);
        die;
    } else {
        if (empty($distro->dbtype)) {
            $installconfig->stage = INSTALL_DATABASETYPE;
        } else {
            $installconfig->stage = INSTALL_DATABASE;
        }
    }

    // switch the string_manager instance to stop using install/lang/
    $CFG->early_install_lang = false;
    $CFG->langotherroot      = $CFG->dataroot.'/lang';
    $CFG->langlocalroot      = $CFG->dataroot.'/lang';
    get_string_manager(true);
}


if ($installconfig->stage == INSTALL_DATABASE) {
    $CFG->early_install_lang = false;

    $database = moodle_database::get_driver_instance($installconfig->dbtype, 'native');

    $sub = '<h3>'.$database->get_name().'</h3>'.$database->get_configuration_help();

    install_print_header($installconfig, get_string('database', 'install'), get_string('databasehead', 'install'), $sub);

    $strdbhost   = get_string('databasehost', 'install');
    $strdbname   = get_string('databasename', 'install');
    $strdbuser   = get_string('databaseuser', 'install');
    $strdbpass   = get_string('databasepass', 'install');
    $strprefix   = get_string('dbprefix', 'install');
    $strdbport   = get_string('databaseport', 'install');
    $strdbsocket = get_string('databasesocket', 'install');

    echo '<div class="userinput">';

    $disabled = empty($distro->dbhost) ? '' : 'disabled="disabled';
    echo '<div class="formrow"><label for="id_dbhost" class="formlabel">'.$strdbhost.'</label>';
    echo '<input id="id_dbhost" name="dbhost" '.$disabled.' type="text" value="'.s($installconfig->dbhost).'" size="50" class="forminput" />';
    echo '</div>';

    echo '<div class="formrow"><label for="id_dbname" class="formlabel">'.$strdbname.'</label>';
    echo '<input id="id_dbname" name="dbname" type="text" value="'.s($installconfig->dbname).'" size="50" class="forminput" />';
    echo '</div>';

    $disabled = empty($distro->dbuser) ? '' : 'disabled="disabled';
    echo '<div class="formrow"><label for="id_dbuser" class="formlabel">'.$strdbuser.'</label>';
    echo '<input id="id_dbuser" name="dbuser" '.$disabled.' type="text" value="'.s($installconfig->dbuser).'" size="50" class="forminput" />';
    echo '</div>';

    echo '<div class="formrow"><label for="id_dbpass" class="formlabel">'.$strdbpass.'</label>';
    // no password field here, the password may be visible in config.php if we can not write it to disk
    echo '<input id="id_dbpass" name="dbpass" type="text" value="'.s($installconfig->dbpass).'" size="50" class="forminput" />';
    echo '</div>';

    echo '<div class="formrow"><label for="id_prefix" class="formlabel">'.$strprefix.'</label>';
    echo '<input id="id_prefix" name="prefix" type="text" value="'.s($installconfig->prefix).'" size="10" class="forminput" />';
    echo '</div>';

    echo '<div class="formrow"><label for="id_prefix" class="formlabel">'.$strdbport.'</label>';
    echo '<input id="id_dbport" name="dbport" type="text" value="'.s($installconfig->dbport).'" size="10" class="forminput" />';
    echo '</div>';

    if (!(stristr(PHP_OS, 'win') && !stristr(PHP_OS, 'darwin'))) {
        echo '<div class="formrow"><label for="id_dbsocket" class="formlabel">'.$strdbsocket.'</label>';
        echo '<input id="id_dbsocket" name="dbsocket" type="text" value="'.s($installconfig->dbsocket).'" size="50" class="forminput" />';
        echo '</div>';
    }

    echo '<div class="hint">'.$hint_database.'</div>';
    echo '</div>';
    install_print_footer($installconfig);
    die;
}


if ($installconfig->stage == INSTALL_DATABASETYPE) {
    $CFG->early_install_lang = false;

    // Finally ask for DB type
    install_print_header($installconfig, get_string('database', 'install'),
                                  get_string('databasetypehead', 'install'),
                                  get_string('databasetypesub', 'install'));

    $databases = array('mysqli' => moodle_database::get_driver_instance('mysqli', 'native'),
                       'mariadb'=> moodle_database::get_driver_instance('mariadb', 'native'),
                       'pgsql'  => moodle_database::get_driver_instance('pgsql',  'native'),
                       'oci'    => moodle_database::get_driver_instance('oci',    'native'),
                       'sqlsrv' => moodle_database::get_driver_instance('sqlsrv', 'native'), // MS SQL*Server PHP driver
                       'mssql'  => moodle_database::get_driver_instance('mssql',  'native'), // FreeTDS driver
                      );

    echo '<div class="userinput">';
    echo '<div class="formrow"><label class="formlabel" for="dbtype">'.get_string('dbtype', 'install').'</label>';
    echo '<select id="dbtype" name="dbtype" class="forminput">';
    $disabled = array();
    $options = array();
    foreach ($databases as $type=>$database) {
        if ($database->driver_installed() !== true) {
            $disabled[$type] = $database;
            continue;
        }
        echo '<option value="'.s($type).'">'.$database->get_name().'</option>';
    }
    if ($disabled) {
        echo '<optgroup label="'.s(get_string('notavailable')).'">';
        foreach ($disabled as $type=>$database) {
            echo '<option value="'.s($type).'" class="notavailable">'.$database->get_name().'</option>';
        }
        echo '</optgroup>';
    }
    echo '</select></div>';
    echo '</div>';

    install_print_footer($installconfig);
    die;
}



if ($installconfig->stage == INSTALL_ENVIRONMENT or $installconfig->stage == INSTALL_PATHS) {
    $version_fail = (version_compare(phpversion(), "5.3.3") < 0);
    $curl_fail    = ($lang !== 'en' and !extension_loaded('curl')); // needed for lang pack download
    $zip_fail     = ($lang !== 'en' and !extension_loaded('zip'));  // needed for lang pack download

    if ($version_fail or $curl_fail or $zip_fail) {
        $installconfig->stage = INSTALL_ENVIRONMENT;

        install_print_header($installconfig, get_string('environmenthead', 'install'),
                                      get_string('errorsinenvironment', 'install'),
                                      get_string('environmentsub2', 'install'));

        echo '<div id="envresult"><dl>';
        if ($version_fail) {
            $a = (object)array('needed'=>'5.3.3', 'current'=>phpversion());
            echo '<dt>'.get_string('phpversion', 'install').'</dt><dd>'.get_string('environmentrequireversion', 'admin', $a).'</dd>';
        }
        if ($curl_fail) {
            echo '<dt>'.get_string('phpextension', 'install', 'cURL').'</dt><dd>'.get_string('environmentrequireinstall', 'admin').'</dd>';
        }
        if ($zip_fail) {
            echo '<dt>'.get_string('phpextension', 'install', 'Zip').'</dt><dd>'.get_string('environmentrequireinstall', 'admin').'</dd>';
        }
        echo '</dl></div>';

        install_print_footer($installconfig, true);
        die;

    } else {
        $installconfig->stage = INSTALL_PATHS;
    }
}



if ($installconfig->stage == INSTALL_PATHS) {
    $paths = array('wwwroot'  => get_string('wwwroot', 'install'),
                   'dirroot'  => get_string('dirroot', 'install'),
                   'dataroot' => get_string('dataroot', 'install'));

    $sub = '<dl>';
    foreach ($paths as $path=>$name) {
        $sub .= '<dt>'.$name.'</dt><dd>'.get_string('pathssub'.$path, 'install').'</dd>';
    }
    if (!file_exists("$CFG->dirroot/admin/environment.xml")) {
        $sub .= '<dt>'.get_string('admindirname', 'install').'</dt><dd>'.get_string('pathssubadmindir', 'install').'</dd>';
    }
    $sub .= '</dl>';

    install_print_header($installconfig, get_string('paths', 'install'), get_string('pathshead', 'install'), $sub);

    $strwwwroot      = get_string('wwwroot', 'install');
    $strdirroot      = get_string('dirroot', 'install');
    $strdataroot     = get_string('dataroot', 'install');
    $stradmindirname = get_string('admindirname', 'install');

    echo '<div class="userinput">';
    echo '<div class="formrow"><label for="id_wwwroot" class="formlabel">'.$paths['wwwroot'].'</label>';
    echo '<input id="id_wwwroot" name="wwwroot" type="text" value="'.s($CFG->wwwroot).'" disabled="disabled" size="70" class="forminput" />';
    echo '</div>';

    echo '<div class="formrow"><label for="id_dirroot" class="formlabel">'.$paths['dirroot'].'</label>';
    echo '<input id="id_dirroot" name="dirroot" type="text" value="'.s($CFG->dirroot).'" disabled="disabled" size="70"class="forminput" />';
    echo '</div>';

    echo '<div class="formrow"><label for="id_dataroot" class="formlabel">'.$paths['dataroot'].'</label>';
    echo '<input id="id_dataroot" name="dataroot" type="text" value="'.s($installconfig->dataroot).'" size="70" class="forminput" />';
    if ($hint_dataroot !== '') {
        echo '<div class="hint">'.$hint_dataroot.'</div>';
    }
    echo '</div>';


    if (!file_exists("$CFG->dirroot/admin/environment.xml")) {
        echo '<div class="formrow"><label for="id_admin" class="formlabel">'.$paths['admindir'].'</label>';
        echo '<input id="id_admin" name="admin" type="text" value="'.s($installconfig->admin).'" size="10" class="forminput" />';
        if ($hint_admindir !== '') {
            echo '<div class="hint">'.$hint_admindir.'</div>';
        }
        echo '</div>';
    }

    echo '</div>';

    install_print_footer($installconfig);
    die;
}



$installconfig->stage = INSTALL_WELCOME;

if ($distro) {
    ob_start();
    include('install/distribution.html');
    $sub = ob_get_clean();

    install_print_header($installconfig, get_string('language'),
                                  get_string('chooselanguagehead', 'install'),
                                  $sub);

} else {
    install_print_header($installconfig, get_string('language'),
                                  get_string('chooselanguagehead', 'install'),
                                  get_string('chooselanguagesub', 'install'));
}

$languages = get_string_manager()->get_list_of_translations();
echo '<div class="userinput">';
echo '<div class="formrow"><label class="formlabel" for="langselect">'.get_string('language').'</label>';
echo '<select id="langselect" name="lang" class="forminput" onchange="this.form.submit()">';
foreach ($languages as $name=>$value) {
    $selected = ($name == $CFG->lang) ? 'selected="selected"' : '';
    echo '<option value="'.s($name).'" '.$selected.'>'.$value.'</option>';
}
echo '</select></div>';
echo '</div>';

install_print_footer($installconfig);
die;

