<?php
namespace {
 define('ABSPATH', __DIR__); define('ARRAY_A', 'ARRAY_A'); define('MINUTE_IN_SECONDS', 60); define('DAY_IN_SECONDS', 86400);
 class WP_Error { public function __construct($code='', $message='') {} }
 class Redirect extends Exception { public $url; public function __construct($url) {$this->url=$url;} }
 function absint($v){return abs((int)$v);} function is_wp_error($v){return $v instanceof WP_Error;}
 function sanitize_key($v){return strtolower((string)$v);} function sanitize_text_field($v){return trim(strip_tags((string)$v));} function sanitize_textarea_field($v){return sanitize_text_field($v);} function sanitize_email($v){return filter_var($v,FILTER_SANITIZE_EMAIL);} function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
 function wp_unslash($v){return $v;} function wp_verify_nonce($v,$action){return $v==='valid';} function esc_url_raw($v){return $v;} function wp_validate_redirect($v,$fallback){return $fallback;}
 function get_permalink($id){return 'https://example.test/events/'.$id.'/';} function add_query_arg($key,$value,$url){return $url.'?'.$key.'='.$value;} function wp_safe_redirect($url){throw new Redirect($url);}
 function get_post($id){return $id===42?(object)['ID'=>42,'post_type'=>'events','post_status'=>'publish','post_content'=>'']:null;}
 function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$key]??'';} function get_current_user_id(){return $GLOBALS['user_id']??0;} function wp_salt($scheme){return 'test-salt';} function current_time($format){return '2026-09-22 12:00:00';} function get_the_title($id){return 'Test workshop';}
 function wp_timezone(){return new DateTimeZone('Australia/Perth');} function wp_date($format,$ts,$tz=null){return (new DateTimeImmutable('@'.$ts))->setTimezone($tz??wp_timezone())->format($format);} function wp_parse_args($args,$defaults){return array_merge($defaults,$args);}
 function get_the_ID(){return $GLOBALS['current_id']??999;} function is_singular($cpt){return false;}
 function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_html__($v,$domain){return esc_html($v);} function esc_attr($v){return esc_html($v);} function esc_url($v){return $v;} function admin_url($path){return 'https://example.test/wp-admin/'.$path;} function wp_nonce_field($action,$name){echo '<input name="'.$name.'" value="valid">';}
 function check($v,$label){if(!$v)throw new Exception($label);echo "PASS $label\n";}
}
namespace Jet_Engine\Modules\Custom_Content_Types {
 class Module {public $manager;public static function instance(){return $GLOBALS['module'];}}
}
namespace HeartHub\EventRegistrations {
 class Settings {public function get($key,$default=''){return ['events_cpt'=>'events','attendee_count_field'=>'number_of_attendees'][$key]??$default;}}
 class Audit_Log {public $entries=[];public function write(...$args){$this->entries[]=$args;}}
 class Capacity_Manager {public $reservations=0;public function attendee_key(){return 'number_of_attendees';}public function attendees_from($item){return max(1,(int)($item['number_of_attendees']??1));}public function enabled(){return true;}public function ensure_reserved(...$args){$this->reservations++;return true;}}
 class Email_Automation {public $received=[];public function send_interest_received($item,$event){$this->received[]=[$item,$event];}}
 require dirname(__DIR__,2).'/includes/class-event-datetime.php';
 require dirname(__DIR__,2).'/includes/class-event-public-display.php';
 require dirname(__DIR__,2).'/includes/class-cct-repository.php';
 require dirname(__DIR__,2).'/includes/class-public-form-token.php';
 require dirname(__DIR__,2).'/includes/class-jetform-integration.php';
 $db=new class {public $items=[];public function set_format_flag($flag){}public function get_item($id){return $this->items[$id]??null;}public function query(...$args){$rows=array_values($this->items);if(isset($GLOBALS["review_query_hook"])){$hook=$GLOBALS["review_query_hook"];unset($GLOBALS["review_query_hook"]);$hook();}return $rows;}};
 $handler=new class($db) {private $db;public function __construct($db){$this->db=$db;}public function update_item($fields){$id=count($this->db->items)+1;$fields['_ID']=$id;$this->db->items[$id]=$fields;$GLOBALS['integration']->after_created($fields,$id,$this);return $id;}};
 $type=new class($db,$handler) {public $db;private $handler;public function __construct($db,$h){$this->db=$db;$this->handler=$h;}public function get_item_handler(){return $this->handler;}public function prepare_query_args($args){return $args;}};
 $module=new \Jet_Engine\Modules\Custom_Content_Types\Module();$module->manager=new class($type){private $type;public function __construct($t){$this->type=$t;}public function get_content_types($slug){return $this->type;}};$GLOBALS['module']=$module;
 $s=new Settings();$repo=new CCT_Repository($s);$audit=new Audit_Log();$capacity=new Capacity_Manager();$email=new Email_Automation();$integration=new JetForm_Integration($repo,$s,$audit,$capacity,$email);$GLOBALS['integration']=$integration;
 $GLOBALS['meta']=['registration_open'=>time()+3600,'registration_close'=>time()+7200,'event_schedule_status'=>'scheduled','event_name'=>'Test workshop'];
 $valid=['event_id'=>42,'hherm_interest_nonce'=>'valid','first_name'=>'Alex','last_name'=>'Example','email'=>'alex@example.test','phone'=>'0400000000','reason_for_attending'=>'Interested'];
 $submit=function()use($integration,$valid){$_POST=$valid;try{$integration->submit_interest();}catch(\Redirect $r){return $r->url;}};
 $GLOBALS['review_query_hook']=function()use($submit){$GLOBALS['nested_result']=$submit();};
 $result=$submit();
 echo json_encode(['first_result'=>$result,'overlapping_result'=>$GLOBALS['nested_result'],'records'=>count($db->items),'confirmation_emails'=>count($email->received),'stored_emails'=>array_column($db->items,'email')]).PHP_EOL;
}

