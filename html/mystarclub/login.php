<?php
require_once("../php_path.php");
require_once("../init.php");
require_once("starchcheck.php");
require_once("member.php");
require_once("memberdao.php");
require_once("starchauth.php");


// リクエスト取得
$request = TS_Request::getInstance();
$form = get_request_data($request);
$keys = preg_split("/[ ]+/", "login_id password auto_login");
$form = set_default_values($form, $keys);


//check
$check = new StarchCheck();
$check->checkLoginIdPw($form);
$template->assign("check", $check);

// Smarty
//$template =& StarchSmarty::getInstance();
//$template->assign($CONST);
$template->assign($form);

$hiddens = $form;
unset($hiddens["login_id"]);
unset($hiddens["password"]);
$template->assign("hiddens", $hiddens);


if ($form['btnLogin'] != '1')
{
    header("Location: ".get_server()."/mystarclub/");
    exit;
}

if ( ! $form["login_id"] or ! $form["password"])
{
    // どちらかが空の場合
    $template->assign("error_msg", "入力してください。");
    $template->display(get_template_dir()."/mystarclub/index_notlogin.html");
    exit();
}

if ($check->hasError())
{
    if ($check->hasError('login_id')) {
        $template->assign("error_msg", $check->printError('login_id'));
    } else if ($check->hasError('password')) {
        $template->assign("error_msg", $check->printError('password'));
    }
    $template->display(get_template_dir()."/mystarclub/index_notlogin.html");
    exit();
}

$starchauth = new StarchAuth();
$ret = $starchauth->doLogin($form["login_id"], $form["password"]);

if ( ! $ret)
{
    // 2016/10/18 既存会員フロー追加
    $memberDAO = new MemberDAO();
    $oldMember = $memberDAO->authenticateOldMember($form["login_id"], $form["password"]);
    if ($oldMember) {
        $hiddens['login_id'] = $form["login_id"];
        $hiddens['password'] = $form["password"];
        $template->assign("hiddens", $hiddens);
        $template->display(get_template_dir()."/mystarclub/oldmember_login/kiyaku.html");
        exit();
    }

    // ログイン失敗
    $template->assign("error_msg", "ログインIDかパスワードが間違っています。");
    $template->display(get_template_dir()."/mystarclub/index_notlogin.html");
    exit();
}

if ($form["auto_login"] == "on")
{
    setcookie(session_name(), session_id(), time() + $expire, '/');
}
else
{
    setcookie(session_name(), session_id(), -(time() + $expire), '/');
}


// ログイン成功。ラウンジへリダイレクト
//header("Location: ".get_sslserver().$starchauth->getContinueUri());
header("Location: ".get_server()."/mystarclub/");
exit;
?>
