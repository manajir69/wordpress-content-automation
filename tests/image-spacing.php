<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Services/BlogAutomationService.php';
$method=new ReflectionMethod(App\Services\BlogAutomationService::class,'spaceArticleImages');
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$figures=array_map(fn($n)=>'<figure><img src="https://example.com/'.$n.'.jpg"></figure>',range(1,5));
foreach([1,2,3,5,12] as $n){
 $html='';for($i=0;$i<$n;$i++)$html.='<h2>Section '.$i.'</h2><p>Useful text '.$i.'.</p>';
 $out=$method->invoke(null,$html,$figures);
 check(substr_count($out,'<figure>')===min($n,5),"$n text blocks: image count capped to separated slots");
 check(!preg_match('~</figure>\s*<figure>~',$out),'no adjacent figures');
 check(preg_replace('~\s*<figure>.*?</figure>\s*~s','',$out)===$html,'text/heading order preserved');
}
$html='<p>Intro.</p><ul><li><p>Nested list text</p></li></ul><table><tr><td><p>Cell text</p></td></tr></table><p>Last.</p>';
$out=$method->invoke(null,$html,$figures);
check(!preg_match('~<(ul|table)>.*?<figure>.*?</\1>~s',$out),'never inserts inside list or table');
check($method->invoke(null,'<h2>Heading only</h2>',$figures)==='<h2>Heading only</h2>','no text means no image pile');
if (isset($argv[1])) {
$original=file_get_contents($argv[1]);
preg_match_all('~<figure\b[^>]*>.*?</figure>~is',$original,$matches);
$body=preg_replace('~<figure\b[^>]*>.*?</figure>~is','',$original);
$fixed=$method->invoke(null,$body,$matches[0]);
check(substr_count($fixed,'<figure')===count($matches[0]),'real article retains all images');
check(!preg_match('~</figure>\s*<figure~',$fixed),'real article has no image pile');
check(trim(preg_replace('~\s+~u',' ',strip_tags($original)))===trim(preg_replace('~\s+~u',' ',strip_tags($fixed))),'real article text unchanged');
if (isset($argv[2])) file_put_contents($argv[2],$fixed);
}
