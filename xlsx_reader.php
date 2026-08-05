<?php
declare(strict_types=1);

function xlsx_rows(string $path,int $sheetNumber=1): array {
    if(!class_exists('ZipArchive')) throw new RuntimeException('Excel reading is not enabled in PHP. Enable the zip extension in XAMPP.');
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('The Excel file could not be opened.');
    try{
        $shared=[];$xml=$zip->getFromName('xl/sharedStrings.xml');
        if($xml!==false){$dom=new DOMDocument();$dom->loadXML($xml);$xp=new DOMXPath($dom);foreach($xp->query('//*[local-name()="si"]') as $si){$text='';foreach($xp->query('.//*[local-name()="t"]',$si) as $t)$text.=$t->textContent;$shared[]=$text;}}
        $sheet=$zip->getFromName('xl/worksheets/sheet'.$sheetNumber.'.xml');if($sheet===false)return [];
        $dom=new DOMDocument();$dom->loadXML($sheet);$xp=new DOMXPath($dom);$rows=[];
        foreach($xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row){$result=[];foreach($xp->query('./*[local-name()="c"]',$row) as $cell){$ref=$cell->getAttribute('r');preg_match('/^[A-Z]+/',$ref,$m);$col=$m[0]??'';$type=$cell->getAttribute('t');$v=$xp->query('./*[local-name()="v"]',$cell)->item(0)?->textContent??'';if($type==='s')$v=$shared[(int)$v]??'';elseif($type==='inlineStr')$v=$xp->query('.//*[local-name()="t"]',$cell)->item(0)?->textContent??'';$result[$col]=trim((string)$v);}if($result)$rows[]=$result;}
        return $rows;
    }finally{$zip->close();}
}

function map_product_sheet(array $raw): array {
    if(!$raw)return [];$headers=[];foreach($raw[0] as $col=>$value)$headers[$col]=trim($value);$rows=[];
    foreach(array_slice($raw,1) as $index=>$source){$row=['_row'=>$index+2];foreach($headers as $col=>$name)$row[$name]=$source[$col]??'';if(array_filter($row,fn($v,$k)=>$k!=='_row'&&$v!=='',ARRAY_FILTER_USE_BOTH))$rows[]=$row;}return $rows;
}
