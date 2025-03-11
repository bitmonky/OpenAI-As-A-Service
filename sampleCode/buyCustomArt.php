<?php
include_once('../../whzon/mkysess.php');
include_once('../../whzon/gold/goldInc.php');
include_once('../../whzon/franMgr/wsRevInc.php');
ini_set('display_errors',1);
error_reporting(E_ALL);

$rcode = null;
$j = new stdClass;
$tran = new stdClass;
$prompt   = safeGET('prompt');  // THe Buyers text promt
$mode     = safeGET('mode');    // Portrai or Landscape
$amount   = safeGET('amount');
$pMUID    = safeGET('pMUID');
$pw       = safeGET('fpassword');
if (!$prompt || $prompt ==''){
  $prompt = "Portrait Ape Photorealistic";
}
if ($mode == 'portrait'){
  $prompt = mkyStrIReplace('portrait','',$prompt); 
  $prompt = 'portrait '.$prompt;
}
$msg = new stdClass;
$msg->action = "getImg";
$msg->prompt = $prompt;
$msg->n      = 1;
$msg->size   = "1024x1024";

$SQL = "SELECT curyName,curySpotPrice,curyAvgPrice FROM ICDirectSQL.tblCurrency where curyName = 'USD_CAD'";
$qres = mkyMsqry($SQL);
$rec  = mkyMsFetch($qres);
if (!$rec){
  fail('Database Not Available Try Later...');
}
$USDtoCAD = $rec['curySpotPrice'];
$SQL = "select ".$msg->n." * igenpPricePer costUSD from tblMkyImageGenPrice where igenpSize = '".$msg->size."'";

$qres = mkyMsqry($SQL);
$rec  = mkyMsFetch($qres);
if (!$rec){
  fail('Database Not Available Try Later...');
}
$cost = 1.50 * $rec['costUSD'] * $USDtoCAD;
$bmgpPrice = getCADToGoldn($cost);

if ($userID == 0){
  fail('Must Be Logged In');
}
/*****************************************
Begin Start Purchase
==========================================
*/

$SQL = "Select now() as dstamp";
$result = mkyMsqry($SQL);
$tRec = mkyMsFetch($result);
$frdatestamp = $tRec['dstamp'];

$txn_id    = "U".$userID."D".$frdatestamp;

if ($goldOnHand < $bmgpPrice){
  fail('Not Enough Gold For This Transaction');
}

if (!mkyStartTransaction()){
  fail('Could Not Start Transaction... Please Try Again Later');
}

$SQL = "Select count(*) as nRec from tblwzOrders where completed is null and NOT ordSubmitDStamp = '".$frdatestamp."'";
$result = mkyMsqry($SQL);
if (!$result) {
  tranFail('Could not create order... Try later');
}
$tRec = mkyMsFetch($result);
$duplicate = $tRec['nRec'];

$SQL = "INSERT INTO tblwzOrders (txnID,oEmail,amount,orderUID,ppPayerID,orderType,ppSubscribeID,pptxn_type,ordCartID,ordSubmitDStamp,isGoldPayment) ";
$SQL .= "values('".$txn_id."','NA',".$bmgpPrice.",".$userID.",'NA','Custom Profile Art Purchase Gold','NA','NA',null,'".$frdatestamp."',1)";
$result = mkyMsqry($SQL);

if (!$result) {
  mailAdmin('peter@bitmonky.com', 'OPEN AI Image Art Purchase Failed Warning', $SQL);
  tranFail('Could not create order... Try later');
}

$pUrl = "https://antsrv.bitmonky.com:".$GLOBALS['MKYC_portOPAI']."/netREQ/msg=".mkyUrlEncode(json_encode($msg));

$res = json_decode(getAccToken($pUrl));
if ($res){
  if (!$res->result){
    fail($res->message);
  }
}
else {
  fail ('mkyImgGen JSON Response Error:');
}
$img = array();
$i = 0;
foreach ($res->imgURLs->data as $idata){
  $img[$i] = storeImg($idata->url,$msg->prompt,$mbrMUID);
  $i = $i +1;
}

/*****************************************
Make Payment For purchase
==========================================
*/

if (spendGoldNewTax($bmgpPrice,$userID)){
  $SQL = "insert into tblGoldPurchaseLog (pUserID,amount,item) ";
  $SQL .= "values (".$userID.",".$bmgpPrice.",'Custom Art Work Purchase')";
  $result = mkyMsqry($SQL);
  if (!$result){
    tranFail();
  }
  $wzOrderID = mkyMyLastID();

  sendCustPurchaseEmail($userID,$bmgpPrice,$wzOrderID);
  mailAdmin('peter@bitmonky.com', 'Gold OPEN AI Image Art Purchase : $'.$bmgpPrice.' CAD', $SQL);
  if (!mkyCommit()){
    fail('Transaction Failed... Try Again Later');
  }
}
else {
  fail("You Don't Have Enough Gold To Purchase That Much Custom Art Work Right Now");
}
respond($img);

function respond($data){
  $result = true;
  fail('Purchase Complete',$data,$result);
}
function tranFail($msg='Database Transaction Failed... Try Later'){
  mkyRollback();
  fail($msg);
}
function sendCustPurchaseEmail($userID,$amt,$invoiceID){

  $SQL = "select email, firstname from tblwzUser where wzUserID=".$userID;
  $tRec = null;
  $result = mkyMsqry($SQL);
  $tRec = mkyMsFetch($result);

  if ($tRec){
    $email = $tRec['email'];
    $firstname = $tRec['firstname'];
  }
  else {
    $email = null;
  }

  if ($email){

    $m  =  "    Hello ".$firstname.", ";
    $m .=  "<P>You have succesfully make a purchase of Custom Art Work on bitmonky.com.";
    $m .=  "<p><b>Purchase Amount:</b> ".mkyNumFormat(0 + $amt,2)."BMGP";
    $m .=  "<p><b>Transaction Reference:</b> ".$invoiceID."";
    $m .=  "<p>Thank you for using bitmonky.com.";

    $m = getEHeader($m,'Custom Art Purchase Complete',$userID);


    wzSendMailNoBlock("bitmonky.com Purchases<support@monkytalk.com>", $email, "Custom Art Work Purchase Completed!", $m);
   }
}
function fail($msg,$data='',$result=false){
  $j = new stdClass;
  $j->result = $result;
  $j->message = $msg;
  $j->data = $data;
  $j->prompt = $GLOBALS['prompt'];
  $j->cost  = $GLOBALS['bmgpPrice'];
  exit(json_encode($j));
}
function getAccToken($url,$method='GET'){
    global $rcode;
    $crl = curl_init();
    $timeout = 5;
    curl_setopt ($crl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt ($crl, CURLOPT_URL,$url);
    curl_setopt ($crl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt ($crl, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt ($crl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt ($crl, CURLOPT_USERAGENT,$_SERVER['HTTP_USER_AGENT']);
    curl_setopt ($crl, CURLOPT_MAXREDIRS,5);
    curl_setopt ($crl, CURLOPT_REFERER, 'https://monkytalk/');
    curl_setopt ($crl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt ($crl, CURLOPT_HTTPHEADER , array(
      'accept: application/json',
      'content-type: application/json')
    );
    $ret = curl_exec($crl);
    $furl = curl_getinfo($crl, CURLINFO_EFFECTIVE_URL);
    if (!curl_errno($crl)) {
      $info = curl_getinfo($crl);
      $rcode = $info['http_code'];
    }

    curl_close($crl);
    return $ret;
}
function storeImg($url,$prompt,$mbrMUID,$imgType='png'){

  $imgdata= tryLFetchURL($url);
  if ($imgdata === False or $imgdata == ''){
    return false;
  }
  $im = imagecreatefromstring($imgdata);
  if ($im === false){
    return false;
  }
  $x = imagesx($im);
  $y = imagesy($im);
  $dwidth = $x;
  $dheight = $y;

  $new = imagecreatetruecolor($dwidth, $dheight);

  imagecopyresampled($new, $im, 0, 0, 0, 0, $dwidth, $dheight, $x, $y);

  ob_start();
    imagepng($new);
    $imagevariable = ob_get_contents();
  ob_end_clean();
  $imgout = $imagevariable;
  $imagevariable = addslashes($imagevariable);
  
  $md5 = hash('sha256',$imagevariable);

  $SQL = "Select count(*) as nRec from mkyGenArt.tblArtStoreImages where aimgMd5='".$md5."'";
  
  $myRec = null;
  $myresult = mkyMyqry($SQL);
  if ($myresult){$mRec = mkyMyFetch($myresult);}

  if ($mRec['nRec'] != 0) {
    return false;
  }

  $SQL = "Insert into mkyGenArt.tblArtStoreImages (aimgMUID,aimgPrompt,aimgImg,aimgWidth,aimgHeight,aimgType,aimgMd5,aimgArtType) ";
  $SQL .= "values ('".$mbrMUID."','".$prompt."','".$imagevariable."',".$dwidth.",".$dheight.",'".$imgType."','".$md5."','Profile')";

  $myresult = mkyMyqry($SQL);

  imagedestroy($im);
  return $md5;
}
?>
