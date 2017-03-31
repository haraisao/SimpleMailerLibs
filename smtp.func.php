<?php
/**
 * smtp.func.php: RFC821 SMTP email transport functions.
 * PHP Version 5
 * @author Isao Hara <isao@hara.jpn.com>
 * @copyright 2017 Isao Hara
 * @license The MIT License
 *
 */

$SMTP_ERRORS=array();

/**
 *   Send message using SMTP server.
 *     with STARTTLS and AUTH 
 */
function smtp_send_message($conf, $to, $message, $cc="", $bcc="")
{
  $id          = $conf['id'];
  $url         = $conf['smtp'];
  $uid         = $conf['user'];
  $crypt_pass  = $conf['passwd'];
  $m_addr      = $conf['address'];
  $auth_method = $conf['auth'];

  $pass       = decrypt_password($id, $crypt_pass);
  $domain     = substr($m_addr, strpos($m_addr,'@')+1);
  
  $smtp_mode  = 1;

  /** set crypto method **/
  $crypt_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
  if(defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')){
    $crypt_method |= STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
  }
  if(defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')){
    $crypt_method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
  }
  if(defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')){
    $crypt_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
  }

  /** connect to SMTP server **/
  $fp = smtp_open($url, $smtp_mode);
  if ( $fp === FALSE ){
    push_smtp_errors("Fail to connect SMTP server.", "smtp_send_message");
    return FALSE;
  }

  /** Hello **/
  $hello_reply = smtp_hello($fp, $domain, $smtp_mode);
  if($hello_reply === FALSE){
    push_smtp_errors("Fail to HELLO Command.", "smtp_send_message");
    smtp_close($fp);
    return FALSE;
  }

  /** move to TLS move ***/
  if( smtp_check_messages($hello_reply, 'STARTTLS') ){
    if(smtp_start_tls($fp, $crypt_method) === FALSE){
      push_smtp_errors("STARTTLS Command not support.", "smtp_send_message");
      smtp_close($fp);
      return FALSE;
    }
    /** Hello again**/
    $hello_reply = smtp_hello($fp, $domain, $smtp_mode);
    if($hello_reply === FALSE){
      push_smtp_errors("Fail to HELLO Command again.", "smtp_send_message");
      smtp_close($fp);
      return FALSE;
    }
  }

  /*** SMTP Autherntication **/
  if( ($line = smtp_check_messages($hello_reply, 'AUTH')) ){
    $res = smtp_authentication($fp, $auth_method, $uid, $pass, $line);
    if ($res === FALSE){
      push_smtp_errors("Fail to login.", "smtp_send_message");
      smtp_close($fp);
      return FALSE;
    }
  }else{
    push_smtp_errors("AUTH Command not support.", "smtp_send_message");
    smtp_close($fp);
    return FALSE;
  }
  
  /*** Send Mail ***/
  $res = smtp_send_mail($fp, $m_addr, $to, $message, $cc, $bcc);
  if($res === FALSE){
    push_smtp_errors("Fail to send message.", "smtp_send_message");
  }
  /***************/
  smtp_close($fp);
  return $res;
}

/**
 *   Open SMTP connection
 */
function smtp_open($url, &$smtp_mode, $debug=FALSE){
  $errno = 0;
  $errstr = "";
  $timeout = 10;
  $smtp_mode = 1;

  $fp = stream_socket_client($url, $errno, $errstr, $timeout);

  if ( !$fp ){
    push_smtp_errors("$errstr ($errno)", "smtp_open");
    return FALSE;
  }else{
    if($debug){ print "==> Connect to SMTP server\n"; }
    /*** Check SMTP Server **/
    $res = fgetss($fp);
    if ( strpos($res, 'ESMTP') === FALSE ){ $smtp_mode = 0; }
  }
  return $fp;
}

/**
 *   Close SMTP connection
 */
function smtp_close($fp, $debug=FALSE){
  if($debug){  print "\n==> Send Quit "; }
  smtp_request($fp, "QUIT");
  if($debug) { print "==> Close connection\n"; }
  fclose($fp);
  return;
}

/**
 *  SMTP Greeing  
 */
function smtp_hello($fp, $domain, $mode=1){
  if($mode == 1){
    $hello = "EHLO ".$domain;
  }else{
    $hello = "HELO ".$domain;
  }
  $res = smtp_request($fp, $hello);
  if ($res['code'] != 250){
    push_smtp_errors("Fail to Hello", "smtp_hello");
    return FALSE;
  }
  $_res = smtp_parse_reply($res);
  return $_res;
}

/**
 * Send message
 */
function smtp_send_mail($fp, $from, $to, $data, $cc="", $bcc=""){
  $mailfrom = "MAIL FROM:<".$from.">";
  $res = smtp_request($fp, $mailfrom);
  if ($res['code'] != 250){
    push_smtp_errors($res['msg'], "smtp_send_mail");
    return FALSE;
  }

  $to_addrs = $to;
  if($cc) { $to_addrs .= ",".$cc; }
  if($bcc) { $to_addrs .= ",".$bcc; }
//  $to_addrs .= ",".$from;

  $to_ar = explode(',', $to_addrs);
  if(count($to_ar) > 0){
    foreach($to_ar as $toaddr){
      $addr = trim($toaddr);
      if ($addr){
        $mailto = "RCPT TO:<".$addr.">";
        $res = smtp_request($fp, $mailto);
        if ($res['code'] != 250){
          push_smtp_errors($res['msg'], "smtp_send_mail");
	  return FALSE;
        }
      }
    }
  }

  /*** send mail contents ***/
  $res = smtp_request($fp, 'DATA');
  if ($res['code'] != 354){
    push_smtp_errors($res['msg'], "smtp_send_mail");
    return FALSE;
  }

  fwrite($fp, $data);

  $res = smtp_request($fp, "\r\n.");
  if ($res['code'] != 250){
    push_smtp_errors($res['msg'], "smtp_send_mail");
    return FALSE;
  }
  /*****/

  return TRUE;
}

/**
 *   Start TLS mode
 */
function smtp_start_tls($fp, $crypt_method){
  $res = smtp_request($fp, "STARTTLS");
  if ($res['code'] != 220){
    push_smtp_errors("Fail to STARTTLS", "smtp_start_tls");
    return FALSE;
  }

  return  stream_socket_enable_crypto($fp, true, $crypt_method);
}

/**
 * SMTP Authetication
 */
function smtp_authentication($fp, $method, $uid, $pass, $msg){
  if (strpos($msg, $method) === FALSE){
    push_smtp_errors("$method does not support", "smtp_authentication");
    return FALSE;
  } 

  if ($method == 'LOGIN'){ /// AUTH LOGIN
    $res = smtp_auth_login($fp, $uid, $pass);
  }else if ( $method == 'PLAIN'){ /// AUTH PLAIN
      $res = smtp_auth_plain($fp, $uid, $pass);
  }else if ( $method == 'CRAM-MD5') { /// AUTH PLAIN
      $res = smtp_auth_cram_md5($fp, $uid, $pass);
  }

  return $res;
}

/**
 *   SMTP Authentication (LOGIN)
 */
function smtp_auth_login($fp, $uid, $pass){
  $login_res = smtp_request($fp, "AUTH LOGIN");
  if ($login_res['code'] != 334){
    push_smtp_errors($login_res['msg'], "smtp_auth_login");
    return FALSE;
  }
  $l_res = base64_decode(substr($login_res['msg'], 4));

  $uid_res = smtp_request($fp, base64_encode($uid));
  if ($uid_res['code'] != 334){
    push_smtp_errors($uid_res['msg'], "smtp_auth_login(uid)");
    return FALSE;
  }
  $u_res = base64_decode(substr($uid_res['msg'], 4));

  $pass_res = smtp_request($fp, base64_encode($pass));
  if ($pass_res['code'] != 235){
    push_smtp_errors($pass_res['msg'], "smtp_auth_login(pass)");
    return FALSE;
  }
  $p_res = $pass_res['msg'];

  return array($l_res, $u_res, $p_res);
}

/**
 *  SMTP Authentication (PLAIN)
 */
function smtp_auth_plain($fp, $uid, $pass){
  $login_res = smtp_request($fp, "AUTH PLAIN");
  if ($login_res['code'] != 334){
    push_smtp_errors($login_res['msg'], "smtp_auth_plain");
    return FALSE;
  }
  $l_res = base64_decode(substr($login_res['msg'], 4));

  $passcode =  base64_encode("$uid\0$uid\0$pass");
  $pass_res = smtp_request($fp, $passcode);
  if ($pass_res['code'] != 235){
    push_smtp_errors($pass_res['msg'], "smtp_auth_plain(pass)");
    return FALSE;
  }
  $p_res = $pass_res['msg'];

  return array($l_res, $p_res);
}

/**
 *   SMTP Authentication (CRAM-MD5)
 */
function smtp_auth_cram_md5($fp, $uid, $pass){
  $login_res = smtp_request($fp, "AUTH CRAM-MD5");
  if ($login_res['code'] != 334){
    push_smtp_errors($login_res['msg'], "smtp_auth_cram_md5");
    return FALSE;
  }

  $cc = base64_decode(trim(substr($login_res['msg'], 4)));
  $passcode =  base64_encode($uid.' '.hash_hmac('md5', $cc, $pass));

  $pass_res = smtp_request($fp, $passcode);
  if ($pass_res['code'] != 235){
    push_smtp_errors($pass_res['msg'], "smtp_auth_cram_md5(pass)");
    return FALSE;
  }
  $p_res = $pass_res['msg'];

  return array($l_res, $p_res);
}

/**
 *  Parse SMTP reply message
 */
function smtp_parse_reply($reply){
  $msgs = explode("\r\n", $reply['msg']);
  $code = $reply['code'];

  $res = array();

  foreach($msgs as $m){
    $key = (int)substr($m, 0, 3);

    if($key == $code){
      array_push($res,  substr($m, 4) );
    }else{
      if(count($res) < 1){
        return FALSE;
      }
    }
  }

  return $res;
}

/**
 * Check SMTP Commands
 */
function smtp_check_messages($msgs, $str){
  foreach($msgs as $msg){
    if(strpos($msg, $str) !== FALSE){
      return $msg;
    }
  }
  return FALSE;
}

/**
 *  SMTP Command Request
 */
function smtp_request($fp, $cmd){
  fwrite($fp, $cmd."\r\n");
  $msg = "";

  while(!feof($fp)){
     $line = fgetss($fp);
     if(preg_match('/^[0-9][0-9][0-9] /', $line, $matches) == 1){
       $msg .= $line;
       return array('code'=> (int)$matches[0], 'msg' => $msg);
     }
     $msg .= $line;
  }
  return array('code'=> 0, 'msg' => $msg);
}

/**
 *   get message in SMTP_ERROR stack
 */
function smtp_errors()
{
  global $SMTP_ERRORS;
  $res = $SMTP_ERRORS;
  $SMTP_ERRORS=array();
  return $res; 
}

/**
 *   push message in SMTP_ERROR stack
 */
function push_smtp_errors($msg, $fun)
{
  global $SMTP_ERRORS;
  $SMTP_ERRORS[]="ERROR in ".$fun.":".$msg;
  return; 
}
?>
