<?php
namespace {
function test_contains( string $haystack, string $needle ): bool { return '' === $needle || false !== strpos( $haystack, $needle ); }
 define('ABSPATH', __DIR__);
 function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));} function sanitize_email($v){return filter_var($v,FILTER_SANITIZE_EMAIL);} function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);} function sanitize_text_field($v){return trim(strip_tags((string)$v));} function sanitize_hex_color($v){return $v;} function esc_url_raw($v){return (string)$v;}
 function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_attr($v){return esc_html($v);} function esc_url($v){return $v;} function wp_kses_post($v){return $v;} function wp_strip_all_tags($v){return strip_tags((string)$v);} function home_url($path=''){return 'https://example.test'.$path;} function admin_url($path=''){return 'https://example.test/wp-admin/'.$path;} function add_query_arg($args,$url){return $url.(test_contains($url,'?')?'&':'?').http_build_query($args);}
 function get_option($key,$default=false){return $GLOBALS['options'][$key]??($key==='admin_email'?'admin@example.test':$default);} function current_time($format){return '2026-09-22 10:15:00';} function wp_mail($to,$subject,$body,$headers){$GLOBALS['mail'][]=compact('to','subject','body','headers');return $to!==($GLOBALS['fail_to']??'');}
 function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;return true;} function delete_option($key){unset($GLOBALS['options'][$key]);return true;} function wp_next_scheduled(...$args){return false;} function wp_schedule_single_event(...$args){return true;} function wp_clear_scheduled_hook(...$args){return 0;} class WP_Error {} function is_wp_error($value){return $value instanceof WP_Error;} define('HOUR_IN_SECONDS',3600);define('MINUTE_IN_SECONDS',60); require __DIR__.'/support/option-lock.php';
 function check($v,$label){if(!$v)throw new Exception($label);echo "PASS $label\n";}
}
namespace HeartHub\EventRegistrations {
 class Settings {public $mode='send';public function get($key,$default=''){return ['email_mode'=>$this->mode,'contact_email'=>'contact@example.test','notification_email'=>'team@example.test','events_url'=>'https://example.test/events/'][$key]??$default;}public function email($key,$default=''){return $default;}}
 class Audit_Log {public $entries=[];public function write(...$args){$this->entries[]=$args;}}
 require dirname(__DIR__).'/includes/class-email-service.php';
 $settings=new Settings();$audit=new Audit_Log();$service=new Email_Service($settings,$audit);
 $item=['_ID'=>7,'first_name'=>'Alex','last_name'=>'Example','email'=>'alex@example.test','phone'=>'0400000000'];$event=['id'=>42,'title'=>'Community workshop'];
 $result=$service->send_interest_received($item,$event);
 \check($result===['customer'=>'sent','team'=>'sent']&&count($GLOBALS['mail'])===2,'interest dispatch sends customer and team messages');
 \check($GLOBALS['mail'][0]['to']==='alex@example.test'&&strpos($GLOBALS['mail'][0]['body'],'closer to the event date')!==false,'customer acknowledgement identifies future contact');
 \check($GLOBALS['mail'][1]['to']==='team@example.test'&&strpos($GLOBALS['mail'][1]['body'],'Community workshop')!==false&&strpos($GLOBALS['mail'][1]['body'],'0400000000')!==false&&strpos($GLOBALS['mail'][1]['body'],'registration_id=7')!==false,'team notification includes event, contact details, and customer record link');
 $GLOBALS['fail_to']='team@example.test';$result=$service->send_interest_received($item,$event);\check($result['team']==='failed'&&end($audit->entries)[2]==='failed','team delivery failure is audited independently');
 $settings->mode='log';$count=count($GLOBALS['mail']);$result=$service->send_interest_received($item,$event);\check(count($GLOBALS['mail'])===$count&&$result===['customer'=>'logged','team'=>'logged'],'log-only mode records both notifications without sending mail');
 unset($GLOBALS['fail_to']);$before=count($GLOBALS['mail']);$result=$service->send_internal_test();\check($result==='sent'&&count($GLOBALS['mail'])===$before+1&&end($GLOBALS['mail'])['to']==='team@example.test','test notification bypasses log-only mode and sends to configured staff address');
 \check(end($audit->entries)[0]===0,'test notification audit is not attached to a sample customer record');
 $settings->mode='send';$before=count($GLOBALS['mail']);$applicant=['id'=>9,'first_name'=>'Jamie','last_name'=>'Example','email'=>'jamie@example.test','phone'=>'0411000000','attended_before'=>0,'reason'=>'I would like peer support.','status'=>'interest','created_at'=>'2026-09-22 11:00:00'];$programme=['id'=>'future-support-group','name'=>'Future support group','sessions'=>[]];$result=$service->send_support_group_submission($applicant,$programme,'https://example.test/wp-admin/admin.php?page=hherm-support-group-editor&applicant_id=9');
 \check($result===['customer'=>'sent','team'=>'sent']&&count($GLOBALS['mail'])===$before+2,'support-group submission sends customer and staff messages');
 $staff=end($GLOBALS['mail']);\check($staff['to']==='team@example.test'&&strpos($staff['body'],'Future support group')!==false&&strpos($staff['body'],'I would like peer support.')!==false&&strpos($staff['body'],'applicant_id=9')!==false,'support-group staff notification contains the full interest context and record link');
}
