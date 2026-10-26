<?php
declare(strict_types=1);

/** Explainable production rules for the Comments / Suggestions field. */
const POSITIVE_WORDS = [
 'excellent','exceptional','outstanding','superb','fantastic','wonderful','amazing','great','good','nice','best','helpful','friendly','kind','caring','compassionate','attentive','professional','courteous','polite','respectful','satisfied','happy','pleased','impressed','delighted','comfortable','efficient','fast','quick','prompt','timely','smooth','clean','organized','neat','thank','thanks','grateful','appreciate','appreciated','appreciation','recommend','recommended','commend','congratulate','praise',
 'magaling','maganda','ganda','maayos','mabilis','mabuti','masaya','mahusay','matulungin','mabait','malinis','malinaw',
 'maayo','nindot','paspas','salamat','buotan','limpyo','hapsay','klaro','komportable','ganahan','matinabangon','mahigalaon'
];
const NEGATIVE_WORDS = [
 'bad','poor','terrible','awful','horrible','worst','dreadful','unacceptable','unsatisfactory','inadequate','mediocre','substandard','rude','unfriendly','unkind','arrogant','dismissive','unhelpful','incompetent','negligent','careless','irresponsible','disrespectful','disappointed','dissatisfied','frustrated','upset','angry','ignored','neglected','waiting','delayed','slow','late','dirty','filthy','unclean','messy','disorganized','disgusting','shameful','outrageous','ridiculous','unprofessional','waste','wasted','useless','pointless','complaint','complain',
 'matagal','tagal','bastos','pangit','masama','mabagal','marumi','magulo','kulang',
 'dugay','lisod','problema','hassle','kasakit','huot','gubot','hinay','hugaw','sapot'
];
const NEUTRAL_HINT_WORDS=['okay','ok','average','fair','fine','acceptable','satisfactory','ordinary','normal','moderate','moderately'];
const NEGATION_WORDS=['not','no','never',"don't","didn't","wasn't","weren't","isn't","aren't","couldn't","wouldn't","shouldn't",'without','none','hindi','dili','di'];
const NEGATION_FILLERS=['at','all','very','really','quite','that','so','too','much','gaano','gaanong','masyado','kaayo','gyud','jud','man','ko','ako','koy','lang','lamang','ra','ka','nga','ang','ng','po','naman'];
const INTENSIFIER_WORDS=['very','extremely','really','absolutely','totally','completely','highly','so','too','quite','super','sobrang','napaka','masyado','kaayo','gyud','jud','grabe'];
const PREFIX_INTENSIFIER_FILLERS=['ka','nga','ang','ng','ra','jud','gyud'];
const POST_INTENSIFIERS=['kaayo','gyud','jud'];
const REQUEST_WORDS=['please','kindly','add','provide','install','increase','extend','improve','improvement','need','needs','needed','request','suggest','suggestion','faster','cleaner','kailangan','pakilinis','pakilinisan','pakiayos','paspasan','dugangan','butangan','unta'];
const CONTRAST_WORDS=['but','however','although','pero','ngunit','kaso','apan'];
const SAFE_SPELLING_VARIATIONS=['maau'=>'maayo','nindut'=>'nindot','buutan'=>'buotan','limpyu'=>'limpyo','klaru'=>'klaro','dugaya'=>'dugay','helpfull'=>'helpful'];

/** @return array{label:string,score:float,confidence:float,polarity:float} */
function analyzeSentiment(string $text): array {
 $normalized=sentimentNormalize(trim($text)); if($normalized===''||sentimentIsRatingsOnly($text))return sentimentResult('Neutral',0,0);
 $pattern='/\s+(?:'.implode('|',array_map(static fn($w)=>preg_quote($w,'/'),CONTRAST_WORDS)).')\s+/u';
 $clauses=preg_split($pattern,$normalized,-1,PREG_SPLIT_NO_EMPTY)?:[$normalized];
 $parts=array_map('sentimentAnalyzeClause',$clauses);
 $pos=array_sum(array_column($parts,'positive'));$neg=array_sum(array_column($parts,'negative'));
 $neutral=array_sum(array_column($parts,'neutral'));$requests=array_sum(array_column($parts,'requests'));
 $raw=array_sum(array_column($parts,'raw'));$words=max(1,array_sum(array_column($parts,'wordCount')));$evidence=$pos+$neg;
 $polarity=max(-1.0,min(1.0,$raw/sqrt((float)$words)));$confidence=sentimentConfidence($evidence,$words);
 // In an explicit contrast, the final evaluative clause is normally the conclusion.
 if(count($parts)>1){for($i=count($parts)-1;$i>=0;$i--){$p=$parts[$i];$clausePolarity=max(-1.,min(1.,$p['raw']/sqrt((float)max(1,$p['wordCount']))));if($p['positive']>0&&$p['negative']===0)return sentimentResult('Positive',max(.72,$confidence),$clausePolarity);if($p['negative']>0&&$p['positive']===0)return sentimentResult('Negative',max(.72,$confidence),$clausePolarity);if($p['positive']>0&&$p['negative']>0)return sentimentResult($clausePolarity>0?'Positive':($clausePolarity<0?'Negative':'Neutral'),max(.60,$confidence),$clausePolarity);}}
 if($pos>0&&$neg>0)return sentimentResult($polarity>0?'Positive':($polarity<0?'Negative':'Neutral'),max(.60,$confidence),$polarity);
 if($pos>0)return sentimentResult('Positive',$confidence,$polarity);if($neg>0)return sentimentResult('Negative',$confidence,$polarity);
 return sentimentResult('Neutral',($requests>0||$neutral>0)?max(.60,$confidence):0,0);
}

/** @return array{positive:int,negative:int,neutral:int,requests:int,raw:float,wordCount:int} */
function sentimentAnalyzeClause(string $text): array {
 $positivePhrases=['no problem','no problems','not bad','walang problema','walay problema','way problema','wala koy reklamo','no complaint','no complaints','well maintained','keep up the good work','job well done'];
 $negativePhrases=['not at all good','not good','hindi gaanong maganda','hindi maganda','hindi mabuti','dili kaayo maayo','dili maayo','dili nindot','di maayo','di nindot','long waiting time','long wait','needs improvement','need improvement','walang gamot','walay tambal','lack of medicine','out of stock','please clean the comfort room','please clean comfort room','please clean the cr','please clean cr','clean the cr','clean cr','improve waiting time','faster er response needed','walang malinaw na instructions'];
 $neutralPhrases=['add more chairs in the waiting area','add more chairs','maayo unta paspasan'];
 [$working,$positive,$ps]=sentimentExtractPhrases($text,$positivePhrases,1.5);[$working,$negative,$ns]=sentimentExtractPhrases($working,$negativePhrases,-1.5);[$working,$neutralPhraseMatches]=sentimentExtractPhrases($working,$neutralPhrases,0);
 $raw=$ps+$ns;$neutral=0;$requests=$neutralPhraseMatches;$negation=0;$multiplier=1.0;$lastSign=0;$distance=PHP_INT_MAX;
 $tokens=preg_split('/\s+/u',$working,-1,PREG_SPLIT_NO_EMPTY)?:[];
 foreach($tokens as $token){
  if(preg_match('/^[,;.!?]+$/u',$token)){$negation=0;$multiplier=1;$lastSign=0;$distance=PHP_INT_MAX;continue;}
  if(in_array($token,NEGATION_WORDS,true)){$negation=4;$multiplier=1;$distance++;continue;}
  if(in_array($token,INTENSIFIER_WORDS,true)){
   if(in_array($token,POST_INTENSIFIERS,true)&&$lastSign!==0&&$distance<=2)$raw+=.5*$lastSign;else $multiplier=1.5;
   if($negation>0)$negation--;$distance++;continue;
  }
  if($multiplier>1&&in_array($token,PREFIX_INTENSIFIER_FILLERS,true)){if($negation>0)$negation--;$distance++;continue;}
  if(in_array($token,REQUEST_WORDS,true)||preg_match('/^(improv|clean|fast|paspas|dugang|butang|paki)/u',$token))$requests++;
  if(in_array($token,NEUTRAL_HINT_WORDS,true))$neutral++;
  $pol=sentimentWordPolarity($token);
  if($pol!==0){if($negation>0)$pol*=-1;if($pol>0){$positive++;$raw+=$multiplier;$lastSign=1;}else{$negative++;$raw-=$multiplier;$lastSign=-1;}$distance=0;$negation=0;$multiplier=1;continue;}
  if($negation>0&&!in_array($token,NEGATION_FILLERS,true))$negation--;if($multiplier>1&&!in_array($token,PREFIX_INTENSIFIER_FILLERS,true))$multiplier=1;$distance++;
 }
 $plain=preg_replace('/[,;.!?]+/u',' ',$text)??$text;$wordCount=count(preg_split('/\s+/u',$plain,-1,PREG_SPLIT_NO_EMPTY)?:[]);
 return compact('positive','negative','neutral','requests','raw','wordCount');
}

function sentimentNormalize(string $text): string {
 if($text==='')return '';$text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
 $text=str_replace(['â€™','â€˜','`','-'],["'","'","'",' '],$text);$text=preg_replace('/[^\p{L}\p{N}\s\'.,;!?]/u',' ',$text)??'';$text=preg_replace('/([,;.!?])/u',' $1 ',$text)??$text;$text=trim(preg_replace('/\s+/u',' ',$text)??'');
 $words=preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];foreach($words as &$word){if(isset(SAFE_SPELLING_VARIATIONS[$word]))$word=SAFE_SPELLING_VARIATIONS[$word];if(preg_match('/^napaka(maayos|ganda|maganda|bait|mabait|bilis|mabilis|bagal|mabagal|pangit)$/u',$word,$m))$word='napaka '.$m[1];}unset($word);return implode(' ',$words);
}
/** @return array{0:string,1:int,2:float} */
function sentimentExtractPhrases(string $text,array $phrases,float $weight): array {$matches=0;$strength=0.;foreach($phrases as $phrase){$pattern='/(?<![\p{L}\p{N}])'.preg_quote($phrase,'/').'(?![\p{L}\p{N}])/u';$count=0;$text=preg_replace($pattern,' ',$text,-1,$count)??$text;$matches+=$count;$strength+=$count*$weight;}return[trim(preg_replace('/\s+/u',' ',$text)??''),$matches,$strength];}
function sentimentWordPolarity(string $token): int {if(in_array($token,POSITIVE_WORDS,true))return 1;if(in_array($token,NEGATIVE_WORDS,true))return -1;if(preg_match('/^(helpful|friendly|clean|quick|fast|kind|professional)(er|est|ly|ness)?$/u',$token))return 1;if(preg_match('/^(wait|delay|complain)(ed|ing|s|t)?$/u',$token)||preg_match('/^(slow|dirty|rude|late)(er|est|ly|ness)?$/u',$token)||preg_match('/^(matagal|tagal|mabagal|dugay|lisod|kulang|hinay|hugaw)/u',$token))return -1;return 0;}
function sentimentConfidence(int $evidence,int $words): float {return $evidence<=0?0:min(1.,.48+min(.32,.12*$evidence)+min(.20,$evidence/max(1,$words)));}
/** @return array{label:string,score:float,confidence:float,polarity:float} */
function sentimentResult(string $label,float $confidence,float $polarity): array {$confidence=round(max(0,min(1,$confidence)),4);return['label'=>$label,'score'=>$confidence,'confidence'=>$confidence,'polarity'=>round(max(-1,min(1,$polarity)),4)];}
function sentimentNormalizeLabel(string $label, string $text = ''): string {
 $canonical=['positive'=>'Positive','negative'=>'Negative','neutral'=>'Neutral'];
 $normalized=strtolower(trim($label));
 if(isset($canonical[$normalized]))return $canonical[$normalized];
 if($normalized!==''&&$text==='')return 'Neutral';
 $text=trim($text);
 if($text===''||sentimentIsRatingsOnly($text))return 'Neutral';
 return analyzeSentiment($text)['label'];
}
function sentimentIsRatingsOnly(string $text): bool {return preg_match('/^(?:[a-z][a-z0-9_]*\s*:\s*(?:(?:[1-5](?:\.\d+)?)|n\/a)?\s*;?\s*)+$/i',trim($text))===1;}
function sentimentDisplayLabel(array $sentiment): string {$label=sentimentNormalizeLabel((string)($sentiment['label']??''));$score=max(0,min(1,(float)($sentiment['score']??0)));return sprintf('%s (%d%%)',$label,(int)round($score*100));}
function computePriority(string $label,float $rating): string {return $label==='Negative'&&$rating>0&&$rating<=2?'High':'Normal';}
function computeDefaultStatus(string $label,bool $auto=false): string {return $label==='Positive'&&$auto?'resolved':'pending';}
if(isset($_GET['text'])&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){header('Content-Type: application/json; charset=utf-8');$text=(string)($_GET['text']??'');echo json_encode(['success'=>true,'input'=>$text,'result'=>analyzeSentiment($text)]);exit;}
