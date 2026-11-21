<?php
// Run in the disposable WordPress installation created by the Plugin Check CI job.
defined('WP_CLI') && WP_CLI || exit;
wp_set_current_user(1);
$upload=wp_upload_dir();
$fixture=trailingslashit($upload['path']).'imageflow-sample.png';
$image=imagecreatetruecolor(480,320);
imagefill($image,0,0,imagecolorallocate($image,62,107,192));
imagepng($image,$fixture);
$id=wp_insert_attachment(array('post_title'=>'ImageFlow sample','post_mime_type'=>'image/png','post_status'=>'inherit'),$fixture);
require_once ABSPATH.'wp-admin/includes/image.php';
wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$fixture));
update_option('qif_workflow',array('first_load'=>true,'rules'=>array('full'=>array('format'=>'webp','quality'=>45))));
$config=Qevora\ImageFlow\settings();
$config['smaller']=0;
if ('converted'!==Qevora\ImageFlow\convert($id,false,$config)) { throw new RuntimeException('Sample conversion failed'); }
$path=get_post_meta($id,'_wp_attached_file',true);
$info=Qevora\ImageFlow\inspect_size($id,$path,'full');
if ($info['bytes']<=0 || $info['width']!==480 || !$info['sizes']) { throw new RuntimeException('Missing inspector size data'); }
$html=wp_get_attachment_image($id,'full');
if (strpos($html,'data-qif-first-load')===false || strpos($html,'image/webp')===false || strpos($html,'.png')===false) { throw new RuntimeException('Expected a typed first-load source and original fallback'); }
$sample=wp_insert_post(array('post_title'=>'Trial context','post_type'=>'post','post_status'=>'publish'));
$template=wp_insert_post(array('post_title'=>'Trial template','post_type'=>'elementor_library','post_status'=>'publish'));
update_post_meta($template,'_elementor_edit_mode','builder');
update_post_meta($template,'_elementor_template_type','section');
update_post_meta($template,'_elementor_version',ELEMENTOR_VERSION);
update_post_meta($template,'_elementor_data',wp_slash(wp_json_encode(array(array('id'=>'a123456','elType'=>'container','settings'=>array(),'elements'=>array(array('id'=>'b123456','elType'=>'widget','widgetType'=>'heading','settings'=>array('title'=>'ImageFlow trial heading'),'elements'=>array())))))));
query_posts(array('p'=>$sample)); // Disposable frontend query for the trial view.
set_query_var('qif_trial',$template);
set_query_var('qif_key',wp_create_nonce('qif_trial_'.$template.'_'.$sample));
$view=apply_filters('template_include','');
if (basename($view)!=='trial-view.php') { throw new RuntimeException('Trial route was not selected'); }
ob_start();
include $view;
$preview=ob_get_clean();
if (strpos($preview,'ImageFlow trial heading')===false) { throw new RuntimeException('Elementor trial markup missing'); }
WP_CLI::success('Verified conversion, size advice data, initial HTML and Elementor template rendering.');

// Exercise both attachment MIME families, boundary values, unknown measurements and the grouped chart totals.
$qif_limits=Qevora\ImageFlow\audit_defaults();
foreach (array(10,50,null) as $qif_mib) {
    $qif_video=wp_insert_attachment(array('post_title'=>'Video sample','post_mime_type'=>'video/mp4','post_status'=>'inherit'));
    if (null!==$qif_mib) { wp_update_attachment_metadata($qif_video,array('filesize'=>$qif_mib*1048576)); }
}
$qif_scan=Qevora\ImageFlow\audit_begin($qif_limits);
while (!Qevora\ImageFlow\audit_step($qif_scan)) { /* Advance through the disposable library. */ }
$qif_video_groups=$qif_scan['groups']['video'];
if (1!==$qif_video_groups['medium']['count'] || 1!==$qif_video_groups['high']['count'] || 1!==$qif_video_groups['unknown']['count'] || 50*1048576!==$qif_video_groups['high']['bytes'] || !$qif_scan['groups']['image']['low']['count']) {
    throw new RuntimeException('Media size report totals or thresholds differ from the fixture.');
}
WP_CLI::success('Verified image and video size audit, threshold boundaries and unknown sizes.');

$qif_xml='<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:source="http://iptc.org/std/Iptc4xmpExt/2008-02-29/" source:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia"/></rdf:RDF>';
$qif_payload="XML:com.adobe.xmp\0\0\0\0\0".$qif_xml;
$qif_chunk=pack('N',strlen($qif_payload)).'iTXt'.$qif_payload.pack('N',crc32('iTXt'.$qif_payload));
$qif_png=file_get_contents($fixture);
$qif_marked=dirname($fixture).'/imageflow-origin.png';
file_put_contents($qif_marked,substr($qif_png,0,-12).$qif_chunk.substr($qif_png,-12));
$qif_photo=wp_insert_attachment(array('post_mime_type'=>'image/png','post_status'=>'inherit'),$qif_marked);
wp_update_attachment_metadata($qif_photo,wp_generate_attachment_metadata($qif_photo,$qif_marked));
if ('generated'!==Qevora\ImageFlow\origin_state($qif_photo)['key']) { throw new RuntimeException('Upload hook missed generated PNG'); }
$qif_uuid=hex2bin('be7acfcb97a942e89c71999491e3afac');
$qif_xml=str_replace('trainedAlgorithmicMedia','compositeWithTrainedAlgorithmicMedia',$qif_xml);
$qif_movie=dirname($fixture).'/imageflow-origin.mp4';
file_put_contents($qif_movie,pack('N',16).'ftypisom'.pack('N',0).pack('N',24+strlen($qif_xml)).'uuid'.$qif_uuid.$qif_xml);
$qif_clip=wp_insert_attachment(array('post_mime_type'=>'video/mp4','post_status'=>'inherit'),$qif_movie);
if ('edited'!==Qevora\ImageFlow\inspect_origin($qif_clip)) { throw new RuntimeException('Video XMP edit declaration missed'); }
file_put_contents($qif_movie,pack('N',16).'ftypisom'.pack('N',0).pack('N',8+strlen($qif_xml)).'mdat'.$qif_xml);
if ('unknown'!==Qevora\ImageFlow\inspect_origin($qif_clip)) { throw new RuntimeException('Video payload incorrectly treated as metadata'); }
$qif_fields=array('qif_origin_nonce'=>wp_create_nonce('qif_origin_'.$qif_photo),'qif_origin'=>'human');
apply_filters('attachment_fields_to_save',array('ID'=>$qif_photo),$qif_fields);
Qevora\ImageFlow\inspect_origin($qif_photo);
foreach (array('human'=>true,'generated'=>false) as $qif_key=>$qif_expected) {
    $qif_ids=get_posts(array('post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids','post__in'=>array($qif_photo),'meta_query'=>array(Qevora\ImageFlow\origin_query($qif_key))));
    if ((bool)$qif_ids!==$qif_expected) { throw new RuntimeException('Origin filter did not honor manual override'); }
}
$qif_fields['qif_origin']='';
apply_filters('attachment_fields_to_save',array('ID'=>$qif_photo),$qif_fields);
if ('generated'!==Qevora\ImageFlow\origin_state($qif_photo)['key']) { throw new RuntimeException('Clearing override did not restore automatic origin'); }
WP_CLI::success('Verified AI origin on upload, video metadata isolation, manual labels and filtered lists.');

// The notice hooks still execute, but third-party markup must not reach ImageFlow pages.
Qevora\ImageFlow\configure_page_notices();
add_action('admin_notices',static function () { echo '<div>Foreign scheduler message</div>'; });
ob_start(); do_action('admin_notices'); $qif_notices=ob_get_clean();
if (false!==strpos($qif_notices,'Foreign scheduler message')) { throw new RuntimeException('Unexpected external notice'); }
add_settings_error('qif_settings','qif_test','ImageFlow validation example','error');
ob_start(); Qevora\ImageFlow\settings_notices(); $qif_notices=ob_get_clean();
if (false===strpos($qif_notices,'ImageFlow validation example')) { throw new RuntimeException('Missing ImageFlow error'); }
