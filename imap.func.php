<?php
/**
 * imap.func.php: IMAP functions
 * PHP Version 5
 * @author Isao Hara <isao@hara.jpn.com>
 * @copyright 2017 Isao Hara
 * @license The MIT License
 *
 */

/**********/
mb_language('Japanese');
mb_internal_encoding('ISO-2022-JP');

include_once('password.func.php');

define('IMAP_CONF_DIR', "/var/www/share/imap/");
$imap_conf_dir=IMAP_CONF_DIR;

$TrashBox = array( "Trash", "ゴミ箱", "ごみ箱","削除済みアイテム" ) ;
$DraftBox = array( "Draft", "下書き") ;
$SentBox  = array( "Sent", "送信済みメール", "送信済みアイテム") ;

/*******************************************************/
/**
 *  UID for Message
 */
function new_message_id()
{
  $uid = uniqid();
  return $uid;
}

/**
 *   get list of subscribed mailboxes
 */
function get_mailboxes($mbox, $mbox_name)
{
  $mboxes = imap_getsubscribed($mbox, $mbox_name, "*");
  return $mboxes;
}

/**
 * get name of mailbox in UTF-8
 */
function get_boxname($name, $mbox_name=""){
   global $_SESSION;

   if($mbox_name){
     $mboxname = substr($name, strlen($mbox_name));
   }else{
     $mboxname = substr($name, strlen($_SESSION['imap_mbox']));
   }
   return mb_convert_encoding($mboxname, "utf-8", 'UTF7-IMAP');
}

/**
 * make list of mailboxes in SESSION variable.
 */
function mk_mbox_list($mbox, $mbox_name)
{
  global $_SESSION;
  $mbox_list = get_mailboxes($mbox, $mbox_name);

  foreach($mbox_list as $itm){
    $name = get_boxname($itm->name, $mbox_name);
    $_SESSION['mboxes'][$name] = $itm->name;
  }
}

/**
 *  move a message to selected mailbox.
 */
function move_to_trash($mbox, $id){
  $trash = get_TrashBox();
  if ($trash){
    $trash =  mb_convert_encoding($trash, 'UTF7-IMAP', 'utf-8');
    imap_mail_move($mbox, $id, $trash);
    imap_expunge($mbox);
  }
}

/**
 *  get mailbox from name.
 */
function get_Mailbox_Name_From_Entry($Boxnames)
{
  global $_SESSION;
  if(isset($_SESSION['mboxes'])){
    foreach(array_keys($_SESSION['mboxes']) as $itm){
      $val = array_search($itm, $Boxnames);
      if($val !== FALSE){
        return $Boxnames[$val];
      }
    }
  }
  return FALSE;
}

/**
 * get mailbox, Trash
 */
function get_TrashBox()
{
  global $TrashBox;
  return get_Mailbox_Name_From_Entry($TrashBox);
}

/**
 * get mailbox, Draft
 */
function get_DraftBox()
{
  global $DraftBox;
  return get_Mailbox_Name_From_Entry($DraftBox);
}

/**
 * get mailbox, Sent
 */
function get_SentBox()
{
  global $SentBox;
  return get_Mailbox_Name_From_Entry($SentBox);
}

/**
 * place message into mailbox
 */
function put_message_to_mailbox($mbox, $msg, $tombox, $opt=NULL)
{
  global $_SESSION;

  $res = FALSE;
  $mailbox_name = get_Mailbox_Name_From_Entry($tombox);
  if ($mailbox_name){
    $mailbox = $_SESSION['mboxes'][$mailbox_name];
    $res = imap_append($mbox, $mailbox, $msg, $opt);
  }
  return $res;
}

/**
 *  Place a sent message
 */
function store_sent_message($mbox, $msg){
  global $_SESSION, $SentBox;
  return put_message_to_mailbox($mbox, $msg, $SentBox,"\\Seen");
}

/**
 *  Place a draft message
 */
function store_message_to_draft($mbox, $msg){
  global $_SESSION, $DraftBox;
  return put_message_to_mailbox($mbox, $msg, $DraftBox, "\\Draft");
}

/**
 *  Load file
 */
function load_file_contents($fname, $path=IMAP_CONF_DIR)
{
  try{
    return file_get_contents($path.$fname);
  }catch(Exception $e){
    return "";
  }
}

/**
 * Load config file 
 */
function load_ini_file($fname, $path=IMAP_CONF_DIR)
{
  return parse_ini_file($path.$fname, TRUE);
}

/**
 *  Save config file
 */
function save_file_contents($config, $fname, $path=IMAP_CONF_DIR)
{
  $content="";

  foreach($config as $key => $data){
    $content .= "[$key]\n";
    foreach($data as $k => $val){
      $content .= "  $k = \"$val\"\n";
    }
    $content .= "\n";
  }
  return file_put_contents($path.$fname, $content);
}
 
/**
 *  Transform special characters to HTML entities
 */
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES);
}

/**
 * convert mail in UTF-8 (for PHP 5.3 or olders)
 */
function convertMailStr($str) {
  if (strpos($str, '=?gb18030?B') === FALSE){
    if (strpos($str, '=?') === FALSE){
      return imap_utf8($str);
    }else{
      return mb_convert_encoding(mb_decode_mimeheader($str), 'utf-8', "auto");
    }
  }else{
    $str1 =imap_utf8($str);
    return iconv('GB18030', 'utf-8', $str1);
  }
}

/**
 * get charset entry from a mail header
 */
function getCharset($prop){
  foreach( $prop->parameters as $param){
    if($param->attribute == "charset"){
      return $param->value;
    }
  }
  return "auto";
}

/**
 *   Connect IMAP Server
 */
function connect_imap_server( $mboxname, $user, $passwd)
{
  $mbox_name = $mboxname."INBOX";
  //$mbox = imap_open($mbox_name, $user, $passwd, OP_HALFOPEN);
  $mbox = imap_open($mbox_name, $user, $passwd);

  if ($mbox !== FALSE){ mk_mbox_list($mbox, $mboxname); }
  return $mbox;
}

/**
 *  disconnect from IMAP server
 */
function close_imap_server($mbox){
  if ($mbox){
    imap_close($mbox);
  }
  return;
}

/**
 *  get header information of a selected mail.
 */
function get_message_header_info($mbox, $id)
{
  $head             = imap_headerinfo($mbox, $id);
  $data['date']     = date('Y-m-d H:i', strtotime($head->date));
  $from             = convertMailStr($head->fromaddress);
  $data['from']     = htmlspecialchars($from);
  $to               = convertMailStr($head->toaddress);
  $data['to']       = htmlspecialchars($to);
  $data['subject']  = convertMailStr($head->subject);
  $data['date']     = $head->date;
  $data['id']       = $id;
  $data['reply_to'] = "";
  if(isset( $head->message_id)){
   $data['message_id'] = $head->message_id;
  }else{
    $data['message_id'] = "";
  }
  $data['all']      = $head;
  
  if (isset($head->reply_to)){
    $f = $head->from[0];
    $r = $head->reply_to[0];
    if( $f->mailbox != $r->mailbox || $f->host != $r->host ){
       $data['reply_to'] = $head->reply_toaddress;
    }
  }
  return $data;
}

/**
 * get message information and create a simple header to display
 */
function get_message_info($mbox, $id, $uid)
{
  $info    = get_message_header_info($mbox, $id);
  $date    = $info['date'];
  $from    = $info['from'];
  $subject = $info['subject'];

  $data =<<<_HTML
  <a href="index.php?cmd=show&mid=$id">$id)</a>
  <font size=\"-1\"> $date </font>: From: $from <br>
  &nbsp;&nbsp;&nbsp; $subject
_HTML;

  return $data;
}

/**
 * get list of message's parts
 */
function get_message_parts($prop, $pos=0)
{
  $res = array();
  if(isset($prop->parts)){
    $len=count($prop->parts);
    foreach(range(1, $len) as $num){

      $child = get_message_parts($prop->parts[$num -1], $num);
      if(count($child)){
        array_push($res, $child);
      }else{
        if($pos > 0){ array_push($res, $pos.".".$num);}
        else{ array_push($res, $num); }
      }
      
    }
  }
  return $res; 
}

/**
 * get part information
 */
function get_part_info($mbox, $id)
{
  $struct = imap_fetchstructure($mbox, $id);
  return array_flatten(get_message_parts($struct));
}

/**
 * get part property
 */
function get_part_prop($prop, $part=0)
{
  if($part != 0){
    $part_val =  explode('.', $part);
      
    while($part_val){
      $pos = array_shift($part_val);
      $prop = $prop->parts[$pos-1];
    }
  }
  return $prop;
}

/**
 *  get attached filename and convert to UTF-8
 */
function get_filename($prop)
{
  if($prop->ifparameters == 0)
  {
    //return  mb_convert_encoding(mb_decode_mimeheader($prop->description), 'utf-8', 'auto');
    return imap_utf8($prop->description);
  }else{
    foreach($prop->parameters as $param){
      if( $param->attribute  == 'name' || $param->attribute  == 'filename'){
//        return mb_convert_encoding(mb_decode_mimeheader($param->value), 'utf-8', 'auto');
        return mb_convert_encoding(mb_decode_mimeheader($param->value), 'utf-8', 'ASCII,JIS,UTF-8,EUC-JP,SJIS,ISO-2022-JP,ISO-2022-JP-MS');
//        return imap_utf8($param->value);
        return $param->value;
      }
    }
  }
  return "No name";
}

/**
 *  get part indexes
 */
function get_part_index($mbox, $id)
{
  $struct = imap_fetchstructure($mbox, $id);
  $part_info = array_flatten(get_message_parts($struct));
  $part_index = "";

  foreach($part_info as $p){
    $prop=get_part_prop($struct, $p);

    if ( isset($prop->disposition) && $prop->disposition == 'attachment'){
      $subtype=get_filename($prop);
    }else if ($prop->type == 5){ // image
      $subtype = get_filename($prop);
      if(!$subtype){
        $subtype=$prop->subtype;
      }
    }else{
      $subtype=$prop->subtype;
    }
    $part_index .=<<<_HTML
   <a href="index.php?cmd=show&mid=$id&part=$p">$p [$subtype] </a>
_HTML;
  }
  return $part_index; 
}

/**
 *  get attachement file list
 */
function check_attachment($mbox, $id)
{
  $struct = imap_fetchstructure($mbox, $id);
  $part_info = array_flatten(get_message_parts($struct));
  $part_index = "";

  foreach($part_info as $p){
    $prop=get_part_prop($struct, $p);
    $fname="";
    if ( isset($prop->disposition) && $prop->disposition == 'attachment'){
      $fname=get_filename($prop);
    }else if ($prop->type == 5){ // image
      $fname = get_filename($prop);
      if(!$subtype){
        $fname=$prop->subtype;
      }
    }
    if($fname){
      $part_index .=<<<_HTML
   [<a href="index.php?cmd=show&mid=$id&part=$p">$fname</a>]
_HTML;
    }
  }
  return $part_index;
}

/**
 *  get client browser info, but too old
 */
///////////////////////////////////////
//  check the Browser, not enough...

function chkBrowser(){
  if (preg_match("/^DoCoMo/",$_SERVER['HTTP_USER_AGENT'])){
    return "imode";
  }else if (preg_match("/iPhone/",$_SERVER['HTTP_USER_AGENT'])){
    return "iphone";
  }else if(preg_match("/NetFront/",$_SERVER['HTTP_USER_AGENT'])){
    return "netfront";
  }else if(preg_match("/Windows/",$_SERVER['HTTP_USER_AGENT'])){
    return "windows";
  }else if(preg_match("/Mac OS X/",$_SERVER['HTTP_USER_AGENT'])){
    return "osx";
  }else if(preg_match("/Safari/",$_SERVER['HTTP_USER_AGENT'])){
    return "safari";
  }else return "unix";
}

/**
 *  get client infomration.
 */
function chkPlatform(){
  if (!isset($_SERVER['HTTP_USER_AGENT'])){
    return "unknown";
  }
  if (preg_match("(^DoCoMo)",$_SERVER['HTTP_USER_AGENT'])){
    return "imode";

  }else if(preg_match("(Windows)",$_SERVER['HTTP_USER_AGENT'])){
    return "Windows";

  }else if(preg_match("(Macintoch)",$_SERVER['HTTP_USER_AGENT'])){
    return "Mac";

  }else if(preg_match("(iPhone)",$_SERVER['HTTP_USER_AGENT'])){
    return "iPhone";

  }else if(preg_match("(iPad)",$_SERVER['HTTP_USER_AGENT'])){
    return "iPad";

  }else if(preg_match("(Android)",$_SERVER['HTTP_USER_AGENT'])){
    return "android";

  }else if(preg_match("(Linux)",$_SERVER['HTTP_USER_AGENT'])){
    return "linux";

  }else return "Unknown";
}

/**
 *  download a attached file..... not completed
 */
function download_file($content, $fname, $content_len){
  if($content_len == 0) return 0;

  header("Pragma: private");
  header("Cache-control: private, must-revalidate");

  $k_code="UTF-8";

  if(chkPlatform() == "Windows"){
   $k_code="SJIS";
  }

  if(chkBrowser() == "safari"){
    header("Content-Disposition: attachment; filename=\"\"");
  }else{
    header("Content-Disposition: attachment; filename=\"".mb_convert_encoding($fname,$k_code,"SJIS,UTF-8,EUC-JP")."\"");
  }
  header("Content-Type: applicaion/octet-stream");
  header("Content-Length: ".$content_len);

  print $content;

  return 1;
}

/**
 * get message body
 */
function get_message_body($mbox, $id, $part=1, $qprint=0, $convert_html=TRUE)
{
  $struct = imap_fetchstructure($mbox, $id);
 
  if ( $part == 0 ){
    $body = imap_fetchbody($mbox, $id, 0);
    $encoding = 0;
    $charset = "auto";

  }else{
    $prop=$struct;

    if (!isset($prop->parts)){
      $encoding = $prop->encoding;
      $body = imap_body($mbox, $id, FT_INTERNAL);
      $charset = getCharset($prop);
      $subtype = $prop->subtype;
    
    }else{
      $prop = get_part_prop($struct, $part);

      $subtype = $prop->subtype;
      $encoding = $prop->encoding;
      $charset = getCharset($prop);
      $body = imap_fetchbody($mbox, $id, $part);
    }

    if( $subtype == 'PLAIN' || $subtype == 'HTML'){
      if ($encoding == 4 || $qprint == 1){ 
        $body = quoted_printable_decode($body);
      }else if ($encoding == 3){ 
        $body = imap_base64($body);
      }

    }else if( $prop->type == 5){ /// Image File
      $filetype = $prop->subtype;
      $body = "<img src=\"data:image/".$filetype.";base64,".$body."\" />";
      $body .= "<br>Subtype:". $prop->subtype."<hr>";
      $body .= get_filename($prop);

      $body .=<<<_HTML
   Type: $prop->type<br>
   Encoding: $prop->encoding<br>
   Subtype: $prop->subtype<br>
   Bytes: $prop->bytes<br>
   Disposition: $prop->disposition<br>
_HTML;
        if($prop->ifparameters){
          foreach($prop->parameters as $param){
            $body .= $param->attribute.":".$param->value."<br>";
          }
        }


    }else if( $prop->type == 4
//           || $prop->type == 5
           || $prop->type == 6
           || $prop->type == 7){

        $filetype = $prop->subtype."<hr>";
        $body = $filetype.".".$body;
    }else{
      if($prop->disposition == 'attachment'){
        $content=imap_base64($body);
        $content_len=$prop->bytes;
        $fname=get_filename($prop);
        download_file($content, $fname, $content_len);
        exit();
      }else{
        $body = "Subtype:". $prop->subtype."<hr>";
        $body .= get_filename($prop);

        $body .=<<<_HTML
   Type: $prop->type<br>
   Encoding: $prop->encoding<br>
   Subtype: $prop->subtype<br>
   Bytes: $prop->bytes<br>
   Disposition: $prop->disposition<br>
_HTML;
        if($prop->ifparameters){
          foreach($prop->parameters as $param){
            $body .= $param->attribute.":".$param->value."<br>";
          }
        }

      }
    }
  }
 
  if($charset == 'gb18030'){
    $body = iconv($charset, "utf-8", $body);
  }else{
    $body = mb_convert_encoding($body, "utf-8", $charset);
  }

  if ($prop->subtype == "PLAIN" && $convert_html){
    $body = str_replace("\n", "<br>\n", $body);
  }
  return $body;
}

/**
 *  get plain part of a selected message.
 */
function get_plain_parts($mbox, $id){
  $struct = imap_fetchstructure($mbox, $id);
  $part_info = array_flatten(get_message_parts($struct));
  $body_info = array();
  foreach($part_info as $p){
    $prop=get_part_prop($struct, $p);
    if($prop->subtype == 'PLAIN'
     || ($p == '1' && $prop->subtype == 'HTML') ){
      array_push($body_info, $p);
    }
  }
  return $body_info;
}

/**
 * get test body part of a selected message.
 */
function get_text_message($mbox, $id, $qprint=0, $convert_html=TRUE)
{
  $data = get_plain_parts($mbox, $id);
  if(count($data)){
    $body="";
    foreach($data as $p){
      $body .= get_message_body($mbox, $id, $p, $qprint, $convert_html);
    }
  }else{
    $body = get_message_body($mbox, $id, 1, $qprint, $convert_html);
  }
  return $body;
}

/**
 *  split a message to header and body.
 */
function split_header($msg){
   $lines = explode("\n", $msg);

   $h=""; $str = "";

   foreach($lines as $l){
     if(trim($l) == "" && $h == ""){
       $h = $str;
       $str = "";
     }else{
       $str .= $l."\n";
     }
   }
   $b = $str;

   return array('header' => $h, 'body' => $b);
}

/**
 *  decode mail address...
 */
function decode_address($addr){
  $addr = str_replace('&lt;','<', $addr);
  $addr = str_replace('&gt;','>', $addr);
  $arr = imap_rfc822_parse_adrlist($addr, "");
  if (!is_array($arr) || count($arr) < 1){
    return FALSE;
  }
  $count = count($arr);
  $res = $arr[0]->mailbox."@".$arr[0]->host;

  for($i=1 ; $i < $count ; $i++){
    $res .= ", ".$arr[$i]->mailbox."@".$arr[$i]->host;
  } 
  return $res;
}

/**
 *  show message, but not used.
 */
function show_message($date, $from, $subject, $msg)
{
  print <<<_HTML
 <table class="message">
  <tr><th>Date:</th><td class="date">$date</td></tr>
  <tr><th>From:</th><td class="from">$from</td></tr>
  <tr><th>Subject:</th><td class="subject">$subject</td></tr>
  <tr><th></th><td class="message">$msg</td></tr>
</table>
_HTML;

}

/**
 * format message to display
 */
function format_message($date, $from, $subject, $msg)
{
  $data = <<<_HTML
 <table class="message">
  <tr><th>Date:</th><td class="date">$date</td></tr>
  <tr><th>From:</th><td class="from">$from</td></tr>
  <tr><th>Subject:</th><td class="subject">$subject</td></tr>
  <tr><th></th><td class="message">$msg</td></tr>
</table>
_HTML;

  return $data;
}
?>
