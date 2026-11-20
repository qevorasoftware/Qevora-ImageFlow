<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;
function pages() { return array('home'=>'Dashboard','settings'=>'Settings','bulk'=>'Bulk Converter','media'=>'Media Library Tools','elementor'=>'Elementor Integration','logs'=>'Logs & Tools'); }
function page_url($key) { return admin_url('admin.php?page=qif-'.$key); }
function h($str) { echo esc_html(translate($str,'qevora-imageflow')); }
add_action('admin_menu',function () {
    add_menu_page('Qevora ImageFlow','Qevora ImageFlow','manage_options','qif-home',__NAMESPACE__.'\\page','dashicons-format-image');
    foreach (pages() as $key=>$title) { add_submenu_page('qif-home',$title,$title,'manage_options','qif-'.$key,__NAMESPACE__.'\\page'); }
});
add_action('admin_enqueue_scripts',function () {
    if (strpos(get_current_screen()->id,'qif-')===false) { return; }
    wp_enqueue_style('qif-admin',QIF_URL.'assets/admin.css',array(),'1.0.0');
    wp_enqueue_script('qif-admin',QIF_URL.'assets/admin.js',array(),'1.0.1',true);
    wp_localize_script('qif-admin','qifAdmin',array('nonce'=>wp_create_nonce('qif_admin')));
});
add_action('admin_init',function () { register_setting('qif','qif_settings',array('sanitize_callback'=>__NAMESPACE__.'\\sanitize')); });
function check($key,$label,$value=null,$disabled=false) {
    $s=settings();
    $on=$value===null?$s[$key]:in_array($value,$s[$key],true);
    echo '<label class="qif-check"><input type="checkbox" name="qif_settings['.esc_attr($key).']'.($value===null?'':'[]').'" value="'.esc_attr($value ?? 1).'" '.checked($on && !$disabled,true,false).' '.disabled($disabled,true,false).'> ';
    h($label); if ($disabled) { echo ' <small>'; h('Codec unavailable'); echo '</small>'; } echo '</label>';
}
function options_page() {
    $s=settings();
    echo '<form method="post" action="options.php">'; settings_fields('qif');
    echo '<section><h2>'; h('Image formats'); echo '</h2><h3>'; h('Convert from'); echo '</h3><div class="qif-grid">';
    foreach (formats() as $key=>$mime) { check('sources',strtoupper($key),$key,!capable($key)); }
    echo '</div><h3>'; h('Generate'); echo '</h3><div class="qif-grid">';
    foreach (outputs() as $key) { check('targets',strtoupper($key),$key,!encoder($key)); }
    echo '</div><p><label>'; h('Preferred delivery format'); echo ' <select name="qif_settings[preferred]">';
    foreach (outputs() as $key) { echo '<option '.selected($key,$s['preferred'],false).' '.disabled(!encoder($key),true,false).' value="'.esc_attr($key).'">'.esc_html(strtoupper($key)).'</option>'; }
    echo '</select></label></p><p>'; h('Same-format outputs are skipped. Images with transparency are retained when JPEG would lose alpha.'); echo '</p></section><section><h2>'; h('Conversion & delivery'); echo '</h2>';
    foreach (array('auto'=>'Convert new uploads in the background','smaller'=>'Keep only smaller alternatives','strip'=>'Strip metadata where supported','delivery'=>'Deliver optimized images','elementor'=>'Enable delivery on Elementor sites','background'=>'Optimize supported CSS backgrounds on Elementor sites') as $k=>$label) { check($k,$label); }
    echo '<h3>'; h('Intermediate image sizes'); echo '</h3><div class="qif-grid">';
    foreach (get_intermediate_image_sizes() as $size) { check('sizes',$size,$size); }
    echo '</div></section><section><h2>'; h('Quality & limits'); echo '</h2><div class="qif-grid">';
    foreach (array('webp'=>'WebP quality','avif'=>'AVIF quality','jpeg'=>'JPEG quality','png'=>'PNG compression','megabytes'=>'Maximum MB','megapixels'=>'Maximum megapixels') as $k=>$label) {
        $max=array('png'=>9,'megabytes'=>128,'megapixels'=>64)[$k] ?? 100;
        echo '<label>'; h($label); echo '<input type="number" min="'.($k==='png'?0:1).'" max="'.intval($max).'" name="qif_settings['.esc_attr($k).']" value="'.intval($s[$k]).'"></label>';
    }
    echo '</div><p><label>'; h('Excluded attachment IDs (comma-separated)'); echo ' <input name="qif_settings[exclude]" value="'.esc_attr($s['exclude']).'"></label></p><p>'; h('Run bulk conversion after changing output, quality, size or exclusion settings.'); echo '</p></section>'; submit_button(); echo '</form>';
}
function button($cmd,$label,$id=0) {
    echo '<button type="button" class="button" data-qif="'.esc_attr($cmd).'" data-id="'.absint($id).'">'; h($label); echo '</button> ';
}
function output_labels($id) {
    $map=get_post_meta($id,'_qif_map',true);
    $formats=array();
    foreach (is_array($map) ? $map : array() as $variants) {
        foreach ($variants as $format=>$variant) {
            if (is_file(root_dir().'/qevora-imageflow/'.$id.'/'.basename($variant['file']))) {
                $formats[$format]=strtoupper($format);
            }
        }
    }
    return implode(', ',$formats);
}
function bulk_form() {
    $s=settings();
    echo '<section><h2>1. Choose images</h2><label>Process <select id="qif-scope"><option value="selected">Checked images</option><option value="start">All eligible images in the library</option><option value="retry">Failed eligible images</option><option value="force">Force reconvert checked images</option></select></label>';
    echo '<p>Enabled source formats: '.esc_html(strtoupper(implode(', ',$s['sources']))).'. List filters help you find images to check.</p>';
    media_rows('bulk');
    echo '</section><section><h2>2. Choose output formats</h2><fieldset id="qif-targets"><legend>Convert images to</legend>';
    foreach (outputs() as $format) {
        $available=encoder($format);
        echo '<label class="qif-format"><input type="checkbox" value="'.esc_attr($format).'" '.checked($available && in_array($format,$s['targets'],true),true,false).' '.disabled(!$available,true,false).'> '.esc_html(strtoupper($format)).($available?'':' (encoder unavailable)').'</label>';
    }
    echo '</fieldset><p>Choose one or more formats for this batch. Settings controls the preferred format delivered to visitors.</p></section><section><h2>3. Review and convert</h2><p id="qif-review" aria-live="polite"></p>';
    button('launch','Start conversion');
    foreach (array('pause'=>'Pause','resume'=>'Resume','stop'=>'Stop') as $cmd=>$label) { button($cmd,$label); }
    echo '<p role="status" id="qif-status"></p></section>';
}
function media_rows($key) {
    $page=max(1,absint($_GET['paged'] ?? 1));
    $mime=sanitize_key($_GET['fmt'] ?? '');
    $state=sanitize_key($_GET['state'] ?? '');
    $args=array('post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>formats()[$mime] ?? 'image','posts_per_page'=>20,'paged'=>$page);
    if (in_array($state,array('converted','failed','skipped'),true)) { $args['meta_key']='_qif_status'; $args['meta_value']=$state; }
    $month=sanitize_text_field($_GET['month'] ?? '');
    if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/',$month,$parts)) { $args['date_query']=array(array('year'=>(int)$parts[1],'month'=>(int)$parts[2])); }
    if ($key==='logs' && !$state) { $args['meta_key']='_qif_detail'; }
    $query=new \WP_Query($args);
    echo '<form method="get"><input type="hidden" name="page" value="qif-'.esc_attr($key).'"><select aria-label="Source format" name="fmt"><option value="">All formats</option>';
    foreach (formats() as $k=>$val) { echo '<option '.selected($mime,$k,false).' value="'.esc_attr($k).'">'.esc_html(strtoupper($k)).'</option>'; }
    echo '</select> <select aria-label="Conversion state" name="state"><option value="">All states</option>';
    foreach (array('converted','failed','skipped') as $k) { echo '<option '.selected($state,$k,false).'>'.esc_html($k).'</option>'; }
    echo '</select> <input type="month" aria-label="Upload month" name="month" value="'.esc_attr($month).'"> <button class="button">Filter list</button></form><table class="widefat striped"><thead><tr><th><input type="checkbox" aria-label="Select visible page" id="qif-all"></th><th>Attachment</th><th>Original format</th><th>Generated formats</th><th>Status</th><th>Last result</th></tr></thead><tbody>';
    foreach ($query->posts as $p) {
        echo '<tr><td><input class="qif-id" type="checkbox" aria-label="Select attachment '.intval($p->ID).'" value="'.intval($p->ID).'"></td><td><a href="'.esc_url(get_edit_post_link($p->ID)).'">'.esc_html($p->post_title ?: '#'.$p->ID).'</a></td><td>'.esc_html(strtoupper(str_replace('image/','',get_post_mime_type($p->ID)))).'</td><td>'.esc_html(output_labels($p->ID) ?: '—').'</td><td>'.esc_html(get_post_meta($p->ID,'_qif_status',true) ?: 'pending').'</td><td>'.esc_html(get_post_meta($p->ID,'_qif_detail',true)).'</td></tr>';
    }
    echo '</tbody></table>'; echo wp_kses_post(paginate_links(array('base'=>add_query_arg('paged','%#%'),'format'=>'','total'=>$query->max_num_pages,'current'=>$page)));
}
function stats($meta) {
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare("SELECT SUM(CAST(meta_value AS SIGNED)) FROM $wpdb->postmeta WHERE meta_key=%s",$meta));
}
function page() {
    $key=substr(sanitize_key($_GET['page'] ?? ''),4);
    echo '<div class="wrap qif"><header><div class="qif-mark" aria-hidden="true">Q</div><div><span>QEVORA SOFTWARE</span><h1>ImageFlow</h1></div></header><nav>';
    foreach (pages() as $k=>$title) { echo '<a '.($k===$key?'aria-current="page"':'').' href="'.esc_url(page_url($k)).'">'; h($title); echo '</a>'; }
    echo '</nav><h2 class="qif-title">'; h(pages()[$key] ?? 'Dashboard'); echo '</h2>'; if ($key!=='bulk') { echo '<p role="status" id="qif-status"></p>'; }
    if ($key==='settings') { options_page(); }
    elseif ($key==='home') {
        global $wpdb;
        $counts=$wpdb->get_results("SELECT meta_value AS state,COUNT(*) AS n FROM $wpdb->postmeta WHERE meta_key='_qif_status' GROUP BY meta_value",OBJECT_K);
        $a=stats('_qif_before'); $b=stats('_qif_after');
        echo '<div class="qif-grid cards">';
        $where=queue_where(array('cursor'=>0,'limit'=>PHP_INT_MAX,'mode'=>'start'));
        $eligible=(int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p WHERE ".$where);
        $pending=(int)$wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p WHERE ".$where." AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta m WHERE m.post_id=p.ID AND m.meta_key='_qif_status')");
        foreach (array('Eligible sources'=>$eligible,'Pending'=>$pending,'Converted'=>$counts['converted']->n ?? 0,'Skipped'=>$counts['skipped']->n ?? 0,'Failed'=>$counts['failed']->n ?? 0,'Delivery savings'=>size_format(abs($a-$b)).($a<$b?' increase':'').' ('.($a?round(100*($a-$b)/$a):0).'%)') as $label=>$val) { echo '<section><span>'; h($label); echo '</span><strong>'.esc_html($val).'</strong></section>'; }
        echo '</div><section><h2>Storage comparison</h2><p>Matched originals · '.esc_html(size_format($a)).' &nbsp; Preferred alternatives · '.esc_html(size_format($b)).'</p><a class="button button-primary" href="'.esc_url(page_url('bulk')).'">Convert existing images</a></section>';
        diagnostics();
    } elseif ($key==='elementor') {
        echo '<section><h2>Elementor assets</h2><p>Image URLs and responsive candidates are resolved in the browser. Same-origin style rules with one background URL can use typed image-set alternatives, including media queries and hover states. Complex backgrounds retain their original CSS.</p>';
        button('css','Regenerate Elementor CSS'); echo '<a class="button" href="'.esc_url(page_url('settings')).'">Delivery settings</a></section>'; diagnostics();
    } elseif ($key==='bulk') { bulk_form(); }
    else {
        echo '<section>';
        if ($key==='media') { button('selected','Convert selected'); button('restore','Remove selected derivatives'); }
        if ($key==='logs') { button('original','Restore original delivery'); button('export','Export settings'); button('clear','Clear log messages'); }
        media_rows($key); echo '</section>';
    }
    echo '</div>';
}
function diagnostics() {
    echo '<section><h2>Server diagnostics</h2><dl>';
    foreach (array('Imagick'=>class_exists('Imagick')?'Available':'Unavailable','GD'=>extension_loaded('gd')?'Available':'Unavailable','WebP encoder'=>encoder('webp')?'Available':'Unavailable','AVIF encoder'=>encoder('avif')?'Available':'Unavailable','Elementor'=>defined('ELEMENTOR_VERSION')?ELEMENTOR_VERSION:'Inactive','Upload limit'=>size_format(wp_max_upload_size()),'PHP memory'=>ini_get('memory_limit')) as $k=>$v) { echo '<dt>'.esc_html($k).'</dt><dd>'.esc_html($v).'</dd>'; }
    echo '</dl></section>';
}
function queue($cmd,$ids,$requested=null) {
    global $wpdb;
    $job=get_option('qif_job',array('state'=>'idle','done'=>0,'total'=>0));
    if (in_array($cmd,array('start','selected','force','retry'),true)) {
        if ($job['state']==='running' || $job['state']==='paused') { throw new \RuntimeException('Stop the current job first.'); }
        $list=in_array($cmd,array('selected','force'),true);
        if ($list && !$ids) { throw new \RuntimeException('Select at least one attachment.'); }
        $job=array('mode'=>$cmd,'ids'=>$ids,'cursor'=>0,'state'=>'running','done'=>0,'converted'=>0,'skipped'=>0,'failed'=>0,'saved'=>0,'last'=>'');
        $config=settings();
        $targets=$requested===null ? $config['targets'] : $requested;
        if (!is_array($targets) || !$targets || array_diff($targets,array_filter(outputs(),__NAMESPACE__.'\\encoder'))) {
            throw new \RuntimeException('Choose at least one available output format.');
        }
        $config['targets']=array_values(array_unique($targets));
        if (!in_array($config['preferred'],$targets,true)) { $config['preferred']=$config['targets'][0]; }
        $job['config']=$config;
        $job['limit']=(int)$wpdb->get_var("SELECT MAX(ID) FROM $wpdb->posts");
        $job['total']=$list?count($ids):(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->posts.' p WHERE '.queue_where($job));
    } elseif ($cmd==='pause' && $job['state']==='running') { $job['state']='paused'; }
    elseif ($cmd==='resume' && $job['state']==='paused') { $job['state']='running'; }
    elseif ($cmd==='stop') { $job['state']='stopped'; }
    elseif ($cmd==='tick' && $job['state']==='running') {
        $list=in_array($job['mode'],array('selected','force'),true);
        $id=$list?($job['ids'][$job['done']] ?? 0):(int)$wpdb->get_var('SELECT ID FROM '.$wpdb->posts.' p WHERE '.queue_where($job).' ORDER BY ID LIMIT 1');
        if (!$id) { $job['state']='complete'; }
        else {
            $result=convert($id,$job['mode']==='force',$job['config'] ?? null);
            if ($result!=='busy') {
                ++$job[$result]; ++$job['done']; $job['cursor']=$id;
                $job['saved']+=(int)get_post_meta($id,'_qif_saved',true);
                $job['last']='#'.$id.' '.$result.' '.get_post_meta($id,'_qif_detail',true);
            }
        }
    }
    if ($cmd!=='status') { update_option('qif_job',$job,false); }
    $job['targets']=$job['config']['targets'] ?? array();
    unset($job['ids'],$job['config']); return $job;
}
function queue_where($job) {
    global $wpdb;
    $mimes=array_intersect_key(formats(),array_flip($job['config']['sources'] ?? settings()['sources']));
    if (!$mimes) { return '1=0'; }
    $sql=$wpdb->prepare("p.post_type='attachment' AND p.post_status='inherit' AND p.ID>%d AND p.ID<=%d AND p.post_mime_type IN (".implode(',',array_fill(0,count($mimes),'%s')).')',array_merge(array($job['cursor'],$job['limit']),array_values($mimes)));
    if ($job['mode']==='retry') { $sql.=" AND EXISTS (SELECT 1 FROM $wpdb->postmeta m WHERE m.post_id=p.ID AND m.meta_key='_qif_status' AND m.meta_value='failed')"; }
    return $sql;
}
add_action('wp_ajax_qif_action',function () {
    check_ajax_referer('qif_admin');
    if (!current_user_can('manage_options')) { wp_send_json_error('Access denied',403); }
    $cmd=sanitize_key($_POST['cmd'] ?? '');
    $ids=array_slice(wp_parse_id_list(wp_unslash($_POST['ids'] ?? '')),0,100);
    try {
        switch ($cmd) {
            case 'export': wp_send_json_success(settings()); break;
            case 'original': $s=settings(); $s['delivery']=0; update_option('qif_settings',$s); $result='Original delivery enabled'; break;
            case 'clear': delete_post_meta_by_key('_qif_detail'); $result='Log messages cleared'; break;
            case 'css':
                if (!class_exists('\Elementor\Plugin') || empty(\Elementor\Plugin::$instance->files_manager) || !is_callable(array(\Elementor\Plugin::$instance->files_manager,'clear_cache'))) { throw new \RuntimeException('Elementor cache API unavailable.'); }
                \Elementor\Plugin::$instance->files_manager->clear_cache(); $result='Elementor CSS cache cleared'; break;
            case 'restore':
                if (!$ids) { throw new \RuntimeException('Select attachments first.'); }
                $result=array();
                foreach ($ids as $id) { if (wp_attachment_is_image($id)) { $result[]='#'.$id.' '.remove_files($id); } }
                $result=implode(', ',$result); break;
            default: $result=locked(0,function () use ($cmd,$ids) { return queue($cmd,$ids,isset($_POST['targets']) ? explode(',',sanitize_text_field(wp_unslash($_POST['targets']))) : null); });
        }
        wp_send_json_success($result);
    } catch (\RuntimeException $e) { wp_send_json_error($e->getMessage(),400); }
});
add_filter('manage_upload_columns',function ($cols) { $cols['qif']='Qevora ImageFlow'; return $cols; });
add_action('manage_media_custom_column',function ($col,$id) {
    if ($col!=='qif' || !wp_attachment_is_image($id)) { return; }
    $map=get_post_meta($id,'_qif_map',true);
    $types=array();
    foreach (is_array($map)?$map:array() as $variants) { $types=array_merge($types,array_keys($variants)); }
    echo esc_html(get_post_mime_type($id).' · '.(get_post_meta($id,'_qif_status',true) ?: 'pending').' '.implode(', ',array_unique($types)).' · '.(int)get_post_meta($id,'_qif_saved',true).' B saved');
},10,2);
add_filter('attachment_fields_to_edit',function ($fields,$post) {
    if (!wp_attachment_is_image($post->ID) || !current_user_can('manage_options')) { return $fields; }
    $html='';
    foreach (array('convert'=>'Convert now','force'=>'Reconvert','restore'=>'Remove derivatives') as $cmd=>$label) {
        $url=wp_nonce_url(add_query_arg(array('action'=>'qif_single','id'=>$post->ID,'cmd'=>$cmd),admin_url('admin-post.php')),'qif_'.$post->ID);
        $html.='<a class="button" href="'.esc_url($url).'">'.esc_html($label).'</a> ';
    }
    $base=wp_get_upload_dir()['baseurl'];
    $map=get_post_meta($post->ID,'_qif_map',true);
    foreach (is_array($map)?$map:array() as $rel=>$variants) {
        $html.='<p>Original · '.esc_html(size_format(reset($variants)['original'])).'<br><img width="96" alt="Original preview" src="'.esc_url($base.'/'.$rel).'"></p>';
        foreach ($variants as $fmt=>$v) {
            $url=$base.'/qevora-imageflow/'.$post->ID.'/'.basename($v['file']);
            $html.='<a href="'.esc_url($url).'"><img width="96" alt="'.esc_attr($fmt).' preview" src="'.esc_url($url).'"> '.esc_html(strtoupper($fmt).' · '.size_format($v['bytes'])).'</a> ';
        }
        break;
    }
    $fields['qif']=array('label'=>'Qevora ImageFlow','input'=>'html','html'=>$html);
    return $fields;
},10,2);
add_action('admin_post_qif_single',function () {
    $id=absint($_GET['id'] ?? 0);
    check_admin_referer('qif_'.$id);
    if (!current_user_can('manage_options') || !current_user_can('edit_post',$id) || !wp_attachment_is_image($id)) { wp_die('Access denied'); }
    $cmd=sanitize_key($_GET['cmd'] ?? '');
    if ($cmd==='restore') { remove_files($id); } else { convert($id,$cmd==='force'); }
    wp_safe_redirect(admin_url('post.php?post='.$id.'&action=edit')); exit;
});
