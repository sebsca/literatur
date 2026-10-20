<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/auth.php';
require __DIR__.'/app/repository.php';
require __DIR__.'/app/citation_import.php';

$error=null;
if($_SERVER['REQUEST_METHOD']==='POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0)>0)$error='Die übermittelten Daten sind zu groß. Bitte einen kleineren Anhang auswählen.';
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='login') {
    try {
        verify_csrf();
        $username=trim((string)($_POST['username']??''));
        if(attempt_login($username,(string)($_POST['password']??''))) {
            session_regenerate_id(true); $_SESSION['user']=$username; $_SESSION['csrf']=bin2hex(random_bytes(32)); redirect();
        }
        $error='Benutzername oder Passwort ist nicht korrekt.';
    } catch(Throwable $ex) { $error=$ex instanceof RuntimeException ? $ex->getMessage() : 'Die Anmeldung ist momentan nicht verfügbar.'; }
}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='logout') {
    verify_csrf(); $_SESSION=[]; session_regenerate_id(true); redirect();
}
$lookups=['journals'=>[],'authors'=>[],'categories'=>[],'projects'=>[]];
$items=[]; $article=null; $index=null; $new=isset($_GET['new']); $ready=false; $deleteConfirmation=null;
$selectedProject=null; $projectDeleteConfirmation=null; $withoutProjectCount=0;
$citationText=''; $citationImport=null;
$draftToken=is_string($_GET['draft']??null) && preg_match('/^[a-f0-9]{32}$/D',$_GET['draft']) ? $_GET['draft'] : null;
$search=mb_substr(trim((string)($_GET['q']??'')),0,200);
$withoutProject=($_GET['project']??null)==='none';
$project=signed_id($_GET['project']??null);
$filter=['q'=>$search]; if($withoutProject)$filter['project']='none'; elseif($project!==null)$filter['project']=$project;
$link=function(array $extra) use($filter):string { return 'index.php?'.http_build_query(array_merge($filter,$extra)); };
if(isset($_SESSION['user'])) {
    if($_SERVER['REQUEST_METHOD']==='GET')unset($_SESSION['delete_confirmation'],$_SESSION['project_delete_confirmation']);
    try {
        $connection=db(); $lookups=lookups($connection);
        $initialProjects=$project===null?[]:array_values(array_filter($lookups['projects'],fn($entry)=>(int)$entry['ID']===$project));
        $selectedProject=$initialProjects[0]??null;
        if($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'', ['request_delete_project','delete_project'], true)) {
            try {
                verify_csrf();
                $projectId=signed_id($_POST['project_id']??null);
                if($projectId===null)throw new InvalidArgumentException('Ungültige Projekt-ID.');
                if($_POST['action']==='delete_project') {
                    $pending=$_SESSION['project_delete_confirmation']??null;
                    if(!$pending || $pending['id']!==$projectId || time()-$pending['created']>600 || !hash_equals($pending['token'],(string)($_POST['confirmation']??'')))throw new RuntimeException('Bitte das Löschen des Projekts erneut bestätigen.');
                    delete_empty_project($connection,$projectId,$pending['name']);
                    unset($_SESSION['project_delete_confirmation']);
                    $_SESSION['notice']='Das Projekt „'.$pending['name'].'“ wurde gelöscht.';
                    redirect('index.php?'.http_build_query(['q'=>$search]));
                }
                if(!$selectedProject || (int)$selectedProject['ID']!==$projectId)throw new RuntimeException('Bitte das Projekt erneut auswählen.');
                if((int)$selectedProject['article_count']!==0)throw new RuntimeException('Das Projekt enthält Artikel und kann nicht gelöscht werden.');
                $projectDeleteConfirmation=bin2hex(random_bytes(32));
                $_SESSION['project_delete_confirmation']=['id'=>$projectId,'name'=>$selectedProject['label'],'token'=>$projectDeleteConfirmation,'created'=>time()];
            } catch(Throwable $ex) {
                unset($_SESSION['project_delete_confirmation']);
                if($ex instanceof InvalidArgumentException || $ex instanceof RuntimeException && !($ex instanceof PDOException))$error=$ex->getMessage();
                else { error_log('Literatur project delete: '.$ex->getMessage()); $error='Das Projekt konnte nicht gelöscht werden. Bitte erneut versuchen oder den Administrator kontaktieren.'; }
            }
        }
        $_SESSION['article_drafts']=array_filter($_SESSION['article_drafts']??[],fn($draft)=>time()-$draft['created']<3600);
        if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='new_article') {
            verify_csrf();
            if(!is_string($_POST['citation_text']??''))throw new InvalidArgumentException('Ungültiger Zitattext.');
            $citationText=trim($_POST['citation_text']??'');
            if($citationText==='')redirect($link(['new'=>1]));
            try {
                $draft=citation_draft(parse_citation($citationText,array_column($lookups['journals'],'Titel')),$lookups);
                $draft['article']['projects']=$initialProjects;
                $token=bin2hex(random_bytes(16));
                if(count($_SESSION['article_drafts'])>=10)array_shift($_SESSION['article_drafts']);
                $_SESSION['article_drafts'][$token]=$draft+['created'=>time()];
                redirect($link(['new'=>1,'draft'=>$token]));
            } catch(InvalidArgumentException $ex) { $error=$ex->getMessage(); }
        }
        if($new && $draftToken!==null && isset($_SESSION['article_drafts'][$draftToken])) {
            $article=$_SESSION['article_drafts'][$draftToken]['article'];
            $citationImport=$_SESSION['article_drafts'][$draftToken]['import'];
            if($_SERVER['REQUEST_METHOD']==='GET' && $article['JournalID']==='new')$_POST['new_journal']=$article['new_journal'];
        }
        elseif($new && $draftToken!==null && $_SERVER['REQUEST_METHOD']==='GET')$error='Der Zitatentwurf ist nicht mehr verfügbar. Bitte den Zitattext erneut einfügen.';
        if($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'', ['request_delete','delete'], true)) {
            $id=signed_id($_POST['id']??null);
            try {
                verify_csrf();
                if($id===null)throw new InvalidArgumentException('Ungültige Artikel-ID.');
                if($_POST['action']==='delete') {
                    $pending=$_SESSION['delete_confirmation']??null;
                    if(!$pending || $pending['id']!==$id || time()-$pending['created']>600 || !hash_equals($pending['token'],(string)($_POST['confirmation']??'')))throw new RuntimeException('Bitte das Löschen des Artikels erneut bestätigen.');
                    delete_article($connection,$id,$pending['version']);
                    unset($_SESSION['delete_confirmation']);
                    $_SESSION['notice']='Der Artikel und seine Anhänge wurden gelöscht.';
                    redirect($link([]));
                }
                $article=load_article($connection,$id);
                if(!$article)throw new RuntimeException('Der Artikel wurde nicht gefunden.');
                if(!hash_equals($article['version'],(string)($_POST['version']??'')))throw new RuntimeException('Der Artikel wurde zwischenzeitlich geändert. Bitte vor dem Löschen erneut prüfen.');
                $deleteConfirmation=bin2hex(random_bytes(32));
                $_SESSION['delete_confirmation']=['id'=>$id,'version'=>$article['version'],'token'=>$deleteConfirmation,'created'=>time()];
            } catch(Throwable $ex) {
                unset($_SESSION['delete_confirmation']);
                if($ex instanceof InvalidArgumentException || $ex instanceof RuntimeException && !($ex instanceof PDOException))$error=$ex->getMessage();
                else { error_log('Literatur delete: '.$ex->getMessage()); $error='Der Artikel konnte nicht gelöscht werden. Bitte erneut versuchen oder den Administrator kontaktieren.'; }
                $article=$id!==null?load_article($connection,$id):null;
            }
            $new=false;
        }
        if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save') {
            verify_csrf();
            $postedId=(string)($_POST['id']??''); $id=$postedId===''?null:signed_id($postedId);
            if($postedId!=='' && $id===null)throw new InvalidArgumentException('Ungültige Artikel-ID.');
            try {
                $id=save_article($connection,$_POST,$id,upload_attachments());
                if($draftToken!==null)unset($_SESSION['article_drafts'][$draftToken]);
                $_SESSION['notice']='Der Artikel wurde gespeichert.';
                redirect($link(['id'=>$id]));
            } catch(Throwable $ex) {
                if($ex instanceof InvalidArgumentException || $ex instanceof RuntimeException && !($ex instanceof PDOException)) $error=$ex->getMessage();
                else { error_log('Literatur save: '.$ex->getMessage()); $error='Der Artikel konnte nicht gespeichert werden. Bitte erneut versuchen oder den Administrator kontaktieren.'; }
                $article=$id!==null ? load_article($connection,$id) : null;
                $article=$article??['ID'=>null,'attachments'=>[]];
                foreach(ARTICLE_FIELDS as $field)$article[$field]=$_POST[$field]??null;
                $article['new_journal']=is_array($_POST['new_journal']??null)?$_POST['new_journal']:[];
                $article['Kopiert']=isset($_POST['Kopiert'])?1:0;
                foreach(RELATIONS as $key=>$_) {
                    $article[$key]=[];
                    foreach(is_array($_POST[$key]??null)?$_POST[$key]:[] as $i=>$v)if(is_scalar($v))$article[$key][]=['label'=>(string)$v,'ID'=>signed_id($_POST[$key.'_ids'][$i]??null)];
                }
                $article['version']=(string)($_POST['version']??'');
                $new=$id===null;
            }
        }
        $items=article_list($connection,$search,$project,$withoutProject);
        if($article===null) {
            $id=signed_id($_GET['id']??null);
            if(!$new && $id===null && $items)$id=(int)$items[0]['ID'];
            if(!$new && $id!==null) {
                $article=load_article($connection,$id);
                if(!$article){http_response_code(404);$error='Der Artikel wurde nicht gefunden.';}
            }
        }
        if($new && $article===null)$article=['ID'=>null,'authors'=>[],'categories'=>[],'projects'=>$initialProjects,'attachments'=>[]];
        foreach($items as $i=>$item)if($article && (string)$item['ID']===(string)($article['ID']??''))$index=$i;
        $total=(int)query($connection,'SELECT COUNT(*) FROM Artikel')->fetchColumn();
        $withoutProjectCount=(int)query($connection,'SELECT COUNT(*) FROM Artikel a WHERE NOT EXISTS (SELECT 1 FROM ProjekteArtikel pa WHERE pa.ArtikelID=a.ID)')->fetchColumn();
        $ready=true;
    } catch(Throwable $ex) { error_log('Literatur page: '.$ex->getMessage()); $error=$ex instanceof PDOException?'Die Datenbank ist momentan nicht erreichbar. Bitte später erneut versuchen.':$ex->getMessage(); }
}
$journal=null;
foreach($lookups['journals'] as $j)if((string)$j['ID']===(string)($article['JournalID']??''))$journal=$j;
$journalValues=$journal??[];
if($journal!==null)$journalValues['URL']=journal_url($journal['URL']);
if(($article['JournalID']??'')==='new' || ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save'))$journalValues=is_array($_POST['new_journal']??null)?$_POST['new_journal']:[];
$journalVersion=$_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save'?(string)($_POST['journal_version']??''):($journal!==null?journal_version($journal):'');
$notice=$_SESSION['notice']??null; unset($_SESSION['notice']);
$projectActionLink=$link($new?array_filter(['new'=>1,'draft'=>$draftToken],fn($v)=>$v!==null):['id'=>$article['ID']??'']);
function field(string $label,string $name,array $article,string $type='text',?int $max=null):void {
    echo '<label class="field"><span>'.e($label).'</span><input type="'.e($type).'" name="'.e($name).'" value="'.e($article[$name]??'').'"'.($max?' maxlength="'.$max.'"':'').'></label>';
}
function relation_editor(string $key,string $title,array $rows,array $choices):void {
    $select='<select name="'.e($key).'[]" aria-label="'.e($title).'"><option value="">Auswählen</option>';
    foreach($choices as $entry)$select.='<option value="'.e($entry['label']).'" data-id="'.e($entry['ID']).'">'.e($entry['label'].($entry['name_count']>1?' [ID: '.$entry['ID'].']':'')).'</option>';
    $select.='<option value="" data-new="true">＋ Neu erfassen</option></select>';
    echo '<section class="relation-editor" data-relation="'.e($key).'"><div class="section-label"><h3>'.e($title).'</h3><button type="button" class="text-button add-row" aria-label="'.e($title).' ergänzen">＋</button></div><div class="relation-rows">';
    if(!$rows)$rows=[['label'=>'']];
    foreach($rows as $row) {
        $id=$row['ID']??null;
        $control=$row['label']!=='' && ($id===null || !in_array($id,array_column($choices,'ID'),true))?'<input name="'.e($key).'[]" value="'.e($row['label']).'" maxlength="50" aria-label="'.e($title).'" placeholder="Neuen Eintrag eingeben">':str_replace(' data-id="'.e($id).'"',' data-id="'.e($id).'" selected',$select);
        echo '<div class="relation-row"><input type="hidden" name="'.e($key).'_ids[]" value="'.e($id).'">'.$control.($key==='authors'?'<button type="button" class="move-row" aria-label="Autor nach oben">↑</button>':'').'<button type="button" class="remove-row" aria-label="Eintrag entfernen">×</button></div>';
    }
    echo '</div><template id="'.e($key).'-control">'.$select.'</template></section>';
}
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Literatur · Artikelverwaltung</title><link rel="icon" href="assets/favicon.ico" sizes="16x16 32x32 48x48"><link rel="icon" href="assets/favicon.svg" type="image/svg+xml" sizes="any"><link rel="stylesheet" href="assets/app.css?v=<?=e(filemtime(__DIR__.'/assets/app.css'))?>"><script src="assets/app.js?v=<?=e(filemtime(__DIR__.'/assets/app.js'))?>" defer></script></head>
<body<?=$ready && $deleteConfirmation===null && $projectDeleteConfirmation===null?' class="has-record-bar"':''?>>
<header class="app-header"><div class="brand"><span class="book-mark" aria-hidden="true">▤</span><div><h1>Literatur</h1><p>Artikel, Journale und Projekte</p></div></div>
<?php if(isset($_SESSION['user'])):?><div class="account"><span><?=e($_SESSION['user'])?></span><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button name="action" value="logout" class="text-button">Abmelden</button></form></div><?php endif;?></header>
<?php if(!isset($_SESSION['user'])):?>
<main class="login-layout"><section class="login-card"><span class="eyebrow">Literaturverwaltung</span><h2>Anmelden</h2><p>Bitte melden Sie sich an, um Ihre Artikel zu öffnen.</p>
<?php if($error):?><div class="alert error" role="alert"><?=e($error)?></div><?php endif;?>
<?php if(!array_filter(users())):?><div class="alert info">Der Zugang ist noch nicht eingerichtet. Bitte wenden Sie sich an den Administrator.</div><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="login"><label class="field"><span>Benutzername</span><input name="username" autocomplete="username" required value="<?=e($_POST['username']??'sscala')?>"></label><label class="field"><span>Passwort</span><input name="password" type="password" autocomplete="current-password" required></label><button class="button primary login-button">Anmelden</button></form></section></main>
<?php else:?>
<main class="app-main">

<div class="page-heading"><div><span class="eyebrow">Literaturverwaltung</span><h2><?=$new?'Neuer Artikel':'Artikel'?></h2></div><div class="heading-actions"><?php if($ready):?><span class="count-badge"><?=e($total)?> Artikel</span><form id="new-article-form" class="new-article-form" method="post" action="<?=e($link($new?['new'=>1]:['id'=>$article['ID']??'']))?>"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="new_article"><label class="citation-paste"><span class="sr-only">Zitattext für einen neuen Artikel</span><textarea name="citation_text" rows="2" maxlength="100000" placeholder="Zitat einfügen (optional)" aria-describedby="citation-formats"><?=e($citationText)?></textarea><span id="citation-formats">NLM · AMA · APA · MLA · nbib · RIS</span></label><button class="button secondary">＋ Neuer Artikel</button></form><?php endif;?></div></div>
<?php if($error):?><div class="alert error" role="alert"><?=e($error)?></div><?php endif;?>
<?php if($notice):?><div class="alert success" role="status"><?=e($notice)?></div><?php endif;?>
<?php if($citationImport!==null):?><section class="alert info citation-import-summary" aria-label="Übernommenes Zitat"><p><strong>Format erkannt: <?=e($citationImport['format'])?>.</strong> Die erkannten Angaben wurden übernommen. Bitte vor dem Speichern prüfen.</p><?php if($citationImport['warnings']):?><ul><?php foreach($citationImport['warnings'] as $warning):?><li><?=e($warning)?></li><?php endforeach;?></ul><?php endif;?><details><summary>Originalen Zitattext anzeigen</summary><pre><?=e($citationImport['source'])?></pre></details></section><?php endif;?>
<?php if($ready):?>
<?php if($projectDeleteConfirmation!==null):?>
<section class="panel delete-confirmation" aria-labelledby="project-delete-heading"><h3 id="project-delete-heading">Leeres Projekt endgültig löschen?</h3><p class="delete-title"><?=e($selectedProject['label'])?></p><p>Dieses Projekt hat keine gespeicherten Artikel und wird endgültig gelöscht.</p><form method="post" action="<?=e($projectActionLink)?>"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?=e($selectedProject['ID'])?>"><input type="hidden" name="confirmation" value="<?=e($projectDeleteConfirmation)?>"><div class="record-actions"><a class="button secondary" href="<?=e($projectActionLink)?>">Abbrechen</a><button class="button danger">Endgültig löschen</button></div></form></section>
<?php elseif($deleteConfirmation!==null):?>
<section class="panel delete-confirmation" aria-labelledby="delete-heading"><h3 id="delete-heading">Artikel endgültig löschen?</h3><p class="delete-title"><?=e($article['Titel'])?></p><p class="muted">Artikel Nr. <?=e($article['ID'])?> · <?=count($article['attachments'])?> Anhänge</p><p>Der Artikel, seine Anhänge und seine Zuordnungen zu Autoren, Kategorien und Projekten werden endgültig gelöscht. Autoren, Journale, Kategorien und Projekte bleiben erhalten.</p><form method="post" action="<?=e($link(['id'=>$article['ID']]))?>"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=e($article['ID'])?>"><input type="hidden" name="confirmation" value="<?=e($deleteConfirmation)?>"><div class="record-actions"><a class="button secondary" href="<?=e($link(['id'=>$article['ID']]))?>">Abbrechen</a><button class="button danger">Endgültig löschen</button></div></form></section>
<?php else:?>
<form id="filter-form" method="get"></form>
<form id="article-form" method="post" enctype="multipart/form-data" data-unsaved="<?=$error || $citationImport!==null?'true':'false'?>" action="<?=e($link($new?array_filter(['new'=>1,'draft'=>$draftToken],fn($v)=>$v!==null):['id'=>$article['ID']??'']))?>">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($article['ID']??'')?>"><input type="hidden" name="version" value="<?=e($article['version']??'')?>">
<div class="workspace">
<section class="panel article-panel"><div class="panel-heading"><h3>Artikel</h3><span class="muted"><?=($article['ID']??null)!==null?'Nr. '.e($article['ID']):'Neuer Datensatz'?></span></div>
<fieldset class="article-fields" <?=$article===null?'disabled':''?>>
<label class="field"><span>Titel <span class="required">*</span></span><textarea class="title-field" name="Titel" maxlength="255" required placeholder="Titel des Artikels"><?=e($article['Titel']??'')?></textarea></label>
<label class="field"><span>DOI <a id="doi-open" class="text-button" href="<?=e(doi_url($article['DOI']??''))?>" <?=doi_url($article['DOI']??'')===''?'hidden':''?> target="_blank" rel="noopener noreferrer">Artikel öffnen ↗</a></span><input id="doi-input" name="DOI" maxlength="255" value="<?=e($article['DOI']??'')?>" placeholder="z. B. 10.1000/xyz" spellcheck="false"></label>
<label class="field"><span>Abstract</span><textarea class="abstract-field" name="Abstract" placeholder="Zusammenfassung des Artikels"><?=e($article['Abstract']??'')?></textarea></label>
<div class="article-details"><?php relation_editor('authors','Autoren',$article['authors']??[],$lookups['authors']);?>
<div class="article-classification"><?php relation_editor('projects','Projekte',$article['projects']??[],$lookups['projects']);relation_editor('categories','Kategorien',$article['categories']??[],$lookups['categories']);?></div>
<section class="attachment-box"><div class="section-label"><h3>Anhänge<?=!empty($article['attachments'])?' ('.count($article['attachments']).')':''?></h3></div>
<?php if(!empty($article['attachments'])):?><div class="attachment-list">
<?php foreach($article['attachments'] as $file):?><div class="file-card"><span class="file-icon" aria-hidden="true">▧</span><span class="file-name"><?=e($file['Dateiname'])?></span><span class="muted"><?=e($file['Dateigroesse']<1024?$file['Dateigroesse'].' B':number_format($file['Dateigroesse']/1024,0,',','.').' KB')?></span><a href="attachment.php?file=<?=e($file['ID'])?>&amp;id=<?=e($article['ID'])?>" target="_blank" rel="noopener" class="text-button">Öffnen ↗</a><a href="attachment.php?file=<?=e($file['ID'])?>&amp;id=<?=e($article['ID'])?>&amp;download=1" class="text-button">Herunterladen</a><label class="checkbox"><input type="checkbox" name="remove_attachments[]" value="<?=e($file['ID'])?>" <?=in_array((string)$file['ID'],is_array($_POST['remove_attachments']??null)?array_map('strval',$_POST['remove_attachments']):[],true)?'checked':''?>> Entfernen</label></div><?php endforeach;?></div>
<?php else:?><div class="empty-file"><span aria-hidden="true">▧</span>Kein Anhang</div><?php endif;?>
<div id="attachment-dropzone" class="upload-dropzone"><p class="drop-hint" hidden>Dateien hier ablegen</p><label class="upload-label" for="attachment-input"><span>Dateien auswählen</span><input id="attachment-input" type="file" name="attachments[]" multiple data-max-files="<?=e(min(20,(int)ini_get('max_file_uploads')))?>" data-max-bytes="<?=e(MAX_ATTACHMENT_BYTES)?>" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.tif,.tiff" aria-describedby="attachment-limits attachment-feedback"></label><p id="attachment-feedback" class="upload-feedback" role="alert" hidden></p><div id="pending-attachments" hidden><p class="upload-limit" role="status" aria-live="polite"></p><ul class="pending-files" aria-label="Neue Anhänge"></ul></div></div><span id="attachment-limits" class="upload-limit">PDF oder Bild · max. <?=e(ini_get('upload_max_filesize'))?>B je Datei · insgesamt <?=e(ini_get('post_max_size'))?>B</span><?php if(!empty($article['attachments'])):?><span class="upload-limit">Markierte Anhänge werden beim Speichern entfernt.</span><?php endif;?><label class="checkbox"><input type="checkbox" name="Kopiert" value="1" <?=!empty($article['Kopiert'])?'checked':''?>> Kopiert</label></section></div>
<label class="field citation-field"><span>Zitat <button type="button" class="text-button" id="copy-citation">Kopieren</button></span><textarea id="citation" readonly><?=e(citation($article??[],$lookups['journals']))?></textarea></label>
</fieldset></section>
<div class="right-column"><section class="panel filter-panel"><div class="panel-heading"><h3>Selektieren</h3><a class="text-button" href="index.php">Zurücksetzen</a></div><div class="filter-fields"><label class="field"><span>Suche</span><div class="search-row"><input type="search" name="q" value="<?=e($search)?>" placeholder="Titel, Abstract, DOI, Autor, Journal oder Kategorie" form="filter-form"><button class="button primary" form="filter-form">Suchen</button></div></label>
<div class="field"><span id="project-filter-label">Projekt</span><div class="project-filter-row"><select name="project" form="filter-form" class="filter-select" aria-labelledby="project-filter-label">
<button type="button"><selectedcontent></selectedcontent></button>
<option value="">Alle Projekte</option><option value="none" <?=$withoutProject?'selected':''?>>Ohne Projekt <span class="project-count"><?=e($withoutProjectCount)?></span></option><?php foreach($lookups['projects'] as $p):?><option value="<?=e($p['ID'])?>" <?=$project!==null && $project===$p['ID']?'selected':''?>><?=e($p['label'])?> <span class="project-count"><?=e($p['article_count'])?></span></option><?php endforeach;?></select><?php if($selectedProject && (int)$selectedProject['article_count']===0):?><button class="button danger" type="submit" form="project-delete-request-form">Projekt löschen</button><?php endif;?></div></div>
<label class="field"><span>Artikel</span><select name="id" form="filter-form" id="article-select"><option value=""><?=$new?'Artikel auswählen':($items?'Bitte auswählen':'Keine Treffer')?></option><?php foreach($items as $item):?><option value="<?=e($item['ID'])?>" <?=($article['ID']??null)!==null && (string)$article['ID']===(string)$item['ID']?'selected':''?>><?=e($item['Titel'])?></option><?php endforeach;?></select></label></div></section>
<section class="panel journal-panel"><div class="panel-heading"><h3>Journal</h3></div><fieldset <?=$article===null?'disabled':''?>>
<label class="field"><span>Journal</span><select name="JournalID" id="journal-select"><option value="">Kein Journal ausgewählt</option><?php foreach($lookups['journals'] as $j):?><option value="<?=e($j['ID'])?>" <?=($article['JournalID']??null)!==null && (string)$article['JournalID']===(string)$j['ID']?'selected':''?> data-version="<?=e(journal_version($j))?>" data-issn="<?=e($j['ISSN'])?>" data-publisher="<?=e($j['Verlag'])?>" data-impact="<?=e($j['Impact'])?>" data-url="<?=e(journal_url($j['URL']))?>" data-title="<?=e($j['Titel'])?>"><?=e($j['Titel'])?></option><?php endforeach;?><option value="new" <?=($article['JournalID']??'')==='new'?'selected':''?>>＋ Neues Journal erfassen</option></select></label>
<label class="field" id="new-journal-title" <?=($article['JournalID']??'')==='new'?'':'hidden'?>><span>Titel des neuen Journals</span><input name="new_journal[Titel]" maxlength="100" value="<?=e($_POST['new_journal']['Titel']??'')?>"></label>
<div class="field-grid"><?php field('Jahrgang','Jahrgang',$article??[],'number');field('Band','Band',$article??[],'text',50);field('Seite von','SeiteVon',$article??[],'number');field('bis','SeiteBis',$article??[],'number');?></div>
<hr><div class="field-grid"><?php field('Standort','Standort',$article??[],'text',50);field('Signatur','Signatur',$article??[],'text',50);?><label class="field"><span>ISSN</span><input id="journal-issn" name="new_journal[ISSN]" maxlength="50" <?=($article['JournalID']??'')===''?'disabled':''?> value="<?=e($journalValues['ISSN']??'')?>"></label><label class="field"><span>Impact</span><input id="journal-impact" name="new_journal[Impact]" inputmode="decimal" <?=($article['JournalID']??'')===''?'disabled':''?> value="<?=e($journalValues['Impact']??'')?>"></label></div>
<label class="field"><span>Verlag</span><input id="journal-publisher" name="new_journal[Verlag]" maxlength="50" <?=($article['JournalID']??'')===''?'disabled':''?> value="<?=e($journalValues['Verlag']??'')?>"></label><label class="field"><span>URL <a id="journal-open" class="text-button" href="<?=e(is_web_url(journal_url($journalValues['URL']??''))?journal_url($journalValues['URL']):'#')?>" <?=is_web_url(journal_url($journalValues['URL']??''))?'':'hidden'?> target="_blank" rel="noopener noreferrer">Öffnen ↗</a></span><input id="journal-url" name="new_journal[URL]" type="text" maxlength="3000" <?=($article['JournalID']??'')===''?'disabled':''?> value="<?=e($journalValues['URL']??'')?>"></label><input type="hidden" id="journal-version" name="journal_version" value="<?=e($journalVersion)?>"><p class="field-note">ISSN, Impact, Verlag und URL werden beim Speichern für das ausgewählte Journal geändert und gelten für alle zugehörigen Artikel.</p>
</fieldset></section></div></div>

</form>
<?php if($selectedProject && (int)$selectedProject['article_count']===0):?><form id="project-delete-request-form" method="post" action="<?=e($projectActionLink)?>"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="request_delete_project"><input type="hidden" name="project_id" value="<?=e($selectedProject['ID'])?>"></form><?php endif;?>
<?php if(($article['ID']??null)!==null):?><form id="delete-request-form" method="post" action="<?=e($link(['id'=>$article['ID']]))?>"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="request_delete"><input type="hidden" name="id" value="<?=e($article['ID'])?>"><input type="hidden" name="version" value="<?=e($article['version']??'')?>"></form><?php endif;?>
<?php if($article===null):?><p class="empty-message">Keine passenden Artikel gefunden. Ändern Sie die Auswahl oder erfassen Sie einen neuen Artikel.</p><?php endif;?>
<?php endif;?>
<?php endif;?>
<?php if($ready && $deleteConfirmation===null && $projectDeleteConfirmation===null):?>
<nav class="record-bar" aria-label="Datensatznavigation"><div class="record-navigation"><?php if($index!==null && $index>0):?><a class="nav-button" href="<?=$link(['id'=>$items[0]['ID']])?>" aria-label="Erster Artikel">«</a><a class="nav-button" href="<?=$link(['id'=>$items[$index-1]['ID']])?>" aria-label="Vorheriger Artikel">‹</a><?php else:?><span class="nav-button disabled">«</span><span class="nav-button disabled">‹</span><?php endif;?><span class="record-count"><?=$new?'Neuer Artikel':($index!==null?($index+1).' von '.count($items):count($items).' Treffer')?></span><?php if($index!==null && $index<count($items)-1):?><a class="nav-button" href="<?=$link(['id'=>$items[$index+1]['ID']])?>" aria-label="Nächster Artikel">›</a><a class="nav-button" href="<?=$link(['id'=>$items[count($items)-1]['ID']])?>" aria-label="Letzter Artikel">»</a><?php else:?><span class="nav-button disabled">›</span><span class="nav-button disabled">»</span><?php endif;?></div><span id="save-state" class="save-state" role="status">Alle Änderungen gespeichert</span><div class="record-actions"><?php if(($article['ID']??null)!==null):?><button class="button danger" type="submit" form="delete-request-form">Artikel löschen</button><?php endif;?><a class="button secondary" href="<?=$link(($article['ID']??null)!==null?['id'=>$article['ID']]:[])?>">Abbrechen</a><button class="button primary" type="submit" form="article-form" <?=$article===null?'disabled':''?>>Speichern</button></div></nav>
<?php endif;?>
</main>
<?php endif;?>
<footer class="app-footer">Literatur · Wissenschaftliche Artikelverwaltung</footer>
</body></html>
