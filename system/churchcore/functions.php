<?php
/**
 *  core functions used in churchtools
 */

/**
 * @ignore
 */
//if (!defined('IN_CT')) exit; //TODO: should this be added to each php file to prevent using it from outside CT?

/**
 * autoloads needed classes
 * TODO: need module home to be handled separate?
 * can we identify core classes without file_exists?
 * look at http://php.net/manual/en/function.spl-autoload.php if we should use this instead
 * 
 * @param string $class_name
 * @return nothing
 *
 **/
function __autoload($class_name)
{
  if (file_exists(CHURCHCORE.CLASSES."/".$class_name.'.php')) include CHURCHCORE.CLASSES."/".$class_name.'.php';
  else include constant(strtoupper($GLOBALS['currentModule'])).CLASSES."/".$class_name.'.php';
}

/**
 * read var from $_REQUEST or from any other array and return the value
 * $_REQUEST as default array doesnt work
 *
 * @param array $var
 * @param bool $default; default false 
 * @param array $array as reference; default false ==> $_REQUEST is used, or any array
 * @return mixed value
 */
function readVar($var, $default = false, &$array = false) {
  if ($array === false) $array =& $_REQUEST;
  $var = isset($array[$var]) ? $array[$var] : $default;
//  echo $var.$array[$var]."<br>";
  return $var;
}

/**
 * read var from $config or from mapping and return the value
 * $_REQUEST as default array doesnt work
 *
 * @param array $var
 * @param bool $default; default false 
 * @param array $array as reference; default false ==> $_REQUEST is used, or any array
 * @return mixed value
 */
function readConf($var, $default = false) {
  global $config, $mapping;
  
  $var = isset($config[$var]) ? $config[$var] : (isset($mapping[$var]) ? $mapping[$var] : $default);
  return $var;
}

?>