<?php
require_once("../php_path.php");
require_once("../init.php");
require_once("recommendtitledao.php");
require_once("specialdao.php");
require_once("rectanglebunnerdao.php");
require_once('timetable.php');
require_once('timetabledao.php');

// MY STAR CLUB URL
$myStarClubURL = "https://mystarclub.star-ch.jp/member/redir";
$template->assign("myStarClubURL", $myStarClubURL);
$date = strtotime("now");

//// 日付の取得
$currentDateTime = $BANGUMI_TODAY;
$today = preg_replace("/\-0/","-",$BANGUMI_TODAY);
$template->assign("today", $today);
$correntMonth = date("n");
$nextMonth = date('n', strtotime(date('Y-m-1').' +1 month'));
$compareNextDispMonth = date('Ym', strtotime(date('Y-m-1').' +1 month'));

$template->assign("correntMonth", $correntMonth); // 現在の月
$template->assign("nextMonth", $nextMonth); // 次月

// オススメ作品の取得
$recommendtitleDAO = new RecommendtitleDAO;
$specialDAO = new SpecialDAO;

$nextMonthFlag = 0;
// 作品情報を取得
$regulartitleList = $specialDAO->getSpecialAll();
$recommendtitleList = $recommendtitleDAO->getRecommendtitleLimitMonth($compareNextDispMonth);
if(!is_null($recommendtitleList)) $nextMonthFlag = 1;

//echo '<PRE>';
//var_dump($regulartitleList);
//echo '</PRE>';


$titleSum = array();
$openChkArray = array();
$copyright_movie = null;
foreach ($regulartitleList as $key => $item) {

    // timetable分割
    $exploadTimetable = explode(',', $item['timetable']);

    // それぞれ検査
    foreach ($exploadTimetable as $tid)
    {
        $timetableDAO = new TimetableDAO;
        $timetable = $timetableDAO->getTimetableById($tid);

        if(strtotime($timetable->broadcast_date) >= $date){
            $openChkArray[$item['special_id']] = 1;
        }
    }
    if(!isset($openChkArray[$item['special_id']]))
    {
        unset($regulartitleList[$key]);
    }

}

foreach ($regulartitleList as $key => $item) {

    // 表示タイトルまとめ
    if (!isset($titleSum[$item['special_id']])) $titleSum[$item['special_id']] = 1;
    else $titleSum[$item['special_id']] = $titleSum[$item['special_id']] + 1;

    if ($titleSum[$item['special_id']] > 1) {
        unset($regulartitleList[$key]);
    }
}

foreach ($regulartitleList as $key => $item) {
    $copyright_movie .= "『" . $item['title2'] . "』" . $item['copyright'];
}

// レクタングルバナー取得
$rectanglebunner = new RectanglebunnerDAO;
$rectanglebunnerList = $rectanglebunner->getRectanglebunnerBydateAndDispflg();

if(get_server() == "http://www.star-ch.jp"){
    foreach($rectanglebunnerList as $key=>$item){
        if($item->disp_flg_staging_only){
            unset($rectanglebunnerList[$key]);
        }
    }
}
foreach($rectanglebunnerList as $key=>$item){
    // コピーライト
    if($item->copyright && $item->copyright != ''){
        $copyright_movie .= $item->copyright;
    }
}

// 現在のページ判定
if(strcmp('regular', $_SERVER['REQUEST_URI'])) {
    $current_page = 'regular';
}
$template->assign("current_page", $current_page);

// コピーライト
$template->assign("copyright_movie", $copyright_movie);

// レクタングルバナー
$template->assign("rectanglebunnerList", $rectanglebunnerList);

// 次月表示フラグ
$template->assign("nextMonthFlag", $nextMonthFlag);

$template->assign("regulartitleList", $regulartitleList);

$template->assign("my", $my);
$template->assign("copyright_movie", $copyright_movie);
$template->assign("dispNum", 20);

$template->display(get_template_filename());
?>
