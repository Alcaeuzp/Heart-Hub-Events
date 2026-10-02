<?php
namespace {
function test_contains( string $haystack, string $needle ): bool { return '' === $needle || false !== strpos( $haystack, $needle ); }
 define('ABSPATH',__DIR__);
 function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_attr($v){return esc_html($v);} function esc_url($v){return esc_attr($v);} function wp_kses_post($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return (string)$v;} function sanitize_text_field($v){return trim((string)$v);} function wp_unslash($v){return $v;} function is_wp_error($v){return false;}
 function current_user_can($v){return true;} function wp_timezone(){return new DateTimeZone('Australia/Sydney');} function wp_date($f,$t,$tz=null){return (new DateTimeImmutable('@'.$t))->setTimezone($tz??wp_timezone())->format($f);}
 function get_post($id){return (object)['ID'=>$id,'post_type'=>'events','post_status'=>'publish','post_title'=>'Event '.$id,'post_content'=>'Event description'];}
 function get_posts($args){return [get_post(10),get_post(20)];} function get_the_title($id){return 'Event '.$id;} function get_post_meta($id,$key,$single){return ['event_name'=>'Event '.$id,'start_date'=>$id===10?'2026-08-26T17:00':'2025-01-01T17:00','end_date__time'=>'2026-08-26T20:00','event_capacity'=>20][$key]??'';}
 function get_term_by(...$args){return false;} function get_the_terms(...$args){return false;} function admin_url($v){return 'https://example.test/wp-admin/'.$v;} function get_permalink($id){return 'https://example.test/event/'.$id;} function add_query_arg($key,$value,$url=null){if(is_array($key))return $value.'&'.http_build_query($key);return $url.'&'.http_build_query([$key=>$value]);} function selected($a,$b){if((string)$a===(string)$b)echo 'selected';}
 function check($v,$label){if(!$v)throw new Exception($label);echo "PASS $label\n";}
}
namespace HeartHub\EventRegistrations {
 class Settings {public function get($key,$default=''){return $default;}}
 class Plugin {const CAPABILITY='manage_events';} class Event_Manager {const LIST_PAGE_SLUG='events';const PAGE_SLUG='edit-event';} class Attendance_Manager {const PAGE_SLUG='attendance';} class Checkin_Manager {const PAGE_SLUG='analytics';} class Admin_Page {const MENU_SLUG='registrations';}
 class CCT_Repository {
  public $queried=[];
  public function event_registrations($id){$this->queried[]=$id;return [
   ['_ID'=>1,'first_name'=>'Alex <script>','registration_status'=>'approved','number_of_attendees'=>4,'attendance_status'=>'partial','checked_in_party_size'=>3,'checked_in_at'=>'2026-08-26 17:05','feedback_score'=>5,'event_feedback'=>'More workshops please'],
   ['_ID'=>2,'first_name'=>'Sam','registration_status'=>'approved','number_of_attendees'=>1,'attendance_status'=>'no-show'],
   ['_ID'=>3,'first_name'=>'Lee','registration_status'=>'approved','number_of_attendees'=>1],
   ['_ID'=>4,'first_name'=>'Jo','registration_status'=>'declined','number_of_attendees'=>1,'feedback_score'=>4],
  ];}
 }
	class Calendar_Schedule { public const TYPE_META = 'hherm_calendar_entry_type'; }
	require dirname(__DIR__).'/includes/class-event-datetime.php';
	require dirname(__DIR__).'/includes/class-event-public-display.php';
	require dirname(__DIR__).'/includes/class-event-types.php';
 require dirname(__DIR__).'/includes/class-event-overview.php';
 require dirname(__DIR__).'/includes/class-analytics-page.php';
 $r=new CCT_Repository();$s=new Settings();$overview=new Event_Overview($s,$r);
 ob_start();$overview->render(10);$html=ob_get_clean();
 \check($r->queried===[10]&&test_contains($html,'4 registrations'),'overview queries only its event and includes all registrations');
 \check(test_contains($html,'3 confirmed people attending')&&test_contains($html,'No-show: 1')&&test_contains($html,'Unrecorded: 1'),'overview separates people, no-shows and unknown attendance');
 \check(test_contains($html,'More workshops please')&&test_contains($html,'Rating only; no comments provided.'),'overview shows comments and rating-only feedback');
 \check(test_contains($html,'Alex &lt;script&gt;')&&!test_contains($html,'Alex <script>'),'applicant content escaped');
 $r->queried=[];$analytics=new Analytics_Page($s,$r);$_GET=['period'=>'all','event_id'=>10];ob_start();$analytics->render();$html=ob_get_clean();
 \check($r->queried===[10]&&test_contains($html,'name="event_id"'),'analytics event filter maintained');
 $r->queried=[];$_GET=['period'=>'all'];ob_start();$analytics->render();ob_end_clean();\check($r->queried===[10,20],'all-time aggregate includes both events');
 $r->queried=[];$_GET=['period'=>'6','date_from'=>'2025-01-01','date_to'=>'2025-01-01'];ob_start();$analytics->render();ob_end_clean();\check($r->queried===[20],'inclusive custom dates override preset period');
 $r->queried=[];$_GET=['period'=>'all','event_id'=>10,'date_to'=>'2025-01-01'];ob_start();$analytics->render();ob_end_clean();\check(!$r->queried,'event and date filters combine');
	$parse=new \ReflectionMethod($analytics,'event_timestamp');if ( PHP_VERSION_ID < 80100 ) $parse->setAccessible(true);$ts=$parse->invoke($analytics,'2026-08-26T17:00');\check(\wp_date('Y-m-d H:i',$ts)==='2026-08-26 17:00'&&$parse->invoke($analytics,(string)$ts)===$ts,'analytics handles local and Unix dates');

 if ( getenv('HHERM_RENDER_PREVIEW') ) {
  $dir=__DIR__.'/preview';if(!is_dir($dir))mkdir($dir);
  $head='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/admin-base.css"><link rel="stylesheet" href="/assets/registrations-page.css"><link rel="stylesheet" href="/assets/events.css"><style>body{margin:0;background:#f4f8fb;padding:32px;font:14px Arial}*{box-sizing:border-box}.button{display:inline-block;padding:8px 12px;border:1px solid #ccd6df;background:white;border-radius:5px;color:#35657f;text-decoration:none;cursor:pointer}input,select,textarea{padding:8px;border:1px solid #aab6c0;border-radius:4px}table{table-layout:fixed}</style>';
  ob_start();$overview->render(10);$body=ob_get_clean();
  file_put_contents($dir.'/event.html',$head.'</head><body>'.$body.'<script>window.HHERM={root:"/tests/preview/api",nonce:"fixture"};</script><script src="/assets/dashboard.js"></script></body></html>');
  $_GET=['period'=>'all'];ob_start();$analytics->render();$body=ob_get_clean();
  file_put_contents($dir.'/analytics.html',$head.'<link rel="stylesheet" href="/assets/analytics.css"></head><body>'.$body.'</body></html>');
  if(!is_dir($dir.'/api'))mkdir($dir.'/api');
  file_put_contents($dir.'/api/1',json_encode(['application'=>['_ID'=>1,'first_name'=>'Alex','last_name'=>'Example','email'=>'alex@example.test','registration_status'=>'approved','number_of_attendees'=>4],'event'=>['title'=>'Sample event','capacity'=>20,'remaining'=>16]]));
  file_put_contents($dir.'/rows.html',$head.'</head><body><div class="hherm-list-card"><table><tr tabindex="0" data-event-url="/tests/preview/event.html"><td>Sample event</td><td><a class="button" href="/tests/preview/analytics.html">Separate action</a></td></tr></table></div><script src="/assets/events.js"></script></body></html>');
 }
}
