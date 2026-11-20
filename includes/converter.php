<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;
function root_dir() { return realpath(wp_get_upload_dir()['basedir']); }
function managed($id) {
    $base = root_dir();
    if (!$base) { throw new \RuntimeException('Uploads directory unavailable.'); }
    $dir = $base.'/qevora-imageflow/'.absint($id);
    if (!wp_mkdir_p($dir) || wp_normalize_path(realpath($dir)) !== wp_normalize_path($dir)) { throw new \RuntimeException('Cannot access derivative directory.'); }
    return $dir;
}
function locked($id,$callback) {
    $dir = managed($id);
    $lock=new \SplFileObject($dir.'/.lock','c');
    if (!$lock->flock(LOCK_EX|LOCK_NB)) { return 'busy'; }
    try { return $callback($dir); } finally { $lock->flock(LOCK_UN); }

}
function remove_files($id) {
    try {
        return locked($id,function ($dir) use ($id) {
            foreach ((array)get_post_meta($id,'_qif_map',true) as $rel=>$variants) {
                delete_option('qif_url_'.md5($rel));
            }
            foreach (glob($dir.'/*') ?: array() as $file) { if (is_file($file)) { wp_delete_file($file); } }
            foreach (array('map','status','detail','saved','before','after') as $key) { delete_post_meta($id,'_qif_'.$key); }
            return 'restored';
        });
    } catch (\Throwable $e) { return 'failed'; }
}
function encode($src,$tmp,$from,$to,$s) {
    // Some ImageMagick delegates expose only the first frame of APNG or animated AVIF.
    if (($from==='png' && strpos(file_get_contents($src),'acTL')!==false)
        || ($from==='webp' && strpos(file_get_contents($src),'ANIM')!==false)
        || ($from==='avif' && strpos(file_get_contents($src,false,null,0,256),'avis')!==false)) {
        return 'Animated container retained';
    }
    if (class_exists('Imagick') && \Imagick::queryFormats(strtoupper($from)) && \Imagick::queryFormats(strtoupper($to))) {
        $im = new \Imagick();
        try {
            $im->pingImage($src);
            if ($im->getNumberImages() !== 1) { return 'Animation or multi-page image retained'; }
            $im->clear();
            $im->readImage($src);
            if ($im->getNumberImages() !== 1 || ($to==='jpeg' && $im->getImageAlphaChannel())) { return 'Multiple frames or alpha retained'; }
            if ($im->getImageOrientation()>1) { return 'Original orientation retained'; }
            if ($s['strip']) {
                $profiles=$im->getImageProfiles('icc',true);
                $im->stripImage();
                if (isset($profiles['icc'])) { $im->profileImage('icc',$profiles['icc']); }
            }
            $im->setImageFormat($to);
            if ($to==='png') { $im->setOption('png:compression-level',(string)$s['png']); }
            else { $im->setImageCompressionQuality($s[$to]); }
            if (!$im->writeImage($tmp)) { throw new \RuntimeException(); }
        } finally { $im->clear(); }
    } else {
        if (!$s['strip'] || !in_array($from,array('jpeg','png','bmp'),true) || !function_exists('imagecreatefrom'.$from) || !function_exists('image'.$to)) { return 'Codec or metadata preservation unavailable'; }
        if ($from==='png' && (strpos(file_get_contents($src),'acTL')!==false || $to==='jpeg')) { return 'Potential animation or transparency retained'; }
        if ($from==='jpeg' && (!function_exists('exif_read_data') || (@exif_read_data($src)['Orientation'] ?? 1)>1)) { return 'Orientation requires Imagick'; }
        $im=call_user_func('imagecreatefrom'.$from,$src);
        if (!$im) { throw new \RuntimeException(); }
        try {
            if (!imageistruecolor($im)) { imagepalettetotruecolor($im); }
            imagealphablending($im,false);
            imagesavealpha($im,true);
            if (!call_user_func('image'.$to,$im,$tmp,$s[$to])) { throw new \RuntimeException(); }
        } finally { imagedestroy($im); }
    }
    return '';
}
function convert($id,$force=false,$config=null,$only=null) {
    if (!wp_attachment_is_image($id)) { return 'skipped'; }
    try {
        return locked($id,function ($dir) use ($id,$force,$config,$only) {
            $s=$config ?? settings();
            $s['rules']=$s['rules'] ?? workflow_options()['rules'];
            $base_config=$s;
            $registered=registered_sources($id);
            $meta=wp_get_attachment_metadata($id);
            $main=get_attached_file($id,true);
            $files=array($main);
            foreach ((array)($meta['sizes'] ?? array()) as $size=>$item) {
                if (in_array($size,$s['sizes'],true)) { $files[]=dirname($main).'/'.basename($item['file']); }
            }
            if (!empty($meta['original_image'])) { $files[]=dirname($main).'/'.basename($meta['original_image']); }
            $old=get_post_meta($id,'_qif_map',true);
            $old=is_array($old)?$old:array();
            $map=$only===null?array():$old; $notes=array(); $before=0; $after=0; $failed=false;
            foreach (array_unique($files) as $file) {
                $src=$file?realpath($file):false;
                if (!$src || strpos($src,root_dir().DIRECTORY_SEPARATOR)!==0 || !is_readable($src)) { $failed=true; $notes[]='Source unavailable'; continue; }
                $rel=wp_normalize_path(substr($src,strlen(root_dir())+1));
                if ($only!==null && $only!==$rel) { continue; }
                $s=size_config($base_config,$registered[$rel] ?? '');
                $info=wp_getimagesize($src);
                $bytes=filesize($src);
                $from=array_search(wp_get_image_mime($src),formats(),true);
                if (!$info || !$bytes) { $failed=true; $notes[]='Invalid image'; continue; }
                if (in_array($id,wp_parse_id_list($s['excluded_ids'] ?? $s['exclude'] ?? ''),true) || !in_array($from,$s['sources'],true) || $bytes>$s['megabytes']*1048576 || $info[0]*$info[1]>$s['megapixels']*1000000) { $notes[]='Excluded by source or size settings'; continue; }
                $hash=hash_file('sha256',$src);
                foreach ($s['targets'] as $to) {
                    if ($to===$from) { $notes[]='Same format'; continue; }
                    $name=hash('sha256',$rel.$hash.$to.$s[$to].$s['strip']).'.'.$to;
                    $dest=$dir.'/'.$name;
                    $tmp=null;
                    try {
                        if (!is_file($dest) || $force) {
                            $tmp=tempnam($dir,'work-');
                            if (!$tmp || dirname($tmp)!==$dir) { throw new \RuntimeException(); }
                            $reason=encode($src,$tmp,$from,$to,$s);
                            if ($reason) { $notes[]=$reason; continue; }
                            $out=wp_getimagesize($tmp);
                            if (!$out || $out[0]!==$info[0] || $out[1]!==$info[1] || wp_get_image_mime($tmp)!==formats()[$to]) { throw new \RuntimeException(); }
                            if ($s['smaller'] && filesize($tmp)>=$bytes) { $notes[]='Derivative is not smaller'; continue; }
                            require_once ABSPATH.'wp-admin/includes/class-wp-filesystem-base.php';
                            require_once ABSPATH.'wp-admin/includes/class-wp-filesystem-direct.php';
                            $filesystem=new \WP_Filesystem_Direct(null);
                            if (!$filesystem->chmod($tmp,fileperms($src)&0666)) { throw new \RuntimeException(); }
                            // Both paths are in the same local directory. Replace atomically, including during forced conversion.
                            if (!rename($tmp,$dest)) { throw new \RuntimeException(); } // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem::move may delete or copy before publishing.
                        }
                        clearstatcache(true,$dest);
                        $length=filesize($dest);
                        if (!$length || ($s['smaller'] && $length>=$bytes)) { $notes[]='Derivative is not smaller'; continue; }
                        $map[$rel][$to]=array('file'=>$name,'bytes'=>$length,'original'=>$bytes,'width'=>$info[0],'height'=>$info[1],'mtime'=>filemtime($src));
                    } catch (\Throwable $e) { $failed=true; $notes[]='Unable to encode or verify '.strtoupper($to); }
                    finally { if ($tmp && is_file($tmp)) { wp_delete_file($tmp); } }
                }
                if (isset($map[$rel])) {
                    update_option('qif_url_'.md5($rel),$id,false);
                }
            }
            foreach ($map as $variants) {
                $v=$variants[$base_config['preferred']] ?? reset($variants);
                $before+=$v['original']; $after+=$v['bytes'];
            }
            foreach (array_diff(array_keys($old),array_keys($map)) as $rel) { delete_option('qif_url_'.md5($rel)); }
            $status=$failed?'failed':($map?'converted':'skipped');
            foreach (array('map'=>$map,'status'=>$status,'before'=>$before,'after'=>$after,'saved'=>$before-$after,'detail'=>gmdate('Y-m-d H:i:s').' UTC · '.implode(', ',array_unique($notes))) as $key=>$value) { update_post_meta($id,'_qif_'.$key,$value); }
            return $status;
        });
    } catch (\Throwable $e) {
        update_post_meta($id,'_qif_status','failed');
        update_post_meta($id,'_qif_detail','Source or storage unavailable');
        return 'failed';
    }
}
