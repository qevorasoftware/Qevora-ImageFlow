<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;

add_filter('query_vars',function ($vars) { $vars[]='qif_inspect'; return $vars; });
add_action('template_redirect',function () {
    if ('1'===(string)get_query_var('qif_inspect') && current_user_can('manage_options')) { nocache_headers(); }
});
add_action('admin_bar_menu',function ($bar) {
    if (!is_admin() && current_user_can('manage_options')) {
        $bar->add_node(array('id'=>'qif-inspect','title'=>__('Inspect page images','qevora-imageflow'),'href'=>add_query_arg('qif_inspect','1')));
    }
},100);
add_action('wp_enqueue_scripts',function () {
    if (!current_user_can('manage_options') || '1'!==(string)get_query_var('qif_inspect')) { return; }
    wp_enqueue_style('qif-inspector',QIF_URL.'assets/inspector.css',array(),'1.3.0');
    wp_enqueue_script('qif-inspector',QIF_URL.'assets/inspector.js',array(),'1.3.0',array('strategy'=>'defer','in_footer'=>true));
    $s=settings();
    wp_localize_script('qif-inspector','qifInspector',array(
        'ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('qif_inspect'),
        'base'=>trailingslashit(wp_get_upload_dir()['baseurl']),'preferred'=>$s['preferred'],
        'delivery'=>(bool)($s['delivery'] && (!did_action('elementor/loaded') || $s['elementor'])),
        'budgets'=>workflow_options()['budgets'],'docs'=>page_url('docs'),'user'=>get_current_user_id()
    ));
});

// Only WordPress-registered files may be inspected or repaired. Never accept a client-supplied filesystem path.
function registered_sources($id) {
    $main=get_post_meta($id,'_wp_attached_file',true);
    if (!$main || !wp_attachment_is_image($id)) { return array(); }
    $meta=wp_get_attachment_metadata($id);
    $dir=dirname($main);
    $prefix=$dir==='.'?'':$dir.'/';
    $files=array($main=>'full');
    foreach (($meta['sizes'] ?? array()) as $size=>$data) {
        if (isset($data['file'])) { $files[$prefix.basename($data['file'])]=$size; }
    }
    if (!empty($meta['original_image'])) { $files[$prefix.basename($meta['original_image'])]='original'; }
    return $files;
}
function inspect_path($rel,$hint) {
    if (!$rel || strlen($rel)>1024 || strpos($rel,'..')!==false || strpos($rel,'\\')!==false) { return array(); }
    $ids=array($hint,(int)get_option('qif_url_'.md5($rel)));
    $base=trailingslashit(wp_get_upload_dir()['baseurl']);
    foreach (array_unique(array_filter($ids)) as $id) {
        $sources=registered_sources($id);
        if (isset($sources[$rel])) { return inspect_size($id,$rel,$sources[$rel]); }
    }
    $stem=preg_replace('/-\d+x\d+(?=\.[^.]+$)/','',$rel);
    foreach (array_unique(array($rel,$stem,preg_replace('/(?=\.[^.]+$)/','-scaled',$stem))) as $candidate) {
        $id=attachment_url_to_postid($base.$candidate);
        if ($id) {
            $sources=registered_sources($id);
            if (isset($sources[$rel])) { return inspect_size($id,$rel,$sources[$rel]); }
        }
    }
    return array();
}
function inspect_size($id,$rel,$size) {
    $s=settings();
    $s['rules']=workflow_options()['rules'];
    $s=size_config($s,$size);
    $map=get_post_meta($id,'_qif_map',true);
    $v=$map[$rel][$s['preferred']] ?? null;
    $result=array('id'=>$id,'size'=>$size,'repair'=>false,'url'=>'','reason'=>'');
    $source=root_dir().'/'.$rel;
    $meta=wp_get_attachment_metadata($id);
    $dims=$meta['sizes'][$size] ?? $meta;
    $result['width']=absint($dims['width'] ?? 0);
    $result['height']=absint($dims['height'] ?? 0);
    $result['originalBytes']=is_file($source)?filesize($source):0;
    $result['format']=$s['preferred'];
    $result['sizes']=array();
    foreach (($meta['sizes'] ?? array()) as $name=>$entry) {
        $result['sizes'][]=array('name'=>$name,'width'=>absint($entry['width'] ?? 0),'height'=>absint($entry['height'] ?? 0));
    }
    $output=$v?'qevora-imageflow/'.$id.'/'.basename($v['file']):'';
    if ($output && is_file(root_dir().'/'.$output) && is_file($source) && filemtime($source)===$v['mtime']) {
        $result['bytes']=filesize(root_dir().'/'.$output);
        $result['url']=trailingslashit(wp_get_upload_dir()['baseurl']).$output;
        $result['reason']='Preferred copy available';
    } elseif (!is_file($source)) { $result['reason']='Registered source file is missing'; }
    elseif (!in_array(get_post_mime_type($id),array_intersect_key(formats(),array_flip($s['sources'])),true)) { $result['reason']='Source format excluded in Settings'; }
    elseif (in_array((int)$id,wp_parse_id_list($s['excluded_ids']),true)) { $result['reason']='Attachment excluded in Settings'; }
    elseif (!in_array($s['preferred'],outputs(),true) || !in_array($s['preferred'],$s['targets'],true) || !encoder($s['preferred'])) { $result['reason']='Preferred encoder or output unavailable'; }
    elseif (formats()[$s['preferred']]===get_post_mime_type($id)) { $result['reason']='Source already uses preferred format'; }
    else { $result['repair']=true; $result['reason']=$v?'Preferred copy missing or stale':'Preferred copy missing for '.$size; }
    return $result;
}
function inspect_request() {
    if (!current_user_can('manage_options')) { wp_send_json_error('Access denied',403); }
}
add_action('wp_ajax_qif_inspect_paths',function () {
    check_ajax_referer('qif_inspect');
    inspect_request();
    $items=json_decode(sanitize_textarea_field(wp_unslash($_POST['items'] ?? '')),true);
    if (!is_array($items) || count($items)>24) { wp_send_json_error('Invalid scan batch',400); }
    $out=array();
    foreach ($items as $item) {
        if (!is_array($item) || !is_string($item['path'] ?? null)) { continue; }
        $out[$item['path']]=inspect_path($item['path'],absint($item['id'] ?? 0));
    }
    wp_send_json_success((object)$out);
});
add_action('wp_ajax_qif_inspect_fix',function () {
    check_ajax_referer('qif_inspect');
    inspect_request();
    $id=absint($_POST['id'] ?? 0);
    $rel=sanitize_text_field(wp_unslash($_POST['path'] ?? ''));
    $sources=registered_sources($id);
    if (!current_user_can('edit_post',$id) || !isset($sources[$rel])) { wp_send_json_error('Unknown attachment size',400); }
    $details=inspect_size($id,$rel,$sources[$rel]);
    if (!$details['repair']) { wp_send_json_success($details['reason']); }
    $s=settings(); $s['rules']=workflow_options()['rules']; $s=size_config($s,$sources[$rel]); $s['targets']=array($s['preferred']); $s['sizes']=array($sources[$rel]);
    $status=convert($id,false,$s,$rel);
    $verified=inspect_size($id,$rel,$sources[$rel]);
    wp_send_json_success($verified['url']?'Verified preferred copy':$status.' · '.get_post_meta($id,'_qif_detail',true));
});
function scan_history() { return (array)get_option('qif_recent_scans',array()); }
add_action('wp_ajax_qif_inspect_record',function () {
    check_ajax_referer('qif_inspect');
    inspect_request();
    $page=substr(sanitize_text_field(wp_unslash($_POST['page'] ?? '')),0,240);
    $width=absint($_POST['width'] ?? 0);
    $rows=json_decode(sanitize_textarea_field(wp_unslash($_POST['items'] ?? '')),true);
    if (!$width || !is_array($rows) || count($rows)>120) { wp_send_json_error('Invalid usage report',400); }
    $items=array();
    foreach ($rows as $row) {
        if (is_array($row) && !empty($row['id']) && is_string($row['where'] ?? null)) {
            $items[]=array('id'=>absint($row['id']),'where'=>substr(sanitize_text_field($row['where']),0,160));
        }
    }
    try {
        $result=locked(0,function () use ($page,$width,$items) {
            $history=scan_history(); $key=md5($page.'|'.$width); unset($history[$key]);
            $history[$key]=array('page'=>$page,'width'=>$width,'date'=>current_time('mysql',true),'items'=>$items);
            update_option('qif_recent_scans',array_slice($history,-24,null,true),false);
            return 'Saved';
        });
        wp_send_json_success($result);
    } catch (\Throwable $e) { wp_send_json_error('Unable to save usage history',500); }
});
function removal_review($ids) {
    $lines=array();
    foreach (array_reverse(scan_history()) as $scan) {
        foreach ($scan['items'] as $item) {
            if (in_array($item['id'],$ids,true)) { $lines[]='#'.$item['id'].' · '.$scan['page'].' · '.$item['where'].' · '.$scan['width'].'px · '.$scan['date'].' UTC'; }
        }
    }
    return implode("\n",array_unique($lines)) ?: __('No matches in recent scans. Other pages may still use these images.','qevora-imageflow');
}
add_action('wp_footer',function () {
    if (!wp_script_is('qif-inspector','enqueued')) { return; }
    ?>
    <aside id="qif-audit" aria-label="ImageFlow page inspector">
        <div class="qif-audit-head"><strong>ImageFlow · Page inspector</strong><a href="<?php echo esc_url(remove_query_arg('qif_inspect')); ?>">Exit</a></div>
        <div class="qif-audit-body">
            <p id="qif-audit-status" role="status">Scan this viewport or pick an image on the page.</p>
            <div class="qif-audit-actions"><button data-task="scan">Scan viewport</button><button data-task="pick" aria-pressed="false">Pick image</button><button data-task="baseline">Save baseline</button><button data-task="compare">Compare baseline</button><a href="<?php echo esc_url(page_url('docs')); ?>">Guide</a></div>
            <div id="qif-audit-results"></div>
        </div>
    </aside>
    <?php
},15);
