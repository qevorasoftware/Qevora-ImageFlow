<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;
function variant($rel) {
    $s=settings();
    if (!$s['delivery'] || !is_string($rel) || strlen($rel)>1024 || strpos($rel,'..')!==false) { return null; }
    $id=(int)get_option('qif_url_'.md5($rel));
    if (!$id) { return null; }
    $map=get_post_meta($id,'_qif_map',true);
    $s['rules']=workflow_options()['rules'];
    if ($s['rules']) { $s=size_config($s,registered_sources($id)[$rel] ?? ''); }
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
function has_generated_files() {
    // Cache the existence check so previously converted libraries also work immediately after upgrading.
    $available=get_transient('qif_has_generated');
    if (false===$available) {
        $ids=get_posts(array('post_type'=>'attachment','post_status'=>'inherit','fields'=>'ids',
            'posts_per_page'=>1,'no_found_rows'=>true,'orderby'=>'none','cache_results'=>false,
            'meta_query'=>array(array('key'=>'_qif_after','value'=>0,'compare'=>'>','type'=>'NUMERIC')))); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Cached single-result check for an existing derivative.
        $available=$ids ? 'yes' : 'no';
        set_transient('qif_has_generated',$available,HOUR_IN_SECONDS);
    }
    return 'yes'===$available;
}
add_action('wp_enqueue_scripts',function () {
    $s=settings();
    if (!$s['delivery'] || (did_action('elementor/loaded') && !$s['elementor']) || !has_generated_files()) { return; }
    wp_enqueue_script('qif-delivery',QIF_URL.'assets/delivery.js',array(),'1.2.0',array('strategy'=>'defer','in_footer'=>true));
    wp_localize_script('qif-delivery','qifDelivery',array('base'=>trailingslashit(wp_get_upload_dir()['baseurl']),'api'=>rest_url('qevora-imageflow/v1/resolve'),'css'=>did_action('elementor/loaded') && $s['background']));
});

function initial_image($html,$id) {
    if (is_admin() || is_feed() || !workflow_options()['first_load'] || !settings()['delivery'] || (did_action('elementor/loaded') && !settings()['elementor']) || stripos($html,'<picture')!==false) { return $html; }
    $tags=new \WP_HTML_Tag_Processor($html);
    if (!$tags->next_tag('img')) { return $html; }
    $src=$tags->get_attribute('src');
    $set=$tags->get_attribute('srcset');
    if (!$src || $tags->get_attribute('data-src') || $tags->get_attribute('data-srcset')) { return $html; }
    $base=trailingslashit(wp_get_upload_dir()['baseurl']);
    $type=''; $mapped=array();
    foreach ($set?explode(',',$set):array($src) as $candidate) {
        $parts=preg_split('/\s+/',trim($candidate));
        $url=array_shift($parts);
        if (strpos($url,$base)!==0 || count($parts)>1 || ($parts && !preg_match('/^\d+w$/D',$parts[0]))) { return $html; }
        $alt=variant(rawurldecode(substr($url,strlen($base))));
        if (!$alt || ($type && $type!==$alt['mime'])) { return $html; }
        $type=$alt['mime']; $mapped[]=$alt['url'].($parts?' '.$parts[0]:'');
    }
    $sizes=$tags->get_attribute('sizes');
    return '<picture data-qif-first-load="1"><source type="'.esc_attr($type).'" srcset="'.esc_attr(implode(', ',$mapped)).'"'.($sizes?' sizes="'.esc_attr($sizes).'"':'').'>'.$html.'</picture>';
}
add_filter('wp_get_attachment_image',__NAMESPACE__.'\initial_image',99,2);
