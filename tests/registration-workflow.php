<?php
namespace {
 define('ABSPATH', __DIR__);
 class WP_Error { public $code; public function __construct($code,$message='',$data=[]) {$this->code=$code;} public function get_error_code(){return $this->code;} }
 class WP_REST_Request extends ArrayObject { public function has_param($key){return $this->offsetExists($key);} public function get_param($key){return $this[$key]??null;} }
 function absint($x){return abs((int)$x);} function sanitize_key($x){return (string)$x;} function sanitize_text_field($x){return trim(strip_tags((string)$x));} function sanitize_textarea_field($x){return sanitize_text_field($x);} function sanitize_email($x){return $x;}
 function is_email($x){return filter_var($x,FILTER_VALIDATE_EMAIL);} function is_wp_error($x){return $x instanceof WP_Error;} function rest_ensure_response($x){return $x;}
 function get_option($key){return $GLOBALS['options'][$key]??false;} function delete_option($key){unset($GLOBALS['options'][$key]);} function add_option($key,$value,...$rest){if(isset($GLOBALS['options'][$key]))return false;$GLOBALS['options'][$key]=$value;return true;}
 function get_current_user_id(){return 1;} function get_post_meta($id,$key,$single){return $GLOBALS['meta'][$key]??'';} function update_post_meta($id,$key,$value){$GLOBALS['meta'][$key]=$value;if(isset($GLOBALS['meta_hook'])){$callback=$GLOBALS['meta_hook'];unset($GLOBALS['meta_hook']);$callback();}}
 function metadata_exists($type,$id,$key){return array_key_exists($key,$GLOBALS['meta']??[]);}
 function get_post($id){return (object)['post_type'=>'events','post_status'=>'publish','post_excerpt'=>'','post_content'=>''];} function get_the_title($id){return 'Test event';}
 function check($value,$label){if(!$value)throw new Exception($label);echo "PASS $label\n";}
 require __DIR__ . '/support/option-lock.php';
}
namespace HeartHub\EventRegistrations {
 class Settings {public function get($key,$default=''){return ['attendee_count_field'=>'party','event_remaining_capacity'=>'remaining','events_cpt'=>'events'][$key]??$default;}}
 class Audit_Log {public $entries=[];public function latest_entry($id,$type){return $this->entries[$id][$type]??[];} public function latest_entry_checked($id,$type){return $this->latest_entry($id,$type);} public function latest_status($id,$type){return $this->latest_entry($id,$type)['status']??'';} public function write($id,$type,$status,$details){$this->entries[$id][$type]=compact('status','details');return true;}}
	class CCT_Repository {public $item;public $fail=false;public $read_hook;public $write_hook;public function get($id){if($this->read_hook){$callback=$this->read_hook;$this->read_hook=null;$callback();}return $this->item;} public function update_registration($id,$fields){if($this->write_hook){$callback=$this->write_hook;$this->write_hook=null;$callback();}if($this->fail)return new \WP_Error('failed');return $this->item=array_replace($this->item,$fields);} public function update_review($id,$status,$notes){return $this->update_registration($id,['registration_status'=>$status,'decline_reason'=>$notes]);}}
	class Email_Automation {public function send_decision($item,$event,$status){return ['status'=>'logged'];}}
	require dirname(__DIR__) . '/includes/class-event-public-display.php';
	require dirname(__DIR__) . '/includes/class-capacity-manager.php';
	require dirname(__DIR__) . '/includes/class-event-datetime.php';
 require dirname(__DIR__) . '/includes/class-rest-controller.php';
 $s=new Settings();$a=new Audit_Log();$c=new Capacity_Manager($s,$a);$r=new CCT_Repository();$controller=new REST_Controller($r,new Email_Automation(),$a,$s,$c);
 foreach ([0,'0','',-2] as $raw) \check($c->attendees_from(['party'=>$raw])===1,'minimum party '.var_export($raw,true));
 foreach (['1.5','oops',1001] as $raw) \check(\is_wp_error($c->attendees_from(['party'=>$raw])),'reject invalid party '.var_export($raw,true));
 $reset=function($party=2,$status='approved')use($r,$a){$GLOBALS['options']=[];$GLOBALS['meta']=['remaining'=>8,'event_capacity'=>10];unset($GLOBALS['meta_hook']);$r->fail=false;$r->read_hook=null;$r->item=['_ID'=>1,'event_id'=>10,'party'=>$party,'registration_status'=>$status,'dietaryrequirements'=>'','please_let_us_know'=>''];$a->entries=[];$a->write(1,'capacity_reservation','reserved',['event_id'=>10,'attendees'=>2]);};
 $edit=function($fields)use($controller){return $controller->edit(new \WP_REST_Request(['id'=>1]+$fields));};
 $reset();$result=$edit(['first_name'=>'Alex','number_of_attendees'=>4]);\check(!\is_wp_error($result)&&$r->item['first_name']==='Alex'&&$GLOBALS['meta']['remaining']===6,'edit details and increase reservation');
 $result=$edit(['number_of_attendees'=>0]);\check(!\is_wp_error($result)&&$r->item['party']===1&&$GLOBALS['meta']['remaining']===9,'zero saved as one and places returned');
 $result=$edit(['number_of_attendees'=>11]);\check(\is_wp_error($result)&&$r->item['party']===1&&$GLOBALS['meta']['remaining']===9,'overbooking rejected without mutation');
 $reset();$r->fail=true;$result=$edit(['number_of_attendees'=>4]);\check(\is_wp_error($result)&&$GLOBALS['meta']['remaining']===8&&$c->reservation_state(1)['attendees']===2,'failed save restores capacity ledger');
 $reset(2,'waitlist');$a->entries=[];$result=$edit(['number_of_attendees'=>4]);\check(!\is_wp_error($result)&&$GLOBALS['meta']['remaining']===8,'waitlist edit does not reserve places');
 $reset();$a->entries=[];$r->fail=true;$result=$edit(['number_of_attendees'=>4]);\check(\is_wp_error($result)&&$GLOBALS['meta']['remaining']===8&&!$c->is_reserved(1),'failed legacy edit releases new reservation');
 $reset();$r->item['checked_in_at']='2026-09-09';\check(\is_wp_error($edit(['number_of_attendees'=>3])),'checked-in party protected');\check(!\is_wp_error($edit(['phone'=>'123'])),'checked-in contact edit allowed');
 $reset();$result=$edit(['registration_status'=>'declined','event_id'=>99,'email'=>'']);\check(!\is_wp_error($result)&&$r->item['registration_status']==='approved'&&$r->item['event_id']===10,'field allowlist and missing email');
 $reset();$result=$edit(['dietaryrequirements'=>'yes','please_let_us_know'=>'Gluten <b>free</b>']);\check(!\is_wp_error($result)&&$r->item['dietaryrequirements']==='yes'&&$r->item['please_let_us_know']==='Gluten free','dietary requirements saved and sanitised');
 $result=$edit(['dietaryrequirements'=>'no','please_let_us_know'=>'Must be cleared']);\check(!\is_wp_error($result)&&$r->item['dietaryrequirements']==='no'&&$r->item['please_let_us_know']==='','dietary details cleared when choice is no');
 $reset();$result=$edit(['dietaryrequirements'=>'sometimes']);\check(\is_wp_error($result)&&$result->get_error_code()==='hherm_invalid_dietary_requirements'&&$r->item['dietaryrequirements']==='','invalid dietary choice rejected without mutation');
 $reset(0,'pending');$a->entries=[];$result=$controller->review(new \WP_REST_Request(['id'=>1,'status'=>'declined','decline_reason'=>'']));\check(!\is_wp_error($result)&&false!==strpos($r->item['decline_reason'],'unable to approve'),'blank decline gets generic reason');
 $reset(0,'pending');$a->entries=[];$result=$controller->review(new \WP_REST_Request(['id'=>1,'status'=>'approved','approval_notes'=>'']));\check(!\is_wp_error($result)&&$c->reservation_state(1)['attendees']===1,'approve zero-party registration reserves one');
 $reset(0,'pending');$a->entries=[];$result=$controller->bulk(new \WP_REST_Request(['ids'=>[1],'status'=>'declined','notes'=>' ']));\check($result['succeeded']===[1]&&false!==strpos($r->item['decline_reason'],'unable to approve'),'bulk blank decline fallback');
 foreach ([[[9]],[true],[-1],[1.5],['1oops'],['9999999999999999999999999']] as $ids) {
  $reset(2,'pending'); $result=$controller->bulk(new \WP_REST_Request(['ids'=>$ids,'status'=>'approved','notes'=>'']));
  \check(\is_wp_error($result)&&$result->get_error_code()==='hherm_invalid_ids'&&$r->item['registration_status']==='pending','malformed bulk ID cannot be coerced into another registration: '.json_encode($ids));
 }
 $reset(2,'pending');$GLOBALS['meta']['event_cancelled']='true';$result=$controller->review(new \WP_REST_Request(['id'=>1,'status'=>'approved','approval_notes'=>'']));
 \check(\is_wp_error($result)&&$result->get_error_code()==='hherm_event_cancelled'&&$r->item['registration_status']==='pending'&&$GLOBALS['meta']['remaining']===8,'cancelled event approval is rejected without changing registration or capacity');
 $result=$controller->review(new \WP_REST_Request(['id'=>1,'status'=>'declined','decline_reason'=>'Event cancelled']));
 \check(!\is_wp_error($result)&&$r->item['registration_status']==='declined'&&$GLOBALS['meta']['remaining']===10,'cancelled event registration can still be declined to release places');
 $replace_lock=function(){$GLOBALS['options']['hherm_review_lock_1']=['token'=>'replacement-owner','expires_at'=>time()+300];};
 foreach(['edit','review'] as $operation){
  $reset(2,'pending');$r->read_hook=$replace_lock;
  $result=$operation==='edit'?$edit(['number_of_attendees'=>4]):$controller->review(new \WP_REST_Request(['id'=>1,'status'=>'declined','decline_reason'=>'test']));
  \check(\is_wp_error($result)&&$result->get_error_code()==='hherm_registration_lock_lost'&&$r->item['party']===2&&$r->item['registration_status']==='pending'&&$GLOBALS['meta']['remaining']===8&&$GLOBALS['options']['hherm_review_lock_1']['token']==='replacement-owner','REST '.$operation.' stops before capacity mutation when its registration lock was replaced');
  $reset(2,'pending');$GLOBALS['meta_hook']=$replace_lock;
  $result=$operation==='edit'?$edit(['number_of_attendees'=>4]):$controller->review(new \WP_REST_Request(['id'=>1,'status'=>'declined','decline_reason'=>'test']));
  \check(\is_wp_error($result)&&$result->get_error_code()==='hherm_registration_lock_lost'&&$r->item['party']===2&&$r->item['registration_status']==='pending'&&$a->latest_status(1,'capacity_error')==='registration_lock_lost'&&$GLOBALS['options']['hherm_review_lock_1']['token']==='replacement-owner','REST '.$operation.' stops before storage and flags capacity for review when lock changes during adjustment');
  $reset(2,'pending');$r->write_hook=$replace_lock;$r->fail=true;
  $result=$operation==='edit'?$edit(['number_of_attendees'=>4]):$controller->review(new \WP_REST_Request(['id'=>1,'status'=>'declined','decline_reason'=>'test']));
  \check(\is_wp_error($result)&&$result->get_error_code()==='hherm_registration_lock_lost'&&$r->item['party']===2&&$r->item['registration_status']==='pending'&&$GLOBALS['meta']['remaining']===($operation==='edit'?6:10)&&$a->latest_status(1,'capacity_error')==='registration_lock_lost'&&$GLOBALS['options']['hherm_review_lock_1']['token']==='replacement-owner','REST '.$operation.' does not compensate over a replacement owner after storage loses its lease');
 }
}

