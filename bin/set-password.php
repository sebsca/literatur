<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../app/bootstrap.php';
$username=$argv[1]??'sscala';
if(!preg_match('/^[a-zA-Z0-9_.@-]{1,80}$/',$username)){fwrite(STDERR,"Ungültiger Benutzername.\n");exit(1);}
$interactive=function_exists('stream_isatty')&&stream_isatty(STDIN);
if($interactive)system('stty -echo');
try {
    fwrite(STDOUT,"Neues Passwort für $username (mindestens 8 Zeichen): ");
    $password=rtrim((string)fgets(STDIN),"\r\n");
    fwrite(STDOUT,"\nPasswort wiederholen: ");
    $repeat=rtrim((string)fgets(STDIN),"\r\n");
} finally { if($interactive)system('stty echo'); fwrite(STDOUT,"\n"); }
if(mb_strlen($password,'UTF-8')<8 || $password!==$repeat){fwrite(STDERR,"Die Passwörter stimmen nicht überein oder sind zu kurz.\n");exit(1);}
$path=APP_ROOT.'/.private/users.json';
$handle=fopen($path,'c+');
if(!$handle || !flock($handle,LOCK_EX)){fwrite(STDERR,"Benutzerdatei ist nicht beschreibbar.\n");exit(1);}
$users=json_decode(stream_get_contents($handle),true)?:[];
$users[$username]=password_hash($password,PASSWORD_DEFAULT);
rewind($handle);ftruncate($handle,0);fwrite($handle,json_encode($users,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fflush($handle);flock($handle,LOCK_UN);fclose($handle);
fwrite(STDOUT,"Der Zugang für $username ist eingerichtet.\n");
