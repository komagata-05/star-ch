﻿﻿<?php
require_once('../../php_path.php');
require_once('../../init.php');
require_once("starchcheck.php");
require_once("membertran.php");
require_once("memberdao.php");
require_once 'starchmail.php';
require_once("api/custinfoget.php");
require_once("affiliate_common.php");

function base64_decode_urlsafe($s){
    $s = (str_replace( array('_','-','.'), array('+','=','/'), $s));
    return($s);
}

function decrypt($encrypt_data, $member, $onetime_key)
{

    $one_time_key = $onetime_key - 1;
    $pass = 'x9vpf9k8ii98u22jf9xwg69ay6sd7kt734pjkyv6';
    $id = $member->member_id . $member->tocs_id;
    $key = $one_time_key . $pass . $id;

    //復号化
    $decrypt = openssl_decrypt(base64_decode_urlsafe($encrypt_data),'aes-256-ecb',$key);
    if(is_null($decrypt) || $decrypt == '' || $decrypt == false) return false;
    $exp_decrypt = explode('/', $decrypt);

    return $exp_decrypt;
}

//request
$request = TS_Request::getInstance();
$form = get_request_data($request);
$keys = preg_split("/[ ]+/", "key num s affiliate_session_id " );
$form = set_default_values($form, $keys);

//check
$check = new StarchCheck();
$template->assign("check", $check);

if(isset($_SESSION['ip_credit_entry_complete_flag']) && $_SESSION['ip_credit_entry_complete_flag'] == 1 )
{
    $template->display(get_template_filename());
    exit;
}
if(!isset($form["key"]))
{
    header('Location: ' . get_sslserver() . '/internettv/');
    exit();
}
/*
 * アフィリエイト用機能追加（2018.1.12）
 */
if(isset($_GET['s']) && !is_null($_GET['s']) && $_GET['s'] != '' && (mb_strlen($_GET['s']) == 8) && ctype_alnum($_GET['s']))
{
    $form['affiliate_session_id'] = $_GET['s'];
}

// member
$dao = new MemberDAO;
$member = $dao->getMemberByOneTimeKey($form["key"]);
if ( ! $member )
{
        show_error_code_itv($ERROR_CODE_ITV['ITV_REGISTER_INVALID_KEY']);
        exit();
}
$before_member = $member; // ステータスチェック用

/* TOCS更新（クレジット情報） */

try {
    $update_flag = 0;

    $memberTran = new MemberTran();
    $form["member_id"] = $member->member_id;
    $form['reserve_regist_date'] = null; // 解約予約日
    $form['regist_complete_date'] = date("Y-m-d H:i:s", time()); // 課金登録日
    $form['cancel_date'] = null; // 課金終了日

    if (!is_null($form['num'])) {
        $decrypt_data = decrypt($form['num'], $member, $form['key']);
    }

    $form["subscriber_status"] = 1; // 加入状況：認証OK
    $form["member_id"] = $member->member_id;
    $form["tocs_id"] = $member->tocs_id;
    $form['billInfoUpdate'] = 1;                // 更新要否		0：更新しない
    $form['sameKbn'] = 0;                        // 請求先同一区分　0：契約者と同じ
    $form['payMethod'] = '3';                   // 支払い方法　3：クレジット
    $form['cardMemberNum'] = (isset($decrypt_data[0])) ? $decrypt_data[0] : '';
    $form['creditCardNo4'] = (isset($decrypt_data[1])) ? $decrypt_data[1] : ''; // カード番号下4桁
    $form['creditCardLimit'] = (isset($decrypt_data[2])) ? $decrypt_data[2] : ''; // 有効期限

    // プレ会員なら更新
    if ($member->status == 1 && $member->subscriber_status == 9) {
        $form["onetime_key"] = '';
        $form["onetime_key_date"] = '';
        $ret = $memberTran->updateMember($form, true, 'itv');
        if (!$ret) {
            show_error_code_itv($ERROR_CODE_ITV['NOT_INSERT']);
            exit();
        }
        $update_flag = 1;
    }

    // 仮登録会員
    if ($member->status == 9) {
        // 本会員登録処理
        $ret = $memberTran->updateCompleteMemberItv($form, 'set_env', $member);
        if (!$ret) {
            show_error_code_itv($ERROR_CODE_ITV['NOT_INSERT']);
            exit();
        }
        $update_flag = 1;
    }

    // プラン申込
    if ($update_flag == 1 && $before_member->subscriber_status == 9) {
        // TOCS連携API：会員情報照会
        $tocsData['commonInfo']['bssCustID'] = $member->tocs_id;        // 基幹顧客ID(*)

        $custInfoGet = new CustInfoGet();
        $custInfoGet->get($tocsData);

        $keiyakuInfo = $custInfoGet->getKeiyakuInfo();
        if (!$keiyakuInfo) {
            show_error_code_itv($ERROR_CODE_ITV['ITV_MEMBER_COMMON']);
            exit();
        }

        // お支払い登録状況チェック
        $credit_info_flag = ($check->checkAlreadyRegisterInternettvMonthlyPlan($keiyakuInfo)) ? 1 : 0;

        if ($credit_info_flag == 0) {
            // TOCSクレジット追加申込
            $ret = $memberTran->setCustInfoAdd($form, 'itv');
            if (!$ret) {
                show_error_code_itv($ERROR_CODE_ITV['NOT_INSERT']);
                exit();
            }
        }
    }
    $_SESSION['ip_credit_entry_complete_flag'] = 1;

    /*
     * アフィリエイトタグ用追加（2018.1.12）
     */
    if ($form['affiliate_session_id'] != null)
    {
        send_to_smart($form['affiliate_session_id'], $member->member_id);
    }
}
catch (Exception $e)
{
    // ログ出力
    TS_LOG::ERROR('register complete : BSSsymphony error. $e:' . $e);

    show_error_code_itv($ERROR_CODE_ITV['ITV_MEMBER_COMMON']);
    exit();
}

// ログイン
$_SESSION["member_id"] = $member->member_id;

/*
 * ITVトップ用にSTARCHSESSID COOKIEをデフォルト有効期限0として保存（2018.02）
 */
setcookie(session_name(), session_id(), -(time() + $expire), '/');

$template->assign("my", $member);

$template->display(get_template_filename());
?>