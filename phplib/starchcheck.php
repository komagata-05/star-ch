<?php
// TODO 旧から
require_once("starch_common.php");
require_once("ts/check.php");
require_once("hexnum.php");
require_once("memberdao.php");
require_once("cpcommondao.php");
require_once("sityorankingdao.php");
require_once('specialdao.php');
require_once('slidedao.php');
require_once('campaigndao.php');
require_once('aes.class.php');
require_once('aesctr.class.php');
require_once('skapermatchingdao.php');
require_once('api/hikari_api.php');

class StarchCheck extends TS_Check {

    /*******************************************************************************
     * 変数
     *
     *  ○ 多数のフォームで使用する汎用的な変数
     *
     */

    // パラメータの$itemとキーが同じであるとき、メッセージの[###]をバリューと置換する
    var $items = array(
        );


    var $msgStrLength  = "[#MIN#]文字から[#MAX#]文字以内で入力してください。";  // 文字長不正時基本メッセージ
    var $msgDigit      = "[#MIN#]ケタから[#MAX#]ケタ以内で入力してください。";  // 桁数不正時基本メッセージ
    var $msgRange      = "[#MIN#]～[#MAX#]の範囲で入力してください。";          // 数字範囲不正時基本メッセージ
    var $msgHiragana   = "ひらがなで入力してください。";
    var $msgTelLength  = "全体の文字数が[#MIN#]文字から[#MAX#]文字以内になるように入力してください。";
    var $msgZipMobile  = "半角数字7桁で入力してください。";
    var $msgZenkakuTOCS = "全角で入力してください。";
    var $msgZenkakuKanaTOCS = "全角カタカナで入力してください。";

    /**
     *  ○ 個別のフォームでのみ使用する変数
     *
     */



    /**
     * コンストラクタ
     *
     * TS_Checkクラスで定義されたメッセージ(インスタンス変数)を
     * iniファイルで定義されたメッセージに置換する
     */
    function StarchCheck () {
        // TS_Checkで指定している基本メッセージを上書します
        // ここから下は固有のメッセージを指定します
        $this->msgSearchCond     = "少なくとも1つ以上の検索条件を入れてください。";
        $this->msgFileSize      = "サイズが大きすぎます。300Kb以下にしてください。";
        $this->msgFileExtension = "拡張子が不正です。「.jpg」「.gif」「.png」の何れかしかアップロードはできません。";
        $this->msgImportExtension = "拡張子が不正です。「.csv」しかアップロードはできません。";
        $this->msgAdminLoginId  = "半角英数字記号(._-)で入力してください。";
        $this->msgEmail         = "メールアドレスを正しく入力してください。";
    }

    /**
     * 個別のエラーメッセージを表示する
     *
     * スーパークラスのメソッドのオーバーライド
     * メッセージをリプレイスして表示する
     *
     * @param string $item    項目名
     */
    function printError ($item) {
        $error_messages = $this->getError($item);
        if (sizeof($error_messages) > 0) {
            $str_message = join("\n", $error_messages);
            // Smartyを使用しているのでそのまま返すだけ
            return $str_message;
        }
    }


    /**
     * 全てのエラーメッセージを表示する
     *
     * スーパークラスのメソッドのオーバーライド
     * メッセージをリプレイスして表示する
     *
     */
    function printErrorAll () {
        $error_messages = $this->getError();
        $arr_messages = array();
        foreach ($error_messages as $item=>$err_list) {
            $err_list = array_unique($err_list);  // 重複するメッセージを削除
            $str_message = join("\n", $err_list);
            array_push($arr_messages, $str_message);
        }
        return join("\n", $arr_messages);
    }


    /**
     * リストの値からチェック
     *
     * NG:
     * 必須時未選択  : 基本メッセージ[$msgSelect] メッセージ指定key[msgSelect]
     * 何か不正な入力: 基本メッセージ[$msgInvalid] メッセージ指定key[msgInvalid]
     *  → データがリストに含まれていない
     *
     * @param  string  $data チェック対象
     * @param  string  $item 項目名
     * @param  integer $list 選択できる要素のリスト
     * @param  integer $chk_null (1:nullチェック有り)
     * @param  array   $messages エラーメッセージ
     * @return bool
     */
    function checkList ($data, $item, $list, $chk_null=null, $messages=null) {
        // 入力チェック
        if ($chk_null) {
            $ret = $this->checkEmptySelect($data, $item, $messages);
            if (!$ret) {
                return false;
            }
        } else {
            if ($this->isEmpty($data)) {
                return true;
            }
        }

        // 範囲チェック
        if (!in_array($data, $list)) {
            $msg = $this->getErrorMessage("msgSelect", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }
        return true;
    }

    /**
     * アップロードファイルチェック
     *
     * OK:true
     * NG:false
     * @param String  $fileinfo チェック対象データ
     * @param String  $item     項目名
     * @param
     * @param
     * @param Integer $chk_null (1:nullチェック有り)
     */
    function checkFile ($fileinfo, $item, $chk_null=null, $messages=null) {
        $data = fetch($fileinfo, "name");

        //check null
        if ($chk_null) {
            $ret = $this->checkEmpty($data, $item, $messages);
            if (!$ret) {
                $msg = $this->getErrorMessage("msgInput", $messages);
                $this->addErrorMessage($item, $msg);
                return false;
            }
        }
        // check size
        $MAXSIZE = 10000000; // 10M
        $filesize = $fileinfo["size"];
        if($filesize > $MAXSIZE) {
            $msg = $this->getErrorMessage("msgFileSize", $messages);
            $this->addErrorMessage($item, $this->msgFileSize);
            return false;
        }
        // check extention
        $extension = $fileinfo["extension"];
        $EXTENSIONS = array("jpg","jpeg","gif","png");
        foreach($EXTENSIONS as $val) {
            if($extension == $val) {
                return true;
            }
        }
        $msg = $this->getErrorMessage("msgFileExtension", $messages);
        $this->addErrorMessage($item, $this->msgFileExtension);
        return false;
    }

    /**
     * アップロードファイルチェック
     *
     * OK:true
     * NG:false
     * @param String  $fileinfo チェック対象データ
     * @param String  $item     項目名
     * @param
     * @param
     * @param Integer $chk_null (1:nullチェック有り)
     */
    function checkCsvFile ($fileinfo, $item, $chk_null=null, $messages=null) {
        $data = fetch($fileinfo, "name");

        //check null
        if ($chk_null) {
		$ret = $this->checkEmpty($data, $item, $messages);
            if (!$ret) {
                //$msg = $this->getErrorMessage("msgInput", $messages);
                //$this->addErrorMessage($item, $msg);
                return false;
            }
        }
        // check size
        $MAXSIZE = 10000000; // 10M
        $filesize = $fileinfo["size"];
        if($filesize > $MAXSIZE) {
            $msg = $this->getErrorMessage("msgFileSize", $messages);
            $this->addErrorMessage($item, $this->msgFileSize);
            return false;
        }
        // check extention
        $extension = $fileinfo["extension"];
        $EXTENSIONS = array("csv");
        foreach($EXTENSIONS as $val) {
            if($extension == $val) {
                return true;
            }
        }
        $msg = $this->getErrorMessage("msgImportExtension", $messages);
        $this->addErrorMessage($item, $this->msgImportExtension);
        return false;
    }


    /**
     * 文字列の文字数チェック
     *
     * スーパークラスのメソッドのオーバーライド
     *
     * 文字列長<最小値、文字列長>最大値の場合はエラーメッセージを設定してfalseを返す
     *
     * @param  string  $data     チェック対象
     * @param  string  $item     項目名
     * @param  integer $min      最小値　バイト数じゃなくて文字数
     * @param  integer $max      最大値　バイト数じゃなくて文字数
     * @param  array   $messages エラーメッセージ(メッセージ指定key[msgLength])
     */
    function checkLength ($data, $item, $min, $max, $messages=null) {
        //$len = mb_strlen($data,"eucJP-win");
        $len = mb_strlen($data,"UTF-8");
        if ($len<$min || $len>$max) {
            $msg = $this->getErrorMessage("msgStrLength", $messages);
            $msg = str_replace('[#MIN#]',$min,$msg);
            $msg = str_replace('[#MAX#]',$max,$msg);
            $this->addErrorMessage($item , $msg);
            return false;
        }
        return true;
    }


    /**
     * 文字列のbyte長チェック
     *
     * 文字列長<最小値、文字列長>最大値の場合はエラーメッセージを設定してfalseを返す
     *
     * @param  string  $data     チェック対象
     * @param  string  $item     項目名
     * @param  integer $min      最小値
     * @param  integer $max      最大値
     * @param  array   $messages エラーメッセージ(メッセージ指定key[msgLength])
     */
    function checkDigit ($data, $item, $min, $max, $messages=null) {
        //$len = strlen($data);
	//UTF8対応 2013.6.13
        $len = mb_strlen($data,"UTF-8");
        if ($len<$min || $len>$max) {
            $msg = $this->getErrorMessage("msgDigit", $messages);
            $msg = str_replace('[#MIN#]',$min,$msg);
            $msg = str_replace('[#MAX#]',$max,$msg);
            $this->addErrorMessage($item , $msg);
            return false;
        }
        return true;
    }

    function isDigit ($data, $min, $max) {
        $len = strlen($data);
        if ($len<$min || $len>$max) {
            return false;
        }
        return true;
    }

    /**
     * 範囲チェック
     *
     * スーパークラスのメソッドのオーバーライド
     *
     * NG:
     * 必須時未選択  : 基本メッセージ[$msgSelect] メッセージ指定key[msgSelect]
     * 何か不正な入力: 基本メッセージ[$msgInvalid] メッセージ指定key[msgInvalid]
     *  → 数値じゃない
     *  → データ<最小値
     *  → データ>最大値
     *
     * @param  string  $data チェック対象
     * @param  string  $item 項目名
     * @param  integer $max  最大値
     * @param  integer $min  最小値
     * @param  integer $chk_null (1:nullチェック有り)
     * @param  array   $messages エラーメッセージ
     * @return bool
     */
    function checkRange ($data, $item, $min, $max, $chk_null=null, $messages=null) {
        // 入力チェック
        if ($chk_null) {
            $ret = $this->checkEmptySelect($data, $item, $messages);
            if (!$ret) {
                return false;
            }
        } else {
            if ($this->isEmpty($data)) {
                return true;
            }
        }

        // 数値チェック
        if (!$this->isInteger($data)) {
            $msg = $this->getErrorMessage("msgInvalid", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        $msg = $this->getErrorMessage("msgRange", $messages);
        $msg = str_replace('[#MIN#]',$min,$msg);
        $msg = str_replace('[#MAX#]',$max,$msg);

        // 範囲チェック
        if ($data < $min){
            $this->addErrorMessage($item, $msg);
            return false;
        }elseif ($data > $max){
            $this->addErrorMessage($item, $msg);
            return false;
        }
        return true;
    }

    /**
     * 範囲チェック(日付専用)
     *
     * スーパークラスのメソッドのオーバーライド
     *
     * NG:
     * 必須時未選択  : 基本メッセージ[$msgSelect] メッセージ指定key[msgSelect]
     * 何か不正な入力: 基本メッセージ[$msgInvalid] メッセージ指定key[msgInvalid]
     *  → 数値じゃない
     *  → データ<最小値
     *  → データ>最大値
     *
     * @param  string  $data チェック対象
     * @param  string  $item 項目名
     * @param  integer $max  最大値
     * @param  integer $min  最小値
     * @param  integer $chk_null (1:nullチェック有り)
     * @param  array   $messages エラーメッセージ
     * @return bool
     */
    function checkDateRange ($data, $item, $min, $max, $chk_null=null, $messages=null) {
        // 入力チェック
        if ($chk_null) {
            $ret = $this->checkEmptySelect($data, $item, $messages);
            if (!$ret) {
                return false;
            }
        } else {
            if ($this->isEmpty($data)) {
                return true;
            }
        }

        // 数値チェック
        if (!$this->isInteger($data)) {
            $msg = $this->getErrorMessage("msgInvalid", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        $msg = $this->getErrorMessage("msgRange", $messages);

        $min_date = $min;
        $max_date = $max;
        $len = strlen($data);
        if($len == 8) {      // 年月日
            $year  = substr($min, 0, 4);
            $month = substr($min, 4, 2);
            $day   = substr($min, 6, 2);
            $min_date = $year."年".$month."月".$day."日";
            $year  = substr($max, 0, 4);
            $month = substr($max, 4, 2);
            $day   = substr($max, 6, 2);
            $max_date = $year."年".$month."月".$day."日";
        } elseif($len == 6) {// 年月
            $year  = substr($min, 0, 4);
            $month = substr($min, 4, 2);
            $min_date = $year."年".$month."月";
            $year  = substr($max, 0, 4);
            $month = substr($max, 4, 2);
            $max_date = $year."年".$month."月";
        } elseif($len == 4) {// 年
            $year  = substr($min, 0, 4);
            $min_date = $year."年";
            $year  = substr($max, 0, 4);
            $max_date = $year."年";
        }

        $msg = str_replace('[#MIN#]',$min_date,$msg);
        $msg = str_replace('[#MAX#]',$max_date,$msg);

        // 範囲チェック
        if ($data < $min){
            $this->addErrorMessage($item, $msg);
            return false;
        }elseif ($data > $max){
            $this->addErrorMessage($item, $msg);
            return false;
        }
        return true;
    }

    /**
     * 英字チェック
     *
     * 英字:true
     * 英字以外:false
     *
     * @param  string  $data  チェック対象
     * @param  string  $item 項目名
     * @param  integer $min  最小値
     * @param  integer $max  最大値
     * @param  integer $chk_null (1:nullチェック有り)
     * @param  array   $messages エラーメッセージ
     */
    function checkAscii ($data, $item, $min, $max, $chk_null=null, $messages=null) {
        // 入力及びレングスチェック
        $ret = $this->checkText($data, $item, $min, $max, $chk_null);
        if(!$ret) {
            return false;
        }

        // 英字チェック
        if (!preg_match('/^([\x21-\x7E\x20])+$/', $data)) {
            $msg = $this->getErrorMessage("msgAlnum", $messages);
            $this->addErrorMessage($item, $msg);

            return false;
        }
        return true;
    }

    /**
     * ICカード番号チェック
     * 電話番号のチェックを{4-4-4-4}対応にしたもの
     */
    function checkCardId($ic1, $ic2, $ic3, $ic4, $item, $min, $max, $chk_null = null, $messages = null) {
        // 入力チェック
        if (!$chk_null && $this->isEmpty($ic1) && $this->isEmpty($ic2) && $this->isEmpty($ic3) && $this->isEmpty($ic4)) {
            // 任意で全て未入力時はtrue
            return true;
        } elseif ($this->isEmpty($ic1) || $this->isEmpty($ic2) || $this->isEmpty($ic3) || $this->isEmpty($ic4)) {
            // 必須任意にかかわらずどれか一つでも未入力はエラー
            //$this->checkEmpty($ic1, $item, $messages);
            //$this->checkEmpty($ic2, $item, $messages);
            //$this->checkEmpty($ic3, $item, $messages);
            //$this->checkEmpty($ic4, $item, $messages);
            $this->addErrorMessage($item, '4桁ずつ半角数字でご記入ください。');
            return false;
        }

        // 数値チェック
        if (!$this->isNumeric($ic1) || !$this->isNumeric($ic2) || !$this->isNumeric($ic3) || !$this->isNumeric($ic4)) {
            $msg = $this->getErrorMessage("msgInvalid", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        // 桁数チェック
        $messages = array();
        $ret1 = $this->isDigit ($ic1, $min[0], $max[0]);
        $ret2 = $this->isDigit ($ic2, $min[1], $max[1]);
        $ret3 = $this->isDigit ($ic3, $min[2], $max[2]);
        $ret4 = $this->isDigit ($ic4, $min[3], $max[3]);
        if (!$ret1 || !$ret2 || !$ret3 || !$ret4) {
            $this->addErrorMessage($item, '4桁ずつ半角数字でご記入ください。');
            return false;
        }

        return true;
    }

    /**
     * BCASカード番号チェック
     * 電話番号のチェックを{4-4-4-4-4}対応にしたもの
     */
    function checkBcasCardId($ic1, $ic2, $ic3, $ic4, $ic5, $item, $min, $max, $chk_null = null, $messages = null) {
        // 入力チェック
        if (!$chk_null && $this->isEmpty($ic1) && $this->isEmpty($ic2) && $this->isEmpty($ic3) && $this->isEmpty($ic4) && $this->isEmpty($ic5)) {
            // 任意で全て未入力時はtrue
            return true;
        } elseif ($this->isEmpty($ic1) || $this->isEmpty($ic2) || $this->isEmpty($ic3) || $this->isEmpty($ic4) || $this->isEmpty($ic5)) {
            // 必須任意にかかわらずどれか一つでも未入力はエラー
            //$this->checkEmpty($ic1, $item, $messages);
            //$this->checkEmpty($ic2, $item, $messages);
            //$this->checkEmpty($ic3, $item, $messages);
            //$this->checkEmpty($ic4, $item, $messages);
            //$this->checkEmpty($ic5, $item, $messages);
            $this->addErrorMessage($item, '4桁ずつ半角数字でご記入ください。');
            return false;
        }

        // 数値チェック
        if (!$this->isNumeric($ic1) || !$this->isNumeric($ic2) || !$this->isNumeric($ic3) || !$this->isNumeric($ic4) || !$this->isNumeric($ic5)) {
            $msg = $this->getErrorMessage("msgInvalid", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        // 桁数チェック
        $ret1 = $this->isDigit ($ic1, $min[0], $max[0]);
        $ret2 = $this->isDigit ($ic2, $min[1], $max[1]);
        $ret3 = $this->isDigit ($ic3, $min[2], $max[2]);
        $ret4 = $this->isDigit ($ic4, $min[3], $max[3]);
        $ret5 = $this->isDigit ($ic5, $min[4], $max[4]);
        if (!$ret1 || !$ret2 || !$ret3 || !$ret4 || !$ret5) {
            $this->addErrorMessage($item, '4桁ずつ半角数字でご記入ください。');
            return false;
        }

        return true;
    }

     /**
     * BCASカード番号チェック
     * スカパーオンデマンド
     * 電話番号のチェックを{5-5}対応にしたもの
     */
    function checkBcasCardIdEx($ic1, $ic2, $item, $min, $max, $chk_null = null, $messages = null) {
        // 入力チェック
        if (!$chk_null && $this->isEmpty($ic1) && $this->isEmpty($ic2)) {
            // 任意で全て未入力時はtrue
            return true;
        } elseif ($this->isEmpty($ic1) || $this->isEmpty($ic2)) {
            $this->addErrorMessage($item, '5桁ずつ半角数字でご記入ください。');
            return false;
        }

        // 数値チェック
        if (!$this->isNumeric($ic1) || !$this->isNumeric($ic2)) {
            $msg = $this->getErrorMessage("msgInvalid", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        // 桁数チェック
        $ret1 = $this->isDigit ($ic1, $min[0], $max[0]);
        $ret2 = $this->isDigit ($ic2, $min[1], $max[1]);
        if (!$ret1 || !$ret2) {
            $this->addErrorMessage($item, '5桁ずつ半角数字でご記入ください。');
            return false;
        }

        return true;
    }

    /**
     * CATVお客様番号（10桁）チェック
     */
    function checkCatvID($catvID, $item, $chk_null = null, $messages = null) {

        // 入力チェック
        if ( ! $chk_null && $this->isEmpty($catvID))
        {
            // 任意で全て未入力時はtrue
            return true;
        }
        elseif ($this->isEmpty($catvID))
        {
            $this->addErrorMessage($item, '半角数字をご記入ください。');
            return false;
        }

        // 半角英数字チェック
        if ( ! $this->isAlnum($catvID))
        {
            $msg = $this->getErrorMessage("msgInvalid", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        // 桁数チェック
//        if ( ! $this->isDigit($catvID, 10, 10))
//        {
//            $this->addErrorMessage($item, '10桁の半角数字をご記入ください。');
//            return false;
//        }

        return true;
    }

    /**
     * CATVコード（4桁）チェック
     */
    function checkCatvBureau($catvBureau, $item, $chk_null = null, $messages = null) {

        // 入力チェック
        if ( ! $chk_null && $this->isEmpty($catvBureau))
        {
            // 任意で全て未入力時はtrue
            return true;
        }
        elseif ($this->isEmpty($catvBureau))
        {
            $this->addErrorMessage($item, '4桁の半角英数字をご記入ください。');
            return false;
        }

        // 半角英数字チェック
        if ( ! $this->isAlnum($catvBureau))
        {
            $msg = $this->getErrorMessage("msgInvalid", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        // 桁数チェック
        if ( ! $this->isDigit($catvBureau, 4, 4))
        {
            $this->addErrorMessage($item, '4桁の半角英数字をご記入ください。');
            return false;
        }

        return true;
    }

    /**
     * ひかりTV契約番号（10桁）チェック
     */
    function checkHikariTvID($hikariID, $item, $chk_null = null, $messages = null) {

        // 入力チェック
        if ( ! $chk_null && $this->isEmpty($hikariID))
        {
            // 任意で全て未入力時はtrue
            return true;
        }
        elseif ($this->isEmpty($hikariID))
        {
            $this->addErrorMessage($item, '10桁の半角数字をご記入ください。');
            return false;
        }

        // 半角英数チェック
        if ( ! $this->isAlnum($hikariID))
        {
            $msg = $this->getErrorMessage("msgInvalid", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        // 桁数チェック
        if ( ! $this->isDigit($hikariID, 10, 10))
        {
            $this->addErrorMessage($item, '10桁の半角数字をご記入ください。');
            return false;
        }

        return true;
    }

    /**
     * santakuテーマ／作品情報の日付のチェック
     */
    function checkStkDate($data, $item, $chk_null=null) {
        if (!$chk_null && $this->isEmpty($data)) {
            return true;
        }
        //$date = split("[-|/]", $data);
        $date = preg_split("/[-|\/]/", $data);
        if(count($date) == 3){
            if (strtotime($data) < 0) {
                // 「-」と「/」が混ざっているとDB更新時にエラーになるので
                $this->addErrorMessage($item, $this->msgDate);
                return false;
            } else {
                $ret = $this->checkDate($date[0], $date[1], $date[2], $item, $chk_null);
                return $ret;
            }
        } else {
            $this->addErrorMessage($item, $this->msgDate);
            return false;
        }
        return true;
    }

    /**
     * 画像ファイルの拡張子のチェック
     */
    function checkExtensions($data, $item, $messages=null) {
        if($data == "jpg" or $data == "jpeg" or $data == "gif" or $data == "png") {
        } else {
            $msg = $this->getErrorMessage("msgFileExtension", $messages);
            $this->addErrorMessage($item, $this->msgFileExtension);
        }
    }

    /**
     * ひらがなチェック
     *
     * ひらがな(「ぁ」-「ん」、「ー」):true
     * 上記以外:false
     * 必須時未入力    : 基本メッセージ[$msgInput] メッセージ指定key[msgInput]
     * 文字列長<最小値 : 基本メッセージ[$msgLength] メッセージ指定key[msgLength]
     * 文字列長>最大値 : 基本メッセージ[$msgLength] メッセージ指定key[msgLength]
     * 半角カナまじり  : 基本メッセージ[$msgKanaHanNg] メッセージ指定key[msgKanaHanNg]
     *
     * @param  string  $data     チェック対象
     * @param  string  $item     項目名
     * @param  integer $min      最小値
     * @param  integer $max      最大値
     * @param  integer $chk_null 1:必須
     * @param  array   $messages エラーメッセージ
     * @return bool
     */
    function checkHiragana ($data, $item, $min, $max, $chk_null=null, $messages=null) {
        $ret = $this->checkText($data, $item, $min, $max, $chk_null, $messages);
        if(!$ret) {
            return false;
        }
        if($data) {
            $ret = $this->isHiragana($data);
            if(!$ret) {
                $msg = $this->getErrorMessage("msgHiragana", $messages);
                $this->addErrorMessage($item, $msg);
            }
        }
    }

    /**
     * 日付チェック
     *
     * NG:
     * 必須時未入力     : 基本メッセージ[$msgInput] メッセージ指定key[msgInput]
     * 日付不正         : 基本メッセージ[$msgDate]  メッセージ指定key[msgDate]
     *
     * @param  string  $data  チェック対象  年月日（YYYYMMDD）
     * @param  string  $item  項目名
     * @param  integer $chk_null (1:nullチェック有り)
     * @param  array   $messages エラーメッセージ
     * @return bool
     */
    function checkDateText ($data, $item, $chk_null = null, $messages = null) {

        // 入力チェック
        if ( ! $chk_null && $this->isEmpty($data))
        {
            // 任意で全て未入力時はtrue
            return true;
        }
        else
        {
            if ( ! $this->checkEmptySelect($data, $item))
            {
                return false;
            }
        }

        // 桁数チェック
        if (strlen($data) != 8)
        {
            $msg = $this->getErrorMessage("msgDate", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        // 整数チェック
        if ( ! $this->isInteger($data))
        {
            $msg = $this->getErrorMessage("msgDate", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        // 日付チェック
        if ( ! checkdate(substr($data, 4, 2), substr($data, 6, 2), substr($data, 0, 4)))
        {
            $msg = $this->getErrorMessage("msgDate", $messages);
            $this->addErrorMessage($item, $msg);
            return false;
        }
        return true;
    }

    /**
     * 電話番号チェックを拡張する
     * checkTelAllに全体の文字数の範囲チェックを追加する
     *
     */
    function checkTelAll ($data, $item, $min, $max, $chk_null=null, $messages=null, $all_length_min = 10, $all_length_max = 12) {
        // checkTelAll
        $ret = parent::checkTelAll ($data, $item, $min, $max, $chk_null=null, $messages=null) ;
        if(!$ret){
            return false;
        }
        $tel = explode("-", $data);

        $len = strlen($tel[0].$tel[1].$tel[2]);
        // 全部の入力文字数をチェックする$all_length_min以上、$all_length_max以下でない場合はエラー
        if($len < $all_length_min || $len >  $all_length_max ){
            $msg = $this->getErrorMessage("msgTelLength", $messages);
            $msg = str_replace('[#MIN#]',$all_length_min,$msg);
            $msg = str_replace('[#MAX#]',$all_length_max,$msg);
            $this->addErrorMessage($item, $msg);
            return false;
        }

        return true;
    }

    /**
     * 全角チェック（TOCS連携API仕様の全角記号チェック含む）
     *
     */
    function checkZenkakuTOCS($data, $item, $min, $max, $chk_null=null, $messages=null)
    {
        global $EM_SYMBOL_ALLOW_LIST;

        // チェックテキスト
        $ret = parent::checkText($data, $item, $min, $max, $chk_null, $messages);
        if ( ! $ret) return false;

        if ( ! $chk_null && $this->isEmpty($data))
        {
            return true;
        }
        else
        {
            // 許容文字は対象から除外する
            $reData = str_replace($EM_SYMBOL_ALLOW_LIST, '', $data);

            // 第1,第2水準漢字チェック
            $str = mb_convert_encoding($reData, 'ISO-2022-JP', 'UTF-8');
            $str = mb_convert_encoding($str, 'UTF-8', 'ISO-2022-JP');
            if ($reData != $str)
            {
                $this->addErrorMessage($item, '登録できない文字が含まれています。');
                return false;
            }

            // 全角チェック
            if (!$this->isEmpty($str) && !preg_match('/^[ぁ-んァ-ヶ一-龠々Ａ-Ｚａ-ｚ０-９]+$/u', $str))
            {
                $msg = $this->getErrorMessage("msgZenkakuTOCS", $messages);
                $this->addErrorMessage($item, $msg);
                return false;
            }
        }

        return true;
    }

    /**
     * 全角カタカナチェック（TOCS連携API仕様の全角記号チェック含む）
     * TOCSの全角カタカナは次を含む
     * 　全角英字小文字
     * 　全角英字大文字
     * 　全角数字
     * 　全角記号を含む
     */
    function checkZenkakuKanaTOCS($data, $item, $min, $max, $chk_null=null, $messages=null)
    {
        global $EM_SYMBOL_ALLOW_LIST;

        // チェックテキスト
        $ret = parent::checkText($data, $item, $min, $max, $chk_null, $messages);
        if ( ! $ret) return false;

        if ( ! $chk_null && $this->isEmpty($data))
        {
            return true;
        }
        else
        {
            // 許容文字は対象から除外する
            $reData = str_replace($EM_SYMBOL_ALLOW_LIST, '', $data);

            // 第1,第2水準漢字チェック
            $str = mb_convert_encoding($reData, 'ISO-2022-JP', 'UTF-8');
            $str = mb_convert_encoding($str, 'UTF-8', 'ISO-2022-JP');
            if ($reData != $str)
            {
                $this->addErrorMessage($item, '登録できない文字が含まれています。');
                return false;
            }

            // 全角カタカナチェック
            if (!$this->isEmpty($str) && !preg_match('/^[ァ-ヶＡ-Ｚａ-ｚ０-９]+$/u', $str))
            {
                $msg = $this->getErrorMessage("msgZenkakuKanaTOCS", $messages);
                $this->addErrorMessage($item, $msg);
                return false;
            }
        }

        return true;
    }


    /**
     * 電話番号チェックを拡張する
     * checkTelAllに全体の文字数の範囲チェックを追加する
     * ハイフンなし
     */
    function checkTelText ($data, $item, $chk_null=null, $messages=null)
    {
        // 入力チェック
        if ($chk_null)
        {
            $ret = $this->checkEmpty($data, $item, $messages);
            if ( ! $ret) return false;
        }
        else
        {
            if ($this->isEmpty($data)) return true;
        }

        // 数値チェック
        if ( ! $this->isInteger($data))
        {
            $msg = $this->getErrorMessage("msgInvalid", $messages);
            $this->addErrorMessage($item, '半角数字10桁または11桁で入力してください。');
            return false;
        }

        // 桁数チェック
        $len = strlen($data);
        // 全部の入力文字数をチェックする$all_length_min以上、$all_length_max以下でない場合はエラー
        if ($len < 10 || $len > 11)
        {
            $this->addErrorMessage($item, '半角数字10桁または11桁で入力してください。');
            return false;
        }

        return true;
    }

    /**
     * 郵便番号チェック(携帯用)
     *
     * NG:
     * 必須時未入力     : 基本メッセージ[$msgInput] メッセージ指定key[msgInput]
     * フォーマット不正 : 基本メッセージ[$msgZip] メッセージ指定key[msgZip]
     *
     * @param  string  $zip チェック対象
     * @param  string  $item 項目名
     * @param  integer $chk_null (1:nullチェック有り)
     * @param  array   $messages エラーメッセージ
     * @return bool
     */
    function checkZipMobile ($zip, $item, $chk_null=null, $messages=null) {
        if ($chk_null) {
            $ret = $this->checkEmpty($zip, $item, $messages);
            if (!$ret) {
                return false;
            }
        }

        if($zip) {
            if (strlen($zip)!=7) {
                // 桁数が不正
                $msg = $this->getErrorMessage("msgZipMobile", $messages);
                $this->addErrorMessage($item, $msg);
                return false;
            } elseif(!$this->isInteger($zip)) {
                // 数値じゃない
                $msg = $this->getErrorMessage("msgZipMobile", $messages);
                $this->addErrorMessage($item, $msg);
                return false;
            }
        }

        return true;
    }

    /**
     * NULL、NULLStringチェック
     *
     * NULL、""、false：true
     * 上記以外：false
     * 数値0や文字列"0"はfalse
     * (empty()を使うと、数値0や文字列"0"はtrueになってしまう)
     *
     * @param  string $data  チェック対象
     * @return bool
     */
    function isEmpty ($data) {
        if ($data === NULL) {
            return true;
        } elseif ($data === "") {
            return true;
        } elseif ($data === false) {
            return true;
        } elseif (preg_match('/^[　 ]+$/', $data)) {
            return true;
        }
        return false;
    }

	/**
	 * サービスIDの重複チェック
	 */
	function checkDupServiceID($data, $serviceUserID) {
		$memberDAO = new MemberDAO;
		$member = $memberDAO->getMemberByServiceUserId($serviceUserID);
		if ($member) {

			if (isset($data["member_id"]) && $member->member_id == $data["member_id"]) {
				// 変更の場合は自分のID除外
			} else {
				$this->addErrorMessage($data["service_error"],'入力されたサービスIDは、既にMY STAR CLUB会員に登録されています。');
				return false;
			}

		}
		return true;
	}

	/**
	 * サービスID, 局コードの重複チェック(CATV用)
	 */
	function checkDupServiceIDCatv($data, $serviceUserID, $bureauCode) {
		$memberDAO = new MemberDAO;
		$member = $memberDAO->getMemberByServiceUserIdAndBureauCode($serviceUserID, $bureauCode);
		// 会員存在かつ局コードが同じ
		if ($member) {
			if (isset($data["member_id"])) {
				if ($member->member_id == $data["member_id"]) {
					// 変更の場合は自分のID除外
				} else {
					return false;
				}
			} else {
				if (isset($data["email1"]) && $member->email1 == $data["email1"]) {
					// CATV局員は同一メールアドレスの更新処理が可能の為重複チェックから除外
				} else {
					return false;
				}
			}

		}
		return true;
	}


	/**
	 * PF認証：ひかりAPI
	 */
	function checkPFfHikari($data, $serviceUserID) {
		$data["service_user_id"] = $serviceUserID;	// 基本契約番号

		$hikariAPI = new HIKARI_API;
		$result = $hikariAPI->send($data);
		if ($result != $hikariAPI->RT_OK) {
			// 契約件数なし、合致データなし
			if ($result == $hikariAPI->RT_NOT_CONTRACT || $result == $hikariAPI->RT_NOT_MATCH) {
				$this->addErrorMessage($data["service_error"], '入力された契約番号では、スターチャンネルの契約が確認できませんでした。');
			// 姓異常
			} else if ($result == $hikariAPI->RT_NG_SEI) {
				$this->addErrorMessage('name', 'ご契約者氏名（姓）が違います。');
			// 名異常
			} else if ($result == $hikariAPI->RT_NG_MEI) {
				$this->addErrorMessage('name','ご契約者氏名（名）が違います。');
			} else if ($result == $hikariAPI->API_NG) {
				$this->addErrorMessage($data["service_error"],'現在メンテナンス中です。しばらく経ってから再度お試しください。');
			}
			return false;
		}
		return true;
	}

	/**
	 * PF認証：スカパー
	 */
	function checkPFSkaper($data, $serviceUserID) {
		global $AES_KEY, $SKAPER_MATCHING_ONLY_BCAS_LIST, $SKAPER_MATCHING_NOT_ALLOW_LIST;

		$skaperMatchingDAO = new SkaperMatchingDAO();
		$skaperMatchingList = $skaperMatchingDAO->getSkaperMatchingListByDeliveryDate(array($serviceUserID));

		$newSkaperMatching = isset($skaperMatchingList[0]) ? $skaperMatchingList[0] : null;

		// 氏名カナ複合化
		$nameKana = AesCtr::decrypt($newSkaperMatching->name_kana, $AES_KEY, 128);

		if (empty($newSkaperMatching)) {
			$this->addErrorMessage($data["service_error"],'入力されたサービスIDでは、スターチャンネルへの加入が確認できませんでした。再度、ご契約のサービスIDをご確認ください。<br />'
            . '万が一、ご契約のサービスIDでスターチャンネルへの加入確認が取れない場合は、上にある「後から登録する」にて、MY STAR CLUBプレ会員登録を行ってください。<br />プレ会員登録後、契約者氏名をご確認の上、登録情報変更から契約情報を追加して再度登録してください。<br />'
            . '<br />'
            . 'エラーが解決せず登録完了しない場合はスターチャンネルカスタマーセンターへお問い合わせください。<br />'
            . '0570-013-111 または、044-540-0809（10:00～18:00、年中無休）<br />'
            . '※お電話でお問い合わせの際は、事前に<a href="/privacy/" target="_blank">「スターチャンネル個人情報保護方針」</a>の内容をご確認いただき、ご同意の上お電話ください。');
			return false;
		}

		// BCAS番号のみで認証(※BCAS番号のみで判定する局番)
		if (in_array($newSkaperMatching->catv_no, $SKAPER_MATCHING_ONLY_BCAS_LIST)) {
			if ($newSkaperMatching->bcas_no != $serviceUserID) {
				$this->addErrorMessage($data["service_error"],'入力されたサービスIDでは、スターチャンネルへの加入が確認できませんでした。再度、ご契約のサービスIDをご確認ください。<br />'
                    . '万が一、ご契約のサービスIDでスターチャンネルへの加入確認が取れない場合は、上にある「後から登録する」にて、MY STAR CLUBプレ会員登録を行ってください。<br />プレ会員登録後、契約者氏名をご確認の上、登録情報変更から契約情報を追加して再度登録してください。<br />'
                    . '<br />'
                    . 'エラーが解決せず登録完了しない場合はスターチャンネルカスタマーセンターへお問い合わせください。<br />'
                    . '0570-013-111 または、044-540-0809（10:00～18:00、年中無休）<br />'
                    . '※お電話でお問い合わせの際は、事前に<a href="/privacy/" target="_blank">「スターチャンネル個人情報保護方針」</a>の内容をご確認いただき、ご同意の上お電話ください。');
				return false;
			}
		// 認証NG(認証があっていてもNGにする局番）
		} else if (in_array($newSkaperMatching->catv_no, $SKAPER_MATCHING_NOT_ALLOW_LIST)){
			$this->addErrorMessage($data["service_error"],'ご契約のケーブルテレビ局では、MY STAR CLUB会員登録の準備中のためご利用いただけません。登録可能開始時期につきましては改めてスターチャンネルホームページ等でご案内いたします。ただし、MY STAR CLUBプレ会員への登録は可能です。');
			return false;
		// 通常はBCAS番号と氏名カナで認証
		} else {
			if (($newSkaperMatching->bcas_no != $serviceUserID) ||
				($nameKana != $data['name_sei_kana'].$data['name_mei_kana'])) {
					$this->addErrorMessage('name_kana','ご契約者氏名（フリガナ）が違います。');
					return false;
			}
		}
		return true;
	}

    function checkPref($data, $item, $list, $chk_null=null, $messages=null) {
        // 入力チェック
        if ($chk_null) {
            $ret = $this->checkEmpty($data, $item);
            if (!$ret) {
                return false;
            }
        } else {
            if ($this->isEmpty($data)) {
                return true;
            }
        }

        // 範囲チェック
        if (!in_array($data, $list)) {
            $msg = '都道府県が正しくありません。';
            $this->addErrorMessage($item, $msg);
            return false;
        }
        return true;
    }

    /*****************************************************************************/
    /*
     * フォームなどの個別のチェック
     *
     * 単純なフォームの入力時チェックには、
     *   「check【Add|Update】【クラス名】」
     * というメソッド名になる(例: checkAddFavoriteActor(), checkUpdateFavoriteDirector())
     *
     * 管理者とユーザでチェック内容が異なる場合は、メソッド名の後ろに「Admin」をつける。
     *
     */

    /**
     * インポートファイルの入力チェック
     */
    function checkImportFile ($fileinfo) {
        $this->checkCsvFile($fileinfo, 'movie_data', 1);
    }

    /**
     * インポートファイルの入力チェック
     */
    function checkRecommendImportFile ($fileinfo) {
        $this->checkCsvFile($fileinfo, 'recommend_data', 1);
    }

    /**
     * インポートファイルの入力チェック(お気に入り俳優マスタ用)
     */
    function checkMactorImportFile ($fileinfo) {
        $this->checkCsvFile($fileinfo, 'mactor_data', 1);
    }

    /**
     * インポートファイルの入力チェック(お気に入り監督マスタ用)
     */
    function checkMdirectorImportFile ($fileinfo) {
        $this->checkCsvFile($fileinfo, 'mdirector_data', 1);
    }

    /**
     * インポートファイルの入力チェック(オンデマンドマスタ用)
     */
    function checkOndemandImportFile ($fileinfo) {
        $this->checkCsvFile($fileinfo, 'ondemand_data', 1);
    }

    /**
     * インポートファイルの入力チェック(Patlaborキャンペーン用)
     */
    function checkPatlaborCpImportFile ($fileinfo) {
        $this->checkCsvFile($fileinfo, 'patlabor_cp_data', 1);
    }

    /**
     * フリーワード検索の入力チェック
     */
    function checkSearchFreeword ($form) {
        global $CHANNEL_TBL,$SEARCH_KIND_TBL;

        $freeword = $form["free_word"];

        $delete_mark_str = delete_mark($freeword);
        $delete_mark_str = str_replace(" ", "", $delete_mark_str);
        if(!$delete_mark_str){
            $this->addErrorMessage("free_word", "フリーワードは空白や記号文字だけでは検索できません。");
        }
        /*
        $this->checkText ($form["free_word"], "free_word", 1, 50, 1);
        $this->checkList ($form["free_word_channel_id"], "free_word_channel_id", array_keys($CHANNEL_TBL), 1);
        $this->checkList ($form["free_word_search_kind"],"free_word_search_kind", array_keys($SEARCH_KIND_TBL), 1);
         */
    }

    function checkSearchMovie ($form) {
        $err_flg = false;

        // キーワード1文字はエラー
        if (isset($form['kw']) && mb_strlen($form['kw']) == 1) {
            $err_flg = true;
            $this->addErrorMessage("kw", "キーワードは2文字以上入力してください。");
        }

        if ($err_flg) return false;

        return true;
    }

    /**
     * 放送形式検索の入力チェック
     */
    function checkSearchBroadcastForm ($form, $GENRE_TBL) {
        global $CHANNEL_TBL,$BROADCAST_FORM_TBL;

        $this->checkList ($form["broadcast_form_channel_id"],     "broadcast_form_channel_id",     array_keys($CHANNEL_TBL), 1);
        $this->checkList ($form["broadcast_form_genre_id"],       "broadcast_form_genre_id",       array_keys($GENRE_TBL), 0);
        $this->checkList ($form["broadcast_form"], "broadcast_form", array_keys($BROADCAST_FORM_TBL), 0);

        if(is_empty($form["broadcast_form_genre_id"]) && is_empty($form["broadcast_form"])){
            $this->addErrorMessage("broadcast_form", "ジャンルか放送形式のいずれかを選択してください。");
        } elseif(!is_empty($form["broadcast_form_genre_id"]) && !is_empty($form["broadcast_form"])){
            $this->addErrorMessage("broadcast_form", "ジャンルか放送形式のいずれかを選択してください。");
        }
    }


    /**
     * ケーブル局検索の入力チェック
     */
    function checkSearchCable ($form) {
        global $PREF_TBL;
        $this->checkRange ($form["pref"], "pref", 1, count($PREF_TBL), true);
    }
    /**
     * ケーブル局検索の詳細画面表示前のチェック
     */
    function checkSearchCableDetail ($form) {
        $this->checkNumeric($form["cable_id"], "cable_id", 9, true);
    }



    /**
     * 50音検索の入力チェック
     */
    function checkSearchInitial ($form) {
        global $CHANNEL_TBL,$SEARCH_KIND_TBL,$INITIAL_TBL;

        $this->checkList ($form["initial_id"],  "initial_id",         array_keys($INITIAL_TBL), 1);
        $this->checkList ($form["initial_channel_id"],  "initial_channel_id", array_keys($CHANNEL_TBL), 1);
        $this->checkList ($form["initial_search_kind"], "initial_search_kind",array_keys($SEARCH_KIND_TBL), 1);
    }


    /**
     * 番組表TOPのパラメータチェック  /////番組表JSON形式への移行に伴い廃止予定！！！！！
     */
    function checkTimetableTOP ($form) {
        global $CHANNEL_TBL;
        $date = explode("-",$form["date"]);
        if(count($date) == 3){
            $this->checkDate ($date[0], $date[1], $date[2], "date", 1, "");
        } else {
            $this->addErrorMessage("date", $this->msgDate);
        }

        $cal_date = explode("-",$form["cal_date"]);
        if(count($date) >= 2){
            $this->checkDate ($cal_date[0], $cal_date[1], 1, "cal_date", 1, "");
        } else {
            $this->addErrorMessage("cal_date", $this->msgDate);
        }
        $this->checkList ($form["channel_id"], "channel_id",array_keys($CHANNEL_TBL), 1);
    }

    /**
     * 携帯用番組表のパラメータチェック
     */
    function checkTimetableMobile ($form) {
        global $CHANNEL_TBL, $BANGUMI_TODAY;
        if ($this->checkDate ($form["year"], $form["month"], $form["day"], "date", 1, "")) {
            // 今日から90日以内におさまっているか
            $time = ts_mktime(0, 0, 0, $form["month"], $form["day"], $form["year"]);
            list($y, $m, $d) = explode("-", $BANGUMI_TODAY);
            if ($time < ts_mktime(0, 0, 0, $m ,$d, $y) ||
                $time > ts_mktime(0, 0, 0, $m, $d + 90, $y)) {
                $this->addErrorMessage("date", "本日から90日以内の日付を選んでください");
            }
        }

        $this->checkList ($form["channel_id"], "channel_id",array_keys($CHANNEL_TBL), 1);
    }


    /**
     * 携帯フリーワード検索の入力チェック
     */
    function checkSearchFreewordMobile ($form) {
        global $CHANNEL_TBL,$SEARCH_KIND_TBL;

        $freeword = $form["free_word"];

        $delete_mark_str = delete_mark($freeword);
        $delete_mark_str = str_replace(" ", "", $delete_mark_str);
        if(!$delete_mark_str){
            $this->addErrorMessage("free_word", "フリーワードは空白や記号文字だけでは検索できません。");
        } else {
            $this->checkText ($form["free_word"], "free_word", 1, 50, 1);
        }
        $this->checkList ($form["free_word_search_kind"],"free_word_search_kind", array_keys($SEARCH_KIND_TBL), 1);
    }


    /**
     * 携帯お知らせメール入力チェック
     */
     function checkAddMyprogramMobile ($form) {
         global $CHANNEL_TBL;

         if (!is_array($form["mail_service"]) || count($form["mail_service"]) <= 0 ) {
             $this->addErrorMessage("mail_service", "登録する番組を選択してください。");
         } else {
             foreach ($form["mail_service"] as $val) {
                 if (!$this->isInteger($val)) {
                     $this->addErrorMessage("mail_service", "不正なIDです。");
                     break;
                 }
             }
         }

         $this->checkNumeric($form["movie_id"], "movie_id", 9, 0);
         $this->checkList($form["channel_id"], "channel_id", array_keys($CHANNEL_TBL), 0);
         $this->checkNumeric($form["myprogram_id"], "myprogram_id", 9, 0);
         $this->checkList($form["detail_flg"], "detail_flg", array(0,1), 0);
         $this->checkNumeric($form["year"], "year", 4, 0);
         $this->checkNumeric($form["month"], "month", 2, 0);
         $this->checkNumeric($form["day"], "day", 2, 0);
     }

    /**
     * お気に入り俳優マスタの登録時チェック
     */
    function checkAddMActor($form) {
        $messages = "";

        // 俳優日本語名
        $this->checkText($form["jname"], 'md_jname', 1, 50, 1);

        // 俳優ふりがな
        $this->checkText($form["jkana"], 'md_jkana', 0, 50, 0);

        // 俳優英語名
        $this->checkAscii  ($form["ename"], 'md_ename', 1, 50, 1);
    }

    /**
     * お気に入り俳優マスタの更新時チェック
     */
    function checkUpdateMActor($form) {
        $messages = "";

        // 俳優日本語名
        $this->checkText($form["jname"], 'md_jname', 1, 50, 1);

        // 俳優ふりがな
        $this->checkText($form["jkana"], 'md_jkana', 0, 50, 0);

        // 俳優英語名
        $this->checkAscii  ($form["ename"], 'md_ename', 1, 50, 1);
    }

    /**
     * ジャンルマスタの登録時チェック
     */
    function checkAddGenre($form) {
        $messages = "";
        // ジャンル名日本語
        // 必須チェック
        $ret = $this->checkEmpty($form["genre_name"], 'genre_name');
        if (!$ret) {
            $this->getErrorMessage("msgInput", $messages);
        } else {
            // 文字列長のチェック、長さはDB参照
            $this->checkLength ($form["genre_name"], 'genre_name', 1, 50);
        }
    }



    /**
     * トピックスマスタの登録時チェック
     */
    function checkAddTopic($form) {

        $this->checkText ($form['topic_content'], 'topic_content', 0, 500, true);
        $this->checkText ($form['url'], 'url', 0, 200, true);
        $check_open_date = $this->checkDate ($form['open_date_year'],$form['open_date_month'],$form['open_date_day'],  'open_date', true);
        $check_close_date = $this->checkDate ($form['close_date_year'],$form['close_date_month'],$form['close_date_day'], 'close_date', true);
        if($check_open_date && $check_close_date) {
            if(mktime(0,0,0,$form['close_date_month'],$form['close_date_day'],$form['close_date_year']) <
               mktime(0,0,0,$form['open_date_month'],$form['open_date_day'],$form['open_date_year'])) {
                $this->addErrorMessage('open_date', '終了日よりも過去の日付を指定してください');
            }
        }
        $this->checkRange ($form['viewable'], 'viewable', 0, 4, true);
        $this->checkRange ($form['top_view_flag'], 'top_view_flag', 0, 1);
        $this->checkNumeric($form["disp_order"], "disp_order", 9, true);
    }
    /**
     * トピックスマスタの更新時チェック
     */
    function checkUpdateTopic($form) {

        $this->checkText ($form['topic_content'], 'topic_content', 0, 500, true);
        $this->checkText ($form['url'], 'url', 0, 200, true);

        $check_open_date = $this->checkDate ($form['open_date_year'],$form['open_date_month'],$form['open_date_day'],  'open_date', true);
        $check_close_date = $this->checkDate ($form['close_date_year'],$form['close_date_month'],$form['close_date_day'], 'close_date', true);
        if($check_open_date && $check_close_date) {
            if(mktime(0,0,0,$form['close_date_month'],$form['close_date_day'],$form['close_date_year']) <
               mktime(0,0,0,$form['open_date_month'],$form['open_date_day'],$form['open_date_year'])) {
                $this->addErrorMessage('open_date', '終了日よりも過去の日付を指定してください');
            }
        }
        $this->checkRange ($form['viewable'], 'viewable', 0, 3, true);
        $this->checkRange ($form['top_view_flag'], 'top_view_flag', 0, 1);
        $this->checkNumeric($form["disp_order"], "disp_order", 9, true);
        $this->checkNumeric($form["topic_id"], "topic_id", 9, true);
    }


    /**
     * ジャンルマスタの更新時チェック
     */
    function checkUpdateGenre($form) {
        $messages = "";
        // ジャンル名日本語
        // 必須チェック
        $ret = $this->checkEmpty($form["genre_name"], 'genre_name');
        if (!$ret) {
            $this->getErrorMessage("msgInput", $messages);
        } else {
            // 文字列長のチェック、長さはDB参照
            $this->checkLength ($form["genre_name"], 'genre_name', 1, 50);
        }
        // ジャンルID
        $this->checkRange ($form["genre_id"], 'genre_id', 0, 32767, true);
    }

    /**
     * ログインID/PWチェック
     */
    function checkLoginIdPw($form)
    {
        // ログインID
        $this->checkEmpty($form["login_id"], 'login_id');

        // パスワード
        if ($this->checkEmpty($form["password"], 'password'))
        {
            if ($this->isAlnum($form["password"]))
            {
                $messages = array("msgDigit" => "パスワードは半角英数字8～12文字でご記入ください。");
                $this->checkDigit($form["password"], 'password', 8, 12, $messages);
            }
            else
            {
                $this->addErrorMessage('password', 'パスワードは半角英数字8～12文字でご記入ください。');
            }
        }
    }

    /**
     * 新メンバーズの登録時チェック（利用規約ページ）
     */
    function checkAddMemberKiyaku($form)
    {
        global $KANYU;

        //kanyu
        $this->checkList($form["star_ch_id"], 'star_ch_id', array_keys($KANYU), 1);
    }

    /**
     * 新メンバーズの登録時チェック
     *
     * 加入者
     */
    function checkAddMember($form, $isQuick=false)
    {
        global $KANYU_TYPE_TBL, $MAIL_RECEIVE_TYPE_TBL, $SEX, $PREF_TBL;

//        //kanyu
//        $this->checkList($form["star_ch_id"], 'star_ch_id',
//            array_keys($KANYU), 1);

        // メールアドレス1
        $isNotNullEmail = $this->checkEmpty($form["email1"], 'email1');
        $isNotNullEmailConf = $this->checkEmpty($form["email1_confirm"], 'email1_confirm');
        if ($isNotNullEmail and $isNotNullEmailConf)
        {
            $same = $this->checkSameData($form["email1"], $form["email1_confirm"], 'email1_confirm', 1);
            if ($same)
            {
                if ($this->checkEmail($form["email1"], 'email1', 100, null, 1))
                {
                    //重複チェック
                    $memberDAO = new MemberDAO;
                    $ret = $memberDAO->checkMemberByEmail($form['email1']);
                    if ($ret)
                    {
                        $this->addErrorMessage('email1','このアドレスはすでに登録されています。');
                    }
                }
            }
        }

        // パスワード SHA256 対応(2013/07)
        $isNotNullPass = $this->checkEmpty($form["password"], 'password');
        $isNotNullPassConf = $this->checkEmpty($form["password_confirm"], 'password_confirm');
        if ($isNotNullPass and $isNotNullPassConf)
        {
            if ($this->isAlnum($form["password"]))
            {
                $same = $this->checkSameData($form["password"], $form["password_confirm"], 'password_confirm', 1);
                if ($same)
                {
                    $messages = array("msgDigit" => "半角英数字8～12文字でご記入ください。");
                    $this->checkDigit($form["password"], 'password', 8, 12, $messages);
                }
            } else {
                $this->addErrorMessage('password', '半角英数字8～12文字でご記入ください。');
            }
        }

        // メールアドレス2
        if ( ! $this->isEmpty($form["email2"]) or ! $this->isEmpty($form["email2_confirm"]))
        {
            $isNotNullEmail = $this->checkEmpty($form["email2"], 'email2');
            $isNotNullEmailConf = $this->checkEmpty($form["email2_confirm"], 'email2_confirm');
            if ($isNotNullEmail and $isNotNullEmailConf)
            {
                $same = $this->checkSameData($form["email2"], $form["email2_confirm"], 'email2_confirm', 1);
                if ($same)
                {
                    $this->checkEmail ($form["email2"], 'email2', 100, null, null);
                }
            }
        }

        // 「MY番組表」お知らせメール
        $this->checkList($form["mail_info_flag"], 'mail_info_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        if ($form["mail_info_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_info_flag', 'メールアドレス2が登録されていません。');
        }

        // メールマガジンの配信
        $this->checkList($form["mail_mag_flag"], 'mail_mag_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        if ($form["mail_mag_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_mag_flag', 'メールアドレス2が登録されていません。');
        }

        // お名前
        $isNameCheckOk = false;
        if ($this->checkText($form["name_sei"], "name", 1, 48, 1) and $this->checkText($form["name_mei"], "name", 1, 48, 1))
        {
            if ($this->checkZenkakuTOCS($form['name_sei'] . $form['name_mei'], 'name', 1, 49, 1))
            {
                $isNameCheckOk = true;
            }
        }

        // お名前（フリガナ）
        $isNameKanaCheckOk = false;
        if ($this->checkText($form["name_sei_kana"], "name_kana", 1, 48, 1) and $this->checkText($form["name_mei_kana"], "name_kana", 1, 48, 1))
        {
            if ($this->checkZenkakuKanaTOCS($form['name_sei_kana'] . $form['name_mei_kana'], 'name_kana', 1, 49, 1))
            {
                $isNameKanaCheckOk = true;
            }
        }

        // 郵便番号
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', 1);

        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);

        // 住所
		$this->checkZenkakuTOCS($form['address1'], 'address1', 1, 100, 1);

        // マンション名等
		$this->checkZenkakuTOCS($form['address2'], 'address2', 1, 64, null);

        // 電話番号
        $this->checkTelText($form['tel'], 'tel', 1);

        // 生年月日
        if ($this->checkDateText($form['birth_y'].$form['birth_m'].$form['birth_d'], 'birth', 1))
        {
            $this->checkDateRange($form['birth_y'].$form['birth_m'].$form['birth_d'], 'birth', 19000101, date("Ymd",time()), 1);
        }

        // 性別
        $this->checkList($form["sex"], 'sex', array_keys($SEX), 1);

        if ( ! $isQuick)
        {
            // 加入しているテレビサービス名
            $this->checkList($form["service_type"], 'service_type', array_keys($KANYU_TYPE_TBL), 1);

            $isServiceUserIDCheckOk = false;
            if ($form["service_type"] == '1')
            {
                // スカパー！：BCAS（20桁）
                $isServiceUserIDCheckOk = $this->checkBcasCardId(
                    $form['ts_sky_bcas1'], $form["ts_sky_bcas2"], $form['ts_sky_bcas3'],
                    $form["ts_sky_bcas4"], $form["ts_sky_bcas5"],
                    'ts_sky_bcas',
                    array(4,4,4,4,4), array(4,4,4,4,4),
                    1);
            }
            else if ($form["service_type"] == '2')
            {
                // スカパー！プレミアムまたはスカパー！プレミアム光：ICカード（16桁）
                $isServiceUserIDCheckOk = $this->checkCardId(
                    $form["ts_sky_card1"], $form["ts_sky_card2"], $form["ts_sky_card3"],
                    $form["ts_sky_card4"],
                    'ts_sky_card',
                    array(4,4,4,4), array(4,4,4,4),
                    1);
            }
            else if ($form["service_type"] == '3')
            {
                // ケーブルテレビ：BCAS（20桁）
                $isServiceUserIDCheckOk = $this->checkBcasCardId(
                    $form['ts_catv_bcas1'], $form["ts_catv_bcas2"], $form['ts_catv_bcas3'],
                    $form["ts_catv_bcas4"], $form["ts_catv_bcas5"],
                    'ts_catv_bcas',
                    array(4,4,4,4,4), array(4,4,4,4,4),
                    1);
            }
            else if ($form["service_type"] == '4')
            {
                // ひかりTV：ひかりTV契約番号（10桁）
                $isServiceUserIDCheckOk = $this->checkHikariTvID($form['ts_hikaritv'], 'ts_hikaritv', 1);
            }
            else if ($form["service_type"] == '5')
            {
                // J:COM：CCAS（20桁）
                $isServiceUserIDCheckOk = $this->checkBcasCardId(
                    $form['ts_jcom_ccas1'], $form["ts_jcom_ccas2"], $form['ts_jcom_ccas3'],
                    $form["ts_jcom_ccas4"], $form["ts_jcom_ccas5"],
                    'ts_jcom_ccas',
                    array(4,4,4,4,4), array(4,4,4,4,4),
                    1);
            }
            else if ($form["service_type"] == '7')
            {
                // スカパーオンデマンド：お客様番号（10桁）
                $isServiceUserIDCheckOk = $this->checkBcasCardIdEx(
                    $form['ts_sky_ondemand1'], $form["ts_sky_ondemand2"],
                    'ts_sky_ondemand',
                    array(5,5), array(5,5),
                    1);
            }

			if ($isServiceUserIDCheckOk and is_array($form['service_user_id'])) {
				$serviceUserID = implode('', $form['service_user_id']);
				// 既に登録されているサービスIDチェック
				if ($serviceUserID != '' && $this->checkDupServiceID($form, $serviceUserID)) {
					// PF認証：スカパー、JCOM関連 契約者氏名カナでチェック
					if (in_array($form["service_type"], array('1', '2', '3', '5', '7')))
					{

                        // ご契約氏名（フリガナ）
						if ($isNameKanaCheckOk)
						{
                            // スカパー：PF認証チェック
                            $this->checkPFSkaper($form, $serviceUserID);
						}

					// PF認証：ひかりTV 契約者氏名でチェック
					} else if ($form["service_type"] == '4') {

                        // ご契約氏名
						if ($isNameCheckOk)
						{
                            // ひかりTV：PF認証チェック
                            $this->checkPFfHikari($form, $serviceUserID);
						}
					}
				}
			}
        }
    }

    /**
     * 新メンバーズの登録時チェック
     *
     * 未加入者
     */
    function checkAddPreMember($form, $type=null)
    {
        global $MAIL_RECEIVE_TYPE_TBL, $SEX, $PREF_TBL;

        $isRequired = null;
        $itvFlag = false;
        // ITVの場合フラグ
        if(!is_null($type) && $type == 'itv')
        {
            $isRequired = 1;
            $itvFlag = true;
        }

        // メールアドレス1
        $isNotNullEmail = $this->checkEmpty($form["email1"], 'email1');
        $isNotNullEmailConf = $this->checkEmpty($form["email1_confirm"], 'email1_confirm');
        if ($isNotNullEmail and $isNotNullEmailConf)
        {
            $same = $this->checkSameData($form["email1"], $form["email1_confirm"], 'email1_confirm', 1);
            if ($same)
            {
                if ($this->checkEmail($form["email1"], 'email1', 100, null, 1))
                {
                    //重複チェック
                    $memberDAO = new MemberDAO;
                    $ret = $memberDAO->checkMemberByEmail($form['email1']);
                    if ($ret)
                    {
                        $member = $memberDAO->getMemberByEmail($form["email1"]);
                        if(!is_null($type))
                        {
                            if(!$this->checkPfForMemberInfoChange($member->service_type, $type) && $member->subscriber_status == 1)
                            {
                                $this->addErrorMessage('email1','すでにスターチャンネル放送契約がありMY STAR CLUBに登録されている方は同一メールアドレスの登録ができません。');
                            }
                            elseif($member->service_type == 9 && !is_null($member->reserve_regist_date))
                            {
                                $this->addErrorMessage('email1','このアドレスはすでに登録されています。');
                            }
                            elseif($member->subscriber_status == 1)
                            {
                                $this->addErrorMessage('email1','このアドレスはすでに登録されています。');
                            }
                            //#20170930
                            //elseif($member->subscriber_status == 0 || $member->subscriber_status == 9)
                            //{
                            //    $this->addErrorMessage('email1','このアドレスはすでに登録されています。');
                            //}
                        }
                        else
                        {
                            $this->addErrorMessage('email1','このアドレスはすでに登録されています。');
                        }
                    }
                }
            }
        }

        // パスワード SHA256 対応(2013/07)
        $isNotNullPass = $this->checkEmpty($form["password"], 'password');
        $isNotNullPassConf = $this->checkEmpty($form["password_confirm"], 'password_confirm');
        if ($isNotNullPass and $isNotNullPassConf)
        {
            if ($this->isAlnum($form["password"]))
            {
                $same = $this->checkSameData($form["password"], $form["password_confirm"], 'password_confirm', 1);
                if ($same)
                {
                    $messages = array("msgDigit" => "半角英数字8～12文字でご記入ください。");
                    $this->checkDigit($form["password"], 'password', 8, 12, $messages);
                }
            } else {
                $this->addErrorMessage('password', '半角英数字8～12文字でご記入ください。');
            }
        }

        // メールアドレス2
        if ( ! $this->isEmpty($form["email2"]) or ! $this->isEmpty($form["email2_confirm"]))
        {
            $isNotNullEmail = $this->checkEmpty($form["email2"], 'email2');
            $isNotNullEmailConf = $this->checkEmpty($form["email2_confirm"], 'email2_confirm');
            if ($isNotNullEmail and $isNotNullEmailConf)
            {
                $same = $this->checkSameData($form["email2"], $form["email2_confirm"], 'email2_confirm', 1);
                if ($same)
                {
                    $this->checkEmail ($form["email2"], 'email2', 100, null, null);
                }
            }
        }

        // 「MY番組表」お知らせメール
        if($itvFlag) {
            $this->checkList($form["mail_info_flag"], 'mail_info_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        }

        // メールマガジンの配信
        $this->checkList($form["mail_mag_flag"], 'mail_mag_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        if ($form["mail_mag_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_mag_flag', 'メールアドレス2が登録されていません。');
        }

        // お名前
        if($itvFlag)
        {
            $isNameCheckOk = false;
            if ($this->checkText($form["name_sei"], "name", 1, 48, 1) and $this->checkText($form["name_mei"], "name", 1, 48, 1))
            {
                if ($this->checkZenkakuTOCS($form['name_sei'] . $form['name_mei'], 'name', 1, 49, 1))
                {
                    $isNameCheckOk = true;
                }
            }
            else
            {
                if ( ! $this->isEmpty($form["name_sei"]) or ! $this->isEmpty($form["name_sei"]))
                {
                    if ($this->checkText($form["name_sei"], "name", 1, 48, 1) and $this->checkText($form["name_mei"], "name", 1, 48, 1))
                    {
                        $this->checkZenkakuTOCS($form['name_sei'] . $form['name_mei'], 'name', 1, 49, 1);
                    }
                }
            }
        }
        else
        {
            if ( ! $this->isEmpty($form["name_sei"]) or ! $this->isEmpty($form["name_sei"]))
            {
                if ($this->checkText($form["name_sei"], "name", 1, 48, 1) and $this->checkText($form["name_mei"], "name", 1, 48, 1))
                {
                    $this->checkZenkakuTOCS($form['name_sei'] . $form['name_mei'], 'name', 1, 49, 1);
                }
            }
        }

        // お名前（フリガナ）
        if($itvFlag)
        {
            $isNameKanaCheckOk = false;
            if ($this->checkText($form["name_sei_kana"], "name_kana", 1, 48, 1) and $this->checkText($form["name_mei_kana"], "name_kana", 1, 48, 1))
            {
                if ($this->checkZenkakuKanaTOCS($form['name_sei_kana'] . $form['name_mei_kana'], 'name_kana', 1, 49, 1))
                {
                    $isNameKanaCheckOk = true;
                }
            }
            else
            {
                if ( ! $this->isEmpty($form["name_sei_kana"]) or ! $this->isEmpty($form["name_mei_kana"]))
                {
                    if ($this->checkText($form["name_sei_kana"], "name_kana", 1, 48, 1) and $this->checkText($form["name_mei_kana"], "name_kana", 1, 48, 1))
                    {
                        $this->checkZenkakuKanaTOCS($form['name_sei_kana'] . $form['name_mei_kana'], 'name_kana', 1, 49, 1);
                    }
                }
            }
        }
        else
        {
            if ( ! $this->isEmpty($form["name_sei_kana"]) or ! $this->isEmpty($form["name_mei_kana"]))
            {
                if ($this->checkText($form["name_sei_kana"], "name_kana", 1, 48, 1) and $this->checkText($form["name_mei_kana"], "name_kana", 1, 48, 1))
                {
                    $this->checkZenkakuKanaTOCS($form['name_sei_kana'] . $form['name_mei_kana'], 'name_kana', 1, 49, 1);
                }
            }
        }

        // 郵便番号
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', $isRequired);

        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), $isRequired);

        // 住所
		$this->checkZenkakuTOCS($form['address1'], 'address1', 1, 100, $isRequired);

        // マンション名等
		$this->checkZenkakuTOCS($form['address2'], 'address2', 1, 64, null);

        // 電話番号
        $this->checkTelText($form['tel'], 'tel', $isRequired);

        // 生年月日
        if ($this->checkDateText($form['birth_y'].$form['birth_m'].$form['birth_d'], 'birth', $isRequired))
        {
            $this->checkDateRange($form['birth_y'].$form['birth_m'].$form['birth_d'], 'birth', 19000101, date("Ymd",time()), $isRequired);
        }

        // 性別
        $this->checkList($form["sex"], 'sex', array_keys($SEX), $isRequired);

        // クーポンコード（半角英数字10文字 201709時点）
        if(isset($form['coupon_code']) && $form['coupon_code'] != '' && !is_null($form['coupon_code']) && !$this->isAlnum($form['coupon_code']))
        {
            $this->addErrorMessage('coupon_code', '半角英数字で入力してください。');
        }

    }

    /**
     * 新メンバーズの変更時チェック
     */
    function checkChangeMember($form, $type = null)
    {
        global $KANYU_TYPE_TBL, $MAIL_RECEIVE_TYPE_TBL, $KANYU, $SEX, $PREF_TBL;

        // スターチャンネルに現在加入していますか。※重要！
        $this->checkList($form["star_ch_id"], 'star_ch_id', array_keys($KANYU), 1);

        // 必須チェック（お名前～性別）
        $isRequiredKanyu = ($form["star_ch_id"] == '1') ? 1 : null;

        // メールアドレス2　※ 変更は email2_confirm なし
        if ( ! $this->isEmpty($form["email2"]))
        {
            $this->checkEmail ($form["email2"], 'email2', 100, null, null);
        }

        // 「MY番組表」お知らせメール
        $this->checkList($form["mail_info_flag"], 'mail_info_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), $isRequiredKanyu);
        if ($form["mail_info_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_info_flag', 'メールアドレス2が登録されていません。');
        }

        // メールマガジンの配信
        $this->checkList($form["mail_mag_flag"], 'mail_mag_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        if ($form["mail_mag_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_mag_flag', 'メールアドレス2が登録されていません。');
        }


        // お名前
        $isNameCheckOk = false;
        if ($isRequiredKanyu == '1')
        {
            if ($this->checkText($form["name_sei"], "name", 1, 48, 1) and $this->checkText($form["name_mei"], "name", 1, 48, 1))
            {
                if ($this->checkZenkakuTOCS($form['name_sei'] . $form['name_mei'], 'name', 1, 49, 1))
                {
                    $isNameCheckOk = true;
                }
            }
        }
        else
        {
            if ( ! $this->isEmpty($form["name_sei"]) or ! $this->isEmpty($form["name_sei"]))
            {
                if ($this->checkText($form["name_sei"], "name", 1, 48, 1) and $this->checkText($form["name_mei"], "name", 1, 48, 1))
                {
                    $this->checkZenkakuTOCS($form['name_sei'] . $form['name_mei'], 'name', 1, 49, 1);
                }
            }
        }

        // お名前（フリガナ）
        $isNameKanaCheckOk = false;
        if ($isRequiredKanyu == '1')
        {
            if ($this->checkText($form["name_sei_kana"], "name_kana", 1, 48, 1) and $this->checkText($form["name_mei_kana"], "name_kana", 1, 48, 1))
            {
                if ($this->checkZenkakuKanaTOCS($form['name_sei_kana'] . $form['name_mei_kana'], 'name_kana', 1, 49, 1))
                {
                    $isNameKanaCheckOk = true;
                }
            }
        }
        else
        {
            if ( ! $this->isEmpty($form["name_sei_kana"]) or ! $this->isEmpty($form["name_mei_kana"]))
            {
                if ($this->checkText($form["name_sei_kana"], "name_kana", 1, 48, 1) and $this->checkText($form["name_mei_kana"], "name_kana", 1, 48, 1))
                {
                    $this->checkZenkakuKanaTOCS($form['name_sei_kana'] . $form['name_mei_kana'], 'name_kana', 1, 49, 1);
                }
            }
        }

        // 郵便番号
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', $isRequiredKanyu);

        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), $isRequiredKanyu);

        // 住所
		$this->checkZenkakuTOCS($form['address1'], 'address1', 1, 100, $isRequiredKanyu);

        // マンション名等
		$this->checkZenkakuTOCS($form['address2'], 'address2', 1, 100, null);

        // 電話番号
        $this->checkTelText($form['tel'], 'tel', $isRequiredKanyu);

        // 生年月日
        if ($this->checkDateText($form['birth_y'].$form['birth_m'].$form['birth_d'], 'birth', $isRequiredKanyu))
        {
            $this->checkDateRange($form['birth_y'].$form['birth_m'].$form['birth_d'], 'birth', 19000101, date("Ymd",time()), $isRequiredKanyu);
        }

        // 性別
        $this->checkList($form["sex"], 'sex', array_keys($SEX), $isRequiredKanyu);

        $isServiceUserIDCheckOk = false;
        if($type != 'itv')
        {
            if ($form["star_ch_id"] == '1')
            {
                // 加入しているテレビサービス名
                $this->checkList($form["service_type"], 'service_type', array_keys($KANYU_TYPE_TBL), 1);

                if ($form["service_type"] == '1')
                {
                    // スカパー！：BCAS（20桁）
                    $isServiceUserIDCheckOk = $this->checkBcasCardId(
                        $form['ts_sky_bcas1'], $form["ts_sky_bcas2"], $form['ts_sky_bcas3'],
                        $form["ts_sky_bcas4"], $form["ts_sky_bcas5"],
                        'ts_sky_bcas',
                        array(4,4,4,4,4), array(4,4,4,4,4),
                        1);
                }
                else if ($form["service_type"] == '2')
                {
                    // スカパー！プレミアムまたはスカパー！プレミアム光：ICカード（16桁）
                    $isServiceUserIDCheckOk = $this->checkCardId(
                        $form["ts_sky_card1"], $form["ts_sky_card2"], $form["ts_sky_card3"],
                        $form["ts_sky_card4"],
                        'ts_sky_card',
                        array(4,4,4,4), array(4,4,4,4),
                        1);
                }
                else if ($form["service_type"] == '3')
                {
                    // ケーブルテレビ：BCAS（20桁）
                    $isServiceUserIDCheckOk = $this->checkBcasCardId(
                        $form['ts_catv_bcas1'], $form["ts_catv_bcas2"], $form['ts_catv_bcas3'],
                        $form["ts_catv_bcas4"], $form["ts_catv_bcas5"],
                        'ts_catv_bcas',
                        array(4,4,4,4,4), array(4,4,4,4,4),
                        1);
                }
                else if ($form["service_type"] == '4')
                {
                    // ひかりTV：ひかりTV契約番号（10桁）
                    $isServiceUserIDCheckOk = $this->checkHikariTvID($form['ts_hikaritv'], 'ts_hikaritv', 1);
                }
                else if ($form["service_type"] == '5')
                {
                    // J:COM：CCAS（20桁）
                    $isServiceUserIDCheckOk = $this->checkBcasCardId(
                        $form['ts_jcom_ccas1'], $form["ts_jcom_ccas2"], $form['ts_jcom_ccas3'],
                        $form["ts_jcom_ccas4"], $form["ts_jcom_ccas5"],
                        'ts_jcom_ccas',
                        array(4,4,4,4,4), array(4,4,4,4,4),
                        1);
                }
                else if ($form["service_type"] == '7')
                {
                    // スカパーオンデマンド：お客様番号（10桁）
                    $isServiceUserIDCheckOk = $this->checkBcasCardIdEx(
                        $form['ts_sky_ondemand1'], $form["ts_sky_ondemand2"],
                        'ts_sky_ondemand',
                        array(5,5), array(5,5),
                        1);
                }

                if ($isServiceUserIDCheckOk and is_array($form['service_user_id'])) {
                    $serviceUserID = implode('', $form['service_user_id']);
                    // 既に登録されているサービスIDチェック
                    if ($serviceUserID != '' && $this->checkDupServiceID($form, $serviceUserID)) {
                        // PF認証：スカパー、JCOM関連 契約者氏名カナでチェック
                        if (in_array($form["service_type"], array('1', '2', '3', '5', '7')))
                        {
                            // ご契約氏名
                            if ($isNameKanaCheckOk)
                            {
                                // スカパー：PF認証チェック
                                $this->checkPFSkaper($form, $serviceUserID);
                            }

                        // PF認証：ひかりTV 契約者氏名でチェック
                        } else if ($form["service_type"] == '4') {

                            // ご契約氏名（フリガナ）
                            if ($isNameCheckOk)
                            {
                                // ひかりTV：PF認証チェック
                                $this->checkPFfHikari($form, $serviceUserID);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * メンバーズ登録内容変更：ログインＩＤ　チェック
     *
     */
    function checkChangeMemberLoginID($form)
    {
        // メールアドレス（仮）
        if ($this->checkEmail($form["email_kari"], 'email_kari', 100, null, 1))
        {
            //重複チェック
            $memberDAO = new MemberDAO;
            $ret = $memberDAO->checkMemberByEmail($form['email_kari']);
            if ($ret)
            {
                $this->addErrorMessage('email_kari','このアドレスはすでに登録されています。');
            }
        }
    }

    /**
     * メンバーズ登録内容変更：パスワード　チェック
     *
     */
    function checkChangeMemberPass($form)
    {
        // パスワード SHA256 対応(2013/07)
        if ($this->checkEmpty($form["password"], 'password'))
        {
            if ($this->isAlnum($form["password"]))
            {
                $same = $this->checkSameData($form["password"], $form["password_confirm"], 'password_confirm', 1);
                if ($same)
                {
                    $messages = array("msgDigit" => "半角英数字8～12文字でご記入ください。");
                    $this->checkDigit($form["password"], 'password', 8, 12, $messages);
                }
            } else {
                $this->addErrorMessage('password', '半角英数字8～12文字でご記入ください。');
            }
        }
    }

    /**
     * MSCメンバーズ登録内容変更：退会　チェック
     *
     */
    function checkCancelMemberMSC($form)
    {
        global $CANCEL_REASON;

        // 退会理由
        $this->checkSelections($form["cancel_reason"], 'cancel_reason', array_keys($CANCEL_REASON), 1);

        // ご意見
        $this->checkText($form['feedback'], 'feedback', 0, 1000, null);
    }


    /**
     * ITVメンバーズ登録内容変更：解約　チェック
     *
     */
    function checkCancelMember($form)
    {
        global $CANCEL_REASON_ITV;

        // 退会理由
        $this->checkSelections($form["cancel_reason"], 'cancel_reason', array_keys($CANCEL_REASON_ITV), 1);

        // ご意見
        $this->checkText($form['feedback'], 'feedback', 0, 1000, null);
    }

    function checkRegistOldMember($form)
    {
        // メールアドレス1
        $this->checkEmail($form["email1"], 'email1', 100, null, 1);

    }

    function checkMailRemind($form)
    {
        // メールアドレス1
        $this->checkEmail($form["email1"], 'email1', 100, null, 1);

    }

    function checkMailRemindPre($form)
    {
        // メールアドレス1
        $this->checkEmail($form["email1"], 'email1', 100, null, 1);

    }

    function checkMailRemindComplete($form)
    {
        // パスワード SHA256 対応(2013/07)
        $isNotNullPass = $this->checkEmpty($form["password"], 'password');
        $isNotNullPassConf = $this->checkEmpty($form["password_confirm"], 'password_confirm');
        if ($isNotNullPass and $isNotNullPassConf)
        {
            if ($this->isAlnum($form["password"]))
            {
                $same = $this->checkSameData($form["password"], $form["password_confirm"], 'password_confirm', 1);
                if ($same) {
                    $messages = array("msgDigit" => "半角英数字8～12文字でご記入ください。");
                    $this->checkDigit($form["password"], 'password', 8, 12, $messages);
                }
            } else {
                $this->addErrorMessage('password', '半角英数字8～12文字でご記入ください。');
            }
        }
    }

    /**
     * CATV用登録の待機会員登録時チェック
     *
     * 加入者
     */
    function checkAddPreMemberCatv($form, $flag=true)
    {
        global $SEX, $PREF_TBL;

        // メールアドレス1
        $isNotNullEmail = $this->checkEmpty($form["email1"], 'email1');
        $isNotNullEmailConf = $this->checkEmpty($form["email1_confirm"], 'email1_confirm');
        if ($isNotNullEmail and $isNotNullEmailConf)
        {
            $same = $this->checkSameData($form["email1"], $form["email1_confirm"], 'email1_confirm', 1);
            if ($same)
            {
                if ($this->checkEmail($form["email1"], 'email1', 100, null, 1))
                {
                    //重複チェック
                    $memberDAO = new MemberDAO;
                    // CATV局員は待機会員の更新を許可する
                    $ret = $memberDAO->checkMemberByEmailCatv($form['email1']);
                    if ($ret)
                    {
                        $this->addErrorMessage('email1','このお客様は既に登録済みです。');
                    }
                }
            }
        }

        // お名前
        $isNameCheckOk = false;
        $isNameSeiCheckOk = false;
        $isNameMeiCheckOk = false;
        if ($this->checkText($form["name_sei"], "name_sei", 1, 48, 1))
        {
            if ($this->checkZenkakuTOCS($form['name_sei'], 'name_sei', 1, 49, 1))
            {
                $isNameSeiCheckOk = true;
            }
        }

        if ($this->checkText($form["name_mei"], "name_mei", 1, 48, 1))
        {
            if ($this->checkZenkakuTOCS($form['name_mei'], 'name_mei', 1, 49, 1))
            {
                $isNameMeiCheckOk = true;
            }
        }

        if ($isNameSeiCheckOk && $isNameMeiCheckOk) {
            $isNameCheckOk = true;
        }

        // お名前（フリガナ）
        $isNameKanaCheckOk = false;
        $isNameKanaSeiCheckOk = false;
        $isNameKanaMeiCheckOk = false;
        if ($this->checkText($form["name_sei_kana"], "name_sei_kana", 1, 48, 1))
        {
            if ($this->checkZenkakuKanaTOCS($form['name_sei_kana'], 'name_sei_kana', 1, 49, 1))
            {
                $isNameKanaSeiCheckOk = true;
            }
        }

        if ($this->checkText($form["name_mei_kana"], "name_mei_kana", 1, 48, 1))
        {
            if ($this->checkZenkakuKanaTOCS($form['name_mei_kana'], 'name_mei_kana', 1, 49, 1))
            {
                $isNameKanaMeiCheckOk = true;
            }
        }

        if ($isNameKanaSeiCheckOk && $isNameKanaMeiCheckOk) {
            $isNameKanaCheckOk = true;
        }

        // 郵便番号
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', 1);
        if ($flag) {
            $this->checkZipMobile($form['zip'], 'zip', 1);
        }

        // 都道府県
        if ($flag) {
            $this->checkPref($form["pref_name"], 'pref_name', $PREF_TBL, 1);
        } else {
            $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);
        }

        // 住所
		$this->checkZenkakuTOCS($form['address1'], 'address1', 1, 100, 1);

        // マンション名等
		$this->checkZenkakuTOCS($form['address2'], 'address2', 1, 64, null);

		// 電話番号
        $this->checkTelText($form['tel'], 'tel', 1);

        // 生年月日
        if ($this->checkDateText($form['birth_y'].$form['birth_m'].$form['birth_d'], 'birth', 1))
        {
            $this->checkDateRange($form['birth_y'].$form['birth_m'].$form['birth_d'], 'birth', 19000101, date("Ymd",time()), 1);
        }

        // 性別
        $this->checkList($form["sex"], 'sex', array_keys($SEX), 1);

        $checkBureau = $this->checkCatvBureau($form['bureau_code'], 'bureau_code', 1);
        $checkId = $this->checkCatvID($form['service_user_id'], 'service_user_id', 1);
        // お客様番号・CATVコード（4桁）
        if ( $checkId && $checkBureau) {
            if ( ! $this->checkDupServiceIDCatv($form, $form['service_user_id'], $form['bureau_code'])) {
                 $this->addErrorMessage('service_user_id', '入力されたお客様番号は、既にスターチャンネル会員に登録されています。');
            }
        }

        // ご利用開始日(当日より前の日付はエラー)
        if ($this->checkDate ($form['reserve_regist_date_y'],$form['reserve_regist_date_m'],$form['reserve_regist_date_d'],  'reserve_regist_date', true)) {
            if(mktime(0,0,0,$form['reserve_regist_date_m'],$form['reserve_regist_date_d'],$form['reserve_regist_date_y']) < strtotime(date('Y-m-d 00:00:00'))) {
                $this->addErrorMessage('reserve_regist_date', 'ご利用開始日は、未来日付で入力してください。');
            }
        }
    }

    /**
     * CATV用本会員登録の登録時チェック
     *
     * 加入者
     */
    function checkAddMemberCatv($form, $member)
    {
        global $MAIL_RECEIVE_TYPE_TBL;

        // メールアドレス1
//        $this->checkEmpty($form["email1"], 'email1');

        // パスワード SHA256 対応(2013/07)
        $isNotNullPass = $this->checkEmpty($form["password"], 'password');
        $isNotNullPassConf = $this->checkEmpty($form["password_confirm"], 'password_confirm');
        if ($isNotNullPass and $isNotNullPassConf)
        {
            if ($this->isAlnum($form["password"]))
            {
                $same = $this->checkSameData($form["password"], $form["password_confirm"], 'password_confirm', 1);
                if ($same)
                {
                    $messages = array("msgDigit" => "半角英数字8～12文字でご記入ください。");
                    $this->checkDigit($form["password"], 'password', 8, 12, $messages);
                }
            } else {
                $this->addErrorMessage('password', '半角英数字8～12文字でご記入ください。');
            }
        }

        // メールアドレス2
        if ( ! $this->isEmpty($form["email2"]) or ! $this->isEmpty($form["email2_confirm"]))
        {
            $isNotNullEmail = $this->checkEmpty($form["email2"], 'email2');
            $isNotNullEmailConf = $this->checkEmpty($form["email2_confirm"], 'email2_confirm');
            if ($isNotNullEmail and $isNotNullEmailConf)
            {
                $same = $this->checkSameData($form["email2"], $form["email2_confirm"], 'email2_confirm', 1);
                if ($same)
                {
                    $this->checkEmail ($form["email2"], 'email2', 100, null, null);
                }
            }
        }

        // 「MY番組表」お知らせメール
        $this->checkList($form["mail_info_flag"], 'mail_info_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        if ($form["mail_info_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_info_flag', 'メールアドレス2が登録されていません。');
        }

        // メールマガジンの配信
        $this->checkList($form["mail_mag_flag"], 'mail_mag_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        if ($form["mail_mag_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_mag_flag', 'メールアドレス2が登録されていません。');
        }

        // お客様番号（10桁）
        if ($this->checkCatvID($form['ts_catv'], 'ts_catv', 1))
        {
            //お客様番号チェック
            if ($form['ts_catv'] !== $member->service_user_id)
            {
                $this->addErrorMessage('ts_catv', 'お客様番号が違います。');
            }
        }
    }

    /**
     * CATV用登録の解約予約時チェック
     *
     * 加入者
     */
    function checkCancelMemberCatv($form)
    {
        // お客様番号・CATVコード（4桁）
        $checkBureau = $this->checkCatvBureau($form['bureau_code'], 'bureau_code', 1);
        $checkID = $this->checkCatvID($form['service_user_id'], 'service_user_id', 1);
        if ($checkBureau && $checkID)
        {
            $memberDAO = new MemberDAO();
            // お客様番号存在チェック(最新データのステータスを判別する)
            if ( ! $member = $memberDAO->getMemberByServiceUserIdAndBureauCode($form['service_user_id'], $form['bureau_code'], false))
            {
                $this->addErrorMessage('service_user_id', 'お客様番号またはCATVコードが違います。');
            }
            // ステータスチェック（待機会員）
            else if ($member->status == '9' && $member->reserve_regist_date != null)
            {
                $this->addErrorMessage('service_user_id', 'このお客様は、まだ本登録が完了していないため、解約できません。');
            }
            // ステータスチェック（解約予約会員、退会）
            else if ($member->status == '99' || ($member->status != '1' || ($member->cancel_date != null && $member->cancel_date != '0001-01-01 00:00:00 BC')))
            {
                $this->addErrorMessage('service_user_id', 'このお客様は既に解約済みもしくは解約予約済みです。');
            }
        }
    }

    /**
     * CATV用待機会員編集時チェック
     *
     * 加入者
     */
    function checkUpdatePreMemberCatv($form)
    {
        $memberDAO = new MemberDAO();
        $member = $memberDAO->getMemberById($form['member_id']);
        
        // ステータスチェック（本会員）
        if ($member->status == '1')
        {
            $this->addErrorMessage('member_id', 'このお客様は本登録が完了しているため、編集できません。');
        }
        // ステータスチェック（解約予約会員、退会）
        else if ($member->status == '99' || ($member->status != '1' && ($member->cancel_date != null && $member->cancel_date != '0001-01-01 00:00:00 BC')))
        {
            $this->addErrorMessage('member_id', 'このお客様は既に解約済みもしくは解約予約済みのため、編集できません。');
        }
        
        // メールアドレス1
        $isNotNullEmail = $this->checkEmpty($form["email1"], 'email1');
        $isNotNullEmailConf = $this->checkEmpty($form["email1_confirm"], 'email1_confirm');
        if ($isNotNullEmail and $isNotNullEmailConf)
        {
            $same = $this->checkSameData($form["email1"], $form["email1_confirm"], 'email1_confirm', 1);
            if ($same)
            {
                if ($this->checkEmail($form["email1"], 'email1', 100, null, 1))
                {
                    //重複チェック
                    $memberDAO = new MemberDAO;
                    // CATV局員は待機会員の更新を許可する
                    $ret = $memberDAO->checkMemberByEmail($form['email1'], $form['member_id']);
                    if ($ret)
                    {
                        $this->addErrorMessage('email1','このお客様は既に登録済みです。');
                    }
                }
            }
        }
        
        $checkId = $this->checkCatvID($form['service_user_id'], 'service_user_id', 1);
        // お客様番号
        if ( $checkId ) {
            if ( ! $this->checkDupServiceIDCatv($form, $form['service_user_id'], $member->bureau_code)) {
                 $this->addErrorMessage('service_user_id', '入力されたお客様番号は、既にスターチャンネル会員に登録されています。');
            }
        }

        // ご利用開始日
        $this->checkDate ($form['reserve_regist_date_y'],$form['reserve_regist_date_m'],$form['reserve_regist_date_d'],  'reserve_regist_date', true);
    }

    /**
     * 管理者機能用　会員新規登録
     */
    function checkAddMemberUseAdmin($form) {
        global $ADMIN_MEMBER_STATUS_TYPE_TBL, $KANYU_TYPE_TBL, $MAIL_RECEIVE_TYPE_TBL, $KANYU, $SEX, $PREF_TBL;

        // 会員ステータス ※重要
        $this->checkList($form["status"], 'status', array_keys($ADMIN_MEMBER_STATUS_TYPE_TBL), 1);

        // スターチャンネルに現在加入していますか。※重要！
        $this->checkList($form["star_ch_id"], 'star_ch_id', array_keys($KANYU), 1);

        // 必須チェック　本会員登録かどうか
        $isStatusMember = ($form["status"] == '1') ? true : false;

        // 必須チェック（お名前～性別）
        $isRequiredKanyu = ($form["star_ch_id"] == '1') ? 1 : null;

        // メールアドレス1
        if ($this->checkEmpty($form["email1"], 'email1'))
        {
            if ($this->checkEmail($form["email1"], 'email1', 100, null, 1))
            {
                //重複チェック
                $memberDAO = new MemberDAO;
                $ret = $memberDAO->checkMemberByEmail($form['email1']);
                if ($ret)
                {
                    $this->addErrorMessage('email1','このアドレスはすでに登録されています。');
                }
            }
        }

        if ($isStatusMember)
        {
            // パスワード SHA256 対応(2013/07)
            $isNotNullPass = $this->checkEmpty($form["password"], 'password');
            $isNotNullPassConf = $this->checkEmpty($form["password_confirm"], 'password_confirm');
            if ($isNotNullPass and $isNotNullPassConf)
            {
                if ($this->isAlnum($form["password"]))
                {
                    $same = $this->checkSameData($form["password"], $form["password_confirm"], 'password_confirm', 1);
                    if ($same)
                    {
                        $messages = array("msgDigit" => "半角英数字8～12文字でご記入ください。");
                        $this->checkDigit($form["password"], 'password', 8, 12, $messages);
                    }
                } else {
                    $this->addErrorMessage('password', '半角英数字8～12文字でご記入ください。');
                }
            }
        }
        else
        {
            // 既存はPWを受け付けない
            if ( ! $this->isEmpty($form["password"]) or ! $this->isEmpty($form["password_confirm"]))
            {
                $this->addErrorMessage('password', '既存会員のPW入力はできません。');
            }
        }

        // メールアドレス2　※ 変更は email2_confirm なし
        if ( ! $this->isEmpty($form["email2"]))
        {
            $this->checkEmail ($form["email2"], 'email2', 100, null, null);
        }

        // 「MY番組表」お知らせメール
        $this->checkList($form["mail_info_flag"], 'mail_info_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), $isRequiredKanyu);
        if ($form["mail_info_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_info_flag', 'メールアドレス2が登録されていません。');
        }

        // メールマガジンの配信
        $this->checkList($form["mail_mag_flag"], 'mail_mag_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        if ($form["mail_mag_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_mag_flag', 'メールアドレス2が登録されていません。');
        }


        // お名前
        $isNameCheckOk = false;
        if ($isRequiredKanyu == '1')
        {
            if ($this->checkText($form["name_sei"], "name", 1, 48, 1) and $this->checkText($form["name_mei"], "name", 1, 48, 1))
            {
                if ($this->checkZenkakuTOCS($form['name_sei'] . $form['name_mei'], 'name', 1, 49, 1))
                {
                    $isNameCheckOk = true;
                }
            }
        }
        else
        {
            if ( ! $this->isEmpty($form["name_sei"]) or ! $this->isEmpty($form["name_sei"]))
            {
                if ($this->checkText($form["name_sei"], "name", 1, 48, 1) and $this->checkText($form["name_mei"], "name", 1, 48, 1))
                {
                    $this->checkZenkakuTOCS($form['name_sei'] . $form['name_mei'], 'name', 1, 49, 1);
                }
            }
        }

        // お名前（フリガナ）
        $isNameKanaCheckOk = false;
        if ($isRequiredKanyu == '1')
        {
            if ($this->checkText($form["name_sei_kana"], "name_kana", 1, 48, 1) and $this->checkText($form["name_mei_kana"], "name_kana", 1, 48, 1))
            {
                if ($this->checkZenkakuKanaTOCS($form['name_sei_kana'] . $form['name_mei_kana'], 'name_kana', 1, 49, 1))
                {
                    $isNameKanaCheckOk = true;
                }
            }
        }
        else
        {
            if ( ! $this->isEmpty($form["name_sei_kana"]) or ! $this->isEmpty($form["name_mei_kana"]))
            {
                if ($this->checkText($form["name_sei_kana"], "name_kana", 1, 48, 1) and $this->checkText($form["name_mei_kana"], "name_kana", 1, 48, 1))
                {
                    $this->checkZenkakuKanaTOCS($form['name_sei_kana'] . $form['name_mei_kana'], 'name_kana', 1, 49, 1);
                }
            }
        }

        // 郵便番号
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', $isRequiredKanyu);

        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), $isRequiredKanyu);

        // 住所
        $this->checkZenkakuTOCS($form['address1'], 'address1', 1, 100, $isRequiredKanyu);

        // マンション名等
        $this->checkZenkakuTOCS($form['address2'], 'address2', 1, 100, null);

        // 電話番号
        $this->checkTelText($form['tel'], 'tel', $isRequiredKanyu);

        // 生年月日
        if ($this->checkDateText($form['birth'], 'birth', $isRequiredKanyu))
        {
            $this->checkDateRange($form['birth'], 'birth', 19000101, date("Ymd",time()), $isRequiredKanyu);
        }

        // 性別
        $this->checkList($form["sex"], 'sex', array_keys($SEX), $isRequiredKanyu);

        if ($form["star_ch_id"] == '1')
        {
            // 加入しているテレビサービス名
            $this->checkList($form["service_type"], 'service_type', array_keys($KANYU_TYPE_TBL), 1);

            if ($form["service_type"] == '1')
            {
                if ($this->checkText($form["service_user_id"], "service_user_id", 20, 20, 1))
                {
                    // スカパー！：BCAS（20桁）
                    $bcasCardIds = str_split($form["service_user_id"], 4);
                    $this->checkBcasCardId(
                        $bcasCardIds[0], $bcasCardIds[1], $bcasCardIds[2],
                        $bcasCardIds[3], $bcasCardIds[4],
                        'service_user_id',
                        array(4,4,4,4,4), array(4,4,4,4,4),
                        1);
                }
            }
            else if ($form["service_type"] == '2')
            {
                // スカパー！プレミアムまたはスカパー！プレミアム光：ICカード（16桁）
                $icCardIds = str_split($form["service_user_id"], 4);
                $this->checkCardId(
                    $icCardIds[0], $icCardIds[1], $icCardIds[2], $icCardIds[3],
                    'service_user_id',
                    array(4,4,4,4), array(4,4,4,4),
                    1);
            }
            else if ($form["service_type"] == '3')
            {
                // ケーブルテレビ：BCAS（20桁）
                $bcasCardIds = str_split($form["service_user_id"], 4);
                $this->checkBcasCardId(
                    $bcasCardIds[0], $bcasCardIds[1], $bcasCardIds[2],
                    $bcasCardIds[3], $bcasCardIds[4],
                    'service_user_id',
                    array(4,4,4,4,4), array(4,4,4,4,4),
                    1);
            }
            else if ($form["service_type"] == '4')
            {
                // ひかりTV：ひかりTV契約番号（10桁）
                $this->checkHikariTvID($form['service_user_id'], 'service_user_id', 1);
            }
            else if ($form["service_type"] == '5')
            {
                // J:COM：CCAS（20桁）
                $bcasCardIds = str_split($form["service_user_id"], 4);
                $this->checkBcasCardId(
                    $bcasCardIds[0], $bcasCardIds[1], $bcasCardIds[2],
                    $bcasCardIds[3], $bcasCardIds[4],
                    'service_user_id',
                    array(4,4,4,4,4), array(4,4,4,4,4),
                    1);
            }
            else if ($form["service_type"] == '7')
            {
                $bcasCardIds = str_split($form["service_user_id"], 5);

                // スカパーオンデマンド：お客様番号（10桁）
                $this->checkBcasCardIdEx(
                    $bcasCardIds[0], $bcasCardIds[1],
                    'service_user_id',
                    array(5,5), array(5,5),
                    1);

            }

            if ( ! $this->isEmpty($form['service_user_id'])) {
                $serviceUserID = $form['service_user_id'];
                // 既に登録されているサービスIDチェック
                if ($serviceUserID != '' && $this->checkDupServiceID($form, $serviceUserID)) {
                    // PF認証：スカパー関連 契約者氏名カナでチェック
                    if (in_array($form["service_type"], array('1', '2', '3', '5', '7')))
                    {
                        // ご契約氏名
                        if ($isNameKanaCheckOk)
                        {
                            // スカパー：PF認証チェック
                            $this->checkPFSkaper($form, $serviceUserID);
                        }

                        // PF認証：ひかりTV 契約者氏名でチェック
                    } else if ($form["service_type"] == '4') {

                        // ご契約氏名（フリガナ）
                        if ($isNameCheckOk)
                        {
                            // ひかりTV：PF認証チェック
                            $this->checkPFfHikari($form, $serviceUserID);
                        }
                    }
                }
            }
        }
    }

    /**
     * 管理者機能用　会員新規登録
     */
    function checkChangeMemberUseAdmin($form) {
        global $ADMIN_MEMBER_STATUS_TYPE_TBL, $ADMIN_KANYU_TYPE_TBL, $MAIL_RECEIVE_TYPE_TBL, $KANYU, $SEX, $PREF_TBL;

        // 会員ステータス ※重要
        $this->checkList($form["status"], 'status', array_keys($ADMIN_MEMBER_STATUS_TYPE_TBL), 1);

        // スターチャンネルに現在加入していますか。※重要！
        $this->checkList($form["star_ch_id"], 'star_ch_id', array_keys($KANYU), 1);

        // 必須チェック　本会員登録かどうか
        $isStatusMember = ($form["status"] == '1') ? true : false;

        // 必須チェック（お名前～性別）
        $isRequiredKanyu = ($form["star_ch_id"] == '1') ? 1 : null;

        // メールアドレス1
        if ($this->checkEmpty($form["email1"], 'email1'))
        {
            if ($this->checkEmail($form["email1"], 'email1', 100, null, 1))
            {
                //重複チェック
                $memberDAO = new MemberDAO;
                $ret = $memberDAO->getMemberByEmail($form['email1']);
                if ($ret and $ret->member_id != $form["member_id"])
                {
                    $this->addErrorMessage('email1','このアドレスはすでに登録されています。');
                }

                $ret = $memberDAO->getOldMemberByEmail($form['email1']);
                if ($ret and $ret->member_id != $form["member_id"])
                {
                    $this->addErrorMessage('email1','このアドレスはすでに既存会員として登録されています。');
                }
            }
        }

        if ($isStatusMember)
        {
            // パスワード SHA256 対応(2013/07)
            $isNullPass = $this->isEmpty($form["password"]);
            $isNullPassConf = $this->isEmpty($form["password_confirm"]);
            if ( ! $isNullPass or ! $isNullPassConf)
            {
                if ($this->isAlnum($form["password"]))
                {
                    $same = $this->checkSameData($form["password"], $form["password_confirm"], 'password_confirm', 1);
                    if ($same)
                    {
                        $messages = array("msgDigit" => "半角英数字8～12文字でご記入ください。");
                        $this->checkDigit($form["password"], 'password', 8, 12, $messages);
                    }
                } else {
                    $this->addErrorMessage('password', '半角英数字8～12文字でご記入ください。');
                }
            }
            else
            {
                // なにもしない
            }
        }
        else
        {
            // 既存はPWを受け付けない
            if ( ! $this->isEmpty($form["password"]) or ! $this->isEmpty($form["password_confirm"]))
            {
                $this->addErrorMessage('password', '既存会員のPW入力はできません。');
            }
        }

        // メールアドレス2　※ 変更は email2_confirm なし
        if ( ! $this->isEmpty($form["email2"]))
        {
            $this->checkEmail ($form["email2"], 'email2', 100, null, null);
        }

        // 「MY番組表」お知らせメール
        $this->checkList($form["mail_info_flag"], 'mail_info_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), $isRequiredKanyu);
        if ($form["mail_info_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_info_flag', 'メールアドレス2が登録されていません。');
        }

        // メールマガジンの配信
        $this->checkList($form["mail_mag_flag"], 'mail_mag_flag', array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        if ($form["mail_mag_flag"] == '2' and $this->isEmpty($form["email2"]))
        {
            $this->addErrorMessage('mail_mag_flag', 'メールアドレス2が登録されていません。');
        }


        // お名前
        $isNameCheckOk = false;
        if ($isRequiredKanyu == '1')
        {
            if ($this->checkText($form["name_sei"], "name", 1, 48, 1) and $this->checkText($form["name_mei"], "name", 1, 48, 1))
            {
                if ($this->checkZenkakuTOCS($form['name_sei'] . $form['name_mei'], 'name', 1, 49, 1))
                {
                    $isNameCheckOk = true;
                }
            }
        }
        else
        {
            if ( ! $this->isEmpty($form["name_sei"]) or ! $this->isEmpty($form["name_sei"]))
            {
                if ($this->checkText($form["name_sei"], "name", 1, 48, 1) and $this->checkText($form["name_mei"], "name", 1, 48, 1))
                {
                    $this->checkZenkakuTOCS($form['name_sei'] . $form['name_mei'], 'name', 1, 49, 1);
                }
            }
        }

        // お名前（フリガナ）
        $isNameKanaCheckOk = false;
        if ($isRequiredKanyu == '1')
        {
            if ($this->checkText($form["name_sei_kana"], "name_kana", 1, 48, 1) and $this->checkText($form["name_mei_kana"], "name_kana", 1, 48, 1))
            {
                if ($this->checkZenkakuKanaTOCS($form['name_sei_kana'] . $form['name_mei_kana'], 'name_kana', 1, 49, 1))
                {
                    $isNameKanaCheckOk = true;
                }
            }
        }
        else
        {
            if ( ! $this->isEmpty($form["name_sei_kana"]) or ! $this->isEmpty($form["name_mei_kana"]))
            {
                if ($this->checkText($form["name_sei_kana"], "name_kana", 1, 48, 1) and $this->checkText($form["name_mei_kana"], "name_kana", 1, 48, 1))
                {
                    $this->checkZenkakuKanaTOCS($form['name_sei_kana'] . $form['name_mei_kana'], 'name_kana', 1, 49, 1);
                }
            }
        }

        // 郵便番号
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', $isRequiredKanyu);

        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), $isRequiredKanyu);

        // 住所
        $this->checkZenkakuTOCS($form['address1'], 'address1', 1, 100, $isRequiredKanyu);

        // マンション名等
        $this->checkZenkakuTOCS($form['address2'], 'address2', 1, 100, null);

        // 電話番号
        $this->checkTelText($form['tel'], 'tel', $isRequiredKanyu);

        // 生年月日
        if ($this->checkDateText($form['birth'], 'birth', $isRequiredKanyu))
        {
            $this->checkDateRange($form['birth'], 'birth', 19000101, date("Ymd",time()), $isRequiredKanyu);
        }

        // 性別
        $this->checkList($form["sex"], 'sex', array_keys($SEX), $isRequiredKanyu);

        if ($form["star_ch_id"] == '1')
        {
            // 加入しているテレビサービス名
            $this->checkList($form["service_type"], 'service_type', array_keys($ADMIN_KANYU_TYPE_TBL), 1);

            if ($form["service_type"] == '1')
            {
                if ($this->checkText($form["service_user_id"], "service_user_id", 20, 20, 1))
                {
                    // スカパー！：BCAS（20桁）
                    $bcasCardIds = str_split($form["service_user_id"], 4);
                    $this->checkBcasCardId(
                        $bcasCardIds[0], $bcasCardIds[1], $bcasCardIds[2],
                        $bcasCardIds[3], $bcasCardIds[4],
                        'service_user_id',
                        array(4,4,4,4,4), array(4,4,4,4,4),
                        1);
                }
            }
            else if ($form["service_type"] == '2')
            {
                // スカパー！プレミアムまたはスカパー！プレミアム光：ICカード（16桁）
                $icCardIds = str_split($form["service_user_id"], 4);
                $this->checkCardId(
                    $icCardIds[0], $icCardIds[1], $icCardIds[2], $icCardIds[3],
                    'service_user_id',
                    array(4,4,4,4), array(4,4,4,4),
                    1);
            }
            else if ($form["service_type"] == '3')
            {
                // ケーブルテレビ：BCAS（20桁）
                $bcasCardIds = str_split($form["service_user_id"], 4);
                $this->checkBcasCardId(
                    $bcasCardIds[0], $bcasCardIds[1], $bcasCardIds[2],
                    $bcasCardIds[3], $bcasCardIds[4],
                    'service_user_id',
                    array(4,4,4,4,4), array(4,4,4,4,4),
                    1);
            }
            else if ($form["service_type"] == '4')
            {
                // ひかりTV：ひかりTV契約番号（10桁）
                $this->checkHikariTvID($form['service_user_id'], 'service_user_id', 1);
            }
            else if ($form["service_type"] == '5')
            {
                // J:COM：CCAS（20桁）
                $bcasCardIds = str_split($form["service_user_id"], 4);
                $this->checkBcasCardId(
                    $bcasCardIds[0], $bcasCardIds[1], $bcasCardIds[2],
                    $bcasCardIds[3], $bcasCardIds[4],
                    'service_user_id',
                    array(4,4,4,4,4), array(4,4,4,4,4),
                    1);
            }
            else if ($form["service_type"] == '7')
            {
                $bcasCardIds = str_split($form["service_user_id"], 5);
                // スカパーオンデマンド：お客様番号（10桁）
                $this->checkBcasCardIdEx(
                    $bcasCardIds[0], $bcasCardIds[1],
                    'service_user_id',
                    array(5,5), array(5,5),
                    1);
            }

            if ( ! $this->isEmpty($form['service_user_id'])) {
                $serviceUserID = $form['service_user_id'];
                // 既に登録されているサービスIDチェック
                if ($serviceUserID != '' && $this->checkDupServiceID($form, $serviceUserID)) {
                    // PF認証：スカパー関連 契約者氏名カナでチェック
                    if (in_array($form["service_type"], array('1', '2', '3', '5', '7')))
                    {
                        // ご契約氏名
                        if ($isNameKanaCheckOk)
                        {
                            // スカパー：PF認証チェック
                            $this->checkPFSkaper($form, $serviceUserID);
                        }

                        // PF認証：ひかりTV 契約者氏名でチェック
                    } else if ($form["service_type"] == '4') {

                        // ご契約氏名（フリガナ）
                        if ($isNameCheckOk)
                        {
                            // ひかりTV：PF認証チェック
                            $this->checkPFfHikari($form, $serviceUserID);
                        }
                    }
                }
            }
        }
    }


    /**
     * 新メンバーズの登録時チェック（前半のみ）携帯
     */
    function checkAddMemberHalfMobile($form) {
        global $MAIL_RECEIVE_TYPE_TBL, $KANYU_TYPE_TBL, $HIKANYU_TYPE_TBL,
        $KANYU, $BROADBAND_TYPE_TBL, $KNOW_TYPE_TBL;

        // mail1
        $this->checkEmail ($form["email1"], 'email1', 100, null, 1);
        $this->checkEmail ($form["email2"], 'email2', 100, null, null);


        // パスワード SHA256 対応(2013/07)
        // 会員情報変更時であればパスワード欄は空欄可
        if($form["change"]){
            // password
            if($form["password"]){
                if ($this->isAlnum($form["password"])) {
                    $same = $this->checkSameData($form["password"],
                                                 $form["password_confirm"],
                                                 'password', 1);
                    if($same) {
                        $messages = array("msgDigit" =>
                                          "半角英数字4～8文字でご記入ください。");
                        $this->checkDigit($form["password"], 'password', 4, 8, $messages);
                    }
                } else {
                    $this->addErrorMessage('password',
                                           '半角英数字4～8文字でご記入ください。');
                }
            }
        }else{
            // password
            if ($this->isAlnum($form["password"])) {
                $same = $this->checkSameData($form["password"],
                                             $form["password_confirm"],
                                             'password', 1);
                if($same) {
                    $messages = array("msgDigit" =>
                                      "半角英数字4～8文字でご記入ください。");
                    $this->checkDigit($form["password"], 'password', 4, 8, $messages);
                }
            } else {
                $this->addErrorMessage('password',
                                       '半角英数字4～8文字でご記入ください。');
            }
        }


        // nickname
        $ret = $this->checkEmpty($form["nickname"], 'nickname');
        if($ret) {
            $messages = array("msgDigit" =>
                              "20文字以内でご記入ください。");
            $this->checkDigit($form["nickname"], 'nickname', 1, 20, $messages);
            //半角英数字 ひらがな 全角カナ 半角カナ 全角英数字 のみ使用可
	    //UFT8対応 2013.6.13
            if(!preg_match('/^(([a-zA-Z0-9])|(\xEF\xBC[\x81-\xBF]|\xEF\xBD[\x80-\xA0])|(\xE3\x81[\x81-\xBF]|\xE3\x82[\x80-\x93])|(\xE3\x82[\xA1-\xBF]|\xE3\x83[\x80-\xB6])|(\xEF\xBD[\xA1-\xBF]|\xEF\xBE[\x80-\x9F]))+$/', $form["nickname"])){
                $this->addErrorMessage('nickname','英数字（半角全角）・カタカナ（半角全角）・ひらがな（全角）でご記入ください。');
            }
        }

        // mail
        $this->checkList($form["mail_info_flag"], 'mail_info_flag',
                         array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        //$this->checkList($form["mail_mag_flag_1"], 'mail_mag_flag_1',
        //                 array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        //$this->checkList($form["mail_mag_flag_2"], 'mail_mag_flag_2',
        //                 array_keys($MAIL_RECEIVE_TYPE_TBL), 1);
        if($form['email2'] == '' && $form["mail_info_flag"] == 2) {
            $this->addErrorMessage('mail_info_flag',
                                   'メールアドレス2を入力してください。');
        }
        //if($form['email2'] == '' && $form["mail_mag_flag_1"] == 2) {
        //    $this->addErrorMessage('mail_mag_flag_1',
        //                           'メールアドレス2を入力してください。');
        //}
        //if($form['email2'] == '' && $form["mail_mag_flag_2"] == 2) {
        //    $this->addErrorMessage('mail_mag_flag_2',
        //                           'メールアドレス2を入力してください。');
        //}

        //kanyu
        $this->checkList($form["star_ch_id"], 'star_ch_id',
                         array_keys($KANYU), 1);

        //kanyu-type
        //$this->checkList($form["star_ch_service2"], 'star_ch_service2',
        //                 array_keys($KANYU_TYPE_TBL), 1);
	//2013.6.17
	if ($form["star_ch_id"] == "1"){
          $this->checkList($form["star_ch_service2"], 'star_ch_service2',
                           array_keys($KANYU_TYPE_TBL), 1);
	}

        if ($form["star_ch_id"] == "1" && $form["star_ch_service2"] === "0") {
            $this->addErrormessage("star_ch_service2", "スターチャンネル加入者は、「いずれも視聴していない」は選択できません");
        }

        // sky parfecTV  2013/07/31 スカパー光チェック追加
        if ($form["star_ch_id"] == '1' && ($form["star_ch_service2"] == '1' || $form["star_ch_service2"] == '6' || $form["star_ch_service2"] == '23')) {
            if ($this->checkNumeric ($form["ic_card_id"],
                                     'ic_card_id', 16, true)) {
                $this->checkDigit ($form["ic_card_id"],
                                   'ic_card_id', 16, 16,
                                   array('msgDigit' =>
                                         '半角数字16ケタで入力してください。'));
            }
        } else {
            if (!$this->isEmpty($form["ic_card_id"])) {
                if ($this->checkNumeric ($form["ic_card_id"],
                                         'ic_card_id', 16, null)) {
                    $this->checkDigit ($form["ic_card_id"],
                                       'ic_card_id', 16, 16,
                                       array('msgDigit' =>
                                             '半角数字16ケタで入力してください。'));
                }
            }
        }

        // BS
        if ($form["star_ch_id"] == '1' && $form["star_ch_service2"] == '4') {
            if ($this->checkNumeric($form["bcas_card_id"],
                                    'bcas_card_id', 20, true)) {
                $this->checkDigit ($form["bcas_card_id"],
                                   'bcas_card_id', 20, 20,
                                   array('msgDigit' =>
                                         '半角数字20ケタで入力してください。'));
            }
        } else {
            if (!$this->isEmpty($form["bcas_card_id"])) {
                if ($this->checkNumeric ($form["bcas_card_id"],
                                         'bcas_card_id', 20, null)) {
                    $this->checkDigit ($form["bcas_card_id"],
                                       'bcas_card_id', 20, 20,
                                       array('msgDigit' =>
                                             '半角数字20ケタで入力してください。'));
                }
            }
        }

        // CATV
        if($form["star_ch_id"] == '1'
            && ($form["star_ch_service2"] == '20'
            || $form["star_ch_service2"] == '21'
            || $form["star_ch_service2"] == '22')) {
             //cableTv
            $this->checkText($form['catv_name'], 'catv_name', 1, 50, 1);
        } else {
            //cabletv
            $this->checkText($form['catv_name'], 'catv_name', 1, 50, null);
        }

        // broad band
        if($form["star_ch_service2"] == '11') {
            // Broadband
            $this->checkList($form["broadband_service"], 'broadband_service',
                             array_keys($BROADBAND_TYPE_TBL), 1);
        } else {
            // Broadband
            $this->checkList($form["broadband_service"], 'broadband_service',
                             array_keys($BROADBAND_TYPE_TBL), null);
        }

        // sonota
        if($form["star_ch_id"] == '1' && $form["star_ch_service2"] == '5') {
            // other
            $this->checkText($form["other"], 'other', 1, 50, 1);
        } else {
            $this->checkText($form["other"], 'other', 1, 50, null);
        }

        // know
        $this->checkList($form["know"], 'know',
                         array_keys($KNOW_TYPE_TBL), 1);
        if ($form["know"] == '7') {
            $this->checkText($form["know_other"], 'know_other', 1, 50, 1);
        } else {
            $this->checkText($form["know_other"], 'know_other', 1, 50, null);
        }
    }

    /**
     * 新メンバーズの登録時チェック（携帯）
     */
    function checkAddMemberMobile($form) {
        global $JOB_TBL, $SEX, $PREF_TBL;

        // sei, mei
        if ($this->checkEmpty($form['sei'], 'name') &&
            $ret = $this->checkEmpty($form['mei'], 'name')) {
            // XXX 本当は切り詰めるべき？→切り詰めた
            $this->checkText($form['sei'].$form['mei'], 'name',1, 20, 1);
        }

        // zip
        if ($form["zip"] == '') {
# nothing
        } elseif (strlen($form["zip"]) == 7) {
            $form['zip1'] = substr($form['zip'], 0, 3);
            $form['zip2'] = substr($form['zip'], 3, 4);
            $this->checkZip($form['zip1'], $form['zip2'], 'zip', null);
        } else {
            $this->addErrorMessage('zip', '7桁の数字で入力してください。');
        }

        // pref
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);
        // address
        $this->checkText($form["address"], "address", 1, 100, 1);

        // sex
        $this->checkList($form["sex"], 'sex', array_keys($SEX), 1);

        // birth-year
        $ret = $this->checkNumeric($form['birth'], 'birth', 4, 1);
        if ($ret) {
            $this->checkRange($form['birth'], 'birth', 1902,
                              date("Y",time()), null);
        }

        // tel
        $this->checkTelAll ($form['tel1'].'-'.$form['tel2'].'-'.$form['tel3'],
                            'tel', array(1,1,1), array(4,5,5), 1);

        // job
        $this->checkList($form["job"], 'job',
                         array_keys($JOB_TBL), null);
    }

    /**
     * 新メンバーズの変更時チェック（携帯）
     */
    function checkChangeMemberMobile($form) {
        global $JOB_TBL, $SEX, $PREF_TBL;

        //name
        $this->checkText($form['name'], 'name',1, 20, 1);

        // zip
        if ($form["zip"] == '') {
# nothing
        } elseif (strlen($form["zip"]) == 7) {
            $form['zip1'] = substr($form['zip'], 0, 3);
            $form['zip2'] = substr($form['zip'], 3, 4);
            $this->checkZip($form['zip1'], $form['zip2'], 'zip', null);
        } else {
            $this->addErrorMessage('zip', '7桁の数字で入力してください');
        }

        // pref
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);
        // address
        $this->checkText($form["address"], "address", 1, 100, 1);

        // sex
        $this->checkList($form["sex"], 'sex', array_keys($SEX), 1);

        // birth-year
        $ret = $this->checkNumeric($form['birth'], 'birth', 4, 1);
        if ($ret) {
            $this->checkRange($form['birth'], 'birth', 1902,
                              date("Y",time()), null);
        }

        // tel
        $this->checkTelAll ($form['tel1'].'-'.$form['tel2'].'-'.$form['tel3'],
                            'tel', array(1,1,1), array(4,5,5), 1);

        // job
        $this->checkList($form["job"], 'job',
                         array_keys($JOB_TBL), null);
    }

    /**
     * お気に入り監督マスタの登録時チェック
     */
    function checkAddMDirector($form) {
        $messages = "";

        // 監督日本語名
        $this->checkText($form["jname"], 'md_jname', 1, 50, 1);

        // 監督ふりがな
        $this->checkText($form["jkana"], 'md_jkana', 0, 50, 0);

        // 監督英語名
        $this->checkAscii  ($form["ename"], 'md_ename', 1, 50, 1);
    }

    /**
     * お気に入り監督マスタの更新時チェック
     */
    function checkUpdateMDirector($form) {
        $messages = "";

        // 監督日本語名
        $this->checkText($form["jname"], 'md_jname', 1, 50, 1);

        // 監督ふりがな
        $this->checkText($form["jkana"], 'md_jkana', 0, 50, 0);

        // 監督英語名
        $this->checkAscii  ($form["ename"], 'md_ename', 1, 50, 1);
    }

    /**
     * 作品検索の入力チェック
     */
    function checkMovieSearchCondAdmin($form) {
        global $INITIAL_SEARCH_TBL;
        $this->checkNumeric($form["movie_id"], "movie_id", null);

        //if($form["initial_search_id"] != 0){
        //    $this->checkList($form["initial_search_id"], "initial_search_id", array_keys($INITIAL_SEARCH_TBL));
        //}

        if(!$form["initial_search_id"] && $form["movie_id"]=='' && $form["right"]=='' && $form["free_word"] == ''){
            $this->addErrorMessage('search_cond', $this->msgSearchCond);
        }
    }

    /**
     * 作品情報新規登録の入力チェック
     */
    function checkAddMovieAdmin($form, $GENRE_TBL) {
        //作品ID
        $this->checkNumeric($form["movie_id"], "movie_id", 9, 1);

        $this->checkUpdateMovieAdmin($form, $GENRE_TBL);
    }
    /**
     * 作品情報編集の入力チェック
     */
    function checkUpdateMovieAdmin($form, $GENRE_TBL) {
        global $FILM_KIND_TBL,$GENRE_TBL;
        //字幕版ID
        $this->checkNumeric($form["caption_movie_id"], "caption_movie_id", 9, 0);
        //作品タイトル
        $this->checkText($form["title"], "title", 1, 100, 1);
        //作品タイトル読み（カタカナ）
        $this->checkText($form["etitle"], "etitle", 1, 100, 1);
        //作品タイトル（レーティング表記なし）
        $this->checkText($form["title2"], "title2", 1, 100, 0);
        //作品原題
        $this->checkText($form["original_title"], "original_title", 1, 100, 1);
        //本編尺CS
        $this->checkNumeric($form["duration_cs"], "duration_cs", null, 0);
        //本編尺BS
        $this->checkNumeric($form["duration_bs"], "duration_bs", null, 0);
        //本編尺HV
        $this->checkNumeric($form["duration_hv"], "duration_hv", null, 0);
        //制作年度
        $this->checkNumeric($form["year"], "year", 4, 0);
        //制作国
        $this->checkText($form["country"], "country", 1, 50, 0);
        //監督日本語
        $this->checkText($form["director_jname"], "director_jname", 1, 255, 0);
        //監督英語
        $this->checkText($form["director_ename"], "director_ename", 1, 255, 0);
        //監督日本語2
        $this->checkText($form["director_jname2"], "director_jname2", 1, 255, 0);
        //監督英語2
        $this->checkText($form["director_ename2"], "director_ename2", 1, 255, 0);
        //キャスト日本語1
        $this->checkText($form["cast_jname1"], "cast_jname1", 1, 255, 0);
        //キャスト英語1
        $this->checkText($form["cast_ename1"], "cast_ename1", 1, 255, 0);
        //キャスト日本語2
        $this->checkText($form["cast_jname2"], "cast_jname2", 1, 255, 0);
        //キャスト英語2
        $this->checkText($form["cast_ename2"], "cast_ename2", 1, 255, 0);
        //キャスト日本語3
        $this->checkText($form["cast_jname3"], "cast_jname3", 1, 255, 0);
        //キャスト英語3
        $this->checkText($form["cast_ename3"], "cast_ename3", 1, 255, 0);
        //キャスト日本語4
        $this->checkText($form["cast_jname4"], "cast_jname4", 1, 255, 0);
        //キャスト英語4
        $this->checkText($form["cast_ename4"], "cast_ename4", 1, 255, 0);
        //キャスト日本語5
        $this->checkText($form["cast_jname5"], "cast_jname5", 1, 255, 0);
        //キャスト英語5
        $this->checkText($form["cast_ename5"], "cast_ename5", 1, 255, 0);
        //声優1
        $this->checkText($form["voice1"], "voice1", 1, 255, 0);
        //声優2
        $this->checkText($form["voice2"], "voice2", 1, 255, 0);
        //声優3
        $this->checkText($form["voice3"], "voice3", 1, 255, 0);
        //声優4
        $this->checkText($form["voice4"], "voice4", 1, 255, 0);
        //声優5
        $this->checkText($form["voice5"], "voice5", 1, 255, 0);
        //その他日本語
        $this->checkText($form["staff_jname"], "staff_jname", 1, 255, 0);
        //その他英語
        $this->checkText($form["staff_ename"], "staff_ename", 1, 255, 0);
        //大分類1
        $this->checkList($form["film_kind1"], "film_kind1", array_keys($FILM_KIND_TBL), 0);
        //ジャンルID1
        $this->checkList($form["genre_id1"], "genre_id1", array_keys($GENRE_TBL), 0);
        //ジャンルID2
        $this->checkList($form["genre_id2"], "genre_id2", array_keys($GENRE_TBL), 0);
        //作品解説
        $this->checkText($form["description"], "description", 1, 1200, 1);
        //監督・キャスト
        $this->checkText($form["staff_description"], "staff_description", 1, 1200, 0);
        //ストーリー
        $this->checkText($form["story_description"], "story_description", 1, 1200, 0);
        //キャッチ
        $this->checkText($form["catch"], "catch", 1, 100);
        //レーティング
        $this->checkText($form["regular"], "regular", 1, 100);
        //レギュラー
        $this->checkText($form["rating"], "rating", 1, 255);
        //コピーライト
        $this->checkText($form["copyright"], "copyright", 1, 500, 0);
        //権利期間開始・終了日
        $check_right_begin = $this->checkDate ($form['right_begin_year'],$form['right_begin_month'],$form['right_begin_day'],  'right_begin', false);
        $check_right_end = $this->checkDate ($form['right_end_year'],$form['right_end_month'],$form['right_end_day'], 'right_end', false);

        if($check_right_begin && $check_right_end &&
           $form['right_end_month'] && $form['right_end_day'] && $form['right_end_year'] &&
           $form['right_begin_month'] && $form['right_begin_day'] && $form['right_begin_year']) {
            if(mktime(0,0,0,$form['right_end_month'],$form['right_end_day'],$form['right_end_year']) <
               mktime(0,0,0,$form['right_begin_month'],$form['right_begin_day'],$form['right_begin_year'])) {
                $this->addErrorMessage('right_begin', '終了日よりも過去の日付を指定してください');
            }
        }

        //動画URL
        $this->checkText($form["video_url"], "video_url", 1, 255);
        //動画掲載期間開始・終了日
        $check_video_begin_date = $this->checkDate ($form['video_begin_date_year'],$form['video_begin_date_month'],$form['video_begin_date_day'],  'video_begin_date', false);
        $check_video_end_date = $this->checkDate ($form['video_end_date_year'],$form['video_end_date_month'],$form['video_end_date_day'], 'video_end_date', false);

        if($check_video_begin_date && $check_video_end_date &&
           $form['video_end_date_month'] && $form['video_end_date_day'] && $form['video_end_date_year'] &&
           $form['video_begin_date_month'] && $form['video_begin_date_day'] && $form['video_begin_date_year']) {
            if(mktime(0,0,0,$form['video_end_date_month'],$form['video_end_date_day'],$form['video_end_date_year']) <
               mktime(0,0,0,$form['video_begin_date_month'],$form['video_begin_date_day'],$form['video_begin_date_year'])) {
                $this->addErrorMessage('video_begin_date', '終了日よりも過去の日付を指定してください');
            }
        }
    }


    /**
     * 番組編集の入力チェック
     */
    function checkUpdateTimetableAdmin($form) {
        global $CHANNEL_TBL,$CAPTION_TBL,$SOUND_TBL,$COLOR_TBL,$SCREEN_SIZE_TBL,
        $OPEN_CAPTION_TBL,$CLOSED_CAPTION_TBL,$PRESENCE_TBL,$THREE_D_TBL,$FREE_AIR_TBL;

        //放送日
        $broadcast_date = preg_split("/[-|\/]/",$form["broadcast_date"]);
        if( count($broadcast_date) == 3){
            if( !is_numeric($broadcast_date[0]) || !is_numeric($broadcast_date[1]) || !is_numeric($broadcast_date[2])){
                $this->addErrorMessage("broadcast_date",$this->msgDate);
            } else {
                $this->checkDate($broadcast_date[0], $broadcast_date[1], $broadcast_date[2], "broadcast_date", 1);
            }
        } else {
            $this->addErrorMessage("broadcast_date",$this->msgDate);
        }
        //番組開始時間
        $this->checkRange ($form["begin_time"], "begin_time", 600, 2959, 1);
        //番組終了時間
        //$this->checkRange ($form["end_time"], "end_time", 600, 2959, 1);
        $this->checkRange ($form["end_time"], "end_time", 600, 3159, 1);
        //放送チャネルID
        $this->checkList($form["channel_id"], "channel_id", array_keys($CHANNEL_TBL), 1);
        //字幕
        $this->checkList($form["caption"], "caption", array_keys($CAPTION_TBL), 1);
        //音声
        $this->checkList($form["sound"], "sound", array_keys($SOUND_TBL), 1);
        //色彩
        $this->checkList($form["color"], "color", array_keys($COLOR_TBL), 1);
        //画面サイズ
        $this->checkList($form["screen_size"], "screen_size", array_keys($SCREEN_SIZE_TBL), 1);
        //オープンキャプション
        $this->checkList($form["open_caption"], "open_caption", array_keys($OPEN_CAPTION_TBL), 1);
        //クローズトキャプション
        $this->checkList($form["closed_caption"], "closed_caption", array_keys($CLOSED_CAPTION_TBL), 0);
        //インターミッション
        $this->checkList($form["intermission"], "intermission", array_keys($PRESENCE_TBL), 1);
        //プログレッシブ
        $this->checkList($form["progressive"], "progressive", array_keys($PRESENCE_TBL), 1);
        //3D作品
        $this->checkList($form["three_d"], "three_d", array_keys($THREE_D_TBL), 1);
        //紹介テキスト
        $this->checkText($form["comment"], "comment", 1, 200);
        //HV
        //$this->checkList($form["hv_mark"], "hv_mark", array_keys($PRESENCE_TBL), 1);
        //本編尺
        $this->checkNumeric($form["duration"], "duration", null, 0);
        //特集名
        $this->checkText($form["special_name"], "special_name", 1, 255);
        //無料放送
        $this->checkList($form["free_air"], "free_air", array_keys($FREE_AIR_TBL), 1);
        //解説
        $this->checkList($form["comment_flag"], "comment_flag", array_keys($PRESENCE_TBL), 1);
        //解説者
        $this->checkText($form["commentator"], "commentator", 1, 255);

        //文字多重放送
        $this->checkList($form["teletext"], "teletext", array_keys($PRESENCE_TBL), 0);

        //本編尺
        if ($form["first_air"]) {
            $this->checkDate(substr($form["first_air"],0,4), substr($form["first_air"],4,6), '01', "first_air", 1);
        }

    }


    /**
     * 問い合わせ、資料請求共通の入力チェック
     */
    function checkInquiryCommon($form) {
        global $PREF_TBL,$KANYU_TYPE_TBL,$KANYU,$BROADBAND_TYPE_TBL;

        //
        /*// 名前
        $this->checkText($form["name"], "name", 1, 100, 1);
        // ふりがな
        $this->checkHiragana($form["fname"], "fname", 0, 100, null);
        */

        // 名前
        if($this->checkEmpty($form["name1"], "name")) {
            if($this->checkEmpty($form["name2"], "name")) {
                $this->checkText($form["name1"].$form["name2"], "name", 1, 100, false);
            }
        }

        // ふりがな
       // if(!$this->isEmpty($form["fname1"]) || !$this->isEmpty($form["fname2"])) {
            if($this->checkEmpty($form["fname1"], "fname")) {
                if($this->checkEmpty($form["fname2"], "fname")) {
                    $this->checkHiragana($form["fname1"].$form["fname2"], "fname", 1, 100, true);
                }
            }
        //}

        // 郵便番号
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', null);
        // 住所２
        //資料請求を選択なら必須
        if($form['kind'] == 3){
        	$this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);
        	$this->checkText($form["address1"], "address1", 0, 100, 1);
        }else{
        	$this->checkText($form["address1"], "address1", 0, 100, null);
        }

        // 電話番号
        $this->checkTelAll ($form['tel1']."-".$form['tel2']."-".$form['tel3'], 'tel', array(1,1,1), array(4,5,5), 1);
        // メールアドレス
        $this->checkEmail ($form["email1"].'@'.$form["email2"], 'email', 100, null, 1);
        // 加入しているか
        $this->checkList($form["star_ch_id"], 'star_ch_id', array_keys($KANYU), 1);

        // 視聴方法
        //$ret_service_flag = $this->checkList($form["service_flag"], 'service_flag', array_keys($KANYU_TYPE_TBL), 1);
	//2013.6.17
	if($form["star_ch_id"] == 1){
        	$ret_service_flag = $this->checkList($form["service_flag"], 'service_flag', array_keys($KANYU_TYPE_TBL), 1);
	}
        if($ret_service_flag){
            if($form["star_ch_id"] == 1 && $form["service_flag"] == 0){
                $this->addErrorMessage('service_flag', 'スターチャンネル加入者は、「いずれも視聴していない」は選択できません');
            }
        }

        // "service_flag" の横の欄の入力チェック
        //if ($form["star_ch_id"] == 1 && ($form["service_flag"] == 20 || $form["service_flag"] == 21 || $form["service_flag"] == 22)) {
        if ($form["star_ch_id"] == 1 && ($form["service_flag"] == 20 || $form["service_flag"] == 22)) {
            // ケーブルTV局名
            $this->checkText($form["shityo_cable"], "shityo_cable", 1, 100, 1);
        } else {
            // ケーブルTV局名
            $this->checkText($form["shityo_cable"], "shityo_cable", 1, 100, false);
        }
        if ($form["star_ch_id"] == 1 && $form["service_flag"] == 11) {
            // その他のサービス名
            $this->checkList($form["shityo_service"], 'shityo_service', array_keys($BROADBAND_TYPE_TBL), 1);
        } else {
            $this->checkList($form["shityo_service"], 'shityo_service', array_keys($BROADBAND_TYPE_TBL), false);
        }

        if ($form["star_ch_id"] == 1 && $form["service_flag"] == 5) {
            // 上記以外での視聴環境
            $this->checkText($form["shityo_other"], "shityo_other", 0, 100, 1);
        } else {
            $this->checkText($form["shityo_other"], "shityo_other", 0, 100, false);
        }

    }


    /**
     * プログラムガイド 年間購読申込み【新規】の入力チェック
     */
    function checkGuide($form) {
        global $PREF_TBL,$KANYU_TYPE_TBL,$TOIAWASE_KNOW_TYPE_TBL,$SEX,$BROADBAND_TYPE_TBL,$START_MONTH_TBL,$PROGRAM_GENRE_TBL;

        // 01. 購読申込み 新規または継続 new_or_continue
        $ret = $this->checkList($form["new_or_continue"], 'new_or_continue', array_keys($PROGRAM_GENRE_TBL), true);
        if(($form["new_or_continue"] == 2) && ($form["pay_kind"] == 2)) {
            $this->addErrorMessage("new_or_continue", "お手元の払込票でお支払下さい。お申込みは不要です。");
        }


        // 払込票番号入力
        if($form["new_or_continue"] == 2) {
            if($this->isEmpty($form["sheet_no"])){
                $this->addErrorMessage("sheet_no", "継続される方は払込票番号を入力してください。");
            }elseif($this->isNumeric($form["sheet_no"])){
                $this->checkDigit ($form["sheet_no"], "sheet_no", 11, 11, array("msgDigit"=>"[#MAX#]桁の数字を入力して下さい。") );
            }else{
                $this->addErrorMessage("sheet_no", "11桁の半角数字でご記入ください");
            }
        }

        /*
        // 02. 購読希望期間
        if ($ret) {
            $now = date('Ymd');

            //$chdays = "25";
            $f_chdays = "14";
            $l_chdays = "26";
            //$this_mon = date('Ym').$l_chdays;
            $this_mon = $START_MONTH_TBL['1'].$l_chdays;
            $this_day = date('d');

            // 新規の場合、26日以降は当月号の選択を不可にするようにチェック
            if(isset($form["new_or_continue"])&&$form["new_or_continue"]==1){
            	//if ($now >= $this_mon && $form["start_month"] == $GLOBALS["START_MONTH_TBL"][1]) {
	            if ($now >= $this_mon && $form["start_month"] == 1) {
	                $this->addErrorMessage('start_month', $l_chdays.'日～末日の間は、翌月号をお選びください。');
	            }
            }
            // 継続の場合、14日以前は翌月号の選択を不可にするようにチェック
            else if($form["new_or_continue"]==2){
            	if ($f_chdays >= $this_day && $form["start_month"] == 4) {
	                $this->addErrorMessage('start_month', '1日～'.$f_chdays.
	                	'日の間は、選択できません。<br>15日以降に再度お申し込みください。');
	            }
            }
        }
        */
        // 03. 【購読料のお支払い方法】いずれかお選び下さい
        $this->checkList($form["pay_kind"], 'pay_kind', array_keys($GLOBALS["PAY_KIND_TBL"]), true);
        if(($form["new_or_continue"] == 2) && ($form["pay_kind"] == 2)) {
            $this->addErrorMessage("pay_kind", "お手元の払込票でお支払下さい。お申込みは不要です。");
        }


        // 名前
        if($this->checkEmpty($form["name1"], "name")) {
            if($this->checkEmpty($form["name2"], "name")) {
                $this->checkText($form["name1"].$form["name2"], "name", 1, 100, false);
            }
        }

        // ふりがな
        //if(!$this->isEmpty($form["fname1"]) || !$this->isEmpty($form["fname2"])) {
            if($this->checkEmpty($form["fname1"], "fname")) {
                if($this->checkEmpty($form["fname2"], "fname")) {
                    $this->checkHiragana($form["fname1"].$form["fname2"], "fname", 1, 100, true);
                }
            }
        //}

        // 郵便番号
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', 1);
        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);
        // 住所１
        $this->checkText($form["address1"], "address1", 1, 100, 1);
        // 住所２
        $this->checkText($form["address2"], "address2", 0, 200, null);
        // 電話番号
        $this->checkTelAll ($form['tel1']."-".$form['tel2']."-".$form['tel3'], 'tel', array(1,1,1), array(4,5,5), true,
                            array("msgDigit"=>"全ての項目について[#MIN#]ケタから[#MAX#]ケタ以内で入力してください。"));
        // メールアドレス
        $this->checkEmail ($form["email1"].'@'.$form["email2"], 'email', 100, false, true);
        // 性別
        $this->checkList($form["sex"], 'sex', array_keys($SEX));
        // 視聴方法
        $this->checkList($form["service_flag"], 'service_flag', array_keys($KANYU_TYPE_TBL), null);

        // "service_flag" の横の欄の入力チェック
        //if ($form["service_flag"] == 20 || $form["service_flag"] == 21 || $form["service_flag"] == 22) {
# 201703_sta
#        if ($form["service_flag"] == 20 || $form["service_flag"] == 22) {
#            // ケーブルTV局名
#            $this->checkText($form["shityo_cable"], "shityo_cable", 1, 100, 1);
#        } elseif ($form["service_flag"] == 11) {
#            // その他のサービス名
#            $this->checkList($form["shityo_service"], 'shityo_service', array_keys($BROADBAND_TYPE_TBL), 1);
#        } elseif ($form["service_flag"] == 5) {
#            // 上記以外での視聴環境
#            $this->checkText($form["shityo_other"], "shityo_other", 0, 100, 1);
#        }
        if ($form["service_flag"] == 3 || $form["service_flag"] == 5) {
            // ケーブルTV局名
            $this->checkText($form["shityo_cable"], "shityo_cable", 1, 100, 1);
        }
# 201703_end

        // 生年月日
        $ret = $this->checkNumeric($form['birth'], 'birth', 4, null);
        if ($ret) {
            $this->checkRange($form['birth'], 'birth', 1902,
                              date("Y",time()), null);
        }

        // このサイトを何処で知ったか
        $this->checkList($form["know_flag"], 'know_flag', array_keys($TOIAWASE_KNOW_TYPE_TBL));
        if($form["know_flag"] == 6) {
            // その他
            $this->checkText($form["know_other"], "know_other", 1, 50, 1);
        }
        // その他
        else{
            $this->checkText($form["know_other"], "know_other", 0, 100, null);
        }

        // 購読は当月号から／翌月号からお選び下さい。（※）
        //$ret = $this->checkList($form["start_month"], 'start_month', array_keys($GLOBALS["START_MONTH_TBL"]), true);
        //$ret = $this->checkList($form["start_month"], 'start_month', array_values($GLOBALS["START_MONTH_TBL"]), true);
        //0$ret = $this->checkList($form["start_month"], 'start_month', array_keys($GLOBALS["START_MONTH_TBL_SINKI"]), true);

    }


    /**
     * リクエストシアターの入力チェック
     */
    function checkRequest($form) {
        global $PREF_TBL,$KANYU_TYPE_TBL,$SEX,$SERVICE_TBL;
        global $JOINING_YEAR,$JOINING_MONTH,$JOB_TBL,$YESNO_TBL;
        // スター・チャンネルで放送してほしい映画タイトル
        $this->checkText($form["title"], "title", 1, 50, true);
        // リクエストする理由
        $this->checkText($form["text"], "text", 1, 100, false);
        // 名前
        $this->checkText($form["name"], "name", 1, 100, true);
        // メールアドレス
        $this->checkEmail ($form["email1"].'@'.$form["email2"], 'email', 100, null, true);
        // 性別
        $this->checkList($form["sex"], 'sex', array_keys($SEX), true);
        // 視聴方法
        $this->checkList($form["service_flag"], 'service_flag', array_keys($KANYU_TYPE_TBL), true);

        // "service_flag" テキスト入力欄チェック
        if ($form["service_flag"] == 20 || $form["service_flag"] == 22) {
            // ケーブルTV局名
           //$this->checkText($form["service_text"], "service_text", 1, 100, 1);
           $this->checkText($form["shityo_cable"], "shityo_cable", 1, 100, 1);
        } elseif ($form["service_flag"] == 5) {
            // 上記以外での視聴環境
            //$this->checkText($form["service_text"], "service_text", 1, 100, 1);
            $this->checkText($form["shityo_other"], "shityo_other", 1, 100, 1);
        }
        ///// メール配信希望するか  //停止！！！！！
        //$this->checkList($form["mail_flag"], 'mail_flag', array_keys($YESNO_TBL), true);

    }


    /**
     * 問い合わせの入力チェック
     */
    function checkInquiryToiawase($form) {
        global $PREF_TBL,$SEX,$REQUEST_TBL,$TOIAWASE_KNOW_TYPE_TBL,$KANYU_TYPE_TBL,$BROADBAND_TYPE_TBL;

        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), null);
        // 住所１
        $this->checkText($form["address1"], "address1", 1, 100, null);
        // 性別
        $this->checkList($form["sex"], 'sex', array_keys($SEX));
        // 生年月日
        $ret = $this->checkDate($form['year'], $form['month'], $form['day'], 'birth', null);
        if ($ret) {
            $date = "";
            if($form['year'] or $form['month'] or $form['day']) {
                $date = sprintf("%d%02d%02d", $form['year'], $form['month'], $form['day']);
            }
            $this->checkDateRange($date, 'birth', 19020101, date("Ymd",time()), null);
        }
        // このサイトを何処で知ったか
        $this->checkList($form["know_flag"], 'know_flag', array_keys($TOIAWASE_KNOW_TYPE_TBL), 1);
        if($form["know_flag"] == 6) {
            // その他
            $this->checkText($form["know_other"], "know_other", 1, 100, 1);
        } else {
            $this->checkText($form["know_other"], "know_other", 0, 100, 0);
        }
        // 問い合わせ種別
        //$this->checkList($form["kind"], 'kind', array_keys($REQUEST_TBL), 1);
        // 問い合わせ内容
        $this->checkText($form["text"], "text", 1, 2000, 1);
    }

    /**
     * 資料請求の入力チェック
     */
    function checkInquiryShiryo($form) {
        global $PREF_TBL,$YESNO_TBL,$TOIAWASE_KNOW_TYPE_TBL;

        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), true);
        // 住所１
        $this->checkText($form["address1"], "address1", 1, 100, true);

        // このサイトを何処で知ったか
        $this->checkList($form["know_flag"], 'know_flag', array_keys($TOIAWASE_KNOW_TYPE_TBL));
        if($form["know_flag"] == 6) {
            // その他
            $this->checkText($form["know_other"], "know_other", 1, 100, 1);
        } else {
            $this->checkText($form["know_other"], "know_other", 0, 100, 0);
        }
        // お得メールフラグ
        $this->checkList($form["mail_flag"], 'mail_flag', array_keys($YESNO_TBL));
    }

    /**
     * パスワード変更の入力チェック
     */
    function checkChangePassword($form) {
        if ($this->isAlnum($form["password"])) {
            $messages = array("msgDigit" => "半角英数字4～8文字でご記入ください。");
            $this->checkDigit($form["password"], 'password', 4, 8, $messages);
        } else {
            $this->addErrorMessage('password', '半角英数字4～8文字でご記入ください。');
        }
    }

    /**
     * プレゼント応募アンケート＆コード＆誰でもチェック
     */
    function checkPresentEnquete($form, &$presentEvent, $member=null) {
        global $SEX, $PREF_TBL, $ENQUETE_TYPE_TBL, $ENQUETE_JOB_TBL, $ENQUETE_HOW_KNOW_TBL, $ENQUETE_HOW_WATCH_TBL, $ENQUETE_FREQUENCY_A_TBL, $ENQUETE_FREQUENCY_B_TBL, $KANYU_TYPE_TBL, $PRESENT_SELET_FLAG_TBL;

		if ($presentEvent->present_event_system == 2) {
			// アンケートの場合
			for ($i=1; $i < 11 ; $i++) {
				$enqueteType = 'enquete' . $i . '_type';
				$enqueteAnswer = 'enquete' . $i . '_answer';
                $enquete_select_question = 'enquete' . $i . '_select_question';
				if ($presentEvent->$enqueteType != 0) {
					// 設問種別
					if ($presentEvent->$enqueteType == 1) {
						// 頻度A
						$this->checkList($form[$enqueteAnswer], $enqueteAnswer, array_keys($ENQUETE_FREQUENCY_A_TBL),1);
					}

					if ($presentEvent->$enqueteType == 2) {
						// 頻度B
						$this->checkList($form[$enqueteAnswer], $enqueteAnswer, array_keys($ENQUETE_FREQUENCY_B_TBL),1);
					}

					if ($presentEvent->$enqueteType == 3) {
						// 認知経路
						$this->checkList($form[$enqueteAnswer], $enqueteAnswer, array_keys($ENQUETE_HOW_KNOW_TBL),1);
					}

					if ($presentEvent->$enqueteType == 4) {
						// 職業
						$this->checkList($form[$enqueteAnswer], $enqueteAnswer, array_keys($ENQUETE_JOB_TBL),1);
					}

					if ($presentEvent->$enqueteType == 5) {
						// どのように映画を見るか
						$this->checkSelections($form[$enqueteAnswer], $enqueteAnswer, array_keys($ENQUETE_HOW_WATCH_TBL), 1);
					}

					if ($presentEvent->$enqueteType == 99) {
						// テキスト
						$this->checkText($form[$enqueteAnswer], $enqueteAnswer, 0, 2000, 1);
					}

                    if ($presentEvent->$enqueteType == 6) {

                        $array = explode("|",  $presentEvent->$enquete_select_question);

                        // 複数選択なし（ラジオボタン）
                        $this->checkList($form[$enqueteAnswer], $enqueteAnswer, array_keys($array),1);
                    }

                    if ($presentEvent->$enqueteType == 7) {

                        $array = explode("|", $presentEvent->$enquete_select_question);

                        // 複数選択あり（チェックボックス）
                        $this->checkSelections($form[$enqueteAnswer], $enqueteAnswer, array_keys($array), 1);
                    }
				}
			}
		} elseif ($presentEvent->present_event_system == 3) {
			// プレゼントコードの場合
			$this->checkText($form["code"], 'code', 0, 100, 1);
			if(! is_null($form["code"]) && $form["code"] != "" && ! ctype_alnum($form["code"])) {
			    $this->addErrorMessage('code', '正しく入力してください。');
            }
		} else {
			// 誰でも（通常）応募の場合

			if (is_null($member)) {

			    // 名前
		        $this->checkText($form["name"], "name", 1, 20, 1); //XXX文字数チェックを変更する
		        // 名前カナ
		        $this->checkText($form["name_kana"], "name_kana", 1, 20, 1);
		        // 郵便番号
		        $this->checkZip($form['zip1'], $form['zip2'], 'zip', 1);
		        // 都道府県
		        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);
		        // 住所１
		        $this->checkText($form["address"], "address", 1, 100, 1); //XXX文字数チェックを変更する
		        // 住所２
		        //$this->checkText($form["bldg"], "bldg", 1, 100, 1);
		        // メールアドレス
		        $this->checkEmail($form["email1"], 'email1', 100, null, 1); //変更必要あり
		        // メアド確認
		        $this->checkSameData($form["email1"], $form["email2"], 'email2', 1); //変更必要あり
				//if (strcmp($form["email1"], $form["email2"]) !== 0 ) $this->addErrorMessage('email2', '正しく入力してください。');
		        // TEL
		        $tel = explode('-', $form['tel']);
				foreach ($tel as $key => $value) {
					$tels .= $value;
				}
				if (!preg_match('/^0\d{9,10}$/u', $tels)) $this->addErrorMessage('tel', '正しく入力してください。');
		        //$this->checkTelAll($form["tel"], 'tel', array(2,2,2), array(5,5,5), null, 1); //変更必要あり
		        // 生年月日
		        //$this->checkEmptySelect($form["birth_y"], 'birth', 100, null, 1);
		        if ($form['birth_y']=="" || $form['birth_m']=="" || $form['birth_d']=="") $this->addErrorMessage('birth', '選択してください。');
		        // 性別
		        $this->checkList($form["sex"], 'sex', array_keys($SEX), 1); //変更必要あり
				// 加入サービスタイプ
				$this->checkList($form["service_type"], 'service_type', array_keys($KANYU_TYPE_TBL),1);

		    }

			// アンケートの場合
			for ($i=1; $i < 11 ; $i++) {
				$enqueteType = 'enquete' . $i . '_type';
				$enqueteAnswer = 'enquete' . $i . '_answer';
                $enquete_select_question = 'enquete' . $i . '_select_question';

				if ($presentEvent->$enqueteType != 0) {
					// 設問種別
					if ($presentEvent->$enqueteType == 1) {
						// 頻度A
						$this->checkList($form[$enqueteAnswer], $enqueteAnswer, array_keys($ENQUETE_FREQUENCY_A_TBL),1);
					}

					if ($presentEvent->$enqueteType == 2) {
						// 頻度B
						$this->checkList($form[$enqueteAnswer], $enqueteAnswer, array_keys($ENQUETE_FREQUENCY_B_TBL),1);
					}

					if ($presentEvent->$enqueteType == 3) {
						// 認知経路
						$this->checkList($form[$enqueteAnswer], $enqueteAnswer, array_keys($ENQUETE_HOW_KNOW_TBL),1);
					}

					if ($presentEvent->$enqueteType == 4) {
						// 職業
						$this->checkList($form[$enqueteAnswer], $enqueteAnswer, array_keys($ENQUETE_JOB_TBL),1);
					}

					if ($presentEvent->$enqueteType == 5) {
						// どのように映画を見るか
						$this->checkSelections($form[$enqueteAnswer], $enqueteAnswer, array_keys($ENQUETE_HOW_WATCH_TBL), 1);
					}

					if ($presentEvent->$enqueteType == 99) {
						// テキスト
						$this->checkText($form[$enqueteAnswer], $enqueteAnswer, 0, 2000, 1);
					}

                    if ($presentEvent->$enqueteType == 6) {

					    $array = explode("|",  $presentEvent->$enquete_select_question);

                        // 複数選択なし（ラジオボタン）
                        $this->checkList($form[$enqueteAnswer], $enqueteAnswer, array_keys($array),1);
                    }

                    if ($presentEvent->$enqueteType == 7) {

                        $array = explode("|", $presentEvent->$enquete_select_question);

                        // 複数選択あり（チェックボックス）
                        $this->checkSelections($form[$enqueteAnswer], $enqueteAnswer, array_keys($array), 1);
                    }
				}
			}


		}

        // 複数プレゼント
        if (($presentEvent->present_select_flag != 0) && ($presentEvent->present_select_question != "")) {
            $selectQuestions = $presentEvent->present_select_question;
            // パイプ全角を半角に
            $selectQuestions = str_replace("｜", "|", $selectQuestions);
            // パイプで区切る
            $selectPresent = explode('|', $selectQuestions);
            if ($presentEvent->present_select_flag == 1) $this->checkList($form["select_present"], 'select_present', array_keys($selectPresent), 1);
            if ($presentEvent->present_select_flag == 2) $this->checkSelections($form["select_present"], "select_present", array_keys($selectPresent), 1);
        }


    }

    /**
     * プレゼント応募チェック
     */
    function checkPresent($form, &$present) {
        global $PREF_TBL,$KANYU_TYPE_TBL,$YESNO_TBL,$KANYU,$PRESENT_KNOW_TYPE_TBL,
            $BROADBAND_TYPE_TBL;

        // 名前
        $this->checkText($form["name"], "name", 1, 20, 1); //XXX文字数チェックを変更する
        // 郵便番号
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', null);
        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);
        // 住所１
        $this->checkText($form["address"], "address", 1, 100, 1); //XXX文字数チェックを変更する
        // メールアドレス
        if($form["info_mail_flag"] == 1) {
            $this->checkEmail ($form["email1"].'@'.$form["email2"], 'email', 100, null, 1);
        }else {
            if($form["email1"] or $form["email2"]) {
                $this->checkEmail ($form["email1"].'@'.$form["email2"], 'email', 100, null);
            }
        }
        // メール配信希望
        $this->checkList($form["info_mail_flag"], 'info_mail_flag', array_keys($YESNO_TBL));
        // 視聴者限定の場合、スターチャンネルに加入していないを選択の場合はエラーを表示
        if( $this->checkList($form["star_ch_id"], 'star_ch_id', array_keys($KANYU), 1) ){
            if($present->applicant_limit == 'viewer'){
                // 加入している以外の場合エラー
                if($form["star_ch_id"] != 1){
                    $this->addErrorMessage('star_ch_id', '視聴者限定のプレゼントは、スターチャンネル加入者しか応募できません。');
                }
            }
        }

        // 視聴方法を表示している場合はチェックを行なう
        if($present->service_flag == 1){
            // 視聴方法
            $ret_service_flag = $this->checkList($form["service_flag"], 'service_flag', array_keys($KANYU_TYPE_TBL),1);
            if($ret_service_flag){

                if ($form["star_ch_id"] == "1" && $form["service_flag"] === "0") {
                    $this->addErrorMessage('service_flag', 'スターチャンネル加入者は、選択できません');
                }

                // J:COM
                if ( $form["star_ch_id"] == "1" &&
                    //($form["service_flag"] == "20" || $form["service_flag"] == "21" || $form["service_flag"] == "22")) {
                    ($form["service_flag"] == "20" || $form["service_flag"] == "22")) {
                    // ケーブル局名
                    $this->checkText($form["shityo_cable"], "shityo_cable", 1, 100, 1);
                } else {
                    $this->checkText($form["shityo_cable"], "shityo_cable", 1, 100, null);
                }

                if ($form["star_ch_id"] == "1" && $form["service_flag"] == "11") {
                    // その他のサービス（IPTV）
                    $this->checkList($form["shityo_service"], 'shityo_service', array_keys($BROADBAND_TYPE_TBL),1);
                } else {
                    $this->checkList($form["shityo_service"], 'shityo_service', array_keys($BROADBAND_TYPE_TBL), null);
                }

                if ($form["star_ch_id"] == "1" && $form["service_flag"] == "5") {
                    // 上記以外での視聴環境
                    $this->checkText($form["shityo_other"], "shityo_other", 1, 100, 1);
                } else {
                    $this->checkText($form["shityo_other"], "shityo_other", 1, 100, null);
                }
            }

            // 認知経路
            $ret_service_flag = $this->checkList($form["know_flag"], 'know_flag', array_keys($PRESENT_KNOW_TYPE_TBL),1);
            // その他の場合はテキスト必須
            if($form["know_flag"] == 5){
                $this->checkText($form["know_other"], "know_other", 1, 100, 1);
            }
        }
        // 意見１
        $this->checkText($form["answer1"], "answer1", 0, 100, null);
        // 意見２
        $this->checkText($form["answer2"], "answer2", 0, 100, null);
        // 意見３
        $this->checkText($form["answer3"], "answer3", 0, 100, null);
        //メンバー情報更新しますか？
        $this->checkList($form["update_member_flag"], 'update_member_flag', array_keys($YESNO_TBL),1);
    }

    /**
     * santakuプレゼント応募チェック(携帯用)
     */
    function checkStkMobilePresent($form) {
        // メールアドレスのチェック
        $this->checkEmail($form["email"], 'email', 100, null, 1);
        // 電話番号のチェック
        $this->checkTelAll($form["tel1"]."-".$form["tel2"]."-".$form["tel3"], 'tel', array(1,1,1), array(4,5,5), 1);
    }

    /**
     * 携帯用ログイン入力チェック
     */
    function checkLoginMobile($form) {
        $messages = "";

        // ログインID
        $this->checkText($form["login_id"], 'login_id', 1, 100, 1);

        // パスワード
        if ($this->isAlnum($form["password"])) {
            $messages = array("msgDigit" => "半角英数字4～8文字でご記入ください。");
            $this->checkDigit($form["password"], 'password', 4, 8, $messages);
        } else {
            $this->addErrorMessage('password', '半角英数字4～8文字でご記入ください。');
        }
    }


    /**
     * お気に入り登録時チェック
     */
    function checkAddFavorite($form) {

        $selected_count = count($form["m_id"]);
        if( $selected_count == 0){
            $this->addErrorMessage('m_id','お気に入りに登録する人を選択してください。');
        } else {
            if($form["search_kind"] == "2"){
                // お気に入り監督を取得
                $favoriteDirectorDAO =& FavoriteDirectorDAO::getInstance();
                $favorite_director_list = $favoriteDirectorDAO->getFavoriteDirectorListByMemberId($form["member_id"]);

                $favorited_count = count($favorite_director_list);

            } elseif($form["search_kind"] == "3"){
                // 俳優マスタの50音検索
                $favoriteActorDAO =& FavoriteActorDAO::getInstance();
                $favorite_actor_list = $favoriteActorDAO->getFavoriteActorListByMemberId($form["member_id"]);

                $favorited_count = count($favorite_actor_list);

            }

            // 現在のお気に入りの人数と選択した人数を足した数が10人を超えた場合はエラー
            if( ($selected_count+$favorited_count) > 10){
                $this->addErrorMessage('m_id','お気に入りに登録できる人数は監督・俳優それぞれ10人までです。');
            }
        }
    }


    /**
     * 汎用投票に投票するときのチェック
     */
    function checkAddGeneralVote($form) {
        $this->checkEmptySelect ($form["id"], 'id', $messages=null);
    }


    /**
     * メンバー用プレゼント応募チェック(携帯用)
     */
    function checkMemberMobilePresent($form, &$present) {
        global $PREF_TBL,$KANYU_TYPE_TBL,$YESNO_TBL,$KANYU,$PRESENT_KNOW_TYPE_TBL;

        // 名前
        $this->checkText($form["name"], "name", 1, 20, 1);
        // 郵便番号
        $this->checkZipMobile($form["zip"], 'zip', 1);
        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);
        // 住所１
        $this->checkText($form["address"], "address", 1, 100, 1); //XXX文字数チェックを変更する
        // メールアドレス
        if($form["info_mail_flag"] == 1) {
            $this->checkEmail ($form["email"], 'email', 100, null);
        }else {
            $this->checkEmail ($form["email"], 'email', 100, null);
        }
        // メール配信希望
        $this->checkList($form["info_mail_flag"], 'info_mail_flag', array_keys($YESNO_TBL));
        // 視聴者限定の場合、スターチャンネルに加入していないを選択の場合はエラーを表示
        if( $this->checkList($form["star_ch_id"], 'star_ch_id', array_keys($KANYU), 1) ){
            if($present->applicant_limit == 'viewer'){
                // 加入している以外の場合エラー
                if($form["star_ch_id"] != 1){
                    $this->addErrorMessage('star_ch_id', '視聴者限定のプレゼントは、スターチャンネル加入者しか応募できません。');
                }
            }
        }

        // 視聴方法を表示している場合はチェックを行なう
        if($present->service_flag == 1){
            // 視聴方法
            $ret_service_flag = $this->checkList($form["service_flag"], 'service_flag', array_keys($KANYU_TYPE_TBL),1);
            if($ret_service_flag){
                //if($form["star_ch_id"] == 1 && $form["service_flag"] == 12){
                if($form["star_ch_id"] == 1 && $form["service_flag"] == 0){
                    $this->addErrorMessage('service_flag', 'スターチャンネル加入者は、いずれかの視聴方法を選択してください。');
                }
            }

            // 認知経路
            $ret_service_flag = $this->checkList($form["know_flag"], 'know_flag', array_keys($PRESENT_KNOW_TYPE_TBL),1);
            // その他の場合はテキスト必須
            if($form["know_flag"] == 5){
                $this->checkText($form["know_other"], "know_other", 1, 100, 1);
            }
        }
        // 意見１
        $this->checkText($form["answer1"], "answer1", 0, 100, null);
        // 意見２
        $this->checkText($form["answer2"], "answer2", 0, 100, null);
        // 意見３
        $this->checkText($form["answer3"], "answer3", 0, 100, null);

    }

    /**
     * レギュラー番組対応表入力チェック
     */
    function checkRegularTimetable ($form) {
        //作品ID
        $this->checkNumeric($form["movie_id"], "movie_id", 10, 1);
        //放送日コメント
        $this->checkText($form["onair_comment"], "onair_comment", 1, 100, 0);
        //作品解説
        $this->checkText($form["description"], "description", 1, 2000, 0);
        //表示順序
        $this->checkNumeric($form["disp_order"], "disp_order", 9, true);
        //表示開始日
        $this->checkStkDate($form["open_date"], "open_date", 1);
        //表示終了日
        $this->checkStkDate($form["close_date"], "close_date", 1);
    }

    /**
     * レギュラー番組対応表入力チェック(複数一括登録用)
     */
    function checkRegularTimetableInsert ($form, $num) {
        $empty_cnt = 0;
        for ($i=0;$i<$num;$i++) {
            $iteration = $i + 1;
            if ($this->isEmpty($form["movie_id"][$i]) &&
//                $this->isEmpty($form["onair_comment"][$i]) &&
                $this->isEmpty($form["description"][$i]) &&
                $this->isEmpty($form["disp_order"][$i]) &&
                $this->isEmpty($form["open_date"][$i]) &&
                $this->isEmpty($form["close_date"][$i])) {
                // １行全て未入力の場合はチェックしない
                $empty_cnt++;
                continue;
            }
            //作品ID
            $this->checkNumeric($form["movie_id"][$i], "movie_id".$iteration, 10, 1);
            //放送日コメント
//            $this->checkText($form["onair_comment"][$i], "onair_comment".$iteration, 1, 100, 0);
            //作品解説
            $this->checkText($form["description"][$i], "description".$iteration, 1, 2000, 0);
            //表示順序  FIXME 32767
            $this->checkRange($form["disp_order"][$i], "disp_order".$iteration, 0, 999999999, 0);
            //表示開始日
            $this->checkStkDate($form["open_date"][$i], "open_date".$iteration, 1);
            //表示終了日
            $this->checkStkDate($form["close_date"][$i], "close_date".$iteration, 1);

        }

        // 全て未入力時のチェック
        if ($num == $empty_cnt) {
            $this->addErrorMessage("regular_timetable", $this->msgInput);
        }
    }


    /**
     * メンバープレゼントチェック
     */
    function checkPresentAdmin($form) {
        global $STK_IMAGE_SHOW_TBL,$DISPLAY_TBL,$APPLICANT_LIMIT_TBL,$PRESENT_EVENT_SYSTEM_TBL,$PRESENT_EVENT_TYPE_TBL,$ENQUETE_TYPE_TBL,$PRESENT_SELET_FLAG_TBL,$DISPLAY_TYPE_TBL;
        global $PRESENT_EVENT_DATE_HOUR,$PRESENT_EVENT_DATE_MINUTE;

        // プレゼント名
        $this->checkText($form["present_event_name"], "present_event_name", 1, 2000, 1);
        // プレゼント説明
        $this->checkText($form["description"], "description", 1, 2000, 1);
        // 応募者制限
        $this->checkList($form["applicant_limit"], 'applicant_limit', array_keys($APPLICANT_LIMIT_TBL), 1);
        // 応募体系
        $this->checkList($form["present_event_system"], 'present_event_system', array_keys($PRESENT_EVENT_SYSTEM_TBL), 1);
        // 画像フラグ
        $this->checkList($form["image"], 'image', array_keys($STK_IMAGE_SHOW_TBL), 1);
        // 種別フラグ
        $this->checkList($form["present_event_type"], 'present_event_type', array_keys($PRESENT_EVENT_TYPE_TBL), 1);
        // 人数
        $this->checkText($form["limit_number"], "limit_number", 1, 200, 0);
        // 提供
        $this->checkText($form["offer"], "offer", 1, 200, 0);
        // 備考
        $this->checkText($form["note"], "note", 0, 3000, 0);
        // クレジット
        $this->checkText($form["credit"], "credit", 0, 200, 0);
        // お知らせメールフラグ
        //$this->checkList($form["info_mail_flag"], 'info_mail_flag', array_keys($DISPLAY_TBL), 1);
        // 視聴方法
        //$this->checkList($form["service_flag"], 'service_flag', array_keys($DISPLAY_TBL), 1);
        if (!$this->isEmpty($form['present_event_type']) && $form['present_event_type'] == '1') {
            // プレゼント選択フラグ
            $this->checkList($form["present_select_flag"], 'present_select_flag', array_keys($PRESENT_SELET_FLAG_TBL), 1);
            // プレゼント選択内容
            if ($form["present_select_flag"]) $this->checkText($form["present_select_question"], "present_select_question", 0, 20000, 1);
        }
        // アンケート設問１
        $this->checkText($form["enquete1_question"], "enquete1_question", 0, 2000, null);
        // アンケート設問１種別
        $this->checkList($form["enquete1_type"], 'enquete1_type', array_keys($ENQUETE_TYPE_TBL), 0);
        // アンケート設問２
        $this->checkText($form["enquete2_question"], "enquete2_question", 0, 2000, null);
        // アンケート設問２種別
        $this->checkList($form["enquete2_type"], 'enquete2_type', array_keys($ENQUETE_TYPE_TBL), 0);
        // アンケート設問３
        $this->checkText($form["enquete3_question"], "enquete3_question", 0, 2000, null);
        // アンケート設問３種別
        $this->checkList($form["enquete3_type"], 'enquete3_type', array_keys($ENQUETE_TYPE_TBL), 0);
        // アンケート設問４
        $this->checkText($form["enquete4_question"], "enquete4_question", 0, 2000, null);
        // アンケート設問４種別
        $this->checkList($form["enquete4_type"], 'enquete4_type', array_keys($ENQUETE_TYPE_TBL), 0);
        // アンケート設問５
        $this->checkText($form["enquete5_question"], "enquete5_question", 0, 2000, null);
        // アンケート設問５種別
        $this->checkList($form["enquete5_type"], 'enquete5_type', array_keys($ENQUETE_TYPE_TBL), 0);
        // アンケート設問６
        $this->checkText($form["enquete6_question"], "enquete6_question", 0, 2000, null);
        // アンケート設問６種別
        $this->checkList($form["enquete6_type"], 'enquete6_type', array_keys($ENQUETE_TYPE_TBL), 0);
        // アンケート設問７
        $this->checkText($form["enquete7_question"], "enquete7_question", 0, 2000, null);
        // アンケート設問７種別
        $this->checkList($form["enquete7_type"], 'enquete7_type', array_keys($ENQUETE_TYPE_TBL), 0);
        // アンケート設問８
        $this->checkText($form["enquete8_question"], "enquete8_question", 0, 2000, null);
        // アンケート設問８種別
        $this->checkList($form["enquete8_type"], 'enquete8_type', array_keys($ENQUETE_TYPE_TBL), 0);
        // アンケート設問９
        $this->checkText($form["enquete9_question"], "enquete9_question", 0, 2000, null);
        // アンケート設問９種別
        $this->checkList($form["enquete9_type"], 'enquete9_type', array_keys($ENQUETE_TYPE_TBL), 0);
        // アンケート設問１０
        $this->checkText($form["enquete10_question"], "enquete10_question", 0, 2000, null);
        // アンケート設問１０種別
        $this->checkList($form["enquete10_type"], 'enquete1_type', array_keys($ENQUETE_TYPE_TBL), 0);
        // 特別ページＵＲＬ
        $this->checkText($form["special_url"], "special_url", 0, 2000, null);
        // 一覧表示タイプ
        $this->checkList($form["list_view_type"], 'list_view_type', array_keys($DISPLAY_TYPE_TBL), 1);


        /*
        // 開始日
        $ret = $this->checkStkDate($form["start_date"], "start_date", 1);
        // 終了日
        $ret1 = $this->checkStkDate($form["end_date"], "end_date", 1);

        if($ret and $ret1) {
            $start_date = preg_split("/[-|\/]/", $form["start_date"]);
            $start = sprintf("%d%02d%02d", $start_date[0],$start_date[1],$start_date[2]);
            $end_date = preg_split("/[-|\/]/", $form["end_date"]);
            $end = sprintf("%d%02d%02d", $end_date[0],$end_date[1],$end_date[2]);

            if($start > $end) {
                $this->addErrorMessage("end_date", "終了日は開始日より後を指定してください");
            }
        }
        */

        // enquete1_select_question～enquete10_select_question
        for ($i = 1; $i <= 11; $i++) {
            $enquete_type = 'enquete'. $i .'_type';
            $enquete_select_question = 'enquete'. $i .'_select_question';
            if(in_array($form["$enquete_type"], array(6, 7)) )
            {
                $this->checkText($form[$enquete_select_question], $enquete_select_question, 0, 500, 1);
            }
        }

        // free_text_area
        $this->checkText($form["free_text_area"], "free_text_area", 0, 500, 0);

        // enquete_title
        $this->checkText($form["enquete_title"], "enquete_title", 0, 500, 0);

        $check_start_date_time = $this->checkDate ($form['start_date_time_year'],$form['start_date_time_month'],$form['start_date_time_day'],  'start_date_time', true);
        $check_end_date_time = $this->checkDate ($form['end_date_time_year'],$form['end_date_time_month'],$form['end_date_time_day'], 'end_date_time', true);
        $check_start_date_time_hour = $this->checkList($form["start_date_time_hour"], "start_date_time_hour", array_keys($PRESENT_EVENT_DATE_HOUR), true);
        $check_start_date_time_minute = $this->checkList($form["start_date_time_minute"], "start_date_time_minute", array_keys($PRESENT_EVENT_DATE_MINUTE), true);
        $check_end_date_time_hour = $this->checkList($form["end_date_time_hour"], "end_date_time_hour", array_keys($PRESENT_EVENT_DATE_HOUR), true);
        $check_end_date_time_minute = $this->checkList($form["end_date_time_minute"], "end_date_time_minute", array_keys($PRESENT_EVENT_DATE_MINUTE), true);

        if($check_start_date_time && $check_end_date_time && $check_start_date_time_hour && $check_start_date_time_minute && $check_end_date_time_hour && $check_end_date_time_minute) {
            if(mktime($form['end_date_time_hour'],$form['end_date_time_minute'],0,$form['end_date_time_month'],$form['end_date_time_day'],$form['end_date_time_year']) <
                mktime($form['start_date_time_hour'],$form['start_date_time_minute'],0,$form['start_date_time_month'],$form['start_date_time_day'],$form['start_date_time_year'])) {
                $this->addErrorMessage('start_date_time', '終了日よりも過去の日付を指定してください');
            }
        }

        //サマリー表示　順序
        $this->checkNumeric($form["summary"], "summary", 5, 0);
        // 劇場新作での表示順
        $this->checkNumeric($form["theater_sort"], "theater_sort", 8, 0);

        $this->checkRange ($form['disp_flg_staging_only'], 'disp_flg_staging_only', 0, 1);
    }

    /**
     * 特集管理用
     */
    function checkSpecial ($form, $ins = null) {
        global $CHANNEL_TBL;
        global $YESNO_TBL,$SP_TYPE_TBL;

        // 特集タイプ
        $this->checkList($form["sp_type"], "sp_type", array_keys($SP_TYPE_TBL));
        if ($form["official_page_flg"] >= 1) {
            // 特集ページURL
            $this->checkText($form["sp_url"], "sp_url", 1, 500, 1);
        }
        if ($form["sp_type"] == 2) {
            // バナー
            $ret1 = $this->checkText($form["banner"], "banner", 1, 500, 1);
            if ($ret1 && !$this->isAscii($form["banner"])) {
                $this->addErrorMessage('banner',$this->msgAlnum);
            }
        }

        /*
        if ($form["sp_type"] == 3) {
            // 特集ページURL
            $this->checkText($form["sp_url"], "sp_url", 1, 500, 1);

            // バナー
            $ret1 = $this->checkText($form["banner"], "banner", 1, 500, 1);
            if ($ret1 && !$this->isAscii($form["banner"])) {
                $this->addErrorMessage('banner',$this->msgAlnum);
            }

        } elseif ($form["sp_type"] == 2) {
            // 背景カラー
            $ret2 = $this->checkText($form["color"], "color", 1, 7, 1);
            if ($ret2 && !$this->isAscii($form["color"])) {
                $this->addErrorMessage('color',$this->msgAlnum);
            }
        }
         */

        //特集名称
        $this->checkText($form["special_name"], "special_name", 1, 50, 1);
        //キャッチテキスト
        $this->checkText($form["catch_text"], "catch_text", 1, 200, 0);
        //概要
        $this->checkText($form["comment"], "comment", 1, 400, 0);
        //詳細
        $this->checkText($form["description"], "description", 1, 2000, 1);
        //チャンネルID
        if (!is_array($form["channel_id"]) || !$form["channel_id"]) {
            $this->addErrorMessage("channel_id", $this->msgSelect);
        } else {
            foreach ($form["channel_id"] as $ch_id) {
                if (!($this->checkList($ch_id, "channel_id", array_keys($CHANNEL_TBL), 1))) {
                    break;
                }
            }
        }
        //放送日コメント
        $this->checkText($form["onair_comment"], "onair_comment", 1, 200, 1);
        //トップページ表示フラグ
        $this->checkList($form["top_viewable"], "top_viewable", array_keys($YESNO_TBL));
        //表示順序
        $this->checkNumeric($form["disp_order"], "disp_order", 9, 1);
        //表示開始日
        $this->checkStkDate($form["open_date"], "open_date", 1);
        //表示終了日
		$this->checkStkDate($form["close_date"], "close_date", 1);
		//ID
		$ret = $this->checkNumeric($form["special_id"], "special_id", 9, 1);

		if($ret){
			if($ins){
				$SpecialDAO = new SpecialDAO();
				$sp = $SpecialDAO->getSpecialById($form["special_id"]);
				if($sp){
					$this->addErrorMessage("special_id", '既に使用されています');
				}
			}
		}

        //動画URL
        $this->checkText($form["video_url"], "video_url", 1, 255);
        //動画掲載開始日
        $this->checkStkDate($form["video_begin_date"], "video_begin_date");
        //動画掲載終了日
        $this->checkStkDate($form["video_end_date"], "video_end_date");
    }

    /**
     * 特集番組対応表入力チェック
     */
    function checkSpecialTimetable ($form) {
        //作品ID
        $this->checkNumeric($form["movie_id"], "movie_id", 10, 1);
        //放送日コメント
        $this->checkText($form["onair_comment"], "onair_comment", 1, 100, 0);
        //作品解説
        $this->checkText($form["description"], "description", 1, 2000, 0);
        //表示順序
        $this->checkNumeric($form["disp_order"], "disp_order", 9, 1);
    }

    /**
     * 特集番組対応表入力チェック(複数一括登録用)
     */
    function checkSpecialTimetableInsert ($form, $num) {
        $empty_cnt = 0;
        for ($i=0;$i<$num;$i++) {
            $iteration = $i + 1;
            if ($this->isEmpty($form["movie_id"][$i]) &&
                //$this->isEmpty($form["onair_comment"][$i]) &&
                $this->isEmpty($form["description"][$i]) &&
                $this->isEmpty($form["disp_order"][$i])) {
                // １行全て未入力の場合はチェックしない
                $empty_cnt++;
                continue;
            }
            //作品ID
            $this->checkNumeric($form["movie_id"][$i], "movie_id".$iteration, 10, 1);
            //放送日コメント
            //$this->checkText($form["onair_comment"][$i], "onair_comment".$iteration, 1, 100, 0);
            //作品解説
            $this->checkText($form["description"][$i], "description".$iteration, 1, 2000, 0);
            //表示順序  FIXME 32767
            $this->checkRange($form["disp_order"][$i], "disp_order".$iteration, 0, 999999999, 0);

        }

        // 全て未入力時のチェック
        if ($num == $empty_cnt) {
            $this->addErrorMessage("special_timetable", $this->msgInput);
        }
    }

    /**
     * ラインナップチェック
     */
    function checkLineUpAdmin($form) {
        global $PRESENCE_TBL;

        //表示順序
        $this->checkNumeric($form["disp_order"], "disp_order", 9, 0);
        // 作品ID
        $this->checkNumeric($form["movie_id"], "movie_id", 9, 1);
        // 映画名
        $this->checkText($form["name"], "name", 1, 200, 1);
        // 映画名（カナ）
        $this->checkKanaZen($form["ename"], "ename", 1, 200, 1);
        // 原題
        $this->checkText($form["original_name"], "original_name", 1, 200, 1);
        // コピーライト
        $this->checkText($form["copyright"], "copyright", 1, 500, 1);
        // キャッチコピー
        $this->checkText($form["catch"], "catch", 1, 1000, 1);
        // 監督
        $this->checkText($form["director"], "director", 1, 200, 1);
        // 出演者1
        $this->checkText($form["cast1"], "cast1", 1, 200, 1);
        // 出演者2
        $this->checkText($form["cast2"], "cast2", 1, 200, 1);
        // 放送日時
        //$this->checkText($form["broadcast_text"], "broadcast_text", 1, 50, 1);
        $this->checkStkDate($form["broadcast_text"], "broadcast_text", 1, 50, 1);
        // 開始日
        $ret = $this->checkStkDate($form["start_date"], "start_date", 1);
        // 終了日
        $ret1 = $this->checkStkDate($form["end_date"], "end_date", 1);

        if($ret and $ret1) {
            $start_date = preg_split("/[-|\/]/", $form["start_date"]);
            $start = sprintf("%d%02d%02d", $start_date[0],$start_date[1],$start_date[2]);
            $end_date = preg_split("/[-|\/]/", $form["end_date"]);
            $end = sprintf("%d%02d%02d", $end_date[0],$end_date[1],$end_date[2]);

            if($start > $end) {
                $this->addErrorMessage("end_date", "終了日は開始日より後を指定してください");
            }
        }
        //ラインナップ動画フラグ
        $this->checkList($form["movie_flag"], "movie_flag", array_keys($PRESENCE_TBL));

        //加入案内表示用チェック
        if ($form['join_disp_flag']) {
	        // 開始日
	        $ret = $this->checkStkDate($form["join_open_date"], "join_open_date", 1);
	        // 終了日
	        $ret1 = $this->checkStkDate($form["join_close_date"], "join_close_date", 1);

	        if($ret and $ret1) {
	            $start_date = preg_split("/[-|\/]/", $form["join_open_date"]);
	            $start = sprintf("%d%02d%02d", $start_date[0],$start_date[1],$start_date[2]);
	            $end_date = preg_split("/[-|\/]/", $form["join_close_date"]);
	            $end = sprintf("%d%02d%02d", $end_date[0],$end_date[1],$end_date[2]);

	            if($start > $end) {
	                $this->addErrorMessage("join_close_date", "終了日は開始日より後を指定してください");
	            }
	        }
	        //表示順序
	        $this->checkNumeric($form["join_sort"], "join_sort", 9, 0);
	    }

        //オンデマンド表示用チェック
        if ($form['ondemand_disp_flag']) {
	        // 開始日
	        $ret = $this->checkStkDate($form["ondemand_open_date"], "ondemand_open_date", 1);
	        // 終了日
	        $ret1 = $this->checkStkDate($form["ondemand_close_date"], "ondemand_close_date", 1);

	        if($ret and $ret1) {
	            $start_date = preg_split("/[-|\/]/", $form["ondemand_open_date"]);
	            $start = sprintf("%d%02d%02d", $start_date[0],$start_date[1],$start_date[2]);
	            $end_date = preg_split("/[-|\/]/", $form["ondemand_close_date"]);
	            $end = sprintf("%d%02d%02d", $end_date[0],$end_date[1],$end_date[2]);

	            if($start > $end) {
	                $this->addErrorMessage("ondemand_close_date", "終了日は開始日より後を指定してください");
	            }
	        }
	        //表示順序
	        $this->checkNumeric($form["ondemand_sort"], "ondemand_sort", 9, 0);
	        //オンデマンドNO
	        $this->checkNumeric($form["ondemand_number"], "ondemand_number", 10, 1);
	        //配信期間
	        $this->checkText($form["ondemand_time"], "ondemand_time", 1, 2000, 1);
	    }

    }

    function checkWebtheaterAdmin($form) {
        global $DISP_AREA_TBL;
        global $YESNO_TBL;
        global $WEB_THEATER_DATE_HOUR;
        global $WEB_THEATER_DATE_MINUTE;



        //sort
        $this->checkNumeric($form['sort'], 'sort', 9, 0);
        //web_theater_id
        $this->checkNumeric($form['web_theater_id'], 'web_theater_id', null, 1);
        //disp_area
        $this->checkList($form["disp_area"], "disp_area", array_keys($DISP_AREA_TBL), true);
        //default_flg
        $this->checkList($form["default_flg"], "default_flg", array_keys($YESNO_TBL));
        //name
        $this->checkText($form["name"], "name", 1, 200, 1);
        //streaming_1
        $this->checkText($form['streaming_1'], 'streaming_1', 1, 500, null);
        //onair_comment
        $this->checkText($form['onair_comment'], 'onair_comment', 1, 200, null);
        //catch
        $this->checkText($form['catch'], 'catch', 1, 1000, null);
        //movie_id
        $this->checkNumeric($form["movie_id"], "movie_id", 10, 0);
        //url
        $this->checkText($form['url'], 'url', 1, 100, null);

        $check_start_date_time = $this->checkDate ($form['start_date_time_year'],$form['start_date_time_month'],$form['start_date_time_day'],  'start_date_time', true);
        $check_end_date_time = $this->checkDate ($form['end_date_time_year'],$form['end_date_time_month'],$form['end_date_time_day'], 'end_date_time', true);
        $check_start_date_time_hour = $this->checkList($form["start_date_time_hour"], "start_date_time_hour", array_keys($WEB_THEATER_DATE_HOUR), true);
        $check_start_date_time_minute = $this->checkList($form["start_date_time_minute"], "start_date_time_minute", array_keys($WEB_THEATER_DATE_MINUTE), true);
        $check_end_date_time_hour = $this->checkList($form["end_date_time_hour"], "end_date_time_hour", array_keys($WEB_THEATER_DATE_HOUR), true);
        $check_end_date_time_minute = $this->checkList($form["end_date_time_minute"], "end_date_time_minute", array_keys($WEB_THEATER_DATE_MINUTE), true);

        if($check_start_date_time && $check_end_date_time && $check_start_date_time_hour && $check_start_date_time_minute && $check_end_date_time_hour && $check_end_date_time_minute) {
            if(mktime($form['end_date_time_hour'],$form['end_date_time_minute'],0,$form['end_date_time_month'],$form['end_date_time_day'],$form['end_date_time_year']) <
                mktime($form['start_date_time_hour'],$form['start_date_time_minute'],0,$form['start_date_time_month'],$form['start_date_time_day'],$form['start_date_time_year'])) {
                $this->addErrorMessage('start_date_time', '終了日よりも過去の日付を指定してください');
            }
        }

//        //start_date
//        $start = $this->checkDate($form['start_date_year'],
//                                  $form['start_date_month'],
//                                  $form['start_date_day'],
//                                  'start_date', 1);
//        //end_date
//        $end = $this->checkDate($form['end_date_year'],
//                                $form['end_date_month'],
//                                $form['end_date_day'],
//                                'end_date', 1);
//        if($start && $end) {
//            if($form['start_date'] > $form['end_date']) {
//                $this->addErrorMessage('start_date',
//                                       '終了日は開始日より後を指定してください');
//            }
//        }


        //volume 公式サイトURL
        $this->checkText($form['volume'], 'volume', 1, 100, 0);
        //open_info 動画リンクを表示する作品ID
        $this->checkText($form['open_info'], 'open_info', 1, 400, null);

        // ニュース動画の場合
        if ($form["disp_area"] == '4') {
            $this->checkRange ($form['new_flg'], 'new_flg', 0, 1, true);
            $check_new_start_date_time = $this->checkDate ($form['new_start_date_time_year'],$form['new_start_date_time_month'],$form['new_start_date_time_day'],  'new_start_date_time', true);
            $check_new_end_date_time = $this->checkDate ($form['new_end_date_time_year'],$form['new_end_date_time_month'],$form['new_end_date_time_day'],  'new_end_date_time', true);
            $check_new_start_date_time_hour = $this->checkList($form["new_start_date_time_hour"], "new_start_date_time_hour", array_keys($WEB_THEATER_DATE_HOUR), true);
            $check_new_start_date_time_minute = $this->checkList($form["new_start_date_time_minute"], "new_start_date_time_minute", array_keys($WEB_THEATER_DATE_MINUTE), true);
            $check_new_end_date_time_hour = $this->checkList($form["new_end_date_time_hour"], "new_end_date_time_hour", array_keys($WEB_THEATER_DATE_HOUR), true);
            $check_new_end_date_time_minute = $this->checkList($form["new_end_date_time_minute"], "new_end_date_time_minute", array_keys($WEB_THEATER_DATE_MINUTE), true);

            if($check_new_start_date_time && $check_new_end_date_time && $check_new_start_date_time_hour && $check_new_start_date_time_minute && $check_new_end_date_time_hour && $check_new_end_date_time_minute) {
                if(mktime($form['new_end_date_time_hour'],$form['new_end_date_time_minute'],0,$form['new_end_date_time_month'],$form['new_end_date_time_day'],$form['new_end_date_time_year']) <
                    mktime($form['new_start_date_time_hour'],$form['new_start_date_time_minute'],0,$form['new_start_date_time_month'],$form['new_start_date_time_day'],$form['new_start_date_time_year'])) {
                    $this->addErrorMessage('new_start_date_time', '終了日よりも過去の日付を指定してください');
                }
            }

            $check_disp_update = $this->checkDate ($form['disp_update_year'],$form['disp_update_month'],$form['disp_update_day'],  'disp_update', true);
        }
    }

    /**
     * オンデマンド追加チェック
     */
    function checkAddOndemand($form) {
        global $DISPLAY_TBL;

        // 作品ID
        $this->checkNumeric($form["movie_id"], "movie_id", 10, 1);
        // タイトル
        $this->checkText($form["title"], "title", 0, 100, false);
        // 画像表示フラグ
        $this->checkList($form["image_disp_flag"], "image_disp_flag", array_keys($DISPLAY_TBL), true);

        // J:COM配信開始日
        $jcom_ret1 = $this->checkStkDate($form["jcom_open_date"], "jcom_open_date", 0);
        // J:COM配信終了日
        $jcom_ret2 = $this->checkStkDate($form["jcom_close_date"], "jcom_close_date", 0);
        // J:COM配信期間チェック
        if($jcom_ret1 and $jcom_ret2) {
            if(!$form["jcom_open_date"] && $form["jcom_close_date"]){
                $this->addErrorMessage("jcom_open_date", "開始日を指定してください");
            }elseif($form["jcom_open_date"] && !$form["jcom_close_date"]){
                $this->addErrorMessage("jcom_close_date", "終了日を指定してください");
            }else{
                $open_date = preg_split("/[-|\/]/", $form["jcom_open_date"]);
                $start = sprintf("%d%02d%02d", $open_date[0],$open_date[1],$open_date[2]);
                $close_date = preg_split("/[-|\/]/", $form["jcom_close_date"]);
                $end = sprintf("%d%02d%02d", $close_date[0],$close_date[1],$close_date[2]);
                if($start > $end) {
                    $this->addErrorMessage("jcom_close_date", "終了日は開始日より後を指定してください");
                }
            }
        }

        // CATV配信開始日
        $catv_ret1 = $this->checkStkDate($form["catv_open_date"], "catv_open_date", 0);
        // CATV配信終了日
        $catv_ret2 = $this->checkStkDate($form["catv_close_date"], "catv_close_date", 0);
        // CATV配信期間チェック
        if($catv_ret1 and $catv_ret2) {
            if(!$form["catv_open_date"] && $form["catv_close_date"]){
                $this->addErrorMessage("catv_open_date", "開始日を指定してください");
            }elseif($form["catv_open_date"] && !$form["catv_close_date"]){
                $this->addErrorMessage("catv_close_date", "終了日を指定してください");
            }else{
                $open_date = preg_split("/[-|\/]/", $form["catv_open_date"]);
                $start = sprintf("%d%02d%02d", $open_date[0],$open_date[1],$open_date[2]);
                $close_date = preg_split("/[-|\/]/", $form["catv_close_date"]);
                $end = sprintf("%d%02d%02d", $close_date[0],$close_date[1],$close_date[2]);
                if($start > $end) {
                    $this->addErrorMessage("catv_close_date", "終了日は開始日より後を指定してください");
                }
            }
        }

        // スカパー配信開始日
        $skaper_ret1 = $this->checkStkDate($form["skaper_open_date"], "skaper_open_date", 0);
        // スカパー配信終了日
        $skaper_ret2 = $this->checkStkDate($form["skaper_close_date"], "skaper_close_date", 0);
        // スカパー配信期間チェック
        if($skaper_ret1 and $skaper_ret2) {
            if(!$form["skaper_open_date"] && $form["skaper_close_date"]){
                $this->addErrorMessage("skaper_open_date", "開始日を指定してください");
            }elseif($form["skaper_open_date"] && !$form["skaper_close_date"]){
                $this->addErrorMessage("skaper_close_date", "終了日を指定してください");
            }else{
                $open_date = preg_split("/[-|\/]/", $form["skaper_open_date"]);
                $start = sprintf("%d%02d%02d", $open_date[0],$open_date[1],$open_date[2]);
                $close_date = preg_split("/[-|\/]/", $form["skaper_close_date"]);
                $end = sprintf("%d%02d%02d", $close_date[0],$close_date[1],$close_date[2]);
                if($start > $end) {
                    $this->addErrorMessage("skaper_close_date", "終了日は開始日より後を指定してください");
                }
            }
        }

    }
    /**
     * オンデマンド更新チェック
     */
    function checkUpdateOndemand($form) {
        global $DISPLAY_TBL;
        // オンデマンドID
        $this->checkNumeric($form["ondemand_id"], "ondemand_id", 9, 1);
        // タイトル
        $this->checkText($form["title"], "title", 0, 100, false);
        // 作品ID
        $this->checkNumeric($form["movie_id"], "movie_id", 9, 1);
        // 画像表示フラグ
        $this->checkList($form["image_disp_flag"], "image_disp_flag", array_keys($DISPLAY_TBL), true);

        // J:COM配信開始日
        $jcom_ret1 = $this->checkStkDate($form["jcom_open_date"], "jcom_open_date", 0);
        // J:COM配信終了日
        $jcom_ret2 = $this->checkStkDate($form["jcom_close_date"], "jcom_close_date", 0);
        // J:COM配信期間チェック
        if($jcom_ret1 and $jcom_ret2) {
            if(!$form["jcom_open_date"] && $form["jcom_close_date"]){
                $this->addErrorMessage("jcom_open_date", "開始日を指定してください");
            }elseif($form["jcom_open_date"] && !$form["jcom_close_date"]){
                $this->addErrorMessage("jcom_close_date", "終了日を指定してください");
            }else{
                $open_date = preg_split("/[-|\/]/", $form["jcom_open_date"]);
                $start = sprintf("%d%02d%02d", $open_date[0],$open_date[1],$open_date[2]);
                $close_date = preg_split("/[-|\/]/", $form["jcom_close_date"]);
                $end = sprintf("%d%02d%02d", $close_date[0],$close_date[1],$close_date[2]);
                if($start > $end) {
                    $this->addErrorMessage("jcom_close_date", "終了日は開始日より後を指定してください");
                }
            }
        }

        // CATV配信開始日
        $catv_ret1 = $this->checkStkDate($form["catv_open_date"], "catv_open_date", 0);
        // CATV配信終了日
        $catv_ret2 = $this->checkStkDate($form["catv_close_date"], "catv_close_date", 0);
        // CATV配信期間チェック
        if($catv_ret1 and $catv_ret2) {
            if(!$form["catv_open_date"] && $form["catv_close_date"]){
                $this->addErrorMessage("catv_open_date", "開始日を指定してください");
            }elseif($form["catv_open_date"] && !$form["catv_close_date"]){
                $this->addErrorMessage("catv_close_date", "終了日を指定してください");
            }else{
                $open_date = preg_split("/[-|\/]/", $form["catv_open_date"]);
                $start = sprintf("%d%02d%02d", $open_date[0],$open_date[1],$open_date[2]);
                $close_date = preg_split("/[-|\/]/", $form["catv_close_date"]);
                $end = sprintf("%d%02d%02d", $close_date[0],$close_date[1],$close_date[2]);
                if($start > $end) {
                    $this->addErrorMessage("catv_close_date", "終了日は開始日より後を指定してください");
                }
            }
        }

        // スカパー配信開始日
        $skaper_ret1 = $this->checkStkDate($form["skaper_open_date"], "skaper_open_date", 0);
        // スカパー配信終了日
        $skaper_ret2 = $this->checkStkDate($form["skaper_close_date"], "skaper_close_date", 0);
        // スカパー配信期間チェック
        if($skaper_ret1 and $skaper_ret2) {
            if(!$form["skaper_open_date"] && $form["skaper_close_date"]){
                $this->addErrorMessage("skaper_open_date", "開始日を指定してください");
            }elseif($form["skaper_open_date"] && !$form["skaper_close_date"]){
                $this->addErrorMessage("skaper_close_date", "終了日を指定してください");
            }else{
                $open_date = preg_split("/[-|\/]/", $form["skaper_open_date"]);
                $start = sprintf("%d%02d%02d", $open_date[0],$open_date[1],$open_date[2]);
                $close_date = preg_split("/[-|\/]/", $form["skaper_close_date"]);
                $end = sprintf("%d%02d%02d", $close_date[0],$close_date[1],$close_date[2]);
                if($start > $end) {
                    $this->addErrorMessage("skaper_close_date", "終了日は開始日より後を指定してください");
                }
            }
        }

    }

    /**
     * Visit追加チェック
     */
    function checkAddVisit($form) {
        // タイトル
        $this->checkText($form["title"], 'title', 0, 255, 0);
        // New表示フラグ
        $this->checkRange ($form['new_flg'], 'new_flg', 0, 1, true);
        // New表示開始日
        $check_new_start_disp_date = $this->checkDate ($form['new_disp_start_date_year'],$form['new_disp_start_date_month'],$form['new_disp_start_date_day'],  'new_disp_start_date', true);
        // New表示終了日
        $check_new_disp_date = $this->checkDate ($form['new_disp_date_year'],$form['new_disp_date_month'],$form['new_disp_date_day'],  'new_disp_date', true);
        // サブタイトル
        $this->checkText($form["subtitle"], 'subtitle', 0, 255, 0);
        // 見出し
        $this->checkText($form["summary"], 'summary', 0, 1000, 0);
        // 内容
        $this->checkText($form["body"], 'body', 0, 2000, 0);
        // バックナンバーのタイトル
        $this->checkText($form["back_number_title"], 'back_number_title', 0, 255, 0);
        // トップ表示順
        $this->checkNumeric($form["disp_order"], "disp_order", 9, 1);
        // 開始日
        $ret = $this->checkStkDate($form["start_date"], "start_date", 1);
        // 終了日
        $ret1 = $this->checkStkDate($form["end_date"], "end_date", 1);

        if($ret and $ret1) {
            $start_date = preg_split("/[-|\/]/", $form["start_date"]);
            $start = sprintf("%d%02d%02d", $start_date[0],$start_date[1],$start_date[2]);
            $end_date = preg_split("/[-|\/]/", $form["end_date"]);
            $end = sprintf("%d%02d%02d", $end_date[0],$end_date[1],$end_date[2]);

            if($start > $end) {
                $this->addErrorMessage("end_date", "終了日は開始日より後を指定してください");
            }
        }
    }
    /**
     * Visit更新チェック
     */
    function checkUpdateVisit($form) {
        // visit_id
        $this->checkNumeric($form["visit_id"], "visit_id", 9, 1);
        // タイトル
        $this->checkText($form["title"], 'title', 0, 255, 0);
        // New表示フラグ
        $this->checkRange ($form['new_flg'], 'new_flg', 0, 1, true);
        // New表示開始日
        $check_new_start_disp_date = $this->checkDate ($form['new_disp_start_date_year'],$form['new_disp_start_date_month'],$form['new_disp_start_date_day'],  'new_disp_start_date', true);
        // New表示終了日
        $check_new_disp_date = $this->checkDate ($form['new_disp_date_year'],$form['new_disp_date_month'],$form['new_disp_date_day'],  'new_disp_date', true);
        // サブタイトル
        $this->checkText($form["subtitle"], 'subtitle', 0, 255, 0);
        // 見出し
        $this->checkText($form["summary"], 'summary', 0, 1000, 0);
        // 内容
        $this->checkText($form["body"], 'body', 0, 2000, 0);
        // バックナンバーのタイトル
        $this->checkText($form["back_number_title"], 'back_number_title', 0, 255, 0);
        // トップ表示順
        $this->checkNumeric($form["disp_order"], "disp_order", 9, 1);
        // 開始日
        $ret = $this->checkStkDate($form["start_date"], "start_date", 1);
        // 終了日
        $ret1 = $this->checkStkDate($form["end_date"], "end_date", 1);

        if($ret and $ret1) {
            $start_date = preg_split("/[-|\/]/", $form["start_date"]);
            $start = sprintf("%d%02d%02d", $start_date[0],$start_date[1],$start_date[2]);
            $end_date = preg_split("/[-|\/]/", $form["end_date"]);
            $end = sprintf("%d%02d%02d", $end_date[0],$end_date[1],$end_date[2]);

            if($start > $end) {
                $this->addErrorMessage("end_date", "終了日は開始日より後を指定してください");
            }
        }
    }

    /**
     * 最新映画情報入力チェック
     */
    function checkNewMovie ($form) {
        // 映画名
        $this->checkText($form["name"], "name", 1, 200, 1);
        // 映画名（カナ）
        $this->checkKanaZen($form["ename"], "ename", 1, 200, 0);
        // 原題
        $this->checkText($form["original_name"], "original_name", 1, 200, 0);
        // 監督1
        $this->checkText($form["director1"], "director1", 1, 200, 0);
        // 監督2
        $this->checkText($form["director2"], "director2", 1, 200, 0);
        // 出演者1
        $this->checkText($form["cast1"], "cast1", 1, 200, 0);
        // 出演者2
        $this->checkText($form["cast2"], "cast2", 1, 200, 0);
        // 出演者3
        $this->checkText($form["cast3"], "cast3", 1, 200, 0);
        // 出演者4
        $this->checkText($form["cast4"], "cast4", 1, 200, 0);
        // 出演者5
        $this->checkText($form["cast5"], "cast5", 1, 200, 0);
        //ジャンル1
        $this->checkNumeric($form["genre1"], "genre1", 0, 0);
        //ジャンル2
        $this->checkNumeric($form["genre2"], "genre2", 0, 0);
        //配給
        $this->checkText($form['distribution'], 'distribution', 1, 200, 0);
        // 上映時間
        $this->checkNumeric($form["duration"], "duration", 5, 0);
        // 制作年
        $this->checkNumeric($form["year"], 'year', 4, 0);
        // 制作国
        $this->checkText($form['country'], 'country', 1, 50, 0);
        // 解説
        $this->checkText($form["story"], "story", 1, 1000, 0);
        // キャッチ
        $this->checkText($form["catch"], "catch", 1, 400, 0);
        // ここがオススメ！
        $this->checkText($form["comment"], "comment", 1, 1000, 0);
        // コピーライト
        $this->checkText($form["copyright"], "copyright", 1, 1000, 0);
        // URL名
        $this->checkText($form['url'], 'url', 1, 100, 0);
        // 公開制御日
        $this->checkStkDate($form["open_controal_date"], "open_controal_date", 1);
        // 公開日
        $this->checkText($form["open_date"], "open_date", 1, 255, 0);
        // 公開情報
        $this->checkText($form["open_info"], "open_info", 1, 1000, 0);
        // メンバープレゼントID
        $this->checkNumeric($form["present_id"], "present_id", 5, 0);
        // 特別プレゼント
        $this->checkNumeric($form["special_present_id"], "special_present_id", 5, 0);
        // 特別ページURL
        $this->checkText($form['special_url'], 'special_url', 1, 100, 0);
        // 表示順
        $this->checkRange($form["disp_order"], "disp_order", 0, 32767, 0);
        // 開始日
        $ret = $this->checkStkDate($form["start_date"], "start_date", 1);
        // 終了日
        $ret1 = $this->checkStkDate($form["end_date"], "end_date", 1);
        if($ret and $ret1) {
            $start_date = preg_split("/[-|\/]/", $form["start_date"]);
            $start = sprintf("%d%02d%02d", $start_date[0],$start_date[1],$start_date[2]);
            $end_date = preg_split("/[-|\/]/", $form["end_date"]);
            $end = sprintf("%d%02d%02d", $end_date[0],$end_date[1],$end_date[2]);
            if($start > $end) {
                $this->addErrorMessage("end_date", "終了日は開始日より後を指定してください");
            }
        }
    }


    /**
     * ワムネット登録用サイトチェック
     */
    function checkWamNet($form) {
        global $WAM_CHANNEL_TBL,$WAM_KNOW_TYPE_TBL,$WAM_NEED_TYPE_TBL,$PREF_TBL;

        // 御社名
        $this->checkText($form["company_name"], "company_name", 1, 100, 1);
        // 郵便番号
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', 1);
        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);
        // 住所１
        $this->checkText($form["address"], "address", 1, 120, 1);
        // 部署名
        $this->checkText($form["division_name"], "division_name", 1, 100, 1);
        // ご担当者 姓
        $ret = $this->checkText($form["name1"], "name", 1, 50, 1);
        if($ret) {
            // ご担当者 名
            $this->checkText($form["name2"], "name", 1, 50, 1);
        }
        // 電話番号
        $this->checkTelAll ($form['tel1']."-".$form['tel2']."-".$form['tel3'], 'tel', array(1,1,1), array(4,5,5), 1);
        // FAX
        $this->checkTelAll ($form['fax1']."-".$form['fax2']."-".$form['fax3'], 'fax', array(1,1,1), array(4,5,5), 1);
        // メールアドレス
        $ret = $this->checkEmail ($form["email1"]."@".$form["email1_1"], 'email1', 100, null, 1);
        $ret1 = $this->checkEmail ($form["email2"]."@".$form["email2_1"], 'email', 100, null, 1);
        if($ret and $ret1) {
            $this->checkSameData($form["email1"]."@".$form["email1_1"],
                                 $form["email2"]."@".$form["email2_1"], 'email', 1);
        }
        // チャンネルフラグ
        if($form["service_flag"]) {
            foreach ($form["service_flag"] as $id) {
                if (!($this->checkList($id, "service_flag", array_keys($WAM_CHANNEL_TBL), 0))) {
                    break;
                }
            }
        }
        // 媒体フラグ
        if($form["know_flag"]) {
            foreach ($form["know_flag"] as $id) {
                if (!($this->checkList($id, "know_flag", array_keys($WAM_KNOW_TYPE_TBL), 0))) {
                    break;
                }
            }
        }
        // テキスト
        $this->checkText($form["text1"], "text1", 0, 100, 0);
        $this->checkText($form["text2"], "text2", 0, 100, 0);
        $this->checkText($form["text3"], "text3", 0, 100, 0);
        $this->checkText($form["text4"], "text4", 0, 100, 0);
        // 編成資料
        if($form["need_flag"]) {
            foreach ($form["need_flag"] as $id) {
                if (!($this->checkList($id, "need_flag", array_keys($WAM_NEED_TYPE_TBL), 0))) {
                    break;
                }
            }
        }
        // テキストエリア
        $this->checkText($form["textarea"], "textarea", 0, 1000, null);
    }

    /**
     * レギュラーコーナー追加チェック
     */
    function checkAddRegular($form) {
        global $BASIC_CHANNEL_TBL;
        global $PRESENCE_TBL;

        // レギュラーコーナー名
        $this->checkText($form["regular_name"], 'regular_name', 0, 50, false);
        // チャンネルID
        if (!is_array($form["channel_id"]) || !$form["channel_id"]) {
            $this->addErrorMessage("channel_id", $this->msgSelect);
        } else {
            foreach ($form["channel_id"] as $ch_id) {
                if (!($this->checkList($ch_id, "channel_id", array_keys($BASIC_CHANNEL_TBL), 1))) {
                    break;
                }
            }
        }
        // 概要
        $this->checkText($form["outline"], 'outline', 0, 200, false);
        // 説明文
        $this->checkText($form["description"], 'description', 0, 2000, false);
        // 放送日コメント
        $this->checkText($form["onair_comment"], 'onair_comment', 0, 100, false);
		//ID
		$ret = $this->checkNumeric($form["regular_id"], "regular_id", 9, 1);
		if($ret){
            $RegularDAO = new RegularDAO();
            $sp = $RegularDAO->getRegularById($form["regular_id"]);
            if($sp){
                $this->addErrorMessage("regular_id", '既に使用されています');
            }
		}

        // バナーあり／なし
        $this->checkList($form["banner_flg"], "banner_flg", array_keys($PRESENCE_TBL), 1);
        // バナー画像
        $chk_null = null;
        if ($form["banner_flg"] == 1) $chk_null = 1;
        $ret1 = $this->checkText($form["banner"], "banner", 1, 500, $chk_null);
        if ($ret1 && !$this->isEmpty($form["banner"]) && !$this->isAscii($form["banner"])) {
            $this->addErrorMessage('banner',$this->msgAlnum);
        }
        // レギュラー枠表示順
        $this->checkNumeric($form["disp_order"], "disp_order", 9);
        // 動画URL
        $this->checkText($form["video_url"], "video_url", 1, 255);
        // 動画掲載開始日
        $this->checkStkDate($form["video_begin_date"], "video_begin_date");
        // 動画掲載終了日
        $this->checkStkDate($form["video_end_date"], "video_end_date");
    }

    /**
     * レギュラーコーナー更新チェック
     */
    function checkUpdateRegular($form) {
        global $BASIC_CHANNEL_TBL;
        global $PRESENCE_TBL;

        // regular_id
        $this->checkNumeric($form["regular_id"], "regular_id", 9, true);
        // レギュラーコーナー名
        $this->checkText($form["regular_name"], 'regular_name', 0, 50, false);
        // チャンネルID
        if (!is_array($form["channel_id"]) || !$form["channel_id"]) {
            $this->addErrorMessage("channel_id", $this->msgSelect);
        } else {
            foreach ($form["channel_id"] as $ch_id) {
                if (!($this->checkList($ch_id, "channel_id", array_keys($BASIC_CHANNEL_TBL), 1))) {
                    break;
                }
            }
        }
        // 概要
        $this->checkText($form["outline"], 'outline', 0, 200, false);
        // 説明文
        $this->checkText($form["description"], 'description', 0, 2000, false);
        // 放送日コメント
        $this->checkText($form["onair_comment"], 'onair_comment', 0, 100, false);

        // バナーあり／なし
        $this->checkList($form["banner_flg"], "banner_flg", array_keys($PRESENCE_TBL), 1);
        // バナー画像
        $chk_null = null;
        if ($form["banner_flg"] == 1) $chk_null = 1;
        $ret1 = $this->checkText($form["banner"], "banner", 1, 500, $chk_null);
        if ($ret1 && !$this->isEmpty($form["banner"]) && !$this->isAscii($form["banner"])) {
            $this->addErrorMessage('banner',$this->msgAlnum);
        }
        // レギュラー枠表示順
        $this->checkNumeric($form["disp_order"], "disp_order", 9);
        // 動画URL
        $this->checkText($form["video_url"], "video_url", 1, 255);
        // 動画掲載開始日
        $this->checkStkDate($form["video_begin_date"], "video_begin_date");
        // 動画掲載終了日
        $this->checkStkDate($form["video_end_date"], "video_end_date");
    }


    /**
     * ケーブル局情報チェック
     */
    function checkCableAdmin($form) {
        global $PREF_TBL,$VALID_TYPE_TBL,$CONDEN_TYPE_TBL,$ONDEMAND_FLAG_TBL;

        // 都道府県
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), 1);
        // ケーブル局名称
        $this->checkText($form["name"], "name", 1, 100, 1);
        // 名称英語表記
        $this->checkText($form["ename"], "ename", 0, 100, 0);
        // 愛称
        $this->checkText($form["name2"], "name2", 0, 100, 0);
        // 放送エリア
        $this->checkText($form["area"], "area", 1, 500, 1);
        // カスタマーセンターTEL
        $this->checkText($form['tel'], "tel", 1, 15, 1);
        /*
        if($form['tel1'] or $form['tel2'] or $form['tel3']) {
            $this->checkTelAll ($form['tel1']."-".$form['tel2']."-".$form['tel3'], 'tel', array(1,1,1), array(4,5,5), 0);
        }
         */
        // カスタマーセンター補足
        $this->checkText($form["option"], "option", 0, 200, 0);
        // カスタマーセンター補足英語表記
        $this->checkText($form["eoption"], "eoption", 0, 200, 0);
        // URL
        $this->checkText($form["url"], "url", 0, 200, 0);

        //channel_flag1 - channel_flag10はチャンネル改編に伴い削除。ただし、今後追加するときはchannel_flag15からにすること

        //スターチャンネル1
        $this->checkList($form["channel_flag11"], 'channel_flag11', array_keys($CONDEN_TYPE_TBL), 0);

        //スターチャンネル2
        $this->checkList($form["channel_flag12"], 'channel_flag12', array_keys($CONDEN_TYPE_TBL), 0);

        //スターチャンネル3
        $this->checkList($form["channel_flag13"], 'channel_flag13', array_keys($CONDEN_TYPE_TBL), 0);

        // 新オンデマンド
        $this->checkList($form["channel_flag8"], 'channel_flag8', array_keys($ONDEMAND_FLAG_TBL), 0);

        // 視聴可否コメント
        $this->checkText($form["channel_comment"], "channel_comment", 0, 1200, 0);
        // 視聴料金
        $this->checkNumeric($form["pay"], "pay", 9, 0);
        // 視聴料金コメント
        $this->checkText($form["pay_comment"], "pay_comment", 0, 1200, 0);
        // 資料請求フラグ
        //$this->checkList($form["document_flag"], 'document_flag', array_keys($VALID_TYPE_TBL), 1);
        $this->checkList($form["document_flag"], 'document_flag', array_keys($VALID_TYPE_TBL), 0);
        // 資料請求リンク
        $this->checkText($form["document_link"], "document_link", 0, 200, 0);
        // 加入申込フラグ
        //$this->checkList($form["application_flag"], 'application_flag', array_keys($VALID_TYPE_TBL), 1);
        $this->checkList($form["application_flag"], 'application_flag', array_keys($VALID_TYPE_TBL), 0);
        // 加入申込リンク
        $this->checkText($form["application_link"], "application_link", 0, 200, 0);
        // 契約内容変更フラグ
        //$this->checkList($form["contract_flag"], 'contract_flag', array_keys($VALID_TYPE_TBL), 1);
        $this->checkList($form["contract_flag"], 'contract_flag', array_keys($VALID_TYPE_TBL), 0);
        // 契約内容変更リンク
        $this->checkText($form["contract_link"], "contract_link", 0, 200, 0);
        // 表示順
        $this->checkNumeric($form["disp_order"], "disp_order", 9, 0);
    }


    /**
     * 会員管理チェック
     **/
    function checkUpdateMemberAdmin($form){
        global $JOB_TBL, $SEX, $PREF_TBL;

        $this->checkAddMemberHalfMobile($form);

        // name
        $this->checkText($form['name'], 'name',1, 20, null);

        // zip
        $this->checkZip($form['zip1'], $form['zip2'], 'zip', null);

        // pref
        $this->checkList($form["pref"], 'pref', array_keys($PREF_TBL), null);
        // address
        $this->checkText($form["address"], "address", 1, 100, null);

        // sex
        $this->checkList($form["sex"], 'sex', array_keys($SEX), null);

        // birth-year
        $ret = $this->checkNumeric($form['birth'], 'birth', 4, null);
        if ($ret) {
            $this->checkRange($form['birth'], 'birth', 1902,
                              date("Y",time()), null);
        }

        // tel
        if($form['tel1'] || $form['tel2'] || $form['tel3']){
            $this->checkTelAll ($form['tel1'].'-'.$form['tel2'].'-'.$form['tel3'],
                                'tel', array(1,1,1), array(4,5,5), null);
        }

        // job
        $this->checkList($form["job"], 'job',
                         array_keys($JOB_TBL), null);

    }


    /**
     * 1ヵ月無料体験CP入力チェック
     */
    function checkAddFreeCp($form){
        global $CP_KNOW_TBL,$CP_SERVICE_TBL;
        // 姓
        $ret = $this->checkText($form["name1"], "name", 1, 50, 1);
        if($ret) {
            // 名
            $this->checkText($form["name2"], "name", 1, 50, 1);
        }

        // セイ
        $ret = $this->checkText($form["name_k1"], "name_k", 1, 50, 1);
        if($ret) {
            // メイ
            $this->checkText($form["name_k2"], "name_k", 1, 50, 1);
        }

        // 電話番号
        $this->checkTelAll ($form['tel1']."-".$form['tel2']."-".$form['tel3'], 'tel', array(1,1,1), array(4,5,5), 1);

        // スカイパーフェクTV!のお客さま番号（10桁）
        $form['skaper_tv_num'] = $form["skaper_tv_num1"].$form["skaper_tv_num2"];
        $ret = $this->checkNumeric ($form['skaper_tv_num'], 'skaper_tv_num', 10, 1);
        if($ret){
            //
            if( !ereg("^(00|10|20)[0-9]{8}$", $form['skaper_tv_num']) ) {
                $this->addErrorMessage('skaper_tv_num',$this->msgInvalid);
            }
        }

        // ICカード番号（16桁）
        $this->checkCardId($form["ic_card_id1"],$form["ic_card_id2"],
                           $form["ic_card_id3"],$form["ic_card_id4"],
                           'ic_card_id', array(4,4,4,4), array(4,4,4,4),
                           1);

        // 視聴サービス
        //$this->checkList($form["service"], 'service', array_keys($CP_SERVICE_TBL), 1);

        // このキャンペーンを何で知りましたか？
        $this->checkList($form["cp_know"], 'cp_know', array_keys($CP_KNOW_TBL), 1);
        if($form["cp_know"] == 7){
            // その他のテキスト
            $this->checkText($form["cp_know_other"], "cp_know_other", 1, 200, 1);
        }
    }

    /**
     * 1ヵ月無料体験CP入力チェック
     */
    function checkAddFreeCphv($form){
        global $CPHV_KNOW_TBL;
        // 姓
        $ret = $this->checkText($form["name1"], "name", 1, 50, 1);
        if($ret) {
            // 名
            $this->checkText($form["name2"], "name", 1, 50, 1);
        }

        // セイ
        $ret = $this->checkText($form["name_k1"], "name_k", 1, 50, 1);
        if($ret) {
            // メイ
            $this->checkText($form["name_k2"], "name_k", 1, 50, 1);
        }

        // 電話番号
        $this->checkTelAll ($form['tel1']."-".$form['tel2']."-".$form['tel3'], 'tel', array(1,1,1), array(4,5,5), 1);

        // スカイパーフェクTV!のお客さま番号（10桁）
        $form['skaper_tv_num'] = $form["skaper_tv_num1"].$form["skaper_tv_num2"];
        $ret = $this->checkNumeric ($form['skaper_tv_num'], 'skaper_tv_num', 10, 1);
        if($ret){
            //
            if( !ereg("^(00|10|20)[0-9]{8}$", $form['skaper_tv_num']) ) {
                $this->addErrorMessage('skaper_tv_num',$this->msgInvalid);
            }
        }

        // B-CAS番号（20桁）
        $ret = $this->checkBcasCardId($form['bcas_card_id1'], $form["bcas_card_id2"],
                                      $form['bcas_card_id3'], $form["bcas_card_id4"],
                                      $form["bcas_card_id5"],
                                      'bcas_card_id',
                                      array(4,4,4,4,4), array(4,4,4,4,4), 1);
        if($ret){
            $num = $form['bcas_card_id1'].$form["bcas_card_id2"].
                $form['bcas_card_id3'].$form["bcas_card_id4"].$form["bcas_card_id5"];

            $num_main = substr($num,0,15);
            $num_check = substr($num,15,5);

            // B-CAS番号厳密チェック
            $h = new HexNum();
            $h->createByString($num_main);
            $hex_str = $h->getHexString();

            $num_hex = substr($hex_str, 4);
            $num12 = substr($num_hex, 0, 4);
            $num34 = substr($num_hex, 4, 4);
            $num56 = substr($num_hex, 8, 4);

            $num_xor = (hexdec($num12) ^ hexdec($num34)) ^ hexdec($num56);
            //var_dump($num_xor);

            if($num_xor != $num_check){
                $this->addErrorMessage("bcas_card_id", "正確に入力してください。");
            }
        }
        // このキャンペーンを何で知りましたか？
        $this->checkList($form["cp_know"], 'cp_know', array_keys($CPHV_KNOW_TBL), 1);
        if($form["cp_know"] == 7){
            // その他のテキスト
            $this->checkText($form["cp_know_other"], "cp_know_other", 1, 200, 1);
        }
    }

    /**
     * ハイビジョン開局記念CP応募情報入力チェック
     */
    function checkBshvspRegistEntry($form) {
        global $PREF_TBL, $SEX, $CP_KNOW_TYPE_TBL, $JOB_TBL, $YESNO_TBL, $CP_WITH_REGIST_TBL, $KANYU, $KANYU_TYPE_TBL, $BROADBAND_TYPE_TBL;
        // お名前
        $this->checkText($form["name"], "name", 1, 20, 1);
        // 郵便番号
        $this->checkZip($form["zip1"], $form["zip2"], "zip", null);
        // 都道府県
        $this->checkList($form["pref"], "pref", array_keys($PREF_TBL), 1);
        // 住所
        $this->checkText($form["address"], "address", 1, 100, 1);
        // 性別
        $this->checkList($form["sex"], "sex", array_keys($SEX), 1);
        // 生まれた年
        $ret = $this->checkNumeric($form["birth_year"], "birth_year", 4, 1);
        if ($ret) {
            $this->checkRange($form["birth_year"], "birth_year", 1902, date("Y",time()), null);
        }
        // 電話番号
        $this->checkTelAll($form["tel"], "tel", array(1,1,1), array(4,5,5), 1);
        // 職業
        $this->checkList($form["job"], "job", array_keys($JOB_TBL), null);
        // メールアドレス
        $this->checkEmail($form["email1"], "email1", 100, null, 1);
        if (!$this->hasError("email1")) {
            // 多重投稿チェック
            $cpcommondao = CpCommonDAO::getInstance();
            $cpcommonlist = $cpcommondao->getListByEmail1($form["email1"]);
            if ($cpcommonlist) {
                $this->addErrorMessage("email1", "すでにご応募いただいております。");
            }
        }
        // お得情報メール配信希望
        $this->checkList($form["mail_flag"], "mail_flag", array_keys($YESNO_TBL), null);
        // このキャンペーンを何で知りましたか？
        $this->checkList($form["cp_know"], "cp_know", array_keys($CP_KNOW_TYPE_TBL), 1);
        if($form["cp_know"] == "499") {
            //その他のテキスト
            $this->checkText($form["cp_know_other"], "cp_know_other", 1, 200, 1);
        }
        // 応募キーワード
        $this->checkText($form["cp_answer"], "cp_answer", 1, 20, 1);
        // 登録フラグ
        $this->checkList($form["regist_flag"], "regist_flag", array_keys($CP_WITH_REGIST_TBL), 1);
        if (!$this->hasError("email1") && !$this->hasError("regist_flag") && $form["regist_flag"] == "1") {
            //重複チェック
            $memberdao =& MemberDAO::getInstance();
            $member = $memberdao->checkMemberByEmail($form["email1"]);
            if ($member) {
                $this->addErrorMessage("email1", "このアドレスはすでに登録されています。\nメンバーの方はログインして下さい。");
            }
        }
        //kanyu
        $this->checkList($form["star_ch_id"], 'star_ch_id',
                         array_keys($KANYU), 1);

        if($form["star_ch_id"] == '1') {
            //kanyu-type
            $this->checkList($form["star_ch_service2"], 'star_ch_service2',
                             array_keys($KANYU_TYPE_TBL), 1);
        } else {
            //kanyu-type
            $this->checkList($form["star_ch_service2"], 'star_ch_service2',
                             array_keys($KANYU_TYPE_TBL), null);
        }

        // sky parfecTV
        if($form["star_ch_service2"] == '1') {
            //IC require
            $this->checkCardId($form["ic_card_id1"],$form["ic_card_id2"],
                               $form["ic_card_id3"],$form["ic_card_id4"],
                               'ic_card_id', array(4,4,4,4), array(4,4,4,4),
                               1);
        } else {
            //IC Option
            $this->checkCardId($form["ic_card_id1"],$form["ic_card_id2"],
                               $form["ic_card_id3"],$form["ic_card_id4"],
                               'ic_card_id', array(4,4,4,4), array(4,4,4,4),
                               null);
        }

        // BS
        if($form["star_ch_service2"] == '3' ||
           $form["star_ch_service2"] == '4') {
            //B-CAS
            $this->checkBcasCardId($form['bcas_card_id1'], $form["bcas_card_id2"],
                                   $form['bcas_card_id2'], $form["bcas_card_id4"],
                                   $form["bcas_card_id5"],
                                   'bcas_card_id',
                                   array(4,4,4,4,4), array(4,4,4,4,4), 1);
        } else {
            //B-CAS option
            $this->checkBcasCardId($form['bcas_card_id1'], $form["bcas_card_id2"],
                                   $form['bcas_card_id2'], $form["bcas_card_id4"],
                                   $form["bcas_card_id5"],
                                   'bcas_card_id',
                                   array(4,4,4,4,4), array(4,4,4,4,4), null);
        }

        // CATV
        if($form["star_ch_service2"] == '2') {
            //cableTv
            $this->checkText($form['catv_name'], 'catv_name', 1, 50, 1);
        } else {
            //cabletv
            $this->checkText($form['catv_name'], 'catv_name', 1, 50, null);
        }

        // broad band
        if($form["star_ch_service2"] == '11') {
            // Broadband
            $this->checkList($form["broadband_service"], 'broadband_service',
                             array_keys($BROADBAND_TYPE_TBL), 1);
        } else {
            // Broadband
            $this->checkList($form["broadband_service"], 'broadband_service',
                             array_keys($BROADBAND_TYPE_TBL), null);
        }

        // sonota
        if($form["star_ch_service2"] == '10') {
            // other
            $this->checkText($form["other"], 'other', 1, 50, 1);
        } else {
            $this->checkText($form["other"], 'other', 1, 50, null);
        }

    }


    //movieid存在チェック
    function checkMovieId($data, $item, $keta=null, $chk_null=null, $messages=null) {
        //数値チェック
        $ret = $this->checkNumeric($data, $item, $keta, $chk_null, $messages);
        if (!$ret) {
            return false;
        }
        //存在チェック
        $SityoRankingDao = new SityoRankingDao();
        $ret = $SityoRankingDao->checkFindByMovieId($data);
        if (!$ret) {
            $this->addErrorMessage($item, "作品がありません");
            return false;
        }
    }

    //管理画面視聴ランキング入力チェック
    function checkSityoRanking($form) {
        $this->checkMovieId($form["movie_id_1"], "movie_id_1", 0, true);
        $this->checkMovieId($form["movie_id_2"], "movie_id_2", 0, true);
        $this->checkMovieId($form["movie_id_3"], "movie_id_3", 0, true);
        $this->checkMovieId($form["movie_id_4"], "movie_id_4", 0, true);
        $this->checkMovieId($form["movie_id_5"], "movie_id_5", 0, true);
        $this->checkText($form["research_text"], "research_text", 1, 50, true);
    }
    function checkSityoRankingTitle($form){
        $this->checkSityoRanking($form);
        $this->checkText($form["movie_title_1"], "movie_title_1", 1, 100, true);
        $this->checkText($form["movie_title_2"], "movie_title_2", 1, 100, true);
        $this->checkText($form["movie_title_3"], "movie_title_3", 1, 100, true);
        $this->checkText($form["movie_title_4"], "movie_title_4", 1, 100, true);
        $this->checkText($form["movie_title_5"], "movie_title_5", 1, 100, true);
    }


    /**
     * インフォメーションマスタの登録時チェック
     */
    function checkAddInformation($form) {
        global $TARGET_TBL;
        global $INFORMATION_OPEN_DATE_HOUR;
        global $INFORMATION_OPEN_DATE_MINUTE;

        $check_disp_date = $this->checkDate ($form['disp_date_year'],$form['disp_date_month'],$form['disp_date_day'],  'disp_date', true);
        $this->checkRange ($form['new_flg'], 'new_flg', 0, 1, true);
        $check_new_disp_start_date = $this->checkDate ($form['new_disp_start_date_year'],$form['new_disp_start_date_month'],$form['new_disp_start_date_day'],  'new_disp_start_date', true);
        $check_new_disp_date = $this->checkDate ($form['new_disp_date_year'],$form['new_disp_date_month'],$form['new_disp_date_day'],  'new_disp_date', true);

        $this->checkText ($form['information_title'], 'information_title', 0, 500, true);
        //$this->checkText ($form['information_content'], 'information_content', 0, 1000, true);
        $this->checkText ($form['information_content'], 'information_content', 0, 3000, true);
        $this->checkText ($form['url'], 'url', 0, 200, false);
        $check_open_date = $this->checkDate ($form['open_date_year'],$form['open_date_month'],$form['open_date_day'],  'open_date', true);
        $check_close_date = $this->checkDate ($form['close_date_year'],$form['close_date_month'],$form['close_date_day'], 'close_date', true);
        $check_open_date_hour = $this->checkList($form["open_date_hour"], "open_date_hour", array_keys($INFORMATION_OPEN_DATE_HOUR), true);
        $check_open_date_minute = $this->checkList($form["open_date_minute"], "open_date_minute", array_keys($INFORMATION_OPEN_DATE_MINUTE), true);
        $check_close_date_hour = $this->checkList($form["close_date_hour"], "close_date_hour", array_keys($INFORMATION_OPEN_DATE_HOUR), true);
        $check_close_date_minute = $this->checkList($form["close_date_minute"], "close_date_minute", array_keys($INFORMATION_OPEN_DATE_MINUTE), true);
        if($check_open_date && $check_close_date && $check_open_date_hour && $check_open_date_minute && $check_close_date_hour && $check_close_date_minute) {
            if(mktime($form['close_date_hour'],$form['close_date_minute'],0,$form['close_date_month'],$form['close_date_day'],$form['close_date_year']) <
               mktime($form['open_date_hour'],$form['open_date_minute'],0,$form['open_date_month'],$form['open_date_day'],$form['open_date_year'])) {
                $this->addErrorMessage('open_date', '終了日よりも過去の日付を指定してください');
            }
        }

        $this->checkRange ($form['disp_flg_staging_only'], 'disp_flg_staging_only', 0, 1);
        $this->checkRange ($form['disp_flg_information'], 'disp_flg_information', 0, 1);
        $this->checkRange ($form['disp_flg_top'], 'disp_flg_top', 0, 1);
        $this->checkRange ($form['disp_flg_timetable'], 'disp_flg_timetable', 0, 1);
        $this->checkRange ($form['disp_flg_drama'], 'disp_flg_drama', 0, 1);
        $this->checkRange ($form['disp_flg_mobile'], 'disp_flg_mobile', 0, 1);
        $this->checkRange ($form['disp_flg_members'], 'disp_flg_members', 0, 1);

        //$this->checkRange ($form['viewable'], 'viewable', 0, 4, true);
        $this->checkRange ($form['top_view_flag'], 'top_view_flag', 0, 1);
        $this->checkNumeric($form["disp_order"], "disp_order", 9, true);
        //if (!$this->isEmpty($form["url"])) {
        //    $this->checkList($form['target'], 'target', array_keys($TARGET_TBL), true);
        //}
    }
    /**
     * インフォメーションマスタの更新時チェック
     */
    function checkUpdateInformation($form) {
        global $TARGET_TBL;
        global $INFORMATION_OPEN_DATE_HOUR;
        global $INFORMATION_OPEN_DATE_MINUTE;

        $check_disp_date = $this->checkDate ($form['disp_date_year'],$form['disp_date_month'],$form['disp_date_day'],  'disp_date', true);
        $this->checkRange ($form['new_flg'], 'new_flg', 0, 1, true);
        $check_new_disp_date = $this->checkDate ($form['new_disp_date_year'],$form['new_disp_date_month'],$form['new_disp_date_day'],  'new_disp_date', true);

        $this->checkText ($form['information_title'], 'information_title', 0, 500, true);
        //$this->checkText ($form['information_content'], 'information_content', 0, 1000, true);
        $this->checkText ($form['information_content'], 'information_content', 0, 3000, true);
        $this->checkText ($form['url'], 'url', 0, 200, false);

        $check_open_date = $this->checkDate ($form['open_date_year'],$form['open_date_month'],$form['open_date_day'],  'open_date', true);
        $check_close_date = $this->checkDate ($form['close_date_year'],$form['close_date_month'],$form['close_date_day'], 'close_date', true);
        $check_open_date_hour = $this->checkList($form["open_date_hour"], "open_date_hour", array_keys($INFORMATION_OPEN_DATE_HOUR), true);
        $check_open_date_minute = $this->checkList($form["open_date_minute"], "open_date_minute", array_keys($INFORMATION_OPEN_DATE_MINUTE), true);
        $check_close_date_hour = $this->checkList($form["close_date_hour"], "close_date_hour", array_keys($INFORMATION_OPEN_DATE_HOUR), true);
        $check_close_date_minute = $this->checkList($form["close_date_minute"], "close_date_minute", array_keys($INFORMATION_OPEN_DATE_MINUTE), true);
        if($check_open_date && $check_close_date && $check_open_date_hour && $check_open_date_minute && $check_close_date_hour && $check_close_date_minute) {
            if(mktime($form['close_date_hour'],$form['close_date_minute'],0,$form['close_date_month'],$form['close_date_day'],$form['close_date_year']) <
               mktime($form['open_date_hour'],$form['open_date_minute'],0,$form['open_date_month'],$form['open_date_day'],$form['open_date_year'])) {
                $this->addErrorMessage('open_date', '終了日よりも過去の日付を指定してください');
            }
        }

        $this->checkRange ($form['disp_flg_staging_only'], 'disp_flg_staging_only', 0, 1);
        $this->checkRange ($form['disp_flg_information'], 'disp_flg_information', 0, 1);
        $this->checkRange ($form['disp_flg_top'], 'disp_flg_top', 0, 1);
        $this->checkRange ($form['disp_flg_timetable'], 'disp_flg_timetable', 0, 1);
        $this->checkRange ($form['disp_flg_drama'], 'disp_flg_drama', 0, 1);
        $this->checkRange ($form['disp_flg_mobile'], 'disp_flg_mobile', 0, 1);
        $this->checkRange ($form['disp_flg_members'], 'disp_flg_members', 0, 1);

        //$this->checkRange ($form['viewable'], 'viewable', 0, 3, true);
        $this->checkRange ($form['top_view_flag'], 'top_view_flag', 0, 1);
        $this->checkNumeric($form["disp_order"], "disp_order", 9, true);
        $this->checkNumeric($form["information_id"], "information_id", 9, true);
        //if (!$this->isEmpty($form["url"])) {
        //    $this->checkList($form['target'], 'target', array_keys($TARGET_TBL), true);
        //}
    }

    /**
     * MY STAR コラム・カミングスーンの登録時チェック
     */
    function checkAddColumnComingsoon($form) {
        global $COLOMN_COMINGSOON_DATE_HOUR;
        global $COLOMN_COMINGSOON_DATE_MINUTE;

        $this->checkRange ($form['type'], 'type', 1, 2, true);
        $check_disp_update = $this->checkDate ($form['disp_update_year'],$form['disp_update_month'],$form['disp_update_day'],  'disp_update', true);
        $this->checkText ($form['title'], 'title', 1, 100, true);
        $this->checkText ($form['article'], 'article', 1, 10000, true);
        $this->checkRange ($form['new_flg'], 'new_flg', 0, 1, true);
        $check_new_disp_start_date = $this->checkDate ($form['new_disp_start_date_year'],$form['new_disp_start_date_month'],$form['new_disp_start_date_day'],  'new_disp_start_date', true);
        $check_new_disp_end_date = $this->checkDate ($form['new_disp_end_date_year'],$form['new_disp_end_date_month'],$form['new_disp_end_date_day'],  'new_disp_end_date', true);
        $check_new_disp_start_date_hour = $this->checkList($form["new_disp_start_date_hour"], "new_disp_start_date_hour", array_keys($COLOMN_COMINGSOON_DATE_HOUR), true);
        $check_new_disp_start_date_minute = $this->checkList($form["new_disp_start_date_minute"], "new_disp_start_date_minute", array_keys($COLOMN_COMINGSOON_DATE_MINUTE), true);
        $check_new_disp_end_date_hour = $this->checkList($form["new_disp_end_date_hour"], "new_disp_end_date_hour", array_keys($COLOMN_COMINGSOON_DATE_HOUR), true);
        $check_new_disp_end_date_minute = $this->checkList($form["new_disp_end_date_minute"], "new_disp_end_date_minute", array_keys($COLOMN_COMINGSOON_DATE_MINUTE), true);

        if($check_new_disp_start_date && $check_new_disp_end_date && $check_new_disp_start_date_hour && $check_new_disp_start_date_minute && $check_new_disp_end_date_hour && $check_new_disp_end_date_minute) {
            if(mktime($form['new_disp_end_date_hour'],$form['new_disp_end_date_minute'],0,$form['new_disp_end_date_month'],$form['new_disp_end_date_day'],$form['new_disp_end_date_year']) <
                mktime($form['new_disp_start_date_hour'],$form['new_disp_start_date_minute'],0,$form['new_disp_start_date_month'],$form['new_disp_start_date_day'],$form['new_disp_start_date_year'])) {
                $this->addErrorMessage('new_disp_start_date', '終了日よりも過去の日付を指定してください');
            }
        }

        $check_start_date = $this->checkDate ($form['start_date_year'],$form['start_date_month'],$form['start_date_day'],  'start_date', true);
        $check_end_date = $this->checkDate ($form['end_date_year'],$form['end_date_month'],$form['end_date_day'], 'end_date', true);
        $check_start_date_hour = $this->checkList($form["start_date_hour"], "start_date_hour", array_keys($COLOMN_COMINGSOON_DATE_HOUR), true);
        $check_start_date_minute = $this->checkList($form["start_date_minute"], "start_date_minute", array_keys($COLOMN_COMINGSOON_DATE_MINUTE), true);
        $check_end_date_hour = $this->checkList($form["end_date_hour"], "end_date_hour", array_keys($COLOMN_COMINGSOON_DATE_HOUR), true);
        $check_end_date_minute = $this->checkList($form["end_date_minute"], "end_date_minute", array_keys($COLOMN_COMINGSOON_DATE_MINUTE), true);

        if($check_start_date && $check_end_date && $check_start_date_hour && $check_start_date_minute && $check_end_date_hour && $check_end_date_minute) {
            if(mktime($form['end_date_hour'],$form['end_date_minute'],0,$form['end_date_month'],$form['end_date_day'],$form['end_date_year']) <
                mktime($form['start_date_hour'],$form['start_date_minute'],0,$form['start_date_month'],$form['start_date_day'],$form['start_date_year'])) {
                $this->addErrorMessage('start_date', '終了日よりも過去の日付を指定してください');
            }
        }

        if (!$this->isEmpty($form['type']) && $form['type'] == '1') {
            if ($this->checkEmpty($form['disp_order'], 'disp_order')) {
                if ($this->isDigit ($form['disp_order'], 10, 10)) {
                    $this->checkNumeric($form["disp_order"], "disp_order");
                } else {
                    $this->addErrorMessage('disp_order', '半角数字10桁で入力してください。');
                }
            }

            $this->checkText ($form['article_simple'], 'article_simple', 1, 200, true);
            $this->checkText ($form['copyright'], 'copyright', 1, 500, true);

        } else if (!$this->isEmpty($form['type']) && $form['type'] == '2') {
            if ($this->checkEmpty($form['disp_order'], 'disp_order')) {
                if ($this->isDigit ($form['disp_order'], 6, 6)) {
                    $this->checkNumeric($form["disp_order"], "disp_order");
                } else {
                    $this->addErrorMessage('disp_order', '半角数字6桁で入力してください。');
                }
            }
        }

        if (!$this->isEmpty($form['copyright_detail'], 'copyright_detail')) {
            $this->checkText ($form['copyright_detail'], 'copyright_detail', 1, 2000, true);
        }

    }
    /**
     * MY STAR コラム・カミングスーンの更新時チェック
     */
    function checkUpdateColumnComingsoon($form) {
        global $COLOMN_COMINGSOON_DATE_HOUR;
        global $COLOMN_COMINGSOON_DATE_MINUTE;

        $this->checkRange ($form['type'], 'type', 1, 2, true);
        $check_disp_update = $this->checkDate ($form['disp_update_year'],$form['disp_update_month'],$form['disp_update_day'],  'disp_update', true);
        $this->checkText ($form['title'], 'title', 1, 100, true);
        $this->checkText ($form['article'], 'article', 1, 10000, true);
        $this->checkRange ($form['new_flg'], 'new_flg', 0, 1, true);
        $check_new_disp_start_date = $this->checkDate ($form['new_disp_start_date_year'],$form['new_disp_start_date_month'],$form['new_disp_start_date_day'],  'new_disp_start_date', true);
        $check_new_disp_end_date = $this->checkDate ($form['new_disp_end_date_year'],$form['new_disp_end_date_month'],$form['new_disp_end_date_day'],  'new_disp_end_date', true);
        $check_new_disp_start_date_hour = $this->checkList($form["new_disp_start_date_hour"], "new_disp_start_date_hour", array_keys($COLOMN_COMINGSOON_DATE_HOUR), true);
        $check_new_disp_start_date_minute = $this->checkList($form["new_disp_start_date_minute"], "new_disp_start_date_minute", array_keys($COLOMN_COMINGSOON_DATE_MINUTE), true);
        $check_new_disp_end_date_hour = $this->checkList($form["new_disp_end_date_hour"], "new_disp_end_date_hour", array_keys($COLOMN_COMINGSOON_DATE_HOUR), true);
        $check_new_disp_end_date_minute = $this->checkList($form["new_disp_end_date_minute"], "new_disp_end_date_minute", array_keys($COLOMN_COMINGSOON_DATE_MINUTE), true);

        if($check_new_disp_start_date && $check_new_disp_end_date && $check_new_disp_start_date_hour && $check_new_disp_start_date_minute && $check_new_disp_end_date_hour && $check_new_disp_end_date_minute) {
            if(mktime($form['new_disp_end_date_hour'],$form['new_disp_end_date_minute'],0,$form['new_disp_end_date_month'],$form['new_disp_end_date_day'],$form['new_disp_end_date_year']) <
                mktime($form['new_disp_start_date_hour'],$form['new_disp_start_date_minute'],0,$form['new_disp_start_date_month'],$form['new_disp_start_date_day'],$form['new_disp_start_date_year'])) {
                $this->addErrorMessage('new_disp_start_date', '終了日よりも過去の日付を指定してください');
            }
        }

        $check_start_date = $this->checkDate ($form['start_date_year'],$form['start_date_month'],$form['start_date_day'],  'start_date', true);
        $check_end_date = $this->checkDate ($form['end_date_year'],$form['end_date_month'],$form['end_date_day'], 'end_date', true);
        $check_start_date_hour = $this->checkList($form["start_date_hour"], "start_date_hour", array_keys($COLOMN_COMINGSOON_DATE_HOUR), true);
        $check_start_date_minute = $this->checkList($form["start_date_minute"], "start_date_minute", array_keys($COLOMN_COMINGSOON_DATE_MINUTE), true);
        $check_end_date_hour = $this->checkList($form["end_date_hour"], "end_date_hour", array_keys($COLOMN_COMINGSOON_DATE_HOUR), true);
        $check_end_date_minute = $this->checkList($form["end_date_minute"], "end_date_minute", array_keys($COLOMN_COMINGSOON_DATE_MINUTE), true);

        if($check_start_date && $check_end_date && $check_start_date_hour && $check_start_date_minute && $check_end_date_hour && $check_end_date_minute) {
            if(mktime($form['end_date_hour'],$form['end_date_minute'],0,$form['end_date_month'],$form['end_date_day'],$form['end_date_year']) <
                mktime($form['start_date_hour'],$form['start_date_minute'],0,$form['start_date_month'],$form['start_date_day'],$form['start_date_year'])) {
                $this->addErrorMessage('start_date', '終了日よりも過去の日付を指定してください');
            }
        }

        if (!$this->isEmpty($form['type']) && $form['type'] == '1') {
            if ($this->checkEmpty($form['disp_order'], 'disp_order')) {
                if ($this->isDigit ($form['disp_order'], 10, 10)) {
                    $this->checkNumeric($form["disp_order"], "disp_order");
                } else {
                    $this->addErrorMessage('disp_order', '半角数字10桁で入力してください。');
                }
            }
            $this->checkText ($form['article_simple'], 'article_simple', 1, 200, true);
            $this->checkText ($form['copyright'], 'copyright', 1, 500, true);

        } else if (!$this->isEmpty($form['type']) && $form['type'] == '2') {
            if ($this->checkEmpty($form['disp_order'], 'disp_order')) {
                if ($this->isDigit ($form['disp_order'], 6, 6)) {
                    $this->checkNumeric($form["disp_order"], "disp_order");
                } else {
                    $this->addErrorMessage('disp_order', '半角数字6桁で入力してください。');
                }
            }
        }

        if (!$this->isEmpty($form['copyright_detail'], 'copyright_detail')) {
            $this->checkText ($form['copyright_detail'], 'copyright_detail', 1, 2000, true);
        }
        $this->checkNumeric($form["column_comingsoon_id"], "column_comingsoon_id", 9, true);

    }

    /**
     * オンライン試写会の登録時チェック
     */
    function checkAddOnlineMovie($form) {
        global $ONLINE_MOVIE_DATE_HOUR;
        global $ONLINE_MOVIE_DATE_MINUTE;

        $this->checkText ($form['title'], 'title', 1, 1000, true);
        $this->checkText ($form['body'], 'body', 1, 20000, true);
        if (!$this->isEmpty($form['body1'])) {
            $this->checkText ($form['body1'], 'body1', 1, 20000, true);
        }
        if (!$this->isEmpty($form['pub_txt'])) {
            $this->checkText ($form['pub_txt'], 'pub_txt', 1, 1000, true);
        }
        if (!$this->isEmpty($form['country_txt'])) {
            $this->checkText ($form['country_txt'], 'country_txt', 1, 1000, true);
        }
        if (!$this->isEmpty($form['year_txt'])) {
            $this->checkText ($form['year_txt'], 'year_txt', 1, 1000, true);
        }
        $this->checkText ($form['time_txt'], 'time_txt', 1, 1000, true);
        if (!$this->isEmpty($form['cast_txt'])) {
            $this->checkText ($form['cast_txt'], 'cast_txt', 1, 1000, true);
        }
        if (!$this->isEmpty($form['staff_txt'])) {
            $this->checkText ($form['staff_txt'], 'staff_txt', 1, 1000, true);
        }
        $this->checkText ($form['copyright'], 'copyright', 1, 200, true);

        if ($this->checkEmpty($form['vlimit'], 'vlimit')) {
            $this->checkNumeric($form["vlimit"], "vlimit");
        }

        $check_playfrom = $this->checkDate ($form['playfrom_year'],$form['playfrom_month'],$form['playfrom_day'],  'playfrom', true);
        $check_playto = $this->checkDate ($form['playto_year'],$form['playto_month'],$form['playto_day'],  'playto', true);
        $check_playfrom_hour = $this->checkList($form["playfrom_hour"], "playfrom_hour", array_keys($ONLINE_MOVIE_DATE_HOUR), true);
        $check_playfrom_minute = $this->checkList($form["playfrom_minute"], "playfrom_minute", array_keys($ONLINE_MOVIE_DATE_MINUTE), true);
        $check_playto_hour = $this->checkList($form["playto_hour"], "playto_hour", array_keys($ONLINE_MOVIE_DATE_HOUR), true);
        $check_playto_minute = $this->checkList($form["playto_minute"], "playto_minute", array_keys($ONLINE_MOVIE_DATE_MINUTE), true);

        if($check_playfrom && $check_playto && $check_playfrom_hour && $check_playfrom_minute && $check_playto_hour && $check_playto_minute) {
            if(mktime($form['playto_hour'],$form['playto_minute'],0,$form['playto_month'],$form['playto_day'],$form['playto_year']) <
                mktime($form['playfrom_hour'],$form['playfrom_minute'],0,$form['playfrom_month'],$form['playfrom_day'],$form['playfrom_year'])) {
                $this->addErrorMessage('playfrom', '終了日よりも過去の日付を指定してください');
            }
        }

        $check_viewfrom = $this->checkDate ($form['viewfrom_year'],$form['viewfrom_month'],$form['viewfrom_day'],  'viewfrom', true);
        $check_viewto = $this->checkDate ($form['viewto_year'],$form['viewto_month'],$form['viewto_day'], 'viewto', true);
        $check_viewfrom_hour = $this->checkList($form["viewfrom_hour"], "viewfrom_hour", array_keys($ONLINE_MOVIE_DATE_HOUR), true);
        $check_viewfrom_minute = $this->checkList($form["viewfrom_minute"], "viewfrom_minute", array_keys($ONLINE_MOVIE_DATE_MINUTE), true);
        $check_viewto_hour = $this->checkList($form["viewto_hour"], "viewto_hour", array_keys($ONLINE_MOVIE_DATE_HOUR), true);
        $check_viewto_minute = $this->checkList($form["viewto_minute"], "viewto_minute", array_keys($ONLINE_MOVIE_DATE_MINUTE), true);

        if($check_viewfrom && $check_viewto && $check_viewfrom_hour && $check_viewfrom_minute && $check_viewto_hour && $check_viewto_minute) {
            if(mktime($form['viewto_hour'],$form['viewto_minute'],0,$form['viewto_month'],$form['viewto_day'],$form['viewto_year']) <
                mktime($form['viewfrom_hour'],$form['viewfrom_minute'],0,$form['viewfrom_month'],$form['viewfrom_day'],$form['viewfrom_year'])) {
                $this->addErrorMessage('viewfrom', '終了日よりも過去の日付を指定してください');
            }
        }

        if (!is_empty($form["vlimit_near"]) && !is_numeric($form["vlimit_near"])) $this->addErrorMessage('vlimit_near', $this->getErrorMessage("msgNumeric"));
        if(!is_empty($form["vlimit"]) && is_numeric($form["vlimit_near"]) && ($form["vlimit_near"]>=$form["vlimit"])) $this->addErrorMessage('vlimit_near', '当選者数より少ない人数を入力してください。');

//        $this->checkText ($form['enquete_file'], 'enquete_file', 1, 100, true);
//        $this->checkText ($form['thumb_img'], 'thumb_img', 1, 200, true);
        $this->checkRange ($form['status'], 'status', 0, 1, true);
        $this->checkText ($form['rights_id'], 'rights_id', 1, 100, true);
        $this->checkText ($form['content_id'], 'content_id', 1, 100, true);
//        $this->checkText ($form['icons'], 'icons', 1, 100, true);
    }

    /**
     * オンライン試写会の更新時チェック
     */
    function checkUpdateOnlineMovie($form) {
        global $ONLINE_MOVIE_DATE_HOUR;
        global $ONLINE_MOVIE_DATE_MINUTE;

        $this->checkText ($form['title'], 'title', 1, 1000, true);
        $this->checkText ($form['body'], 'body', 1, 20000, true);
        if (!$this->isEmpty($form['body1'])) {
            $this->checkText ($form['body1'], 'body1', 1, 20000, true);
        }
        if (!$this->isEmpty($form['pub_txt'])) {
            $this->checkText ($form['pub_txt'], 'pub_txt', 1, 1000, true);
        }
        if (!$this->isEmpty($form['country_txt'])) {
            $this->checkText ($form['country_txt'], 'country_txt', 1, 1000, true);
        }
        if (!$this->isEmpty($form['year_txt'])) {
            $this->checkText ($form['year_txt'], 'year_txt', 1, 1000, true);
        }
        $this->checkText ($form['time_txt'], 'time_txt', 1, 1000, true);
        if (!$this->isEmpty($form['cast_txt'])) {
            $this->checkText ($form['cast_txt'], 'cast_txt', 1, 1000, true);
        }
        if (!$this->isEmpty($form['staff_txt'])) {
            $this->checkText ($form['staff_txt'], 'staff_txt', 1, 1000, true);
        }
        $this->checkText ($form['copyright'], 'copyright', 1, 200, true);

        if ($this->checkEmpty($form['vlimit'], 'vlimit')) {
            $this->checkNumeric($form["vlimit"], "vlimit");
        }

        $check_playfrom = $this->checkDate ($form['playfrom_year'],$form['playfrom_month'],$form['playfrom_day'],  'playfrom', true);
        $check_playto = $this->checkDate ($form['playto_year'],$form['playto_month'],$form['playto_day'],  'playto', true);
        $check_playfrom_hour = $this->checkList($form["playfrom_hour"], "playfrom_hour", array_keys($ONLINE_MOVIE_DATE_HOUR), true);
        $check_playfrom_minute = $this->checkList($form["playfrom_minute"], "playfrom_minute", array_keys($ONLINE_MOVIE_DATE_MINUTE), true);
        $check_playto_hour = $this->checkList($form["playto_hour"], "playto_hour", array_keys($ONLINE_MOVIE_DATE_HOUR), true);
        $check_playto_minute = $this->checkList($form["playto_minute"], "playto_minute", array_keys($ONLINE_MOVIE_DATE_MINUTE), true);

        if($check_playfrom && $check_playto && $check_playfrom_hour && $check_playfrom_minute && $check_playto_hour && $check_playto_minute) {
            if(mktime($form['playto_hour'],$form['playto_minute'],0,$form['playto_month'],$form['playto_day'],$form['playto_year']) <
                mktime($form['playfrom_hour'],$form['playfrom_minute'],0,$form['playfrom_month'],$form['playfrom_day'],$form['playfrom_year'])) {
                $this->addErrorMessage('playfrom', '終了日よりも過去の日付を指定してください');
            }
        }

        $check_viewfrom = $this->checkDate ($form['viewfrom_year'],$form['viewfrom_month'],$form['viewfrom_day'],  'viewfrom', true);
        $check_viewto = $this->checkDate ($form['viewto_year'],$form['viewto_month'],$form['viewto_day'], 'viewto', true);
        $check_viewfrom_hour = $this->checkList($form["viewfrom_hour"], "viewfrom_hour", array_keys($ONLINE_MOVIE_DATE_HOUR), true);
        $check_viewfrom_minute = $this->checkList($form["viewfrom_minute"], "viewfrom_minute", array_keys($ONLINE_MOVIE_DATE_MINUTE), true);
        $check_viewto_hour = $this->checkList($form["viewto_hour"], "viewto_hour", array_keys($ONLINE_MOVIE_DATE_HOUR), true);
        $check_viewto_minute = $this->checkList($form["viewto_minute"], "viewto_minute", array_keys($ONLINE_MOVIE_DATE_MINUTE), true);

        if($check_viewfrom && $check_viewto && $check_viewfrom_hour && $check_viewfrom_minute && $check_viewto_hour && $check_viewto_minute) {
            if(mktime($form['viewto_hour'],$form['viewto_minute'],0,$form['viewto_month'],$form['viewto_day'],$form['viewto_year']) <
                mktime($form['viewfrom_hour'],$form['viewfrom_minute'],0,$form['viewfrom_month'],$form['viewfrom_day'],$form['viewfrom_year'])) {
                $this->addErrorMessage('viewfrom', '終了日よりも過去の日付を指定してください');
            }
        }

        if (!is_empty($form["vlimit_near"]) && !is_numeric($form["vlimit_near"])) $this->addErrorMessage('vlimit_near', $this->getErrorMessage("msgNumeric"));
        if(!is_empty($form["vlimit"]) && is_numeric($form["vlimit_near"]) && ($form["vlimit_near"]>=$form["vlimit"])) $this->addErrorMessage('vlimit_near', '当選者数より少ない人数を入力してください。');

//        $this->checkText ($form['enquete_file'], 'enquete_file', 1, 100, true);
//        $this->checkText ($form['thumb_img'], 'thumb_img', 1, 200, true);
        $this->checkRange ($form['status'], 'status', 0, 1, true);
        $this->checkText ($form['rights_id'], 'rights_id', 1, 100, true);
        $this->checkText ($form['content_id'], 'content_id', 1, 100, true);
//        $this->checkText ($form['icons'], 'icons', 1, 100, true);

        $this->checkNumeric($form["id"], "id", 9, true);

    }

    /**
     * スライド管理用
     */
    function checkSlide ($form, $ins = null) {
        global $SLIDE_AREA_TBL, $SLIDE_COLOR_TBL;
        global $SLIDE_START_AT_HOUR, $SLIDE_START_AT_MINUTE;

        // 表示ページ
        $check_area = $this->checkList($form["slide_area"], "slide_area", array_keys($SLIDE_AREA_TBL), 1);
        // 登録ID
        $check_id = $this->checkText($form["slide_id"], "slide_id", 1, 20, 1);
        if($check_area && $check_id){
            if($ins){
                $SlideDAO = new SlideDAO();
                $slide = $SlideDAO->getSlideById($form["slide_id"], $form["slide_area"]);
                if($slide){
                    $this->addErrorMessage("slide_id", '既に使用されています');
                }
            }
        }
        // 表示開始日時,表示終了日時
        $check_start_at = $this->checkDate($form['start_at_year'],$form['start_at_month'],$form['start_at_day'],  'start_at', true);
        $check_end_at = $this->checkDate($form['end_at_year'],$form['end_at_month'],$form['end_at_day'], 'end_at', true);
        $check_start_at_hour = $this->checkList($form["start_at_hour"], "start_at_hour", array_keys($SLIDE_START_AT_HOUR), true);
        $check_start_at_minute = $this->checkList($form["start_at_minute"], "start_at_minute", array_keys($SLIDE_START_AT_MINUTE), true);
        $check_end_at_hour = $this->checkList($form["end_at_hour"], "end_at_hour", array_keys($SLIDE_START_AT_HOUR), true);
        $check_end_at_minute = $this->checkList($form["end_at_minute"], "end_at_minute", array_keys($SLIDE_START_AT_MINUTE), true);
        if($check_start_at && $check_end_at && $check_start_at_hour && $check_start_at_minute && $check_end_at_hour && $check_end_at_minute) {
            if(mktime($form['end_at_hour'],$form['end_at_minute'],0,$form['end_at_month'],$form['end_at_day'],$form['end_at_year']) <
               mktime($form['start_at_hour'],$form['start_at_minute'],0,$form['start_at_month'],$form['start_at_day'],$form['start_at_year'])) {
                $this->addErrorMessage('start_at', '終了日時よりも過去の日時を指定してください');
            }
        }
        // 表示順
        $this->checkNumeric($form["disp_index"], "disp_index", 9, 1);
        // 本番非表示
        $this->checkRange($form['disp_flg_staging_only'], 'disp_flg_staging_only', 0, 1);
        // 配色
        $this->checkList($form["support_color"], "support_color", array_keys($SLIDE_COLOR_TBL), 1);
        // リンクURL
        $this->checkText($form["link_url"], "link_url", 1, 300, 1);
        // 画像URL
        $this->checkText($form["img_url"], "img_url", 1, 100, 1);
        // ALTテキスト
        $this->checkText($form["alt_text"], "alt_text", 0, 1000);
        // コピーライト表記
        $this->checkText($form["copyright"], "copyright", 0, 1000);

    }

    /**
     * スライド管理(CSV)用
     */
    function checkSlideCsv ($form) {
        global $SLIDE_COLOR_TBL;
        global $SLIDE_START_AT_HOUR, $SLIDE_START_AT_MINUTE;

        // 登録ID
        $check_slide_id = $this->checkText($form["slide_id"], "slide_id", 1, 20, 1);

        $form["start_at_year"] = null;
        $form["start_at_month"] = null;
        $form["start_at_day"] = null;
        $form["start_at_hour"] = null;
        $form["start_at_minute"] = null;
        $start_at_ary = preg_split("/ /", $form["start_at"]);
        if (count($start_at_ary) == 2) {
            $start_at_ymd_ary = preg_split("/[-|\/]/", $start_at_ary[0]);
            if(count($start_at_ymd_ary) == 3) {
                $form["start_at_year"] = $start_at_ymd_ary[0];
                $form["start_at_month"] = $start_at_ymd_ary[1];
                $form["start_at_day"] = $start_at_ymd_ary[2];
            }
            $start_at_hi_ary = preg_split("/:/", $start_at_ary[1]);
            if(count($start_at_hi_ary) == 2) {
                $form["start_at_hour"] = $start_at_hi_ary[0];
                $form["start_at_minute"] = $start_at_hi_ary[1];
            }
        }

        $form["end_at_year"] = null;
        $form["end_at_month"] = null;
        $form["end_at_day"] = null;
        $form["end_at_hour"] = null;
        $form["end_at_minute"] = null;
        $end_at_ary = preg_split("/ /", $form["end_at"]);
        if (count($end_at_ary) == 2) {
            $end_at_ymd_ary = preg_split("/[-|\/]/", $end_at_ary[0]);
            if(count($end_at_ymd_ary) == 3) {
                $form["end_at_year"] = $end_at_ymd_ary[0];
                $form["end_at_month"] = $end_at_ymd_ary[1];
                $form["end_at_day"] = $end_at_ymd_ary[2];
            }
            $end_at_hi_ary = preg_split("/:/", $end_at_ary[1]);
            if(count($end_at_hi_ary) == 2) {
                $form["end_at_hour"] = $end_at_hi_ary[0];
                $form["end_at_minute"] = $end_at_hi_ary[1];
            }
        }

        // 表示開始日時,表示終了日時
        $check_start_at = $this->checkDate($form['start_at_year'],$form['start_at_month'],$form['start_at_day'],  'start_at', true);
        $check_start_at_hour = $this->checkList($form["start_at_hour"], "start_at_hour", array_keys($SLIDE_START_AT_HOUR), true);
        $check_start_at_minute = $this->checkList($form["start_at_minute"], "start_at_minute", array_keys($SLIDE_START_AT_MINUTE), true);

        $check_end_at = $this->checkDate($form['end_at_year'],$form['end_at_month'],$form['end_at_day'], 'end_at', true);
        $check_end_at_hour = $this->checkList($form["end_at_hour"], "end_at_hour", array_keys($SLIDE_START_AT_HOUR), true);
        $check_end_at_minute = $this->checkList($form["end_at_minute"], "end_at_minute", array_keys($SLIDE_START_AT_MINUTE), true);

        // 表示順
        $check_disp_index = $this->checkNumeric($form["disp_index"], "disp_index", 9, 1);
        // 配色
        $check_support_color = $this->checkList($form["support_color"], "support_color", array_keys($SLIDE_COLOR_TBL), 1);
        // リンクURL
        $check_link_url = $this->checkText($form["link_url"], "link_url", 1, 300, 1);
        // 画像URL
        $check_img_url = $this->checkText($form["img_url"], "img_url", 1, 100, 1);
        // ALTテキスト
        //$check_alt_text = $this->checkText($form["alt_text"], "alt_text", 0, 1000);
        $check_alt_text = $this->checkLength($form["alt_text"], "alt_text", 0, 1000);
        // コピーライト表記
        //$check_copyright = $this->checkText($form["copyright"], "copyright", 0, 2000);
        $check_copyright = $this->checkLength($form["copyright"], "copyright", 0, 2000);


        $this->clearError();

        if (!$check_slide_id) {
            $this->addErrorMessage('slide_id', '登録IDの指定が不正です。');
        }
        if (!$check_start_at || !$check_start_at_hour || !$check_start_at_minute) {
            $this->addErrorMessage('start_at', '表示開始日時を正確に指定してください。');
        }
        if (!$check_end_at || !$check_end_at_hour || !$check_end_at_minute) {
            $this->addErrorMessage('end_at', '表示終了日時を正確に指定してください。');
        }
        if($check_start_at && $check_end_at && $check_start_at_hour && $check_start_at_minute && $check_end_at_hour && $check_end_at_minute) {
            if(mktime($form['end_at_hour'],$form['end_at_minute'],0,$form['end_at_month'],$form['end_at_day'],$form['end_at_year']) <
               mktime($form['start_at_hour'],$form['start_at_minute'],0,$form['start_at_month'],$form['start_at_day'],$form['start_at_year'])) {
                $this->addErrorMessage('start_at', '表示開始日時は表示終了日時よりも過去の日時を指定してください。');
            }
        }
        if (!$check_disp_index) {
            $this->addErrorMessage('disp_index', '表示順を数字で指定してください。');
        }
        if (!$check_support_color) {
            $this->addErrorMessage('support_color', '配色の指定が不正です。');
        }
        if (!$check_link_url) {
            $this->addErrorMessage('link_url', 'リンクURLを300文字以内で指定してください。');
        }
        if (!$check_img_url) {
            $this->addErrorMessage('img_url', '画像URLを100文字以内で指定してください。');
        }
        if (!$check_alt_text) {
            $this->addErrorMessage('alt_text', 'ALTテキストを1000文字以内で指定してください。');
        }
        if (!$check_copyright) {
            $this->addErrorMessage('copyright', 'コピーライト表記を2000文字以内で指定してください。');
        }

    }

    /**
     * キャンペーン管理用
     */
    function checkCampaign ($form, $ins = null) {
        global $CAMPAIGN_START_AT_HOUR, $CAMPAIGN_START_AT_MINUTE;

        // 登録ID
        $check_id = $this->checkNumeric($form["campaign_id"], "campaign_id", 10, 20, 1);
        if($check_id){
            if($ins){
                $CampaignDAO = new CampaignDAO();
                $campaign = $CampaignDAO->getCampaignById($form["campaign_id"]);
                if($campaign){
                    $this->addErrorMessage("campaign_id", '既に使用されています');
                }
            }
        }
        /*
        // キャンペーン期間
        $check_campaign_kikan_start = $this->checkDate($form['campaign_kikan_start_year'],$form['campaign_kikan_start_month'],$form['campaign_kikan_start_day'],  'campaign_kikan_start', true);
        $check_campaign_kikan_end = $this->checkDate($form['campaign_kikan_end_year'],$form['campaign_kikan_end_month'],$form['campaign_kikan_end_day'], 'campaign_kikan_end', true);
        if($check_campaign_kikan_start && $check_campaign_kikan_end) {
            if(mktime(0,0,0,$form['campaign_kikan_end_month'],$form['campaign_kikan_end_day'],$form['campaign_kikan_end_year']) <
               mktime(0,0,0,$form['campaign_kikan_start_month'],$form['campaign_kikan_start_day'],$form['campaign_kikan_start_year'])) {
                $this->addErrorMessage('campaign_kikan_start', '終了日時よりも過去の日を指定してください');
            }
        }
         */
        // 表示開始日時
        $check_campaign_hyoji_start = $this->checkDate($form['campaign_hyoji_start_year'],$form['campaign_hyoji_start_month'],$form['campaign_hyoji_start_day'],  'campaign_hyoji_start', true);
        $check_campaign_hyoji_end = $this->checkDate($form['campaign_hyoji_end_year'],$form['campaign_hyoji_end_month'],$form['campaign_hyoji_end_day'], 'campaign_hyoji_end', true);
        $check_campaign_hyoji_start_hour = $this->checkList($form["campaign_hyoji_start_hour"], "campaign_hyoji_start_hour", array_keys($CAMPAIGN_START_AT_HOUR), true);
        $check_campaign_hyoji_start_minute = $this->checkList($form["campaign_hyoji_start_minute"], "campaign_hyoji_start_minute", array_keys($CAMPAIGN_START_AT_MINUTE), true);
        $check_campaign_hyoji_end_hour = $this->checkList($form["campaign_hyoji_end_hour"], "campaign_hyoji_end_hour", array_keys($CAMPAIGN_START_AT_HOUR), true);
        $check_campaign_hyoji_end_minute = $this->checkList($form["campaign_hyoji_end_minute"], "campaign_hyoji_end_minute", array_keys($CAMPAIGN_START_AT_MINUTE), true);
        if($check_campaign_hyoji_start && $check_campaign_hyoji_end && $check_campaign_hyoji_start_hour && $check_campaign_hyoji_start_minute && $check_campaign_hyoji_end_hour && $check_campaign_hyoji_end_minute) {
            if(mktime($form['campaign_hyoji_end_hour'],$form['campaign_hyoji_end_minute'],0,$form['campaign_hyoji_end_month'],$form['campaign_hyoji_end_day'],$form['campaign_hyoji_end_year']) <
               mktime($form['campaign_hyoji_start_hour'],$form['campaign_hyoji_start_minute'],0,$form['campaign_hyoji_start_month'],$form['campaign_hyoji_start_day'],$form['campaign_hyoji_start_year'])) {
                $this->addErrorMessage('campaign_hyoji_start', '終了日時よりも過去の日時を指定してください');
            }
        }

        // 本番非表示
        $this->checkRange($form['disp_flg_staging_only'], 'disp_flg_staging_only', 0, 1);
        // タイトル
        $this->checkText($form["campaign_title"], "campaign_title", 1, 64, 1);
        // リンクURL
        $this->checkText($form["jump_url"], "jump_url", 0, 300);
        // タイトル1
        $this->checkText($form["campaign_dt1"], "campaign_dt1", 0, 1000);
        // 表記1
        $this->checkText($form["campaign_dd1"], "campaign_dd1", 0, 1000);
        // タイトル2
        $this->checkText($form["campaign_dt2"], "campaign_dt2", 0, 1000);
        // 表記2
        $this->checkText($form["campaign_dd2"], "campaign_dd2", 0, 1000);
        // タイトル3
        $this->checkText($form["campaign_dt3"], "campaign_dt3", 0, 1000);
        // 表記3
        $this->checkText($form["campaign_dd3"], "campaign_dd3", 0, 1000);
        // タイトル4
        $this->checkText($form["campaign_dt4"], "campaign_dt4", 0, 1000);
        // 表記4
        $this->checkText($form["campaign_dd4"], "campaign_dd4", 0, 1000);
        // タイトル5
        $this->checkText($form["campaign_dt5"], "campaign_dt5", 0, 1000);
        // 表記5
        $this->checkText($form["campaign_dd5"], "campaign_dd5", 0, 1000);

    }

    /**
     * 作品投票応募
     */
    function checkAwardEntry ($form) {

        $voteArray = $form['vote'];
        $voteCheckArray = array();

        foreach ($voteArray as $vote) {
            if ($vote != '0') {
                $voteCheckArray[] = $vote;
            }
        }

        if (count($voteCheckArray) < 1) {
            $this->addErrorMessage('vote', '投票する動画を選択してください。');
        } else if (count($voteCheckArray) > 3) {
            $this->addErrorMessage('vote', '投票できる動画は３つまでとなっています。');
        }
    }


    /**
     * クレジットカード登録・更新情報チェック
     *
     */
    function checkCreditEntryInfo($form)
    {
        $card_check_error_flag = null;
        // 桁数チェック
        if ( ! $this->isDigit($form["card_number"], 14, 16))
        {
            $this->addErrorMessage('card_number', '正しく入力してください。');
            $card_check_error_flag = 1;
        }
        // 番号チェック（数字のみ）
        if (is_null($card_check_error_flag) && !$this->isInteger($form["card_number"]))
        {
            $this->addErrorMessage('card_number', '半角数字でご記入ください。');
        }

        // 有効期限チェック（現在月より前だとエラー）
        if($form['card_year'].sprintf('%02d', $form['card_month']) < date("Ym"))
        {
            $this->addErrorMessage('card_expiration', '正しく入力してください。');
        }

    }

    /**
     * インターネットTV閲覧許可PFチェック
     *
     */
    function checkPfForInternettvMember($service_type)
    {
        global $ITV_AUTHORIZED_PF_TBL;
        if(!in_array($service_type, array_keys($ITV_AUTHORIZED_PF_TBL)))
        {
            return false;
        }
        return true;
    }

    /**
     * 会員情報変更許可PFチェック
     *
     */
    function checkPfForMemberInfoChange($service_type, $type = null, $subscriber_status = null)
    {
        if ($type == 'itv') {
            if (!in_array($service_type, array(8, 9)) || $subscriber_status == 9) {
                return false;
            }
        } else {
            if (in_array($service_type, array(8, 9))) {
                return false;
            }
        }
        return true;
    }


    /**
     * インターネットTV解約時　初月無料は変更拒否チェック
     *
     */
    function checkCancelMemberInternettvAfterOneMonthForCPPlan($member)
    {
        // CPのプランIDであれば課金開始日から一か月経っているかチェック
        $date = date_create($member->regist_complete_date);
        $date_format = date_format($date, 'Y-m-01');
        $endMonth = date("Y-m-01 00:00:00",strtotime($date_format."+1 month"));
        $now = date("Y-m-d H:i:s");

        if(strtotime($endMonth) > strtotime($now))
        {
            return false;
        }
        return true;
    }

    /**
     * インターネットTV　プラン加入チェック
     *
     */
    function checkAlreadyRegisterInternettvMonthlyPlan($keiyakuInfo)
    {
        if(isset($keiyakuInfo))
        {
            $kanyu_flag = false;
            // keiyakuInfoからCPのプランIDをとる
            foreach ($keiyakuInfo as $keiyaku)
            {
                if($keiyaku['keiyakuStatus'] == '4' && in_array($keiyaku['planId'], array('3104000033', '3104000035', '3104000040')) && $keiyaku['svcStatus'] == '4')
                {
                    $kanyu_flag = true;
                }
//                else
//                {
//                    $kanyu_flag = false;
//                }
            }
            // フラグ返答
            return $kanyu_flag;
        }
        return false;
    }

}

?>
