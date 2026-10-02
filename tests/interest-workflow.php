<?php
namespace {
 define('ABSPATH', __DIR__); define('ARRAY_A', 'ARRAY_A'); define('MINUTE_IN_SECONDS', 60); define('DAY_IN_SECONDS', 86400);
 class WP_Error { private $code;private $message;public function __construct($code='', $message='') {$this->code=$code;$this->message=$message;}public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;} }
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
 require __DIR__.'/support/option-lock.php';
}
namespace Jet_Engine\Modules\Custom_Content_Types {
 class Module {public $manager;public static function instance(){return $GLOBALS['module'];}}
}
namespace HeartHub\EventRegistrations {
 class Settings {public function get($key,$default=''){return ['events_cpt'=>'events','attendee_count_field'=>'number_of_attendees'][$key]??$default;}}
 class Audit_Log {public $entries=[];public function write(...$args){$this->entries[]=$args;}}
 class Capacity_Manager {public $reservations=0;public function attendee_key(){return 'number_of_attendees';}public function attendees_from($item){return max(1,(int)($item['number_of_attendees']??1));}public function enabled(){return true;}public function ensure_reserved(...$args){$this->reservations++;return true;}}
 class Email_Automation {public $received=[];public function send_interest_received($item,$event){$this->received[]=[$item,$event];}}
 require dirname(__DIR__).'/includes/class-event-datetime.php';
 require dirname(__DIR__).'/includes/class-event-public-display.php';
 require dirname(__DIR__).'/includes/class-cct-repository.php';
 require dirname(__DIR__).'/includes/class-public-form-token.php';
 require dirname(__DIR__).'/includes/class-jetform-integration.php';
 $db=new class {public $items=[];public $query_fault=false;public $on_query;public function set_format_flag($flag){}public function get_item($id){return $this->items[$id]??null;}public function query(...$args){if($this->query_fault)return new \WP_Error('query_failed');$rows=array_values($this->items);if($this->on_query){$hook=$this->on_query;$this->on_query=null;$hook();}return $rows;}};
 $handler=new class($db) {private $db;private $next_id=1;public $mode='save';public function __construct($db){$this->db=$db;}public function update_item($fields){$id=$fields['_ID']??$this->next_id++;$fields['_ID']=$id;$stored=$fields;if($this->mode==='truncate')$stored['first_name']=substr($stored['first_name']??'',0,2);if($this->mode==='omit_contact')unset($stored['email'],$stored['phone']);$this->db->items[$id]=array_replace($this->db->items[$id]??[],$stored);$GLOBALS['integration']->after_created($fields,$id,$this);if($this->mode==='throw_after')throw new \RuntimeException('after insert');return $id;}public function raw_delete_item($id){if($this->mode!=='delete_failure')unset($this->db->items[$id]);}};
 $type=new class($db,$handler) {public $db;private $handler;public function __construct($db,$h){$this->db=$db;$this->handler=$h;}public function get_item_handler(){return $this->handler;}public function prepare_query_args($args){return $args;}};
 $module=new \Jet_Engine\Modules\Custom_Content_Types\Module();$module->manager=new class($type){private $type;public function __construct($t){$this->type=$t;}public function get_content_types($slug){return $this->type;}};$GLOBALS['module']=$module;
 $s=new Settings();$repo=new CCT_Repository($s);$audit=new Audit_Log();$capacity=new Capacity_Manager();$email=new Email_Automation();$integration=new JetForm_Integration($repo,$s,$audit,$capacity,$email);$GLOBALS['integration']=$integration;
 $GLOBALS['meta']=['registration_open'=>time()+3600,'registration_close'=>time()+7200,'event_schedule_status'=>'scheduled','event_name'=>'Test workshop'];
 $valid=['event_id'=>42,'hherm_interest_nonce'=>'valid','first_name'=>'Alex','last_name'=>'Example','email'=>'alex@example.test','phone'=>'0400000000','reason_for_attending'=>'Interested'];
 $markup='<form class="jet-form-builder" data-form-id="2774"><input value="42" type="hidden" name="event_id"></form>';
 $render=$integration->replace_elementor_registration_form($markup,null);\check(strpos($render,'Submit expression of interest')!==false&&strpos($render,'value="42"')!==false,'archive popup uses the rendered event ID independent of attribute order');
 $GLOBALS['current_id']=999;\check($integration->replace_elementor_registration_form(str_replace('value="42"','value="999"',$markup),null)!==$render,'non-event popup context cannot create an interest form');
 $submit=function($data)use($integration){$_POST=$data;try{$integration->submit_interest();}catch(\Redirect $r){return $r->url;}throw new \Exception('Missing redirect');};
 \check(strpos($submit($valid),'hherm_interest=received')!==false,'basic expression of interest saves successfully');
 \check(count($db->items)===1&&$db->items[1]['registration_status']==='interest'&&$db->items[1]['event_id']===42,'record persists against correct event with interest status');
 \check($capacity->reservations===0,'interest never reserves event capacity');
 \check(count($email->received)===1&&$email->received[0][0]['email']==='alex@example.test','created lifecycle dispatches interest notifications');
 \check(strpos($submit($valid),'duplicate')!==false&&count($db->items)===1,'duplicate submission does not create or notify again');
 $invalid=$valid;$invalid['hherm_interest_nonce']='bad';\check(strpos($submit($invalid),'expired')!==false&&count($db->items)===1,'forged submission rejected without a record');
 $cached=$valid;$cached['hherm_interest_nonce']='stale';$cached['email']='cached@example.test';$cached[Public_Form_Token::FIELD]=Public_Form_Token::issue('hherm_event_interest_42');\check(strpos($submit($cached),'received')!==false&&count($db->items)===2,'guest form from a cached page is accepted after its nonce expires');
 $tampered=$cached;$tampered['email']='tampered@example.test';$tampered[Public_Form_Token::FIELD]=substr($cached[Public_Form_Token::FIELD],0,-1).(substr($cached[Public_Form_Token::FIELD],-1)==='0'?'1':'0');\check(strpos($submit($tampered),'expired')!==false&&count($db->items)===2,'tampered form token rejected');
 $old=$cached;$old['email']='old@example.test';$issued=time()-31*86400;$old[Public_Form_Token::FIELD]=$issued.'.'.hash_hmac('sha256','hherm_public_form|hherm_event_interest_42|'.$issued,'test-salt');\check(strpos($submit($old),'expired')!==false&&count($db->items)===2,'form token older than 30 days rejected');
 $other=$cached;$other['email']='other-event@example.test';$other[Public_Form_Token::FIELD]=Public_Form_Token::issue('hherm_event_interest_7');\check(strpos($submit($other),'expired')!==false&&count($db->items)===2,'token issued for another event rejected');
 $GLOBALS['user_id']=5;$member=$cached;$member['email']='member@example.test';\check(strpos($submit($member),'expired')!==false&&count($db->items)===2,'signed-in visitor still needs a valid nonce');$GLOBALS['user_id']=0;
 $invalid=$valid;$invalid['email']='invalid';\check(strpos($submit($invalid),'invalid')!==false,'invalid email rejected');
 $GLOBALS['meta']['event_cancelled']='true';\check(strpos($submit($valid),'unavailable')!==false,'cancelled event rejects interest');unset($GLOBALS['meta']['event_cancelled']);
 $GLOBALS['meta']['registration_open']=time()-3600;\check(strpos($submit($valid),'unavailable')!==false,'interest closes when registration opens');
 $db->items[2]=['_ID'=>2,'event_id'=>99,'registration_status'=>'pending','first_name'=>'Other','email'=>'other@example.test'];
 $list=$repo->list(['event_id'=>42,'status'=>'interest']);\check($list['total']===1&&$list['items'][0]['_ID']===1,'admin event and interest filters select the saved submission');
 $GLOBALS['meta']['registration_open']=time()+3600;$db->items=[];$email->received=[];$GLOBALS['options']=[];
 $db->on_query=function()use($submit,$valid){$GLOBALS['overlapping_interest']=$submit(array_replace($valid,['email'=>'ALEX@example.test']));};
 $result=$submit($valid);
 \check(strpos($result,'received')!==false&&strpos($GLOBALS['overlapping_interest'],'failed')!==false&&count($db->items)===1&&count($email->received)===1,'overlapping interest submissions share a normalized email lock and create one acknowledgement');
 \check($GLOBALS['options']===[],'interest lock is released before redirecting');
 $db->query_fault=true;$before=count($db->items);$result=$submit(array_replace($valid,['email'=>'lookup-fault@example.test']));
 \check(strpos($result,'failed')!==false&&count($db->items)===$before&&count($email->received)===1&&$GLOBALS['options']===[],'failed duplicate lookup saves nothing and releases the lock');$db->query_fault=false;
 $db->on_query=function(){foreach($GLOBALS['options'] as $key=>$value){if(strpos($key,'hherm_event_interest_lock_')===0)$GLOBALS['options'][$key]=['token'=>'replacement-owner','expires_at'=>time()+300];}};
 $result=$submit(array_replace($valid,['email'=>'lease-fault@example.test']));
 \check(strpos($result,'failed')!==false&&count($db->items)===$before&&count($email->received)===1&&count($GLOBALS['options'])===1,'lost interest lock stops before inserting and preserves the replacement owner');$GLOBALS['options']=[];
 foreach(['truncate','omit_contact','throw_after'] as $fault){
  $handler->mode=$fault;$result=$submit(array_replace($valid,['email'=>$fault.'@example.test']));
  \check(strpos($result,'failed')!==false&&count($db->items)===$before&&count($email->received)===1&&$GLOBALS['options']===[],$fault.' insert is removed without notifying or leaving a duplicate');
 }
 $handler->mode='save';$result=$submit(array_replace($valid,['email'=>'omit_contact@example.test']));
 \check(strpos($result,'received')!==false&&count($db->items)===$before+1&&count($email->received)===2,'a corrected retry succeeds after the incomplete insert was removed');
}
