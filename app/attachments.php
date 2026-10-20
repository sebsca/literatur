<?php
declare(strict_types=1);

const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

function u16(string $data, int $offset): int
{
    if ($offset < 0 || $offset + 2 > strlen($data)) throw new RuntimeException('Unvollständiger Dateicontainer.');
    return unpack('v', substr($data, $offset, 2))[1];
}
function u32(string $data, int $offset): int
{
    if ($offset < 0 || $offset + 4 > strlen($data)) throw new RuntimeException('Unvollständiger Dateicontainer.');
    return unpack('V', substr($data, $offset, 4))[1];
}

// MS-CFB: reconstruct FAT and MiniFAT streams rather than slicing fragmented files.
function compound_streams(string $data): array
{
    $shift = u16($data, 30);
    if (!in_array($shift, [9,12], true) || u16($data, 32) !== 6) throw new RuntimeException('Unbekannter OLE-Container.');
    $size = 1 << $shift;
    $sector = function(int $id) use ($data, $size): string {
        $offset = ($id + 1) * $size;
        if ($id < 0 || $offset + $size > strlen($data)) throw new RuntimeException('Beschädigter OLE-Sektor.');
        return substr($data, $offset, $size);
    };
    $difat = array_values(unpack('V*', substr($data, 76, 436)));
    $next = u32($data, 68); $seen = [];
    for ($n=0; $n<u32($data,72); $n++) {
        if (isset($seen[$next])) throw new RuntimeException('Beschädigte OLE-Sektorkette.');
        $seen[$next] = true;
        $ids = array_values(unpack('V*', $sector($next)));
        $next = array_pop($ids);
        $difat = array_merge($difat, $ids);
    }
    $fat = [];
    foreach ($difat as $id) if ($id < 0xfffffffa) $fat = array_merge($fat, array_values(unpack('V*', $sector($id))));
    $chain = function(int $id, array $allocation, callable $reader): string {
        $output = ''; $seen = [];
        while ($id < 0xfffffffa) {
            if (isset($seen[$id]) || !isset($allocation[$id])) throw new RuntimeException('Beschädigte OLE-Sektorkette.');
            $seen[$id] = true;
            $output .= $reader($id);
            if (strlen($output) > 33554432) throw new RuntimeException('OLE-Container ist zu groß.');
            $id = $allocation[$id];
        }
        return $output;
    };
    $directory = $chain(u32($data,48), $fat, $sector);
    $entries = []; $root = null;
    for ($pos=0; $pos+128<=strlen($directory); $pos+=128) {
        $entry = substr($directory,$pos,128); $length = u16($entry,64); $type = ord($entry[66]);
        if ($length < 2 || $length > 64 || !in_array($type,[2,5],true)) continue;
        $item = ['name'=>mb_convert_encoding(substr($entry,0,$length-2),'UTF-8','UTF-16LE'),'type'=>$type,'sector'=>u32($entry,116),'size'=>u32($entry,120)];
        if ($type===5) $root=$item; else $entries[]=$item;
    }
    $miniFatData = $chain(u32($data,60), $fat, $sector);
    $miniFat = $miniFatData !== '' ? array_values(unpack('V*',$miniFatData)) : [];
    $miniStream = $root ? substr($chain($root['sector'],$fat,$sector),0,$root['size']) : '';
    $miniReader = function(int $id) use ($miniStream): string {
        if ($id*64+64>strlen($miniStream)) throw new RuntimeException('Beschädigter OLE-Minisektor.');
        return substr($miniStream,$id*64,64);
    };
    $streams=[];
    foreach($entries as $entry) {
        if ($entry['size']===0) { $streams[$entry['name']]=''; continue; }
        $stream=$entry['size']<u32($data,56) ? $chain($entry['sector'],$miniFat,$miniReader) : $chain($entry['sector'],$fat,$sector);
        $streams[$entry['name']]=substr($stream,0,$entry['size']);
    }
    return $streams;
}

function package_file(string $data): ?array
{
    // OLE Packager: flags, label, source path, flags, temporary path, payload length.
    if (strlen($data)<20 || u16($data,0)!==2) return null;
    $pos=2; $end=strpos($data,"\0",$pos); if($end===false)return null;
    $filename=substr($data,$pos,$end-$pos); $pos=$end+1;
    $end=strpos($data,"\0",$pos); if($end===false)return null;
    $pos=$end+1+4;
    if($pos+4>strlen($data))return null;
    $pathLength=u32($data,$pos); $pos+=4+$pathLength;
    if($pos+4>strlen($data))return null;
    $length=u32($data,$pos); $pos+=4;
    if($length===0 || $pos+$length>strlen($data))return null;
    return ['data'=>substr($data,$pos,$length),'filename'=>basename(str_replace('\\','/',$filename))];
}

function unwrap_attachment(string $blob): array
{
    $cfbMagic=hex2bin('d0cf11e0a1b11ae1');
    if (str_starts_with($blob,"\x15\x1c")) {
        $pos=u16($blob,2);
        if(u32($blob,$pos)!==0x501 || u32($blob,$pos+4)!==2) throw new RuntimeException('Unbekanntes Access-Anhangsformat.');
        $pos+=8;
        for($n=0;$n<3;$n++){ $len=u32($blob,$pos); $pos+=4+$len; }
        $length=u32($blob,$pos); $pos+=4;
        if($pos+$length>strlen($blob))throw new RuntimeException('Unvollständiger Access-Anhang.');
        $blob=substr($blob,$pos,$length);
        if(!str_starts_with($blob,$cfbMagic)) {
            $package=package_file($blob);
            if($package)return $package;
        }
    }
    if(str_starts_with($blob,$cfbMagic)) {
        $streams=compound_streams($blob);
        foreach($streams as $name=>$stream) {
            if(strtolower($name)==='contents') {
                // Imaging.Document stores its TIFF behind an additional Wang header.
                if(str_starts_with($stream,"EZ\x04\0\0\0") && strlen($stream)>10) {
                    $offset=u16($stream,6)+10;
                    $signature=substr($stream,$offset,4);
                    if($signature==="II*\0" || $signature==="MM\0*")$stream=substr($stream,$offset);
                }
                return ['data'=>$stream,'filename'=>null];
            }
        }
        if(isset($streams["\x01Ole10Native"])) {
            $native=$streams["\x01Ole10Native"];
            $package=package_file(substr($native,4));
            if($package)return $package;
        }
        throw new RuntimeException('Dieser OLE-Anhang enthält kein unterstütztes Dateiformat.');
    }
    return ['data'=>$blob,'filename'=>null];
}
function file_kind(string $data): array
{
    if(str_starts_with($data,'%PDF-'))return ['mime'=>'application/pdf','extension'=>'pdf','inline'=>true];
    if(str_starts_with($data,"II*\0") || str_starts_with($data,"MM\0*"))return ['mime'=>'image/tiff','extension'=>'tif','inline'=>false];
    if(str_starts_with($data,"\x89PNG\r\n\x1a\n"))return ['mime'=>'image/png','extension'=>'png','inline'=>true];
    if(str_starts_with($data,"\xff\xd8\xff"))return ['mime'=>'image/jpeg','extension'=>'jpg','inline'=>true];
    if(str_starts_with($data,'GIF87a') || str_starts_with($data,'GIF89a'))return ['mime'=>'image/gif','extension'=>'gif','inline'=>true];
    if(str_starts_with($data,'RIFF') && substr($data,8,4)==='WEBP')return ['mime'=>'image/webp','extension'=>'webp','inline'=>true];
    return ['mime'=>'application/octet-stream','extension'=>'bin','inline'=>false];
}
function upload_attachments(): array
{
    $files=$_FILES['attachments']??null;
    if(!$files)return [];
    foreach(['name','error','size','tmp_name'] as $key)if(!is_array($files[$key]??null))throw new InvalidArgumentException('Ungültiger Upload.');
    if(count($files['name'])>20)throw new InvalidArgumentException('Bitte höchstens 20 Dateien auf einmal hinzufügen.');
    $uploads=[];
    foreach($files['name'] as $i=>$name) {
        $error=$files['error'][$i]??null;
        if($error===UPLOAD_ERR_NO_FILE)continue;
        if($error!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Ein Anhang konnte nicht hochgeladen werden. Bitte die angezeigte Größenbegrenzung beachten.');
        if(($files['size'][$i]??0)>MAX_ATTACHMENT_BYTES)throw new InvalidArgumentException('Der Anhang darf höchstens 10 MB groß sein.');
        $tmp=$files['tmp_name'][$i]??'';
        if(!is_string($tmp) || !is_uploaded_file($tmp))throw new InvalidArgumentException('Ungültiger Upload.');
        $data=file_get_contents($tmp);
        if($data===false || $data==='')throw new InvalidArgumentException('Die Datei ist leer oder nicht lesbar.');
        if(strlen($data)>MAX_ATTACHMENT_BYTES)throw new InvalidArgumentException('Der Anhang darf höchstens 10 MB groß sein.');
        if(file_kind($data)['extension']==='bin')throw new InvalidArgumentException('Bitte ein PDF oder ein Bild im Format JPG, PNG, GIF, WebP oder TIFF auswählen.');
        if(!is_string($name))throw new InvalidArgumentException('Ungültiger Dateiname.');
        $uploads[]=['data'=>$data,'filename'=>$name];
    }
    return $uploads;
}
