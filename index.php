<?php

/**
 * Salesforce Workflow Outboud message reciever in PHP
 * requires: Salesforce php toolkit, and you must install phpseclib 2.0 using Composer, which must also be installed. 
 * There is probably a way to include the necessary phpseclib tool without Composer, but this is the way we did it.
 * Your php.ini file must have a line 'always_populate_raw_post_data=-1' as well (at least ours required it), or you can use the ini_set command to make it dynamic.
 * Also, download the SOAP WSDL from Salesforce, which is written in xml, and make a directory called /wsdl/ in the same directory as this script, and put the wsdl file in it.
 * There are other instructions to pay attention to throughout the script. 
 * Written by Matt Davis with help from Shaun Zendner
 */

/////////////////////////////////////////// Includes //////////////////////////////////////////////
include realpath(__DIR__ . '/../autoload.php');

$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('phpseclib\\', realpath(__DIR__ . '/../vendor/phpseclib/phpseclib/phpseclib'));
$loader->register();

use phpseclib\Crypt\RSA;
use phpseclib\Net\SSH2;
include realpath(__DIR__ . '/../commonDirLocation.php');
include realpath(COMMON_PHP_DIR . '/SlackMessagePost.php');
include realpath(COMMON_PHP_DIR . '/creds.php');
include realpath(COMMON_PHP_DIR . '/checkOrgID.php');
include realpath(COMMON_PHP_DIR . '/respond.php');
include realpath(COMMON_PHP_DIR . '/parseNotification.php');
include realpath(COMMON_PHP_DIR . '/deleteOldLogs.php');
include realpath(COMMON_PHP_DIR . '/checkWait.php');
include realpath(COMMON_PHP_DIR . '/writelog.php');
include realpath(COMMON_PHP_DIR . '/logTime.php');
//$wsdl = __DIR__ . '/wsdl/' . 'soapxml.wsdl'; // the wsdl directory location, with file called soapxml.wsdl

date_default_timezone_set('America/Los_Angeles');
ini_set("soap.wsdl_cache_enabled", "0");  // clean WSDL for develop
ini_set("allow_url_fopen", true);
$f_name = pathinfo(__FILE__)['basename'];
$f_dir = pathinfo(__FILE__)['dirname'];
$log_dir = '/log/';

//////////////////////////////////////// Variables /////////////////////////////////////////////////



/* // This creates an array of IPs to use for testing.
$octet = 1;

while ($octet < 255) {
    $addip = "57.204.46.$octet";
    $ipArrays[] = $addip;
    $octet++;
}
*/
//$ipArrays = array('57.204.46.52', '57.204.46.53', '57.204.46.54');



/////////////////////////////////////////////// Functions //////////////////////////////////////////////////



function enter_config() {
  global $ssh;
  $success = '';
  $ssh->setTimeout(3);
  $ssh->write("\r");
  $result = $ssh->read();
//    								      writelog($result);
  $pattern = '%\(config\)#%';
  if (!(preg_match($pattern, $result ))) {
    end_config();
    							              writelog("\nTRYING TO ENTER CONFIG MODE...");
    $ssh->write("conf t\r");
    $result = $ssh->read();
//  								      writelog("\nENTER CONFIG RESULT: $result");
     
    if (preg_match('%Current Configuration Session%', $result )) {
                                                                      writelog("\nThere is still an active config session. Salesforce will wait and try again...");
      $success = false;
    } else if (preg_match($pattern, $result)) {
      $success = true;
    } else {
                                                                      writelog("COULD NOT ENTER CONFIG MODE");
      $success = false;
    }
  } else {
    $success = true;
  }
  if ($success) {
    $ssh->setTimeout(1);
                                                                      writelog("\nENTERED CONFIG MODE...");
    return true; 
  } else {
    return false;
  }
}


function end_config() { // tries to end the config mode, and returns success (true or false)
  global $ssh;
  $ssh->setTimeout(1);
  $ssh->write("\r");
  $result = $ssh->read();
//    								      writelog($result);
  $pattern = '%config%';
  if ((preg_match($pattern, $result ))) {
                                                                      writelog("\nTRYING TO END CONFIG MODE...");
    $ssh->write("end\r");
    $result = $ssh->read();
//    								      writelog($result);
    if (preg_match('%Uncommitted changes found%', $result)) {
      $ssh->write("yes\r");
      $result = $ssh->read();
//    								      writelog($result);
    }
    if (preg_match('%commit anyway\?%', $result)) {
      $ssh->write("yes\r");
    }
  }
  $result = $ssh->read();   // Are we out of config mode? //
//    								      writelog($result);
  $out_pattern = '%(?!(config).*#)%';
  if (!preg_match($out_pattern, $result)) { //regex interpretation: if you don't see a "#" without "config" somewhere before it in the $result string
    $success = false;
    writelog("\n\nEND command failed.\n\n");
  } else {
    $success = true;
  }
  return $success;
}


function get_configured_routes($ips){  //input: array of IPs. Returns routes matching tag and ips in a multidimensional array (nested array)
  $f_name = 'get_configured_routes';
  global $ssh;
  $ssh->setTimeout(1);
  $ip_routes_mult_array = array();
  $ssh->write("\r");
  $result = $ssh->read();
  if (preg_match('%config%', $result)) {
      end_config();
  }
  $ssh->setTimeout(1.5);

  $cmd0_result = false;
  $cmd0 = "terminal length 0\r";
  $ssh->write($cmd0);
  $cmd0_result = $ssh->read('#');
//                                                                    writelog("\n$f_name CMD0 RESULT: " . $cmd0_result);

  foreach($ips as $i){
                                                                      writelog("\n\n $f_name IP is " . $i);
    $cmd1_result = false;
    $cmd1 = "show run router static | include " . TAG . "\r"; 
    $ssh->write($cmd1);
    $cmd1_result = $ssh->read('#');
                                                                      writelog("\n$f_name CMD1 RESULT:\n" . $cmd1_result . "\n:END CMD1 RESULT");
    $pattern = '  ' . $i . '\/32 ' . NULL_RTE_ESC_IP . ' tag ' . TAG;
//    								writelog("\n$f_name PATTERN: $pattern \n");
    $matches = array();
    if (preg_match_all("+$pattern+", $cmd1_result, $matches)) {
                                                                      writelog("\n\n$f_name MATCHES:\n\n");
                                                                      writelog($matches[0]);
      foreach ($matches as $m){
        $ip_routes_mult_array[$i][TAG] = $m;
      }
    } else {
                                                                      writelog("\n$f_name -- PATTERN:  " . $pattern . "  :not found in get_configured_routes result\n");
    }
  }

  $ip_routes_mult_array = array_filter($ip_routes_mult_array); //array_filter() function's default behavior will remove all values from array which are equal to null, 0, '' or false
  $ip_routes_mult_array2 = array(); // new array to append the cleaned up array items to
  foreach ($ip_routes_mult_array as $key => $r){
    $ip_routes_mult_array2[$key] = array_filter($r);
  }
  $output = $ip_routes_mult_array2;
//                                                                    writelog("\n$f_name OUTPUT:\n");
//                                                                    writelog($output);
//                                                                    writelog("\n:END $f_name OUTPUT\n");
  return $output; //should return a multidimensional array something like this: Array([192.168.33.33] => Array( [999] => Array([0] => S    192.168.33.33/32 [1/0] via 205.157.xxx.xxx, 00:00:08)))
}


function rem_fr_routes($arr_ipsToRemove, $retries = 5){
  $f_name = 'rem_fr_routes';
  global $ssh;
  global $config_route_prompt;
  $chk_route_result = get_configured_routes($arr_ipsToRemove);
//                                                                    writelog("$f_name -- chk_route_result:\n $chk_route_result");
  if (! empty($chk_route_result)) {
    if (enter_config()){
        foreach ($chk_route_result as $arr_ipToRemove => $ip_array) {
          foreach ($ip_array as $route_arr => $route_list) {
            $ssh->setTimeout(1);
//                                                                    writelog("\n\n$f_name route NAME: $route_arr");
            foreach ($route_list as $route_idx => $routeToRemove) {
              $cmd1 = "no router static address-family ipv4 unicast $arr_ipToRemove/32 " . NULL_RTE_IP . " tag " . TAG .  "\r";
                                                                      writelog("\n$f_name  CMD1: $cmd1");
              $ssh->write($cmd1);
              $cmd1_result = $ssh->read($config_route_prompt);
            }
          }
        }

      $commit = "commit\r";
      $ssh->setTimeout(1);
      $ssh->write($commit);
      $commit_result = $ssh->read($config_route_prompt);
//                                                                    writelog("\n$f_name COMMIT IPs: ");
//                                                                    writelog($ip_array);
      $conf_routes = get_configured_routes($arr_ipsToRemove);
//                                                                    writelog("\n$f_name conf_routes:\n");
//                                                                    writelog($conf_routes);
      if (empty($conf_routes)) {
        $success = true;
      } else {
        $success = false;
        if ($retries >= 0) {
                                                                      writelog("\n$f_name !!!!!!!!!!!!!!!!!!!!!!!!  RETRYING rem_fr_routes !!!!!!!!!!!!!!!!!!!!!!!!!!");
          rem_fr_routes($arr_ipsToRemove, $retries--); // $retries-- (decrements the number of retries)
        }
      }
    } else {
      $success = false;
    }
  } else {	
    $success = true; //success is true because get_configured_routes() did not find the ip in static routes table
      								      writelog("\n$f_name SUCCESS: $success");
  }
  if (!$success) {
                                                                      writelog ("\n\n$f_name FAILED");
  }
  return $success;
}


function recursive_array_search($needle, $haystack, $currentKey = '') {
  foreach($haystack as $key=>$value) {
    if (is_array($value)) {
      $nextKey = recursive_array_search($needle,$value, $currentKey . '[' . $key . ']');
      if ($nextKey) {
        return $nextKey;
      }
    } else if(preg_match("+$needle$+", "$value")) {
//    return is_numeric($key) ? $currentKey . '[' .$key . ']' : $currentKey . '["' .$key . '"]';
      return true;
    }
  }
  return false;
}


function add_to_route($arr_ipsToAdd){ // Input: IP address. Returns true if all IPs are found in the recursive array search, else returns false.
  $f_name = 'add_to_route';
  global $ssh;
  global $config_route_prompt;
  $ssh->setTimeout(2);
  rem_fr_routes($arr_ipsToAdd, 3);
  if (enter_config()) {
      foreach($arr_ipsToAdd as $ip) {
          $cmd1 = "router static address-family ipv4 unicast $ip/32 " . NULL_RTE_IP . " tag " . TAG . "\r";
                                                                      writelog("\n$f_name CMD1: $cmd1");
          $ssh->write($cmd1);
          $cmd1_result = $ssh->read($config_route_prompt);
      }
    
    $commit = "commit\r";
    $ssh->setTimeout(2);
    $ssh->write($commit);
    $commit_result = $ssh->read($config_route_prompt);
                                                                      writelog("\n$f_name COMMIT RESULT: \n $commit_result");
    $chk_ips = get_configured_routes($arr_ipsToAdd);
    $success = true;
    foreach ($arr_ipsToAdd as $i) {
                                                                      writelog("\n$f_name IP_TO_CHECK: " . $i);
//    							              writelog("\n$f_name CHK_IPS_ARRAY:");
//    							              writelog($chk_ips);
//    $cip_type = gettype($chk_ips);
//                                                                    writelog("\n$f_name chk_ips_TYPE: " . $cip_type . "\n");
      $pattern = "  $i\/32 " . NULL_RTE_ESC_IP . " tag " . TAG;
      if (! recursive_array_search($pattern, $chk_ips)) {
        $success = false; 
      }
    }
  } else {
    $success = false;
  }
  return $success;
}



//=================================================== START EXECUTION CODE =========================================================
ob_start();



$config_route_prompt = '(config)#';

$req = file_get_contents('php://input');
if (empty($req)) {
  writelog("\n\nRequest is empty. Responding true and exiting...");
  respond('true');
  exit;
}
//									writelog("\n\nREQ:\n\n");
//									writelog($req);
$xml = new DOMDocument();
$xml->loadXML($req);
$request_array = parseNotification($xml);
unset($request_array['sObject']);
//									writelog( "\nXML_request_array:\n\n");
//									writelog($request_array);


if (array_key_exists('OrganizationId', $request_array)) {
  $org_id = $request_array['OrganizationId'];
  if (!checkOrgID($org_id)) {
    writelog("\nID check failed.");
    respond('true');
    exit;
  }
} else {
  writelog("\nNo Org ID found.");
  respond('true');
  exit;
}


$arr_size=count($request_array['MapsRecords']);
$success_count = 0;
writelog("\n\n=====================================================================================================\n\n");
writelog("\n NUMBER OF NOTIFICATIONS IN MESSAGE: $arr_size\n");

$msg_array = array();

for($i=0;$i<$arr_size;$i++) {
  $tf_string = $request_array['MapsRecords'][$i]['Disable_IP__c']; //value here is 'true' or 'false'
//									writelog("\ntf_string: $tf_string");
  $ip_string = $request_array['MapsRecords'][$i]['IP__c'];
  $ip_is_valid = filter_var($ip_string, FILTER_VALIDATE_IP);
  if (!$ip_is_valid) {
    $msg = "IP address: $ip_string is not recognized as valid";
    writelog("\n\n$msg\n");
    slack("$f_name :: $msg", 'mattd');
    $success_count++;
  }
//									writelog("\nip_string: $ip_string");
  $msg_array[$tf_string][] = $ip_string;
}
//									writelog($msg_array); 
try {

  $routers = array(RTR_IP_1); //array of hosts to connect to 
  if (!checkWait()) {
    foreach ($routers as $r){ // might have it connect to multiple devices later
      $ssh = new SSH2($r, RTR_PORT);
      $ssh->setTimeout(2);
      if (!$ssh->login(RTR_U_1, RTR_P_1)) {
        writelog('Login Failed');
      } else {
        $ssh->write("\r"); // somehow works around an issue of premature end of connection
        $ssh->read('#');
        foreach($msg_array as $routeCmd => $ipArray) {
          if ($routeCmd == 'false'){
            $remchk = rem_fr_routes($ipArray, 3);
            if ($remchk){
              writelog("\n\nrem_fr_routes - $routeCmd SUCCESS\n\n");
              $success_count++;
            } else {
              $msg = "rem_fr_routes - $routeCmd FAIL";
              writelog("\n\n$msg\n\n");
              slack("$f_name :: $msg", 'mattd');
            }
          } else if ($routeCmd == 'true'){
            $addchk = add_to_route($ipArray); 
            if ($addchk){
              writelog("\n\nadd_to_route $routeCmd SUCCESS\n\n");
              $success_count++;
            } else {
              $msg = "add_to_route - $routeCmd FAIL";
              writelog("\n\n$msg\n\n");
              slack("$f_name :: $msg", 'mattd');
            }
          } else {
            $msg = "I DO NOT UNDERSTAND THE COMMAND: $routeCmd";
            writelog("\n\n$msg \n");
            slack("$f_name :: $msg", 'mattd');
          }
        }
      }
    }
  }

  if ($success_count == $arr_size) {
    $resp = ob_get_clean();
    respond('true');
  } else {
    respond('false');
  }

} catch (Exception $e) {
  $msg = "Caught exception: $e->getMessage()";
  writelog("\n$msg\n");
  slack("$f_name :: $msg" , 'mattd');
}

$logs_dir = __DIR__ . '/log/';
deleteOldLogs($logs_dir, 60);
                                                    /////////////////////////////// END EXECUTION CODE ////////////////////////////////////////////
?>
