<?php
/**
 * ChurchTools 2.0
 * http://www.churchtools.de
 *
 * Copyright (c) 2013 Jens Martin Rauen
 * Licensed under the MIT license, located in LICENSE.txt
 */

$q = "";                  // which module to use
$config = array ();       //
$mapping = array ();      //
$files_dir = DEFAULT_SITE;// dir for page specific files

$add_header = "";         // http headers?
$content = "";            // page content
$user = null;             // user
$embedded = false;        //
$i18n = null;             // 



/**
 * Shutdown fuction, if an error happened, an error message is displayed.
 * FIXME: need to be changed - this errors  are corrupting json answers
 * replace $ajax with something appropriate !
 * if error on ajax is needed. add something like
 * $json['error'] = $info;
 * 
 * error_get_last() dont respects error_reporting() - f.e. deprecated options in php.ini which are not shown, 
 * maybe cant be changed by admins and dont influence scripting causes an error here
 * 
 * I think heavy core errors shouldnt be shown on the page and all others should be catched by an exception handler.
 */
function handleShutdown() {
  $ajax = false;
  $error = error_get_last();
  if (!$ajax && $error !== NULL) {
    $info = "[ERROR] file:" . $error['file'] . ":" . $error['line'] . " <br/><i>" . $error['message'] . '</i>' . PHP_EOL;
    echo '<div class="alert alert-error">' . $info . '</div>';
  }
}

/**
 * Load config from file while checking for multisite installation.
 *
 *
 * For multisite use you have to add a folder for each subdomain in sites,
 * eg sites/mghh for mghh.churchtools.de.
 * Don't works for more then one subdomain like intern.mghh.churchtools.de
 */
function loadConfig() {
  global $files_dir;
  // Unix default. Should have ".conf" extension as per standards.
  $config = null;
  
  // read config, based on subdomain.
  // WARNING: This code dont works for per IP address access and supports only last subdomain.
  if (strpos($_SERVER["SERVER_NAME"], ".") > 0) {
    $subdomain = substr($_SERVER["SERVER_NAME"], 0, strpos($_SERVER["SERVER_NAME"], "."));
    $cnf_location = SITES . "/$subdomain/churchtools.config";
    if (file_exists($cnf_location)) {
      $config = parse_ini_file($cnf_location);
      $files_dir = SITES . "/" . $subdomain;
    }
  }
  
  // if no config, read default config
  $cnf_location = DEFAULT_SITE . "/churchtools.config";
  if ($config == null && file_exists($cnf_location)) {
    $config = parse_ini_file($cnf_location);
  }
  
  // if still no config, look in default linux etc location
  $cnf_location = "/etc/churchtools/default.conf";
  if ($config == null && @file_exists($cnf_location)) {
    $config = parse_ini_file($cnf_location);
  }
  
  // still no config? Look fo r host specific config in etc
  // Package installed, per domain.
  // All possible virt-hosts in HTTP server has to be symlinked to it.
  $cnf_location = "/etc/churchtools/hosts/" . $_SERVER["SERVER_NAME"] . ".conf";
  if ($config == null && @file_exists($cnf_location)) {
    $config = parse_ini_file($cnf_location);
  }
  
  //
  if ($config == null) {
    $error_message = "<h3>" . "Error: Configuration file was not found." . "</h3>
        <p>Expected locations are:
            <ul>
                <li>Default appliance: <code>/etc/churchtools/default.conf</code></li>
                <li>Per-domain appliance: <code>/etc/churchtools/hosts/" .
                   $_SERVER["SERVER_NAME"] . ".conf</code></li>
                <li>Shared hosting per domain: <code><i>YOUR_INSTALLATION</i>/sites/" .
                   $_SERVER["SERVER_NAME"] . "/churchtools.config</code></li>
                <li>Hosting per sub-domain: <code><i>YOUR_INSTALLATION</i>/sites/<b>&lt;subdomain&gt;.&lt;domain&gt;</b>/churchtools.config</code></li>
                <li>Shared hosting default (single installation): <code><i>YOUR_INSTALLATION</i>/" .
                  DEFAULT_SITE . "/churchtools.config</code></li>
            </ul>
            <div class=\"alert alert-info\">You can also use <strong>example</strong> file in
            <code><i>INSTALLATION</i>/" .
              DEFAULT_SITE . "/churchtools.example.config</code> by renaming it to
              either one location that suits your setup and further editing it accordingly.</div>";
    addErrorMessage($error_message);
  }
  else {
    $config["_current_config_file"] = $cnf_location;
  }
  
  return $config;
}

/**
 * Loads all config data from the db
 */
function loadDBConfig() {
  global $config;
  try {
    $res = db_query("select * from {cc_config}", null, false);
    foreach ($res as $val) {
      $config[$val->name] = $val->value;
    }
  }
  catch (SQLException $e) {
  }
}

/**
 * Load url mappings for each module and merge them together 
 * module map path like like system/churchdb/churchdb.mapping
 * 
 * @return array
 */
function loadMapping() {
  $map = parse_ini_file(SYSTEM . "/churchtools.mapping");
  
  foreach (churchcore_getModulesSorted(true) as $module) {
    if (file_exists(SYSTEM . "/$module/$module.mapping")) {
      $modMap = parse_ini_file(SYSTEM . "/$module/$module.mapping");
      if (isset($modMap["page_with_noauth"]) && isset($map["page_with_noauth"])) 
        $modMap["page_with_noauth"] = array_merge($modMap["page_with_noauth"], $map["page_with_noauth"]);
      $map=array_merge($map, $modMap);
    }
  }
  return $map;
}

/**
 * Loads the user object in the session.
 *
 * If there is no user, it will create an anymous user
 */
function loadUserObjectInSession() {
  global $q;
  if (!isset($_SESSION['user'])) {
    // Wenn nicht ausgeloggt wird und RememberMe bei der letzten Anmeldung aktiviert wurde
    if (($q != "logout") && (isset($_COOKIE['RememberMe'])) && ($_COOKIE['RememberMe'] == 1)) {
      if (isset($_COOKIE['CC_SessionId'])) {
        $res = db_query("SELECT * FROM {cc_session} WHERE session=:session AND hostname=:hostname", 
            array (":session" => $_COOKIE['CC_SessionId'], 
                   ":hostname" => $_SERVER["HTTP_HOST"]));
        // if session exists, read user data
        if ($res != false) {
          $res = $res->fetch();
          if (isset($res->person_id)) {
            $res = db_query("select * from {cdb_person} where id=:id", array (":id" => $res->person_id))->fetch();
            $res->auth = getUserAuthorization($res->id);
            $_SESSION['user'] = $res;
            addInfoMessage("Willkommen zur&uuml;ck, " . $res->vorname . "!", true);
          }
        }
      }
    }
    if (!isset($_SESSION['user'])) {
      createAnonymousUser();
    }
  }
  else {
    $_SESSION["user"]->auth = getUserAuthorization($_SESSION["user"]->id);
    if (isset($_COOKIE['CC_SessionId'])) {
      $dt = new DateTime();
      db_query("UPDATE {cc_session} SET datum=:datum WHERE person_id=:p_id AND session=:session AND hostname=:hostname", 
      array (":datum" => $dt->format('Y-m-d H:i:s'), 
             ":session" => $_COOKIE['CC_SessionId'],
             ":p_id" => $_SESSION["user"]->id,
             ":hostname" => $_SERVER["HTTP_HOST"],
      ));
    }
  }
}

/**
 * Gets the base url from the server
 * 
 * @return string
 */
// function getBaseUrl() {
//   $base_url = $_SERVER['HTTP_HOST'];
//   $b = $_SERVER['REQUEST_URI'];
//   if (strpos($b, "/index.php") !== false) $b = substr($b, 0, strpos($b, "/index.php"));
//   if (strpos($b, "?") !== false) $b = substr($b, 0, strpos($b, "?"));
//   $base_url = $base_url . $b;
//   if ($base_url[strlen($base_url) - 1] != "/") $base_url .= "/";
//   if ((isset($_SERVER['HTTPS'])) && ($_SERVER['HTTPS'] != false)) $base_url = "https://" . $base_url;
//   else $base_url = "http://" . $base_url;
//   return $base_url;
// }

//TODO: check if the following does the same as function above

function getBaseUrl() {
  $base_url = $_SERVER['HTTP_HOST']. dirname($_SERVER['REQUEST_URI']);
  $base_url = (!empty($_SERVER['HTTPS']) ? "https://" : "http://") . $base_url; 
  return $base_url;
}

/**
 * For accept data security
 */
function pleaseAcceptDatasecurity() {
  global $user, $q;
  include_once (CHURCHWIKI . "/churchwiki.php");
  if (isset($_GET["acceptsecurity"])) {
    db_query("update {cdb_person} set acceptedsecurity=current_date() where id=$user->id");
    $user->acceptedsecurity = new DateTime();
    addInfoMessage(t("datasecurity.accept.thanks"));
    return churchtools_processRequest($q);
  }
  
  $data = churchwiki_load("Sicherheitsbestimmungen", 0);
  $text = str_replace("[Vorname]", $user->vorname, $data->text);
  $text = str_replace("[Nachname]", $user->name, $text);
  $text = str_replace("[Spitzname]", ($user->spitzname == "" ? $user->vorname : $spitzname), $text);
  
  $text = '<div class="container-fluid"><div class="well">' . $text;
  $text .= '<a href="?q=' . $q . '&acceptsecurity=true" class="btn btn-important">' . t("datasecurity.accept") . '</a>';
  $text .= '</div></div>';
  return $text;
}

/**
 * Will call churchservice => churchservice_main or churchservice/ajax => churchservice_ajax
 * 
 * @param $q - Complete request URL inkl. suburl e.g. churchservice/ajax
 * 
 * TODO: should completely rewritten, using some classes
 */
function churchtools_processRequest($_q) {
  global $mapping, $config, $q;
  
  $content = "";
  
  // include mapped file
  if (isset($mapping[$_q])) {
    include_once (SYSTEM . "/" . $mapping[$_q]);
    
    $param = "main";
    if (strpos($_q, "/") > 0) {
      $param = "_" . substr($_q, strpos($_q, "/") + 1, 99);
      $_q = substr($_q, 0, strpos($_q, "/"));
    }
    
    if ((!user_access("view", $_q)) && (!in_array($_q, $mapping["page_with_noauth"])) && ($_q != "login") &&
         (!in_array($_q, (isset($config["page_with_noauth"]) ? $config["page_with_noauth"] : array ())))) {
      // Wenn kein Benutzer angemeldet ist, dann zeige nun die Anmeldemaske
      if (!userLoggedIn()) {
        if (strrpos($q, "ajax") === false) {
          $q = "login";
          return churchtools_processRequest("login"); 
        }
        else {
          drupal_json_output(jsend()->error("Session expired!"));
          die();
        }
      }
      else {
        $name = $_q;
        if (isset($config[$_q . "_name"])) $name = $config[$_q . "_name"];
        addInfoMessage(t("no.permission.for", $name));
        return "";
      }
    }
    // does the main work?
    $content .= call_user_func($_q . "_" . $param);
    if ($content == null) die();
  }
  else
    addErrorMessage(t("mapping.not.found", $_q));
  return $content;
}

/**
 * Main entry point for churchtools.
 * This will be called from /index.php
 * Function loads i18n, configuration, check data security.
 * If everything is ok, it calls churchtools_processRequest()
 */
function churchtools_main() {
  global $q, $q_orig, $currentModule, $add_header, $config, $mapping, $content, $base_url, $files_dir, $user, $embedded, $i18n;
  include_once (CHURCHCORE . "/churchcore_db.inc");
  include_once (SYSTEM . "/lib/i18n.php");
  
  // which module is requested?
  $q = $q_orig = readVar("q", userLoggedIn() ? "home" : readConf("site_startpage", "home"));
  // $currentModule is needed for class autoloading and maybe other include paths
  list($currentModule) = explode('/', readVar("q")); //get first part of $q or churchcore
  $embedded = readVar("embedded", false);
  
  $base_url = getBaseUrl();
  
  
  $config = loadConfig();
  
  if ($config) {
    if (db_connect()) {
      // DBConfig overwrites the config files
      loadDBConfig();
      
      date_default_timezone_set(variable_get("timezone", "Europe/Berlin"));
      
      if (isset($_COOKIE["language"])) $config["language"] = $_COOKIE["language"];
      
      // Load i18n churchcore-bundle
      if (!isset($config["language"])) {
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $config["language"] = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        else $config["language"] = "de";
      }
      $i18n = new TextBundle(CHURCHCORE . "/resources/messages");
      $i18n->load("churchcore", ($config["language"] != null ? $config["language"] : null));
      
      // Session Init
      if (!file_exists($files_dir . "/tmp")) @mkdir($files_dir . "/tmp", 0775, true);
      if (!file_exists($files_dir . "/tmp")) {
        // Admin should act accordingly, default suggestion is 0755.
        addErrorMessage(t("permission.denied.write.dir", $files_dir));
      }
      else {
        session_save_path($files_dir . "/tmp");
        // TODO: saves the session in /sites/default/tmp ==> this gave an folder not exists error i searched for in
      // php.ini!!!
        // Shouldnt it be saved in tmp or in the RELATIVE site folder rather then in a root folder?
      }
      session_name("ChurchTools_" . $config["db_name"]);
      session_start();
      register_shutdown_function('handleShutdown');
      
      // Check for offline mode. If it's activated display message and return false;
      if ((isset($config["site_offline"]) && ($config["site_offline"] == 1))) {
        if ((!isset($_SESSION["user"]) || (!in_array($_SESSION["user"]->id, $config["admin_ids"])))) {
          echo t("site.is.down");
          return false;
        }
      }
      // which module is requested?
      //TODO: need this on top of function to assure all time availability
//       $q = $q_orig = readVar("q", userLoggedIn() ? "home" : readConf("site_startpage", "home"));
      $embedded = readVar("embedded", false);
      $mapping = loadMapping();
      $success = true;
      // Check for DB-Updates and loginstr only if this is not an ajax call.
      if (strrpos($q, "ajax") === false) {
        $success = checkForDBUpdates();
      }
      
      if ($success) {
        // Is there a loginstr which does not fit to the current logged in user?
        if (readVar("loginstr") && readVar("id") && userLoggedIn() && $_SESSION["user"]->id != readVar("id")) {
          logout_current_user();
          session_start();
        }
        else
          loadUserObjectInSession();
      }
      
      if ($success) {
        if (isset($_SESSION['user'])) $user = $_SESSION['user'];
        
        // Accept data security?
        if ((userLoggedIn()) && (!isset($_SESSION["simulate"])) && ($q != "logout") &&
             (isset($config["accept_datasecurity"])) && ($config["accept_datasecurity"] == 1) &&
             (!isset($user->acceptedsecurity))) $content .= pleaseAcceptDatasecurity();
        else $content .= churchtools_processRequest($q);
      }
    }
  }
  include (INCLUDES . "/header.php");
  echo $content;
  include (INCLUDES . "/body.php");
}

?>
