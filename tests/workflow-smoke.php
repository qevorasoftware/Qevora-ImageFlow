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
