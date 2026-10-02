<?php
namespace {
 define('ABSPATH',__DIR__);
 require __DIR__.'/support/option-lock.php';
 class WP_Error {public function __construct(...$args){} }
 function absint($v){return abs((int)$v);} function is_wp_error($v){return $v instanceof WP_Error;}
 function sanitize_key($v){return strtolower((string)$v);}
 function wp_unslash($v){return is_array($v)?array_map('wp_unslash',$v):(is_string($v)?stripslashes($v):$v);} function sanitize_text_field($v){return trim(strip_tags((string)$v));} function sanitize_textarea_field($v){return sanitize_text_field($v);} function sanitize_email($v){return strtolower(trim($v));} function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
 function wp_verify_nonce($v,$a){return $v==='valid';} function get_transient($k){return $GLOBALS['transients'][$k]??0;} function set_transient($k,$v,$ttl){$GLOBALS['transients'][$k]=$v;} function delete_transient($k){unset($GLOBALS['transients'][$k]);}
 function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_attr($v){return esc_html($v);} function esc_url($v){return esc_attr($v);} function disabled($v){if($v)echo 'disabled';} function wp_nonce_field(...$a){echo '<input name="hherm_public_nonce" value="valid">';}
 function home_url($v){return 'https://example.test'.$v;} function add_query_arg($k,$v,$url){return $url.'?'.urlencode($k).'='.urlencode($v);}
 function check($v,$label){if(!$v)throw new Exception($label);echo "PASS $label\n";}
 function fixture_contains($haystack,$needle){return false!==strpos($haystack,$needle);}
}
namespace HeartHub\EventRegistrations {
 class Settings {public $enabled='false';public function get($key,$default=''){return $key==='checkin_feedback_enabled'?$this->enabled:$default;}}
 class CCT_Repository {public $item=['_ID'=>1,'event_id'=>10,'email'=>'guest@example.test','number_of_attendees'=>4,'registration_status'=>'approved'];public $saved=[];public function get($id){return $this->item;}public function find_approved_by_email($event,$email){return $event===10&&$email===$this->item['email']?$this->item:new \WP_Error();} public function update_self_checkin(...$args){$this->saved=array_slice($args,0,5);$this->item['checked_in_at']='now';$this->item['checked_in_party_size']=$args[2];return $this->item;}}
 class Audit_Log {public function write(...$args){return true;}}
 class Capacity_Manager {public $released=0;public $released_ids=[];public function with_event_lock($event,$callback){return $callback();}public function attendee_key(){return 'number_of_attendees';}public function release_unused($id,$event,$n){if(!isset($this->released_ids[$id])){$this->released+=$n;$this->released_ids[$id]=true;}return true;}}
 class Public_Page_Theme {public function __construct(...$a){}}
 require dirname(__DIR__).'/includes/class-checkin-manager.php';
 $settings=new Settings();$repo=new CCT_Repository();$capacity=new Capacity_Manager();$manager=new Checkin_Manager($settings,$repo,new Audit_Log(),$capacity,new Public_Page_Theme());
	$submit=new \ReflectionMethod($manager,'process_public_submission');if ( PHP_VERSION_ID < 80100 ) $submit->setAccessible(true);
	$form=new \ReflectionMethod($manager,'render_checkin_form');if ( PHP_VERSION_ID < 80100 ) $form->setAccessible(true);
 $_POST=['hherm_public_nonce'=>'valid','identity'=>'guest@example.test','checkin_step'=>'lookup'];
 \check($submit->invoke($manager,10,'token')===''&&!$repo->saved,'email lookup does not check in');
 ob_start();$form->invoke($manager,'token',false);$html=ob_get_clean();
 \check(fixture_contains($html,'All 4 of us')&&fixture_contains($html,'3 of 4 attending'),'lookup displays actual party and partial choices');
 \check(!fixture_contains($html,'feedback_score')&&!fixture_contains($html,'Preview only'),'live form omits disabled feedback and preview text');
 $_POST['checkin_step']='confirm';$_POST['party_size']=5;
 \check(fixture_contains($submit->invoke($manager,10,'token'),'choose')&&!$repo->saved,'oversized party rejected');
 foreach ([0,-1,'1.5','invalid',['3']] as $invalid_party) {
  $_POST['party_size']=$invalid_party;
  \check(fixture_contains($submit->invoke($manager,10,'token'),'choose')&&!$repo->saved,'malformed party rejected: '.json_encode($invalid_party));
  $GLOBALS['transients']=[];
 }
 $_POST['party_size']=3;$_POST['feedback']='injected';$_POST['feedback_score']=5;
 \check(fixture_contains($submit->invoke($manager,10,'token'),'recorded'),'partial check-in succeeds');
 \check($repo->saved===[1,'',3,'partial',0]&&$capacity->released===1,'partial attendance saves three and returns one; disabled feedback ignored');
 $submit->invoke($manager,10,'token');\check($capacity->released===1,'repeated check-in does not release twice');
 unset($repo->item['checked_in_at']);$repo->saved=[];$settings->enabled='true';$_POST['party_size']=4;
 $submit->invoke($manager,10,'token');\check($repo->saved===[1,'injected',4,'attended',5],'enabled feedback saved with attendance');
 unset($repo->item['checked_in_at']);$repo->saved=[];$_POST['feedback']=addslashes("Guest's feedback");
 $submit->invoke($manager,10,'token');\check($repo->saved===[1,"Guest's feedback",4,'attended',5],'slashed feedback retains apostrophes after sanitization');
 unset($repo->item['checked_in_at']);$repo->saved=[];$GLOBALS['transients']=[];
 $_POST['feedback']='a'.str_repeat("\xF0\x9F\x8C\xB1",1250);
 $submit->invoke($manager,10,'token');\check($repo->saved[1]===$_POST['feedback']&&preg_match('//u',$repo->saved[1])===1,'valid multibyte comments exceeding 5000 bytes are saved intact');
 unset($repo->item['checked_in_at']);$repo->saved=[];$GLOBALS['transients']=[];
 $_POST['feedback']=str_repeat("\xE2\x80\x94",5000);
 $submit->invoke($manager,10,'token');\check($repo->saved[1]===$_POST['feedback'],'exactly 5000 Unicode characters are saved without byte truncation');
 unset($repo->item['checked_in_at']);$repo->saved=[];$GLOBALS['transients']=[];
 $_POST['feedback']=str_repeat('x',5001);
 \check(fixture_contains($submit->invoke($manager,10,'token'),'5,000')&&!$repo->saved&&!isset($repo->item['checked_in_at']),'oversized comments are rejected before attendance or feedback changes');
 $GLOBALS['transients']=[];$_POST['feedback']=str_repeat("\xF0\x9F\x8C\xB1",5001);
 \check(fixture_contains($submit->invoke($manager,10,'token'),'5,000')&&!$repo->saved,'oversized multibyte comments are rejected without corrupting text');
 $GLOBALS['transients']=[];$settings->enabled='false';
 \check(fixture_contains($submit->invoke($manager,10,'token'),'recorded')&&$repo->saved[1]==='','disabled check-in feedback ignores injected oversized comments');
 $repo->saved=[];$_POST['identity']='other@example.test';\check(fixture_contains($submit->invoke($manager,10,'token'),'staff member')&&!$repo->saved,'unknown email rejected');
 $_POST['identity']='guest@example.test';\check(fixture_contains($submit->invoke($manager,20,'token'),'staff member')&&!$repo->saved,'registration cannot check in to another event');
 $_POST['hherm_public_nonce']='invalid';\check(fixture_contains($submit->invoke($manager,10,'token'),'refresh'),'invalid nonce rejected');
 ob_start();$form->invoke($manager,'preview',true);$html=ob_get_clean();\check(fixture_contains($html,'disabled'),'admin preview cannot submit');
}
