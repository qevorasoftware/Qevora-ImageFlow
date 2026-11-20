<?php
namespace Qevora\ImageFlow;
defined('ABSPATH') || exit;

// Only inspect metadata containers. Skip image pixels, movie payloads and large packets.
final class Origin_Reader {
    private $file;
    private $remaining=4096;
    private $budget=1048576;
    private $labels=array();

    public function read($path) {
        if (!is_string($path) || !$path || wp_is_stream($path) || !is_file($path) || !is_readable($path) || !class_exists('DOMDocument')) { return 'unknown'; }
        try {
            $this->file=new \SplFileObject($path,'rb');
            $end=$this->file->getSize();
            if ($end<12) { return 'unknown'; }
            $header=$this->at(0,12);
            if (substr($header,0,2)==="\xff\xd8") { $this->jpeg($end); }
            elseif (substr($header,0,8)==="\x89PNG\r\n\x1a\n") { $this->chunks(8,$end,false); }
            elseif ('RIFF'===substr($header,0,4) && 'WEBP'===substr($header,8,4)) { $this->chunks(12,$end,true); }
            elseif ('ftyp'===substr($header,4,4) || in_array(substr($header,4,4),array('moov','wide','mdat','free'),true)) { $this->boxes(0,$end,0); }
        } catch (\Throwable $e) { return 'unknown'; }
        return count($this->labels)===1?key($this->labels):'unknown';
    }

    private function at($offset,$len) {
        if ($this->file->fseek($offset)!==0 || $len<1) { return ''; }
        $bytes=$this->file->fread($len);
        return is_string($bytes) && strlen($bytes)===$len?$bytes:'';
    }

    private function packet($start,$len) {
        if ($len<1 || $len>262144 || $len>$this->budget) { return ''; }
        $this->budget-=$len;
        return $this->at($start,$len);
    }

    private function jpeg($end) {
        $pos=2;
        $prefix="http://ns.adobe.com/xap/1.0/\0";
        while ($pos+4<=$end && $this->remaining-->0) {
            $h=$this->at($pos,4);
            if (strlen($h)!==4 || "\xff"!==$h[0] || "\xda"===$h[1] || "\xd9"===$h[1]) { return; }
            if ("\xff"===$h[1]) { ++$pos; continue; }
            $size=unpack('n',$h,2)[1];
            if ($size<2 || $pos+2+$size>$end) { return; }
            if ("\xe1"===$h[1] && $size>=2+strlen($prefix) && $this->at($pos+4,strlen($prefix))===$prefix) {
                $this->xmp($this->packet($pos+4+strlen($prefix),$size-2-strlen($prefix)));
            }
            $pos+=2+$size;
        }
    }

    private function chunks($pos,$end,$webp) {
        while ($pos+8<=$end && $this->remaining-->0) {
            $h=$this->at($pos,8);
            if (!$h) { return; }
            $size=$webp?unpack('V',$h,4)[1]:unpack('N',$h)[1];
            $tag=$webp?substr($h,0,4):substr($h,4,4);
            $next=$pos+8+$size+($webp?$size%2:4);
            if ($next>$end) { return; }
            if ($webp && 'XMP '===$tag) { $this->xmp($this->packet($pos+8,$size)); }
            elseif (!$webp && 'iTXt'===$tag) {
                $prefix="XML:com.adobe.xmp\0";
                if ($size>strlen($prefix)+3 && $this->at($pos+8,strlen($prefix))===$prefix) {
                    $payload=$this->packet($pos+8+strlen($prefix),$size-strlen($prefix));
                    if (strlen($payload)>=4 && substr($payload,0,2)==="\0\0") {
                        $parts=explode("\0",substr($payload,2),3);
                        if (isset($parts[2])) { $this->xmp($parts[2]); }
                    }
                }
            }
            $pos=$next;
        }
    }

    private function boxes($pos,$end,$depth) {
        if ($depth>5) { return; }
        while ($pos+8<=$end && $this->remaining-->0) {
            $h=$this->at($pos,8);
            if (!$h) { return; }
            $size=unpack('N',$h)[1]; $type=substr($h,4); $data=$pos+8;
            if (1===$size) {
                $ext=$this->at($data,8);
                if (!$ext) { return; }
                $words=unpack('Nhi/Nlo',$ext);
                $size=$words['hi']*4294967296+$words['lo']; $data+=8;
            } elseif (0===$size) { $size=$end-$pos; }
            if ($size<$data-$pos || $size>$end-$pos) { return; }
            $next=$pos+$size;
            if ('uuid'===$type && $next-$data>=16 && bin2hex($this->at($data,16))==='be7acfcb97a942e89c71999491e3afac') {
                $this->xmp($this->packet($data+16,$next-$data-16));
            } elseif ('XMP_'===$type) { $this->xmp($this->packet($data,$next-$data)); }
            elseif (in_array($type,array('moov','udta'),true)) { $this->boxes($data,$next,$depth+1); }
            $pos=$next;
        }
    }

    private function xmp($xml) {
        if (!$xml || false!==stripos($xml,'<!DOCTYPE') || false!==strpos($xml,"\0")) { return; }
        $doc=new \DOMDocument();
        if (!$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) { return; }
        $query=new \DOMXPath($doc);
        $query->registerNamespace('rdf','http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $query->registerNamespace('i','http://iptc.org/std/Iptc4xmpExt/2008-02-29/');
        $terms=array('trainedAlgorithmicMedia'=>'generated','compositeWithTrainedAlgorithmicMedia'=>'edited');
        foreach ($query->query('//rdf:RDF/rdf:Description/@i:DigitalSourceType | //rdf:RDF/rdf:Description/i:DigitalSourceType | //rdf:RDF/rdf:Description/i:DigitalSourceType/@rdf:resource') as $node) {
            if (preg_match('~^https?://cv\.iptc\.org/newscodes/digitalsourcetype/([A-Za-z]+)$~D',trim($node->nodeValue),$match) && isset($terms[$match[1]])) {
                $this->labels[$terms[$match[1]]]=true;
            }
        }
    }
}
