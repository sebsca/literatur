<?php
declare(strict_types=1);

const MAX_CITATION_BYTES = 200000;

function citation_clean(string $value): string
{
    return trim((string)preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $value)));
}
function citation_key(string $value): string
{
    return (string)preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value));
}
function citation_doi(string $value): string
{
    if (!preg_match('~10\.\d+(?:\.\d+)*/[^\s"“”]+~u', $value, $m)) return '';
    $doi = rtrim($m[0], '.,;');
    foreach ([')' => '(', ']' => '[', '}' => '{'] as $close => $open) {
        while (str_ends_with($doi, $close) && substr_count($doi, $close) > substr_count($doi, $open)) $doi = substr($doi, 0, -1);
    }
    return $doi;
}
function citation_author(string $name, bool $givenFirst = false): string
{
    $name = trim(citation_clean($name), " ,;.\t\n\r");
    $name = (string)preg_replace('/^(?:and\s+|&\s*)/iu', '', $name);
    if ($name === '' || preg_match('/^(?:et\s+al|and others)$/iu', $name)) return '';
    if (str_contains($name, ',')) {
        [$family, $given] = array_map('trim', explode(',', $name, 2));
    } elseif (preg_match('/^(.+?)\s+((?:\p{Lu}\.?\s*){1,6})$/u', $name, $m)) {
        [$family, $given] = [$m[1], $m[2]];
    } elseif ($givenFirst && str_contains($name, ' ')) {
        $parts = explode(' ', $name); $family = array_pop($parts);
        while ($parts && in_array(mb_strtolower(end($parts)), ['van','von','de','del','der','den','da','di','la','le'], true)) $family = array_pop($parts).' '.$family;
        $given = implode(' ', $parts);
    } else return $name;
    $given = (string)preg_replace('/\s+(?:Jr|Sr|II|III|IV)\.?$/u', '', $given);
    $initials = '';
    foreach (preg_split('/[\s.\-]+/u', trim($given)) as $part) {
        if ($part === '') continue;
        $letters = (string)preg_replace('/[^\p{L}]/u', '', $part);
        $initials .= preg_match('/^\p{Lu}{1,6}$/u', $letters) ? $letters : mb_strtoupper(mb_substr($letters, 0, 1));
    }
    return trim($family).($initials !== '' ? '-'.$initials : '');
}
function citation_author_list(string $value, string $style): array
{
    $value = (string)preg_replace('/\bet\s+al\.?|…|\.{3}/iu', '', $value);
    $result = [];
    if ($style === 'APA' && preg_match_all('/(?:^|,\s*|&\s*)([^,]+),\s*((?:\p{Lu}\.\s*(?:-\s*)?)+)/u', $value, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) $result[] = citation_author($m[1].', '.$m[2]);
    } elseif ($style === 'MLA') {
        $value = (string)preg_replace('/,?\s+(?:and|&)\s+/iu', ', ', trim($value, " ,."));
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)),fn($s)=>$s!==''));
        if(count($parts)>1)$result[]=citation_author(array_shift($parts).', '.array_shift($parts));
        foreach($parts as $part)$result[]=citation_author($part,true);
    } else {
        foreach (preg_split('/\s*[,;]\s*|\s+(?:and|&)\s+/iu', $value) as $part) $result[] = citation_author($part);
    }
    // Lesbar im bisherigen Schema: Familienname-Initialen.
    return array_values(array_filter(array_map(fn($name) => str_replace(' -', '-', $name), $result), fn($name) => $name !== ''));
}
function citation_pages(string $value, array &$warnings): array
{
    $value = trim((string)preg_replace('/^(?:pp?\.?\s*)/i', '', str_replace(['–','—','−'], '-', $value)), " .;");
    if ($value === '') return [null, null];
    if (!preg_match('/^(\d+)(?:\s*-\s*(\d+))?$/D', $value, $m)) {
        $warnings[] = 'Seitenangabe/Artikelnummer „'.$value.'“ enthält Buchstaben oder Sonderzeichen. Die Seitenfelder unterstützen nur ganze Zahlen; die Originalangabe steht im Zitattext.';
        return [null, null];
    }
    $start = (int)$m[1]; $end = isset($m[2]) ? (int)$m[2] : null;
    if (isset($m[2]) && strlen($m[2]) < strlen($m[1])) {
        $end = (int)(substr($m[1], 0, strlen($m[1])-strlen($m[2])).$m[2]);
        if ($end < $start) $end += 10 ** strlen($m[2]);
    }
    if ($start > 2147483647 || ($end !== null && ($end < $start || $end > 2147483647))) {
        $warnings[] = 'Der Seitenbereich „'.$value.'“ ist nicht gültig. Bitte prüfen.'; return [null, null];
    }
    return [$start, $end];
}
function citation_tagged(string $text, string $format): array
{
    $fields = []; $tag = null; $index = 0;
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^([A-Z0-9]{2,4})\s*-\s?(.*)$/', $line, $m)) {
            $tag = $m[1]; $fields[$tag][] = trim($m[2]); $index = count($fields[$tag])-1;
        } elseif ($tag !== null && trim($line) !== '') $fields[$tag][$index] .= ' '.trim($line);
    }
    if (count($fields[$format === 'RIS' ? 'TY' : 'PMID'] ?? []) > 1 || count($fields['TI'] ?? []) > 1 || ($format === 'RIS' && count($fields['ER'] ?? []) > 1)) throw new InvalidArgumentException('Bitte genau einen Artikel einfügen, keine Liste mehrerer Datensätze.');
    $first = function(array $tags) use($fields):string {foreach($tags as $tag)if(!empty($fields[$tag][0]))return citation_clean($fields[$tag][0]);return '';};
    $warnings = [];
    if ($format === 'RIS') {
        $title = $first(['TI','T1']); $journal = $first(['JF','JO','T2','JA','J1','J2']);
        $authors = $fields['AU'] ?? $fields['A1'] ?? [];
        $date = $first(['PY','Y1','DA']); $volume = $first(['VL']); $issue = $first(['IS']);
        $pages = $first(['SP']); $end = $first(['EP']); if($end !== '')$pages .= '-'.$end;
        $abstract = $first(['AB','N2']); $doi = citation_doi($first(['DO']));
        if ($doi === '') foreach ($fields['UR'] ?? [] as $url) { $doi = citation_doi($url); if($doi !== '')break; }
        $issn = $first(['SN']); $publisher = $first(['PB']);
    } else {
        $title = $first(['TI']); $journal = $first(['JT','TA']);
        $authors = $fields['AU'] ?? $fields['FAU'] ?? []; // AU enthält die veröffentlichten Initialen.
        $date = $first(['DP']); $volume = $first(['VI']); $issue = $first(['IP']); $pages = $first(['PG']);
        $abstract = $first(['AB']); $doi = '';
        foreach (array_merge($fields['AID'] ?? [], $fields['LID'] ?? []) as $identifier) if(preg_match('/\[doi\]/i',$identifier)){ $doi = citation_doi($identifier); if($doi !== '')break; }
        $issn = (string)preg_replace('/\s*\([^)]*\).*$/', '', $first(['IS'])); $publisher = '';
    }
    preg_match('/\b(\d{4})\b/', $date, $year);
    $names = array_map(fn($name) => str_replace(' -','-',citation_author($name)), $authors);
    [$from,$to] = citation_pages($pages,$warnings);
    return ['format'=>$format,'data'=>['Titel'=>$title,'DOI'=>$doi,'Abstract'=>$abstract,'Jahrgang'=>isset($year[1])?(int)$year[1]:null,'Band'=>$volume.($issue !== ''?'('.$issue.')':''),'SeiteVon'=>$from,'SeiteBis'=>$to,'authors'=>array_values(array_filter($names))],'journal'=>['Titel'=>$journal,'ISSN'=>$issn,'Verlag'=>$publisher],'warnings'=>$warnings];
}
function citation_journal_equal(string $source, string $target): bool
{
    if (citation_key($source) === citation_key($target)) return citation_key($source) !== '';
    $words = fn($s) => array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($s)),fn($w)=>$w !== '' && !in_array($w,['of','the','and','in','for','on','&'],true)));
    $a = $words($source); $b = $words($target);
    if (!$a || !$b) return false;
    if (count($a) === 1 && count($b) > 1) return $a[0] === implode('',array_map(fn($w)=>mb_substr($w,0,1),$b));
    if (count($a) !== count($b)) return false;
    foreach ($a as $i=>$word) if(!str_starts_with($b[$i],$word))return false;
    return true;
}
function citation_title_journal(string $body, array $journalTitles = []): array
{
    $body = trim($body, " .,"); $candidates = [];
    if (preg_match_all('/[.!?]\s+/u', $body, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as [$separator,$offset]) {
            $title = trim(substr($body,0,$offset).($separator[0]!=='.'?$separator[0]:'')); $journal = trim(substr($body,$offset+strlen($separator))," .,");
            if($title === '' || $journal === '')continue;
            $score = 0;
            foreach($journalTitles as $known)if($known !== null && citation_journal_equal($journal,$known)){$score=1000;break;}
            if(preg_match('/^(?:J\.?\s|Am\.?\s|Br\.?\s|Int\.?\s|Eur\.?\s|Journal\b|Nature\b|Science\b|Lancet\b|The Lancet\b|Proc\.?\s|BMJ\b|JAMA\b)/i',$journal))$score+=50;
            // Punkte innerhalb von Journalabkürzungen bilden keine Titelgrenze.
            if(preg_match('/(?:^|\s)(?:\p{L}{1,3}|Clin|Immunol|Endocrinol|Physiol|Biol|Med|Vet|Res|Chem|Microbiol|Pharmacol|Psychol|Neurosci)$/u',$title))$score-=100;
            $candidates[] = [$score,$offset,$title,$journal];
        }
    }
    if(!$candidates)throw new InvalidArgumentException('Titel und Journal konnten nicht getrennt werden. Bitte ein vollständiges Artikelzitat, nbib oder RIS einfügen.');
    usort($candidates,fn($a,$b)=>($b[0]<=>$a[0])?:($b[1]<=>$a[1]));
    return [$candidates[0][2],$candidates[0][3]];
}
function citation_prose(string $text, array $journalTitles): array
{
    $warnings = []; $doi = citation_doi($text);
    $body = citation_clean($text);
    $body = (string)preg_replace('/^\d+[.)]\s+/', '', $body);
    // DOI, PubMed-Kennungen und Epub-Daten gehören nicht zum Journalnamen.
    $body = (string)preg_replace('~\s*(?:https?://(?:dx\.)?doi\.org/|doi\s*:\s*)10\..*$~iu', '', $body);
    if($doi !== '')$body=str_replace($doi,'',$body);
    $body = (string)preg_replace('/\s*(?:PMID|PMCID|Epub)\s*[:.]?.*$/i', '', $body);
    $body = trim(str_replace(['**','*'], '', $body), " .;");
    $body = (string)preg_replace('/(?<!\w)_([^_]+)_(?!\w)/u','$1',$body);
    if(preg_match('/\bet\s+al\b|…|\.{3}/iu',$body))$warnings[]='Die Autorenliste ist im Zitat gekürzt (et al./…). Übernommen wurden nur die genannten Autoren.';
    $year = null; $volume = ''; $issue = ''; $pages = ''; $authors = '';
    if(preg_match('/^(?<authors>.+?)\s*\((?<year>\d{4})[a-z]?(?:,\s*[^)]+)?\)\.\s*(?<rest>.+)$/u',$body,$m)) {
        $format='APA'; $authors=$m['authors']; $year=(int)$m['year']; $rest=$m['rest'];
        if(preg_match('/^(?<body>.+),\s*(?<volume>\d+[a-z]?)(?:\s*\((?<issue>[^)]+)\))?\s*,\s*(?<pages>[^,]+)$/u',$rest,$tail)) {
            $volume=$tail['volume'];$issue=$tail['issue']??'';$pages=$tail['pages'];$rest=$tail['body'];
        } elseif(preg_match('/^(?<body>.+),\s*(?<volume>\d+[a-z]?)(?:\s*\((?<issue>[^)]+)\))?$/u',$rest,$tail)) {
            $volume=$tail['volume'];$issue=$tail['issue']??'';$rest=$tail['body'];
        }
        [$title,$journal]=citation_title_journal($rest,$journalTitles);
    } elseif(preg_match('/^(?<authors>.+?)\s*["“](?<title>.+)["”]\s*\.?\s*(?<rest>.+)$/u',$body,$m)) {
        $format='MLA';$authors=trim($m['authors']," .");$title=trim($m['title']," .");$rest=$m['rest'];
        if(preg_match('/^(?<journal>.+?)\s+(?:vol\.\s*)?(?<volume>\d+)(?:\s*[,.(]\s*(?<issue>[^)\s]+)\)?)?\s*\((?<year>\d{4})\)\s*:\s*(?<pages>.+)$/iu',$rest,$tail)) {
            $journal=trim($tail['journal']," ., ");$year=(int)$tail['year'];$volume=$tail['volume'];$issue=$tail['issue']??'';$pages=$tail['pages'];
        } else {
            $journal=trim(preg_split('/,\s*(?:vol\.|no\.|\d{4}\b)/iu',$rest)[0]," ., ");
            if(preg_match('/\b(?:vol\.)\s*(\d+)/i',$rest,$part))$volume=$part[1];
            if(preg_match('/\bno\.\s*([^,]+)/i',$rest,$part))$issue=trim($part[1]);
            if(preg_match('/\b(\d{4})\b/',$rest,$part))$year=(int)$part[1];
            if(preg_match('/\bpp?\.\s*(.+)$/i',$rest,$part))$pages=$part[1];
        }
    } else {
        if(!preg_match('/\b(?<year>\d{4})(?:\s+[A-Za-z]+(?:[-\/][A-Za-z]+)?(?:\s+\d{1,2}(?:-\d{1,2})?)?)?\s*;\s*(?<volume>[^:;]+?)\s*:\s*(?<pages>[^.]+)(?:\.|$)/u',$body,$tail,PREG_OFFSET_CAPTURE)) {
            if(!preg_match('/\b(?<year>\d{4})(?:\s+[A-Za-z]+(?:[-\/][A-Za-z]+)?(?:\s+\d{1,2}(?:-\d{1,2})?)?)?(?:\s*;\s*(?<volume>[^.;]+))?\s*$/u',$body,$tail,PREG_OFFSET_CAPTURE))throw new InvalidArgumentException('Das Zitatformat konnte nicht erkannt werden. Bitte ein vollständiges Artikelzitat in NLM, AMA, APA, MLA, nbib oder RIS einfügen.');
        }
        $year=(int)$tail['year'][0];$volume=trim($tail['volume'][0]??'');$pages=trim($tail['pages'][0]??'');
        $head=trim(substr($body,0,$tail[0][1])," .;");
        if(!preg_match('/^(.+?)\.\s+(?!\p{Lu}\.[\s,])(.+)$/u',$head,$first))throw new InvalidArgumentException('Autoren und Titel konnten nicht getrennt werden. Bitte das vollständige Zitat einfügen.');
        $authors=$first[1];[$title,$journal]=citation_title_journal($first[2],$journalTitles);
        $format=preg_match('/\b\d{4}\s+[A-Za-z]+/',$tail[0][0])?'NLM':'NLM / AMA';
    }
    [$from,$to]=citation_pages($pages,$warnings);
    return ['format'=>$format,'data'=>['Titel'=>$title,'DOI'=>$doi,'Abstract'=>'','Jahrgang'=>$year,'Band'=>$volume.($issue !== ''?'('.$issue.')':''),'SeiteVon'=>$from,'SeiteBis'=>$to,'authors'=>citation_author_list($authors,$format)],'journal'=>['Titel'=>$journal,'ISSN'=>'','Verlag'=>''],'warnings'=>$warnings];
}
function parse_citation(string $text, array $journalTitles = []): array
{
    if(strlen($text)>MAX_CITATION_BYTES || !mb_check_encoding($text,'UTF-8'))throw new InvalidArgumentException('Der Zitattext ist zu lang oder nicht als UTF-8 lesbar.');
    $text=trim(str_replace(["\xef\xbb\xbf","\r\n","\r"],['',"\n","\n"],$text));
    if($text==='')throw new InvalidArgumentException('Bitte einen Zitattext einfügen.');
    if(preg_match_all('/^\s*\d+[.)]\s+\S/m',$text)>1)throw new InvalidArgumentException('Bitte genau einen Artikel einfügen, keine Liste mehrerer Zitate.');
    if(preg_match('/^TY\s*-\s*\S+/m',$text))$result=citation_tagged($text,'RIS');
    elseif(preg_match('/^(?:PMID|FAU|JT|DP|TI)\s*-\s*\S+/m',$text))$result=citation_tagged($text,'nbib');
    else $result=citation_prose($text,$journalTitles);
    if(trim($result['data']['Titel'])==='')throw new InvalidArgumentException('Im Zitat wurde kein Artikeltitel gefunden.');
    foreach(['Titel'=>255,'DOI'=>255,'Band'=>50] as $field=>$limit)if(mb_strlen($result['data'][$field])>$limit)$result['warnings'][]='Die Angabe „'.$field.'“ überschreitet die Feldlänge. Bitte vor dem Speichern kürzen.';
    if(!$result['data']['authors'])$result['warnings'][]='Im Zitat wurden keine Autoren erkannt.';
    if(!$result['data']['Jahrgang'])$result['warnings'][]='Im Zitat wurde kein Erscheinungsjahr erkannt.';
    if($result['journal']['Titel']==='')$result['warnings'][]='Im Zitat wurde kein Journal erkannt.';
    if(count($result['data']['authors'])>50)$result['warnings'][]='Es wurden mehr als 50 Autoren erkannt. Bitte die Autorenliste vor dem Speichern anpassen.';
    $result['source']=$text;
    return $result;
}
function citation_draft(array $import, array $lookups): array
{
    $article=array_merge($import['data'],['ID'=>null,'JournalID'=>null,'authors'=>[],'categories'=>[],'projects'=>[],'attachments'=>[],'Kopiert'=>0]);
    foreach($import['data']['authors'] as $name) {
        $matches=array_values(array_filter($lookups['authors'],fn($author)=>citation_key((string)$author['label'])===citation_key($name)));
        $article['authors'][]=count($matches)===1?['ID'=>$matches[0]['ID'],'label'=>$matches[0]['label']]:['ID'=>null,'label'=>$name];
        if(count($matches)>1)$import['warnings'][]='Der Autor „'.$name.'“ ist mehrfach vorhanden. Bitte den passenden Eintrag mit ID auswählen.';
        if(mb_strlen($name)>50)$import['warnings'][]='Der Autorenname „'.$name.'“ ist länger als 50 Zeichen. Bitte anpassen.';
    }
    $title=$import['journal']['Titel'];
    if($title!=='') {
        $matches=array_values(array_filter($lookups['journals'],fn($journal)=>citation_key((string)$journal['Titel'])===citation_key($title)));
        if(!$matches)$matches=array_values(array_filter($lookups['journals'],fn($journal)=>citation_journal_equal($title,(string)$journal['Titel'])));
        if(count($matches)===1)$article['JournalID']=$matches[0]['ID'];
        else {
            $article['JournalID']='new';$article['new_journal']=$import['journal'];
            $import['warnings'][]=count($matches)>1?'Das Journal ist mehrdeutig. Bitte ein vorhandenes Journal auswählen.':'Das Journal wurde im Bestand nicht eindeutig gefunden. Es ist als neues Journal vorgemerkt; bitte die Auswahl prüfen.';
        }
    }
    return ['article'=>$article,'import'=>$import];
}
