<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;
require_once __DIR__.'/origin-reader.php';

function origin_media($id) { return in_array(strtok((string)get_post_mime_type($id),'/'),array('image','video'),true); }
function origin_labels() { return array('generated'=>'AI-generated','edited'=>'AI-edited','human'=>'Human-created','unknown'=>'Unknown','pending'=>'Not checked'); }
function origin_state($id) {
    $manual=get_post_meta($id,'_qif_origin_manual',true);
    $auto=get_post_meta($id,'_qif_origin_detected',true);
    return array('key'=>$manual ?: ($auto ?: 'pending'),'manual'=>(bool)$manual);
}
function inspect_origin($id,$meta=null) {
    $path=get_attached_file($id,true);
    if ($path && wp_attachment_is_image($id)) {
        $meta=$meta ?? wp_get_attachment_metadata($id);
        if (!empty($meta['original_image'])) { $path=dirname($path).'/'.wp_basename($meta['original_image']); }
    }
    $reader=new Origin_Reader();
    $label=$reader->read($path);
    update_post_meta($id,'_qif_origin_detected',$label);
    return $label;
}
add_filter('wp_generate_attachment_metadata',function ($meta,$id) {
    if (origin_media($id)) { inspect_origin($id,$meta); }
    return $meta;
},12,2);

function origin_badge($id) {
    $state=origin_state($id);
    $label=origin_labels()[$state['key']] ?? 'Unknown';
    $basis=$state['manual']?'Manual label':(in_array($state['key'],array('generated','edited'),true)?'Embedded metadata':'');
    return '<span class="qif-origin-label qif-origin-'.esc_attr($state['key']).'">'.esc_html($label).'</span>'.($basis?'<small class="qif-origin-basis">'.esc_html($basis).'</small>':'');
}

add_filter('manage_upload_columns',function ($columns) { $columns['qif_origin']='AI origin'; return $columns; });
add_action('manage_media_custom_column',function ($column,$id) {
    if ('qif_origin'===$column && origin_media($id)) { echo wp_kses_post(origin_badge($id)); }
},10,2);
add_filter('attachment_fields_to_edit',function ($fields,$post) {
    if (!origin_media($post->ID)) { return $fields; }
    $html=origin_badge($post->ID);
    if (current_user_can('edit_post',$post->ID)) {
        $id=$post->ID;
        $manual=get_post_meta($id,'_qif_origin_manual',true);
        $html.='<p><select aria-label="AI origin override" name="attachments['.absint($id).'][qif_origin]">';
        foreach (array(''=>'Use embedded metadata','generated'=>'AI-generated (manual)','edited'=>'AI-edited (manual)','human'=>'Human-created (manual)','unknown'=>'Unknown (manual)') as $key=>$label) {
            $html.='<option value="'.esc_attr($key).'" '.selected($key,$manual,false).'>'.esc_html($label).'</option>';
        }
        $html.='</select><input type="hidden" name="attachments['.absint($id).'][qif_origin_nonce]" value="'.esc_attr(wp_create_nonce('qif_origin_'.$id)).'"></p>';
    }
    $fields['qif_origin']=array('label'=>'AI origin','input'=>'html','html'=>$html,'helps'=>'Unknown means a supported AI declaration was not found. Set a manual label if you know the origin.');
    return $fields;
},10,2);
add_filter('attachment_fields_to_save',function ($post,$fields) {
    $id=absint($post['ID']);
    if (!array_key_exists('qif_origin',$fields) || !wp_verify_nonce($fields['qif_origin_nonce'] ?? '','qif_origin_'.$id) || !current_user_can('edit_post',$id) || !origin_media($id)) { return $post; }
    $key=sanitize_key($fields['qif_origin']);
    if (''===$key) { delete_post_meta($id,'_qif_origin_manual'); }
    elseif (in_array($key,array('generated','edited','human','unknown'),true)) { update_post_meta($id,'_qif_origin_manual',$key); }
    return $post;
},10,2);

function origin_filter() {
    $value=sanitize_key(wp_unslash($_GET['qif_origin'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only media list filter.
    return isset(origin_labels()[$value])?$value:'';
}
add_action('restrict_manage_posts',function ($type) {
    if ('attachment'!==$type) { return; }
    echo '<label class="screen-reader-text" for="qif-origin-filter">Filter by AI origin</label><select id="qif-origin-filter" name="qif_origin"><option value="">All AI origins</option>';
    foreach (origin_labels() as $key=>$label) { echo '<option value="'.esc_attr($key).'" '.selected(origin_filter(),$key,false).'>'.esc_html($label).'</option>'; }
    echo '</select>';
});
function origin_query($key) {
    $manual='_qif_origin_manual'; $auto='_qif_origin_detected';
    $no_manual=array('key'=>$manual,'compare'=>'NOT EXISTS');
    if ('pending'===$key) { return array('relation'=>'AND',$no_manual,array('key'=>$auto,'compare'=>'NOT EXISTS')); }
    if ('human'===$key) { return array('key'=>$manual,'value'=>'human'); }
    return array('relation'=>'OR',array('key'=>$manual,'value'=>$key),array('relation'=>'AND',$no_manual,array('key'=>$auto,'value'=>$key)));
}
add_action('pre_get_posts',function ($query) {
    global $pagenow;
    if (!is_admin() || 'upload.php'!==$pagenow || !$query->is_main_query() || 'attachment'!==$query->get('post_type')) { return; }
    $key=origin_filter();
    if (!$key) { return; }
    $conditions=array('relation'=>'AND',origin_query($key));
    if ($query->get('meta_query')) { $conditions[]=$query->get('meta_query'); }
    $query->set('meta_query',$conditions);
    $mimes=array_filter((array)$query->get('post_mime_type'));
    $media=array_filter($mimes,static function ($mime) { return in_array(strtok($mime,'/'),array('image','video'),true); });
    if ($mimes && !$media) { $query->set('post__in',array(0)); }
    else { $query->set('post_mime_type',$media ?: array('image','video')); }
});

function origin_page() {
    echo '<section><h2>Find AI media</h2><p>Open a filtered Media Library list to review each attachment. The AI origin column shows whether the label came from metadata or a person.</p>';
    foreach (array('generated','edited','unknown','pending') as $key) {
        $link=add_query_arg(array('mode'=>'list','qif_origin'=>$key),admin_url('upload.php'));
        echo '<a class="button" href="'.esc_url($link).'">'.esc_html(origin_labels()[$key]).'</a> ';
    }
    echo '</section><section><h2>Check existing uploads</h2><p>New image and video uploads are checked automatically. Scan older uploads in small batches. Manual labels are kept.</p><button type="button" class="button button-primary" id="qif-origin-scan">Scan existing uploads</button><p role="status" id="qif-origin-status"></p><a href="'.esc_url(page_url('docs').'#ai-origin').'">Supported metadata and formats</a></section>';
}

add_action('wp_ajax_qif_origin_scan',function () {
    check_ajax_referer('qif_admin');
    if (!current_user_can('manage_options')) { wp_send_json_error('Access denied',403); }
    $cursor=absint($_POST['cursor'] ?? 0);
    $limit=absint($_POST['limit'] ?? 0);
    $types=array('image','video');
    if (!$limit) {
        $top=attachment_query(array('cursor'=>0,'limit'=>PHP_INT_MAX,'mode'=>'origin'),false,'','DESC',$types)->posts;
        $limit=(int)($top[0] ?? 0);
    }
    $ids=attachment_query(array('cursor'=>$cursor,'limit'=>$limit,'mode'=>'origin'),false,'','ASC',$types,12)->posts;
    foreach ($ids as $id) { inspect_origin($id); $cursor=(int)$id; }
    wp_send_json_success(array('cursor'=>$cursor,'limit'=>$limit,'count'=>count($ids),'finished'=>count($ids)<12 || $cursor>=$limit));
});
add_action('admin_enqueue_scripts',function () {
    $id=get_current_screen()->id;
    if (in_array($id,array('upload','attachment'),true) || 'qif-origin'===substr($id,-10)) {
        wp_enqueue_style('qif-origin',QIF_URL.'assets/origin.css',array(),'1.4.0');
    }
    if ('qif-origin'===substr($id,-10)) { wp_enqueue_script('qif-origin',QIF_URL.'assets/origin.js',array('qif-admin'),'1.4.0',true); }
});
