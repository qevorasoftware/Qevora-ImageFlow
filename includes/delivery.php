<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;
function variant($rel) {
    $s=settings();
    if (!$s['delivery'] || !is_string($rel) || strlen($rel)>1024 || strpos($rel,'..')!==false) { return null; }
    $id=(int)get_option('qif_url_'.md5($rel));
    if (!$id) { return null; }
    $map=get_post_meta($id,'_qif_map',true);
    $v=$map[$rel][$s['preferred']] ?? null;
    if (!$v || !in_array($s['preferred'],$s['targets'],true)) { return null; }
    $path='qevora-imageflow/'.$id.'/'.basename($v['file']);
    $root=root_dir();
    if (!is_file($root.'/'.$path) || !is_file($root.'/'.$rel) || filemtime($root.'/'.$rel)!==$v['mtime']) { return null; }
    return array('url'=>wp_get_upload_dir()['baseurl'].'/'.$path,'mime'=>formats()[$s['preferred']]);
}
add_action('rest_api_init',function () {
    register_rest_route('qevora-imageflow/v1','/resolve',array(
        'methods'=>'POST','permission_callback'=>'__return_true','callback'=>function ($request) {
            $paths=$request->get_param('paths');
            if (!is_array($paths) || count($paths)>40) { return new \WP_Error('invalid','Expected up to 40 media paths.',array('status'=>400)); }
            $out=array();
            foreach ($paths as $path) { if (is_string($path) && ($v=variant($path))) { $out[$path]=$v; } }
            return rest_ensure_response((object)$out);
        }
    ));
});
add_action('wp_enqueue_scripts',function () {
    $s=settings();
    if (!$s['delivery'] || (did_action('elementor/loaded') && !$s['elementor'])) { return; }
    wp_enqueue_script('qif-delivery',QIF_URL.'assets/delivery.js',array(),'1.0.0',true);
    wp_localize_script('qif-delivery','qifDelivery',array('base'=>trailingslashit(wp_get_upload_dir()['baseurl']),'api'=>rest_url('qevora-imageflow/v1/resolve'),'css'=>did_action('elementor/loaded') && $s['background']));
});
