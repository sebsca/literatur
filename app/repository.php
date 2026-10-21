<?php
declare(strict_types=1);
require_once __DIR__ . '/attachment_repository.php';

const ARTICLE_FIELDS = ['Titel','DOI','Abstract','JournalID','Jahrgang','Band','SeiteVon','SeiteBis','Standort','Signatur','Kopiert'];
const RELATIONS = [
    'authors' => ['Autoren', 'Name', 'AutorArtikel', 'AutorID'],
    'categories' => ['Kategorien', 'Kategorie', 'KategorieArtikel', 'KategorieID'],
    'projects' => ['Projekte', 'Projekt', 'ProjekteArtikel', 'ProjektID'],
];

function query(PDO $db, string $sql, array $params = []): PDOStatement
{
    $s = $db->prepare($sql);
    foreach(array_values($params) as $i=>$value)$s->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:($value===null?PDO::PARAM_NULL:PDO::PARAM_STR));
    $s->execute(); return $s;
}
function lookups(PDO $db): array
{
    $result = [];
    foreach (RELATIONS as $key => [$table, $name]) {
        $count = $key === 'projects' ? ', (SELECT COUNT(*) FROM ProjekteArtikel pa WHERE pa.ProjektID=m.ID) AS article_count' : '';
        $result[$key] = query($db, "SELECT m.ID, m.`$name` AS label,COUNT(*) OVER (PARTITION BY m.`$name`) AS name_count$count FROM `$table` m ORDER BY m.`$name`, m.ID")->fetchAll();
    }
    $result['journals'] = query($db, 'SELECT * FROM Journale ORDER BY Titel, ID')->fetchAll();
    return $result;
}
function article_list(PDO $db, string $search, ?int $project, bool $withoutProject = false, bool $withoutAttachments = false, bool $notCopied = false): array
{
    $where = []; $params = [];
    if ($search !== '') {
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $where[] = "(a.Titel LIKE ? ESCAPE '!' OR a.Abstract LIKE ? ESCAPE '!' OR a.DOI LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM AutorArtikel aa JOIN Autoren au ON au.ID=aa.AutorID WHERE aa.ArtikelID=a.ID AND au.Name LIKE ? ESCAPE '!') OR EXISTS (SELECT 1 FROM Journale j WHERE j.ID=a.JournalID AND j.Titel LIKE ? ESCAPE '!') OR EXISTS (SELECT 1 FROM KategorieArtikel ka JOIN Kategorien k ON k.ID=ka.KategorieID WHERE ka.ArtikelID=a.ID AND k.Kategorie LIKE ? ESCAPE '!'))";
        $params = array_fill(0, 6, $pattern);
    }
    if ($withoutProject) { $where[] = 'NOT EXISTS (SELECT 1 FROM ProjekteArtikel pa WHERE pa.ArtikelID=a.ID)'; }
    elseif ($project !== null) { $where[] = 'EXISTS (SELECT 1 FROM ProjekteArtikel pa WHERE pa.ArtikelID=a.ID AND pa.ProjektID=?)'; $params[] = $project; }
    if ($withoutAttachments) { $where[] = 'NOT EXISTS (SELECT 1 FROM Anhaenge an WHERE an.ArtikelID=a.ID)'; }
    if ($notCopied) { $where[] = 'COALESCE(a.Kopiert+0,0)=0'; }
    return query($db, 'SELECT a.ID,a.Titel,a.Jahrgang FROM Artikel a' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.Titel,a.ID', $params)->fetchAll();
}
function load_article(PDO $db, int $id): ?array
{
    $rows = query($db, 'SELECT ID,' . implode(',', ARTICLE_FIELDS) . ',Kopiert+0 AS Kopiert FROM Artikel WHERE ID=?', [$id])->fetchAll();
    if (count($rows) > 1) throw new RuntimeException('Die Artikel-ID ist nicht eindeutig. Bitte den Datenbestand prüfen lassen.');
    if (!$rows) return null;
    $article = $rows[0];
    foreach (RELATIONS as $key => [$table, $name, $link, $fk]) {
        $order = $key === 'authors' ? 'l.Position' : "m.`$name`,m.ID";
        $article[$key] = query($db, "SELECT m.ID,m.`$name` AS label FROM `$link` l JOIN `$table` m ON m.ID=l.`$fk` WHERE l.ArtikelID=? ORDER BY $order", [$id])->fetchAll();
    }
    $article['attachments'] = article_attachments($db,$id);
    $article['version'] = article_version($article);
    return $article;
}
function article_version(array $article): string
{
    $data = [];
    foreach (array_merge(['ID'], ARTICLE_FIELDS) as $field) $data[$field] = $article[$field] ?? null;
    $data['attachments']=$article['attachments']??[];
    foreach (RELATIONS as $key => $_) { $ids = array_column($article[$key] ?? [], 'ID'); sort($ids); $data[$key] = $ids; }
    $data['author_order'] = array_column($article['authors'] ?? [], 'ID');
    return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
}
function journal_version(array $journal): string
{
    $data=[];
    foreach(['ID','Titel','ISSN','Impact','Verlag','URL'] as $field)$data[$field]=isset($journal[$field])?(string)$journal[$field]:null;
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}
function validate_article(array $input): array
{
    $result = [];
    foreach (['Titel'=>255,'DOI'=>255,'Band'=>50,'Standort'=>50,'Signatur'=>50] as $key=>$max) {
        $value = trim((string)($input[$key] ?? ''));
        if (mb_strlen($value) > $max) throw new InvalidArgumentException("Das Feld $key darf höchstens $max Zeichen enthalten.");
        if ($key === 'Titel' && $value === '') throw new InvalidArgumentException('Bitte einen Titel eingeben.');
        $result[$key] = $value === '' ? null : $value;
    }
    $result['Abstract'] = trim((string)($input['Abstract'] ?? ''));
    if (strlen($result['Abstract']) > 1000000) throw new InvalidArgumentException('Der Abstract ist zu lang.');
    foreach (['JournalID','Jahrgang','SeiteVon','SeiteBis'] as $key) {
        $raw = trim((string)($input[$key] ?? ''));
        if($key==='JournalID' && $raw==='new')$raw='';
        $result[$key] = $raw === '' ? null : signed_id($raw);
        if ($raw !== '' && $result[$key] === null) throw new InvalidArgumentException("Bitte für $key eine ganze Zahl eingeben.");
    }
    if ($result['Jahrgang'] !== null && ($result['Jahrgang'] < 1000 || $result['Jahrgang'] > 9999)) throw new InvalidArgumentException('Bitte ein vierstelliges Erscheinungsjahr eingeben.');
    foreach (['SeiteVon','SeiteBis'] as $key) if ($result[$key] !== null && $result[$key] < 0) throw new InvalidArgumentException('Seitenzahlen dürfen nicht negativ sein.');
    if ($result['SeiteVon'] !== null && $result['SeiteBis'] !== null && $result['SeiteVon'] > $result['SeiteBis']) throw new InvalidArgumentException('Die letzte Seite darf nicht vor der ersten Seite liegen.');
    $result['Kopiert'] = isset($input['Kopiert']) ? 1 : 0;
    $result['new_journal']=null;
    $result['journal_update']=null;
    $isNewJournal=($input['JournalID']??'')==='new';
    if($isNewJournal || ($result['JournalID']!==null && isset($input['new_journal']))) {
        if(!is_array($input['new_journal']??[]))throw new InvalidArgumentException('Ungültige Journalangaben.');
        $journal=$input['new_journal']??[]; $newJournal=[];
        $fields=['ISSN'=>50,'Verlag'=>50,'URL'=>3000];
        if($isNewJournal)$fields=['Titel'=>100]+$fields;
        foreach($fields as $key=>$max) {
            if(!is_scalar($journal[$key]??'') && ($journal[$key]??null)!==null)throw new InvalidArgumentException('Ungültige Journalangaben.');
            $value=trim((string)($journal[$key]??''));
            if(mb_strlen($value)>$max)throw new InvalidArgumentException("Das Journal-Feld $key ist zu lang.");
            $newJournal[$key]=$value!==''?$value:null;
        }
        if($isNewJournal && !$newJournal['Titel'])throw new InvalidArgumentException('Bitte den Titel des neuen Journals eingeben.');
        if($newJournal['URL']!==null && (!filter_var($newJournal['URL'],FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($newJournal['URL'],PHP_URL_SCHEME)??''),['http','https'],true)))throw new InvalidArgumentException('Bitte eine gültige Journal-URL mit https:// oder http:// eingeben.');
        if(!is_scalar($journal['Impact']??'') && ($journal['Impact']??null)!==null)throw new InvalidArgumentException('Ungültige Journalangaben.');
        $impact=str_replace(',','.',trim((string)($journal['Impact']??'')));
        if($impact!=='' && (!is_numeric($impact) || !is_finite((float)$impact) || (float)$impact<0))throw new InvalidArgumentException('Bitte einen gültigen Impact-Faktor eingeben.');
        $newJournal['Impact']=$impact!==''?(float)$impact:null;
        $result[$isNewJournal?'new_journal':'journal_update']=$newJournal;
    }
    foreach (RELATIONS as $key=>$_) {
        if (!is_array($input[$key] ?? [])) throw new InvalidArgumentException('Ungültige Zuordnungen.');
        $rows = []; $seen = [];
        $originalIds = is_array($input[$key.'_ids']??null) ? $input[$key.'_ids'] : [];
        foreach (($input[$key] ?? []) as $i=>$value) {
            if (!is_scalar($value)) throw new InvalidArgumentException('Ungültige Zuordnung.');
            $name=trim((string)$value); if($name==='')continue;
            $originalId=signed_id($originalIds[$i]??null);
            // Datalist choices identify people sharing the same displayed name.
            if (preg_match('/^(.*?) \\[ID: (-?\\d+)\\]$/u', $name, $choice)) {
                $name = $choice[1];
                $originalId = signed_id($choice[2]);
                if ($originalId === null) throw new InvalidArgumentException('Ungültige Zuordnungs-ID.');
            }
            if(mb_strlen($name)>50)throw new InvalidArgumentException('Namen und Zuordnungen dürfen höchstens 50 Zeichen enthalten.');
            $unique=$originalId!==null?'id:'.$originalId:'name:'.$name;
            if(isset($seen[$unique]))continue;
            $seen[$unique]=true; $rows[]=['label'=>$name,'ID'=>$originalId];
        }
        if(count($rows)>50)throw new InvalidArgumentException('Es sind höchstens 50 Zuordnungen je Bereich möglich.');
        $result[$key] = $rows;
    }
    return $result;
}
function save_article(PDO $db, array $input, ?int $id, array $uploads = []): int
{
    $data = validate_article($input);
    if(count($uploads)>20)throw new InvalidArgumentException('Bitte höchstens 20 Dateien auf einmal hinzufügen.');
    $removals=$input['remove_attachments']??[];
    if(!is_array($removals))throw new InvalidArgumentException('Ungültige Anhangsauswahl.');
    $removeIds=[];
    foreach($removals as $raw){$remove=signed_id($raw);if($remove===null || $remove<1)throw new InvalidArgumentException('Ungültige Anhangs-ID.');$removeIds[$remove]=$remove;}
    if($id===null && $removeIds)throw new InvalidArgumentException('Ein neuer Artikel besitzt noch keine Anhänge.');
    if (query($db, "SELECT GET_LOCK('literatur.frontend.write',10)")->fetchColumn() != 1) throw new RuntimeException('Ein anderer Speichervorgang läuft. Bitte erneut versuchen.');
    try {
        $db->beginTransaction();
        if ($data['JournalID'] !== null) {
            $journal=query($db,'SELECT * FROM Journale WHERE ID=? FOR UPDATE',[$data['JournalID']])->fetch();
            if(!$journal)throw new InvalidArgumentException('Bitte ein vorhandenes Journal auswählen.');
            if($data['journal_update']!==null) {
                if(!hash_equals(journal_version($journal),(string)($input['journal_version']??'')))throw new RuntimeException('Das Journal wurde zwischenzeitlich geändert. Bitte neu laden und die Änderungen vergleichen.');
                $j=$data['journal_update'];
                // Access-Hyperlinkwerte bleiben erhalten, wenn die sichtbare URL unverändert ist.
                if($j['URL']===journal_url($journal['URL']) || ($j['URL']===null && journal_url($journal['URL'])===''))$j['URL']=$journal['URL'];
                query($db,'UPDATE Journale SET ISSN=?,Impact=?,Verlag=?,URL=? WHERE ID=?',[$j['ISSN'],$j['Impact'],$j['Verlag'],$j['URL'],$data['JournalID']]);
            }
        }
        if($data['new_journal']!==null) {
            $j=$data['new_journal'];
            $matches=query($db,'SELECT ID FROM Journale WHERE Titel=? ORDER BY ID',[$j['Titel']])->fetchAll();
            if($matches)throw new InvalidArgumentException('Ein Journal mit diesem Titel existiert bereits. Bitte aus der Liste auswählen.');
            query($db,'INSERT INTO Journale (Titel,ISSN,Verlag,Impact,URL) VALUES (?,?,?,?,?)',[$j['Titel'],$j['ISSN'],$j['Verlag'],$j['Impact'],$j['URL']]);
            $data['JournalID']=(int)$db->lastInsertId();
        }
        if ($id !== null) {
            query($db, 'SELECT ID FROM Artikel WHERE ID=? FOR UPDATE', [$id])->fetchAll();
            $current = load_article($db, $id);
            if (!$current) throw new RuntimeException('Der Artikel wurde nicht gefunden.');
            if (!hash_equals($current['version'], (string)($input['version'] ?? ''))) throw new RuntimeException('Der Artikel wurde zwischenzeitlich geändert. Bitte neu laden und die Änderungen vergleichen.');
            $set = implode(',', array_map(fn($f)=>"`$f`=?", ARTICLE_FIELDS));
            query($db, "UPDATE Artikel SET $set WHERE ID=?", [...array_map(fn($f)=>$data[$f], ARTICLE_FIELDS), $id]);
        } else {
            query($db, 'INSERT INTO Artikel (' . implode(',', ARTICLE_FIELDS) . ') VALUES (' . implode(',', array_fill(0, count(ARTICLE_FIELDS), '?')) . ')', array_map(fn($f)=>$data[$f], ARTICLE_FIELDS));
            $id = (int)$db->lastInsertId();
        }
        foreach (RELATIONS as $key=>[$table,$name,$link,$fk]) {
            query($db, "DELETE FROM `$link` WHERE ArtikelID=?", [$id]);
            $linkedIds=[];
            foreach ($data[$key] as $relation) {
                $label=$relation['label'];
                $matches = $relation['ID']!==null ? query($db,"SELECT ID FROM `$table` WHERE ID=? AND `$name`=? FOR SHARE",[$relation['ID'],$label])->fetchAll() : query($db, "SELECT ID FROM `$table` WHERE `$name`=? ORDER BY ID LIMIT 2 FOR SHARE", [$label])->fetchAll();
                if ($relation['ID'] !== null && !$matches) throw new InvalidArgumentException('Eine ausgewählte Zuordnung wurde geändert oder gelöscht. Bitte neu auswählen.');
                if (count($matches) > 1) throw new InvalidArgumentException('Der Name „'.$label.'“ ist mehrfach vorhanden. Bitte den passenden Eintrag mit ID aus den Vorschlägen auswählen.');
                $masterId = $matches[0]['ID'] ?? null;
                if ($masterId === null) {
                    query($db, "INSERT INTO `$table` (`$name`) VALUES (?)", [$label]);
                    $masterId = (int)$db->lastInsertId();
                }
                if(isset($linkedIds[$masterId]))continue;
                $linkedIds[$masterId]=true;
                if ($key === 'authors') query($db, "INSERT INTO `$link` (`$fk`,ArtikelID,Position) VALUES (?,?,?)", [$masterId,$id,count($linkedIds)]);
                else query($db, "INSERT INTO `$link` (`$fk`,ArtikelID) VALUES (?,?)", [$masterId,$id]);
            }
        }
        foreach($removeIds as $remove) {
            if(query($db,'SELECT ID FROM Anhaenge WHERE ID=? AND ArtikelID=? FOR UPDATE',[$remove,$id])->fetchColumn()===false)throw new InvalidArgumentException('Ein ausgewählter Anhang gehört nicht zu diesem Artikel oder wurde gelöscht.');
            query($db,'DELETE FROM Anhaenge WHERE ID=? AND ArtikelID=?',[$remove,$id]);
        }
        $position=(int)query($db,'SELECT COALESCE(MAX(Position),0) FROM Anhaenge WHERE ArtikelID=?',[$id])->fetchColumn();
        foreach($uploads as $upload)insert_attachment($db,$id,$upload,++$position);
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    } finally { query($db, "SELECT RELEASE_LOCK('literatur.frontend.write')"); }
}

function delete_article(PDO $db, int $id, string $version): void
{
    if (query($db, "SELECT GET_LOCK('literatur.frontend.write',10)")->fetchColumn() != 1) throw new RuntimeException('Ein anderer Speichervorgang läuft. Bitte erneut versuchen.');
    try {
        $db->beginTransaction();
        query($db, 'SELECT ID FROM Artikel WHERE ID=? FOR UPDATE', [$id])->fetchAll();
        $current = load_article($db, $id);
        if (!$current) throw new RuntimeException('Der Artikel wurde nicht gefunden.');
        if (!hash_equals($current['version'], $version)) throw new RuntimeException('Der Artikel wurde zwischenzeitlich geändert. Bitte neu laden und vor dem Löschen prüfen.');
        // Fremdschlüssel entfernen Anhänge und Artikelzuordnungen mit dem Artikel.
        query($db, 'DELETE FROM Artikel WHERE ID=?', [$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    } finally { query($db, "SELECT RELEASE_LOCK('literatur.frontend.write')"); }
}

function delete_empty_project(PDO $db, int $id, string $name): void
{
    if (query($db, "SELECT GET_LOCK('literatur.frontend.write',10)")->fetchColumn() != 1) throw new RuntimeException('Ein anderer Speichervorgang läuft. Bitte erneut versuchen.');
    try {
        $db->beginTransaction();
        $current = query($db, 'SELECT Projekt FROM Projekte WHERE ID=? FOR UPDATE', [$id])->fetchColumn();
        if ($current === false) throw new RuntimeException('Das Projekt wurde nicht gefunden.');
        if (!hash_equals($name, (string)$current)) throw new RuntimeException('Das Projekt wurde zwischenzeitlich geändert. Bitte das Löschen erneut bestätigen.');
        if (query($db, 'SELECT 1 FROM ProjekteArtikel WHERE ProjektID=? LIMIT 1', [$id])->fetchColumn() !== false) throw new RuntimeException('Das Projekt enthält Artikel und kann nicht gelöscht werden.');
        query($db, 'DELETE FROM Projekte WHERE ID=?', [$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    } finally { query($db, "SELECT RELEASE_LOCK('literatur.frontend.write')"); }
}

function citation(array $article, array $journals): string
{
    $authors = implode(', ', array_column($article['authors'] ?? [], 'label'));
    $journal = '';
    foreach ($journals as $j) if ((string)$j['ID'] === (string)($article['JournalID'] ?? '')) $journal = $j['Titel'];
    if (($article['JournalID'] ?? null) === 'new') $journal = $article['new_journal']['Titel'] ?? '';
    $pages = $article['SeiteVon'] ?? '';
    if (($article['SeiteBis'] ?? '') !== '') $pages .= '–' . $article['SeiteBis'];
    return trim(($authors ? $authors . ' ' : '') . (($article['Jahrgang'] ?? '') ? '(' . $article['Jahrgang'] . '): ' : '') . ($article['Titel'] ?? '') . '. ' . $journal . (($article['Band'] ?? '') ? ' ' . $article['Band'] : '') . ($pages !== '' ? ', ' . $pages : '') . '.', ' ');
}

function doi_url(mixed $value): string
{
    $doi=trim((string)($value??''));
    $doi=(string)preg_replace('~^doi:\s*~i','',$doi);
    if(preg_match('~^https?://(?:dx\.)?doi\.org/~i',$doi,$prefix)) {
        $doi=substr($doi,strlen($prefix[0]));
        if(preg_match('/%(?![0-9a-f]{2})/i',$doi))return '';
        $doi=rawurldecode($doi);
    }
    if(!preg_match('~^10\.\d+(?:\.\d+)*/[^\s\x00-\x1f\x7f]+$~uD',$doi))return '';
    return 'https://doi.org/'.str_replace('%2F','/',rawurlencode($doi));
}

function journal_url(mixed $value): string
{
    $url=trim((string)($value??''));
    if(str_contains($url,'#')) {
        $parts=explode('#',$url);
        if(isset($parts[1]) && preg_match('~^https?://~i',$parts[1]))return $parts[1];
    }
    return $url;
}
function is_web_url(string $url): bool
{
    return (bool)filter_var($url,FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true);
}
