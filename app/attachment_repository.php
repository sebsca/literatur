<?php
declare(strict_types=1);
require_once __DIR__.'/attachments.php';

function attachment_filename(?string $name, string $fallback):string
{
    $name=$name??'';
    if(!mb_check_encoding($name,'UTF-8'))$name=mb_convert_encoding($name,'UTF-8','Windows-1252');
    $name=basename(str_replace('\\','/',$name));
    $name=trim((string)preg_replace('/[\x00-\x1f\x7f]/u','',$name));
    if(in_array($name,['','.','..'],true))$name=$fallback;
    return mb_substr($name,0,255);
}
function article_attachments(PDO $db,int $articleId):array
{
    return query($db,'SELECT ID,Dateiname,MimeTyp,Dateigroesse,HEX(SHA256) AS sha256,Position,ErstelltAm FROM Anhaenge WHERE ArtikelID=? ORDER BY Position,ID',[$articleId])->fetchAll();
}
function insert_attachment(PDO $db,int $articleId,array $file,int $position):int
{
    $data=$file['data']??null;
    if(!is_string($data) || $data==='')throw new InvalidArgumentException('Die Datei ist leer oder nicht lesbar.');
    if(strlen($data)>MAX_ATTACHMENT_BYTES)throw new InvalidArgumentException('Der Anhang darf höchstens 10 MB groß sein.');
    $kind=file_kind($data);
    if($kind['extension']==='bin')throw new InvalidArgumentException('Bitte ein PDF oder ein Bild im Format JPG, PNG, GIF, WebP oder TIFF auswählen.');
    $name=attachment_filename($file['filename']??null,'Artikel-'.$articleId.'.'.$kind['extension']);
    query($db,'INSERT INTO Anhaenge (ArtikelID,Dateiname,MimeTyp,Dateigroesse,SHA256,Daten,Position) VALUES (?,?,?,?,?,?,?)',[$articleId,$name,$kind['mime'],strlen($data),hash('sha256',$data,true),$data,$position]);
    return (int)$db->lastInsertId();
}
function get_attachment(PDO $db,?int $fileId,?int $articleId):?array
{
    if($fileId!==null){
        $sql='SELECT * FROM Anhaenge WHERE ID=?';$params=[$fileId];
        if($articleId!==null){$sql.=' AND ArtikelID=?';$params[]=$articleId;}
    }else{
        if($articleId===null)throw new InvalidArgumentException('Ungültiger Artikel.');
        $sql='SELECT * FROM Anhaenge WHERE ArtikelID=? ORDER BY Position,ID LIMIT 1';$params=[$articleId];
    }
    $file=query($db,$sql,$params)->fetch();
    if(!$file)return null;
    if(strlen($file['Daten'])!==(int)$file['Dateigroesse'] || !hash_equals($file['SHA256'],hash('sha256',$file['Daten'],true)))throw new RuntimeException('Die Prüfsumme des Anhangs stimmt nicht.');
    return $file;
}
