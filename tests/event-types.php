<?php
namespace {
define('ABSPATH',__DIR__);
class WP_Error {}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_term_by($field,$value,$taxonomy){
	$GLOBALS['term_lookups'][]=array($field,$value,$taxonomy);
	if('slug'===$field && isset($GLOBALS['terms_by_slug'][$value]))return (object)array('term_id'=>$GLOBALS['terms_by_slug'][$value]);
	return false;
}
function check($v,$m){if(!$v)throw new Exception($m);echo "PASS $m\n";}
}
namespace HeartHub\EventRegistrations {
require dirname(__DIR__).'/includes/class-event-types.php';
$GLOBALS['terms_by_slug']=array('workshop'=>11,'fundraising-event'=>22);
$GLOBALS['term_lookups']=array();
\check(Event_Types::terms()===array('workshop'=>11,'fundraising'=>22),'event types resolve the live fundraising-event category slug');
\check(in_array(array('slug','fundraising-event','event-category'),$GLOBALS['term_lookups'],true),'fundraising lookup requests the fundraising-event slug');
\check(Event_Types::is_protected_category((object)array('slug'=>'fundraising-event','name'=>'Fundraising Event')),'fundraising category is protected');
\check(Event_Types::is_protected_category((object)array('slug'=>'community-outreach','name'=>'Community Outreach')),'community outreach category is protected');
\check(Event_Types::is_protected_category((object)array('slug'=>'mindfulness-workshops','name'=>'Mindfulness Workshops')),'mindfulness workshops category is protected');
\check(!Event_Types::is_protected_category((object)array('slug'=>'social-event','name'=>'Social Event')),'ordinary categories remain editable');
$types=['workshop'=>11,'fundraising'=>22];
\check(Event_Types::selection([],$types,true)==='workshop','new events default Workshop');
\check(Event_Types::selection([22,83],$types,false)==='fundraising','existing fundraising selected');
\check(Event_Types::selection([83],$types,false)==='keep','untyped existing event preserved');
\check(Event_Types::selection([11,22,83],$types,false)==='keep','mixed types preserved on initial load');
\check(Event_Types::categories('fundraising',[],[],$types)===[22],'new fundraiser automatically receives fundraising category');
\check(Event_Types::categories('workshop',[83],[22,83],$types)===[83,11],'type change preserves Past Events');
\check(Event_Types::categories('keep',[83],[11,22,83],$types)===[83,11,22],'keep preserves both existing type terms');
\check(Event_Types::categories('fundraising',[83,84],[11,83],$types,[83,84])===[22,83],'hidden lifecycle categories cannot be newly assigned and existing ones survive type changes');
\check(\is_wp_error(Event_Types::categories('workshop',[],[],['workshop'=>0,'fundraising'=>22])),'missing category rejected');
\check(\is_wp_error(Event_Types::categories('past-events',[],[],$types)),'lifecycle category cannot be selected as type');
}
