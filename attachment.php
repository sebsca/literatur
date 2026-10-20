<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/repository.php';
require_login();
session_write_close();
try {
    $id=signed_id($_GET['id']??null);
    $fileId=signed_id($_GET['file']??null);
    if(isset($_GET['id']) && $id===null || isset($_GET['file']) && ($fileId===null || $fileId<1))throw new InvalidArgumentException('Ungültiger Anhang.');
    $file=get_attachment(db(),$fileId,$id);
    if(!$file){http_response_code(404);exit('Kein Anhang vorhanden.');}
    $kind=file_kind($file['Daten']);$id=$file['ArtikelID'];
    if($kind['mime']!==$file['MimeTyp'])throw new RuntimeException('Der Dateityp des Anhangs stimmt nicht.');
    $name=attachment_filename($file['Dateiname'],'Artikel-'.$id.'.'.$kind['extension']);
    $inline=$kind['inline'] && !isset($_GET['download']);
    header('Content-Type: '.$kind['mime']);
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="Artikel-'.$id.'.'.$kind['extension'].'"; filename*=UTF-8\'\''.rawurlencode($name));
    header('Content-Length: '.$file['Dateigroesse']);
    echo $file['Daten'];
} catch(Throwable $error) {
    error_log('Literatur attachment: '.$error->getMessage());
    http_response_code(422); echo 'Der Anhang konnte nicht geöffnet werden. Bitte den Administrator kontaktieren.';
}
