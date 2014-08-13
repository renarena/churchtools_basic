<?php

$churchauth=null;

function churchauth_getModule() {
  global $churchauth;
  if ($churchauth==null) 
  	$churchauth=new CTChurchAuthModule("churchauth");
  return $churchauth;
}

function churchauth__ajax() {
  $module = new CTChurchAuthModule("churchauth");
  $ajax = new CTAjaxHandler($module);
  drupal_json_output($ajax->call());
}

function churchauth_main() {
  if (!user_access("administer persons","churchcore")) {
  		addInfoMessage(_("no.permission.for", "administer persons"));
  		return " ";
  }
   
  drupal_add_css(ASSETS.'/fileuploader/fileuploader.css');
   
  drupal_add_js(BOOTSTRAP.'/js/bootstrap-multiselect.js');
  drupal_add_js(ASSETS.'/fileuploader/fileuploader.js');
  drupal_add_js(ASSETS.'/js/jquery.history.js');
   
  drupal_add_css(ASSETS.'/dynatree/ui.dynatree.css');
  drupal_add_js(ASSETS.'/dynatree/jquery.dynatree-1.2.4.js');
   
  drupal_add_js(CHURCHCORE .'/churchcore.js');
  drupal_add_js(CHURCHCORE .'/churchforms.js');
  drupal_add_js(CHURCHCORE .'/cc_abstractview.js');
  drupal_add_js(CHURCHCORE .'/cc_standardview.js');
  drupal_add_js(CHURCHCORE .'/cc_maintainstandardview.js');
  drupal_add_js(CHURCHCORE .'/cc_interface.js');
   
  drupal_add_js(CHURCHCORE .'/cc_authview.js');
  drupal_add_js(CHURCHCORE .'/cc_authmain.js');
  
  drupal_add_js(createI18nFile("churchcore"));
  
  $content=$content."
<div class=\"row-fluid\">
  <div class=\"span3\">
    <div id=\"cdb_menu\"></div>
    <div id=\"cdb_filter\"></div>
  </div>
  <div class=\"span9\">
    <div id=\"cdb_search\"></div>
    <div id=\"cdb_group\"></div>
    <div id=\"cdb_content\"></div>
  </div>
</div>";
  return $content;
}


class CTChurchAuthModule extends CTAbstractModule {	

  // uses __constructor of parent class
  
  /**
   * Save Auth
   * 
   * @param unknown $params
   * @throws CTNoPermission
   */
  public function saveAuth($params) {
    if (!user_access("administer persons","churchcore")) 
  	  throw new CTNoPermission("administer persons", "churchcore");
    
    db_query("delete from {cc_domain_auth} where domain_type=:domain_type and domain_id=:domain_id", 
        array(":domain_type"=>$params["domain_type"], ":domain_id"=>$params["domain_id"]));
    if (isset($params["data"])) {    
      foreach ($params["data"] as $data) {
        if (isset($data["daten_id"]))
          db_query("insert into {cc_domain_auth} (domain_type, domain_id, auth_id, daten_id)
               values (:domain_type, :domain_id, :auth_id, :daten_id)", 
              array(":domain_type"=>$params["domain_type"], ":domain_id"=>$params["domain_id"], 
                  ":auth_id"=>$data["auth_id"], ":daten_id"=>$data["daten_id"]));
        else      
          db_query("insert into {cc_domain_auth} (domain_type, domain_id, auth_id)
               values (:domain_type, :domain_id, :auth_id)", 
              array(":domain_type"=>$params["domain_type"], ":domain_id"=>$params["domain_id"], 
                  ":auth_id"=>$data["auth_id"]));
      }
    }    
  }

  public function getMasterData() {
    global $config;
  
    $res=array();
    $res["auth_table_plain"]=getAuthTable();
    
    foreach($res["auth_table_plain"] as $auth) {
      if (($auth->datenfeld!=null) && (!isset($res[$auth->datenfeld])))
        $res[$auth->datenfeld]=churchcore_getTableData($auth->datenfeld);    
    }
    $res["modules"]=churchcore_getModulesSorted(true, false);
    
    $res["person"]=churchcore_getTableData("cdb_person", "name, vorname", null, "id, concat(name, ', ', vorname) as bezeichnung");
    $res["person"][-1]=new stdClass(); $res["person"][-1]->id=-1;  $res["person"][-1]->bezeichnung="- "+_("public.user")+" -";
    $res["gruppe"]=churchcore_getTableData("cdb_gruppe", null, null, "id, bezeichnung");
    $res["status"]=churchcore_getTableData("cdb_status");
    $res["publiccalendar_name"]=variable_get("churchcal_maincalname", "Church Calendar");
    $res["category"]=churchcore_getTableData("cc_calcategory", null, null, "id, bezeichnung, privat_yn, oeffentlich_yn");
    $res["modulename"]="churchcore";
    $res["admins"]=$config["admin_ids"];
    
    $auths=churchcore_getTableData("cc_domain_auth");
    if ($auths!=false)
    foreach ($auths as $auth) {
      $domaintype=array();
      // Initalisiere $res[domain_tye]
      if (isset($res[$auth->domain_type]))
        $domaintype=$res[$auth->domain_type];
        
        
      $elem=new stdClass();  
      if (isset($domaintype[$auth->domain_id]))
        $elem=$domaintype[$auth->domain_id];
      else {
        $elem->id=$auth->domain_id;
        if (isset($db[$auth->domain_type][$auth->domain_id]))
          $elem->bezeichnung=$db[$auth->domain_type][$auth->domain_id]->bezeichnung;
        else $elem->bezeichnung=t("non.existent");  
      }
      
      if ($auth->daten_id==null)
        $elem->auth[$auth->auth_id]=$auth->auth_id;
      else {
        if (!isset($elem->auth[$auth->auth_id]))
          $elem->auth[$auth->auth_id]=array();
        $elem->auth[$auth->auth_id][$auth->daten_id]=$auth->daten_id;
      }  
      
      
      $domaintype[$auth->domain_id]=$elem;
      $res[$auth->domain_type]=$domaintype;
    }  
    foreach (churchcore_getModulesSorted() as $name) {
      if (isset($config[$name."_name"]))
        $res["names"][$name]=$config[$name."_name"];
    }
    return $res;
  }  
}


?>
