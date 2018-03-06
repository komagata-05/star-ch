<?php
require_once('../../php_path.php');
require_once('../../init.php');
require_once("starchcheck.php");
require_once("starchauth.php");
require_once("membertran.php");
require_once("memberdao.php");
require_once 'starchmail.php';

//request
$request = TS_Request::getInstance();
$form = get_request_data($request);
$keys = preg_split("/[ ]+/", "key" );
$form = set_default_values($form, $keys);

//check
$check = new StarchCheck();
$template->assign("check", $check);

// member
$dao = new MemberDAO;
$member = $dao->getMemberByOneTimeKey($form["key"]);
if ( ! $member or $member->status != '9')
{
    show_error($ERROR_MESSAGES['MSC_REGISTER_INVALID_KEY']);
    exit();
}

// サービスID重複
if (!empty($member->service_user_id)) {
	if ($dao->getMemberByServiceUserId($member->service_user_id))
	{
		show_error($ERROR_MESSAGES['MSC_REGISTER_DUPLICATION_SERVICE_ID']);
		exit();
	}
}

//update
$memberTran = new MemberTran();
$form["member_id"] = $member->member_id;

// 本会員登録処理
$ret = $memberTran->updateCompleteMember($form);
if( ! $ret)
{
    show_error($ERROR_MESSAGES['NOT_INSERT']);
    exit();
}

// ログイン
//$auth = new StarchAuth();
//$auth->doLogin($member->email1, $form["password"]);
$_SESSION["member_id"] = $member->member_id;
$template->assign("my", $member);

$template->display(get_template_filename());
?>

