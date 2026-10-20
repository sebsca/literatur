<?php
declare(strict_types=1);

function doi_match_text(string $text): string
{
    $text=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=strtr(mb_strtolower($text),['α'=>'alpha','β'=>'beta','γ'=>'gamma','δ'=>'delta','µ'=>'mu','μ'=>'mu','κ'=>'kappa']);
    if(class_exists(Normalizer::class))$text=Normalizer::normalize($text,Normalizer::FORM_D)?:$text;
    return (string)preg_replace('/[^\p{L}\p{N}]/u','',$text);
}
function doi_title_similarity(string $a,string $b): float
{
    $a=doi_match_text($a);$b=doi_match_text($b);
    if($a==='' || $b==='')return 0;
    if($a===$b)return 1;
    $length=max(mb_strlen($a),mb_strlen($b));
    if($length<40)return 0; // Bei kurzen Titeln nur exakte Übereinstimmung.
    $aa=preg_split('//u',$a,-1,PREG_SPLIT_NO_EMPTY);$bb=preg_split('//u',$b,-1,PREG_SPLIT_NO_EMPTY);
    $previous=range(0,count($bb));
    foreach($aa as $i=>$letter){$current=[$i+1];foreach($bb as $j=>$other)$current[$j+1]=min($current[$j]+1,$previous[$j+1]+1,$previous[$j]+($letter===$other?0:1));$previous=$current;}
    return max(0,1-end($previous)/$length);
}
function doi_author_family(string $name): string
{
    // Der Altbestand verwendet überwiegend Familienname-Initialen.
    return doi_match_text((string)preg_replace('/-[A-ZÄÖÜ]{1,6}$/u','',trim($name)));
}
function doi_bibliographic_volume(mixed $value): string
{
    $value=trim((string)$value);$value=preg_split('/[:(]/u',$value,2)[0];
    return doi_match_text($value);
}
function doi_bibliographic_page(mixed $value): string
{
    $value=trim((string)$value);if($value==='' || $value==='0')return '';
    return doi_match_text(preg_split('/[-–—]/u',$value,2)[0]);
}
function doi_candidate_match(array $article,array $candidate): array
{
    $doi=trim((string)($candidate['DOI']??''));
    $result=['doi'=>$doi,'accepted'=>false,'title_similarity'=>0.0,'evidence'=>[],'conflicts'=>[],'reason'=>'Keine ausreichende Übereinstimmung'];
    if(strlen($doi)>255 || doi_url($doi)===''){$result['reason']='Ungültige DOI';return $result;}
    if(($candidate['type']??'')!=='journal-article'){$result['reason']='Kein Journalartikel';return $result;}
    foreach($candidate['title']??[] as $title)$result['title_similarity']=max($result['title_similarity'],doi_title_similarity((string)$article['Titel'],(string)$title));
    if($result['title_similarity']<0.98){$result['reason']='Titel stimmt nicht ausreichend überein';return $result;}
    $result['evidence'][]='Titel';
    $years=[];
    foreach(['issued','published-print','published-online','published'] as $key)if(isset($candidate[$key]['date-parts'][0][0]))$years[]=(int)$candidate[$key]['date-parts'][0][0];
    $year=(int)($article['Jahrgang']??0);
    if($year>=1000 && $year<=9999 && $years){if(in_array($year,$years,true))$result['evidence'][]='Jahr';else $result['conflicts'][]='Jahr';}
    $sourceVolume=doi_bibliographic_volume($article['Band']??'');$targetVolume=doi_bibliographic_volume($candidate['volume']??'');
    if($sourceVolume!=='' && $targetVolume!==''){if($sourceVolume===$targetVolume)$result['evidence'][]='Band';else $result['conflicts'][]='Band';}
    $sourcePage=doi_bibliographic_page($article['SeiteVon']??'');$targetPage=doi_bibliographic_page($candidate['page']??'');
    if($sourcePage!=='' && $targetPage!==''){if($sourcePage===$targetPage)$result['evidence'][]='Anfangsseite';else $result['conflicts'][]='Anfangsseite';}
    $sourceAuthors=array_values(array_filter(array_map('doi_author_family',array_column($article['authors']??[],'label'))));
    $targetAuthors=array_values(array_filter(array_map(fn($a)=>doi_match_text((string)($a['family']??'')),$candidate['author']??[])));
    if($sourceAuthors && $targetAuthors){
        if($sourceAuthors[0]===$targetAuthors[0])$result['evidence'][]='Erstautor';
        elseif(count(array_intersect($sourceAuthors,$targetAuthors))>=2)$result['evidence'][]='Mehrere Autoren';
        else $result['conflicts'][]='Autoren';
    }
    $journal=(string)($article['Journal']??'');
    foreach($candidate['container-title']??[] as $title)if(doi_match_text($journal)!=='' && doi_match_text($journal)===doi_match_text((string)$title)){$result['evidence'][]='Journal';break;}
    $sourceIssn=(string)($article['ISSN']??'');
    foreach($candidate['ISSN']??[] as $issn)if(doi_match_text($sourceIssn)!=='' && doi_match_text($sourceIssn)===doi_match_text((string)$issn)){$result['evidence'][]='ISSN';break;}
    $hasAuthors=in_array('Erstautor',$result['evidence'],true)||in_array('Mehrere Autoren',$result['evidence'],true);
    $hasJournal=in_array('Journal',$result['evidence'],true)||in_array('ISSN',$result['evidence'],true);
    $hasPages=in_array('Anfangsseite',$result['evidence'],true);
    $hasVolume=in_array('Band',$result['evidence'],true);
    $hasYear=in_array('Jahr',$result['evidence'],true);
    $exact=$result['title_similarity']===1.0;
    // Autoren plus Jahr und eine weitere unabhängige Angabe; bei Titelabweichungen zusätzlich Band und Seite.
    // Fehlende Autoren nur mit exakt gleichem Titel, Journal, Jahr, Band und Anfangsseite ausgleichen.
    $sufficient=$hasYear && (($hasAuthors && ($hasJournal||($hasVolume&&$hasPages))) || (!$sourceAuthors && $exact && $hasJournal && $hasVolume && $hasPages));
    if(!$exact)$sufficient=$sufficient && $hasAuthors && $hasVolume && $hasPages;
    $result['accepted']=!$result['conflicts'] && $sufficient;
    $result['reason']=$result['accepted']?'Bibliografische Angaben eindeutig passend':($result['conflicts']?'Widerspruch: '.implode(', ',$result['conflicts']):'Zu wenige bestätigte Angaben');
    return $result;
}
function doi_choose_candidate(array $article,array $candidates): array
{
    $evaluated=[];$accepted=[];
    foreach($candidates as $candidate){$match=doi_candidate_match($article,$candidate);$evaluated[]=$match+['metadata'=>$candidate];if($match['accepted'])$accepted[mb_strtolower($match['doi'])]=$match+['metadata'=>$candidate];}
    if(count($accepted)===1)return ['status'=>'matched','match'=>array_values($accepted)[0],'candidates'=>$evaluated];
    return ['status'=>count($accepted)>1?'ambiguous':'unmatched','match'=>null,'candidates'=>$evaluated];
}
function doi_crossref_query(array $article): string
{
    // Es werden ausschließlich bibliografische Angaben gesendet, keine IDs, Abstracts oder Anhänge.
    $parts=[trim((string)$article['Titel'])];
    $first=$article['authors'][0]['label']??'';if($first!=='')$parts[]=(string)preg_replace('/-[A-ZÄÖÜ]{1,6}$/u','',$first);
    if((int)($article['Jahrgang']??0)>=1000)$parts[]=(string)$article['Jahrgang'];
    foreach(['Journal','Band','SeiteVon'] as $key)if(trim((string)($article[$key]??''))!=='' && (string)$article[$key]!=='0')$parts[]=(string)$article[$key];
    return implode(' ',$parts);
}
function doi_snapshot(PDO $db): array
{
    $ids=query($db,"SELECT ID FROM Artikel WHERE DOI IS NULL OR TRIM(DOI)='' ORDER BY ID")->fetchAll(PDO::FETCH_COLUMN);
    $journals=[];foreach(query($db,'SELECT ID,Titel,ISSN FROM Journale')->fetchAll() as $journal)$journals[$journal['ID']]=$journal;
    $rows=[];
    foreach($ids as $id){$a=load_article($db,(int)$id);if(!$a || trim((string)$a['DOI'])!=='')continue;$journal=$journals[$a['JournalID']]??[];
        $rows[]=['ID'=>$a['ID'],'Titel'=>$a['Titel'],'Jahrgang'=>$a['Jahrgang'],'Band'=>$a['Band'],'SeiteVon'=>$a['SeiteVon'],'SeiteBis'=>$a['SeiteBis'],'Journal'=>$journal['Titel']??'','ISSN'=>$journal['ISSN']??'','authors'=>$a['authors'],'version'=>$a['version'],'old_doi'=>$a['DOI']];
    }
    return $rows;
}
function doi_apply_report(PDO $db,array $report): array
{
    if(query($db,"SELECT GET_LOCK('literatur.frontend.write',10)")->fetchColumn()!=1)throw new RuntimeException('Ein anderer Speichervorgang läuft.');
    $updates=[];$skipped=[];
    try {
        $db->beginTransaction();
        foreach($report['results']??[] as $entry){
            if(($entry['status']??'')!=='matched')continue;
            $id=(int)$entry['article']['ID'];$doi=(string)$entry['match']['doi'];
            if(doi_url($doi)==='' || mb_strlen($doi)>255)throw new RuntimeException('Ungültige DOI im Prüfbericht.');
            query($db,'SELECT ID FROM Artikel WHERE ID=? FOR UPDATE',[$id])->fetchAll();$current=load_article($db,$id);
            if(!$current || trim((string)$current['DOI'])!=='' || !hash_equals($entry['article']['version'],$current['version'])){$skipped[]=$id;continue;}
            query($db,"UPDATE Artikel SET DOI=? WHERE ID=? AND (DOI IS NULL OR TRIM(DOI)='')",[$doi,$id]);
            $updates[]=['id'=>$id,'old_doi'=>$current['DOI'],'new_doi'=>$doi,'before_version'=>$current['version'],'after_version'=>load_article($db,$id)['version']];
        }
        $db->commit();
    }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
    finally{query($db,"SELECT RELEASE_LOCK('literatur.frontend.write')");}
    return ['updates'=>$updates,'skipped'=>$skipped];
}
