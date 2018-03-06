<?php
// TODO 旧から
require_once ('member.php');
require_once ('memberdao.php');
require_once ("api/custentry.php");
require_once("api/custinfoget.php");
require_once("api/custcancel.php");
require_once("api/custreentry.php");
require_once("api/custadd.php");


class MemberTran{

    var $db;
    var $null;

    function MemberTran(){
        $this->db = $GLOBALS["STARCH_DB"];
        $this->null = null;
    }

    /**
     * DBオブジェクトゲッター
     *
     * @return  Object  $db    DBオブジェクト
     */
    function &getDB () {
        return $this->db;
    }


    /**
	 * 仮会員登録
	 */
	function insertPreMember($form, $type = null) {
		$db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        //$dao =& MemberDAO::getInstance();
        $dao = new MemberDAO;
        $member_exist = $dao->checkMemberByEmail($form['email1']);

        if($member_exist) {
            $db->rollback();
            return null;
        }

        // パスワード SHA256 対応(2013/07)
        $form['password'] =hash('SHA256',$form['password']);

        $member = new Member();
        $row = $this->_organizeRowFromForm($form);

        $member->setRow($row);
        $result = $member->insert();
        if( ! $result) {
            $db->rollback();
            return false;
        }

		$insertMember = $dao->getPreMemberByEmail($member->email1, $type);
		if(!$insertMember){
            $db->rollback();
            return false;
        }

		// TOCS連携API：新規登録
		global $PREF_TBL, $TOCS_PLAN_ID_TBL;
        $tocsData = array();
		$tocsData['commonInfo']['frontCustID'] = $insertMember->member_id;																	// フロント顧客ID

		$tocsData['custInfo']['kanyuMmousikomiDate'] = date("Ymd", time());																	// 加入申込日
        $nameKana = (!empty($form['name_sei_kana']) or !empty($form['name_mei_kana'])) ? $form['name_sei_kana'] . '　' . $form['name_mei_kana'] : null;
        $tocsData['custInfo']['custNameKana'] = (!empty($nameKana)) ? $nameKana : 'ミニュウリョク';				                			// お客様氏名カナ
        $name = (!empty($form['name_sei']) and !empty($form['name_mei'])) ? $form['name_sei'] . '　' . $form['name_mei'] : null;
        $tocsData['custInfo']['custName'] = (!empty($name)) ? $name : '未入力';                                                             // お客様氏名
		$tocsData['custInfo']['zipCode'] = ($form["zip1"] != '' && $form["zip2"] != '') ? $form['zip1'] . $form['zip2'] : '0000000';		// 郵便番号
		$tocsData['custInfo']['todofuken'] = isset($PREF_TBL[$form['pref']]) ? $PREF_TBL[$form['pref']] : '';								// 都道府県
		$tocsData['custInfo']['siku'] = $form['address1'];																					// 市区
		$tocsData['custInfo']['tatemonoName'] = $form['address2'];																			// 建物名
		$tocsData['custInfo']['homeTelNum'] = preg_match("/^[0-9]{10}$/", $form["tel"]) ? $form["tel"] : '';								// 固定電話番号
		$tocsData['custInfo']['mobileTelNum'] = preg_match("/^[0-9]{11}$/", $form["tel"]) ? $form["tel"] : '';								// 携帯電話番号
		$tocsData['custInfo']['seibetu'] = $form['sex'];																					// 性別
		$tocsData['custInfo']['birthDate'] = $form['birth_y'] . $form['birth_m'] . $form['birth_d'];										// 生年月日
		$tocsData['custInfo']['mailAddrss1'] = $form['email1'];																				// メールアドレス
//		$tocsData['custInfo']['pfKanyuUmu'] = $form['subscriber_status'] == '1' ? '1' : '0';												// PF加入状況	　0：なし 1：あり
        $tocsData['custInfo']['pfKanyuUmu'] = $this->serviceTypeChangeToTOCS($form);                                                 // PF加入状況 20桁
        $tocsData['custInfo']['yobi1'] = (isset($form['job'])) ? $form['job'] : '0';                                                        // 職業 2桁（可変）
        $tocsData['custInfo']['yobi2'] = (isset($form['favorite_genre'])) ? $form['favorite_genre'] : '000000000000000000000000000000';     // 好みのジャンル 30桁（可変）

        $tocsData['billInfo']['sameKbn'] = '0';																								// 請求先同一区分　0：契約者と同じ
		$tocsData['billInfo']['payMethod'] = (isset($form['payMethod'])) ? $form['payMethod'] : '4';																							// 支払い方法　　　4：窓口
		$tocsData['billInfo']['settlementAgentKbn'] = '1';

        $tocsData['billInfo']['cardMemberNum'] = (isset($form['cardMemberNum'])) ? $form['cardMemberNum'] : '';
        $tocsData['billInfo']['creditCardNo4'] = (isset($form['creditCardNo4'])) ? $form['creditCardNo4'] : '';
        $tocsData['billInfo']['creditCardLimit'] = (isset($form['creditCardLimit'])) ? $form['creditCardLimit'] : '';



        $plan_id = null;
        // MSC
        if (is_null($type)) {
            $plan_id = '3104000012';
        // MSC以外
        } else {
            // ITV
            if ($type == 'itv') {
                $plan_id = '3104000012';
            } else {
                $plan_id = $TOCS_PLAN_ID_TBL[$type];
            }
        }

		$tocsData['keiyakuInfo'][0]['planId'] = $plan_id;																				// 申込プラン

		$custEntry = new CustEntry();
		$bssCastID = $custEntry->entry($tocsData);
		if (!$bssCastID) {
			$db->rollback();
            return false;
		}

		// tocs_idをmemberテーブルに反映
		$form['member_id'] = $insertMember->member_id;
		$form['tocs_id'] = $bssCastID;

		$insertMember->setRow($form);
        $result = $insertMember->update();
        if(!$result){
            $db->rollback();
            return false;
        }

        $db->commit();
        return true;
	}

    /**
     * 仮登録データの更新　※特殊　※前のデータは完全に消去し上書き
     *
     * （利用箇所）
     * ・仮登録ユーザーが再度新規登録（仮登録）を実行
     */
	function updatePreMember($form, $type = null)
    {
        $db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        $dao = new MemberDAO;

        // パスワード SHA256 対応(2013/07)
        $form['password'] =hash('SHA256',$form['password']);

        if ( ! $form['subscribe_sei']) $form['subscribe_sei'] = null;
        if ( ! $form['subscribe_mei']) $form['subscribe_mei'] = null;
        if ( ! $form['subscribe_sei_kana']) $form['subscribe_sei_kana'] = null;
        if ( ! $form['subscribe_mei_kana']) $form['subscribe_mei_kana'] = null;
        if ( ! $form['email2']) $form['email2'] = null;
        if ( ! $form['email_kari']) $form['email_kari'] = null;
        if ( ! $form['service_type']) $form['service_type'] = null;
        if ( ! $form['service_user_id']) $form['service_user_id'] = null;
        if ( ! $form['mail_info_flag']) $form['mail_info_flag'] = null;
        if ( ! $form['mail_mag_flag']) $form['mail_mag_flag'] = null;
        if ( ! $form['pref']) $form['pref'] = null;
        if ( ! $form['sex']) $form['sex'] = null;
        if ( ! $form['birth']) $form['birth'] = null;

        $member = $dao->getMemberById($form["member_id"]);
        $this->_organizeRowFromForm($form);

        // TOCS連携API：新規登録(データ更新)
        global $PREF_TBL, $TOCS_PLAN_ID_TBL;
        $tocsData = array();
		$tocsData['commonInfo']['frontCustID'] = $member->member_id;																	// フロント顧客ID

		$tocsData['custInfo']['kanyuMmousikomiDate'] = date("Ymd", time());																	// 加入申込日
        $nameKana = (!empty($form['name_sei_kana']) or !empty($form['name_mei_kana'])) ? $form['name_sei_kana'] . '　' . $form['name_mei_kana'] : null;
        $tocsData['custInfo']['custNameKana'] = (!empty($nameKana)) ? $nameKana : 'ミニュウリョク';				                			// お客様氏名カナ
        $name = (!empty($form['name_sei']) and !empty($form['name_mei'])) ? $form['name_sei'] . '　' . $form['name_mei'] : null;
        $tocsData['custInfo']['custName'] = (!empty($name)) ? $name : '未入力';                                                             // お客様氏名
		$tocsData['custInfo']['zipCode'] = ($form["zip1"] != '' && $form["zip2"] != '') ? $form['zip1'] . $form['zip2'] : '0000000';		// 郵便番号
		$tocsData['custInfo']['todofuken'] = isset($PREF_TBL[$form['pref']]) ? $PREF_TBL[$form['pref']] : '';								// 都道府県
		$tocsData['custInfo']['siku'] = $form['address1'];																					// 市区
		$tocsData['custInfo']['tatemonoName'] = $form['address2'];																			// 建物名
		$tocsData['custInfo']['homeTelNum'] = preg_match("/^[0-9]{10}$/", $form["tel"]) ? $form["tel"] : '';								// 固定電話番号
		$tocsData['custInfo']['mobileTelNum'] = preg_match("/^[0-9]{11}$/", $form["tel"]) ? $form["tel"] : '';								// 携帯電話番号
		$tocsData['custInfo']['seibetu'] = $form['sex'];																					// 性別
		$tocsData['custInfo']['birthDate'] = $form['birth_y'] . $form['birth_m'] . $form['birth_d'];										// 生年月日
		$tocsData['custInfo']['mailAddrss1'] = $form['email1'];																				// メールアドレス
//		$tocsData['custInfo']['pfKanyuUmu'] = $form['subscriber_status'] == '1' ? '1' : '0';												// PF加入状況	　0：なし 1：あり
        $tocsData['custInfo']['pfKanyuUmu'] = $this->serviceTypeChangeToTOCS($form);                                                 // PF加入状況 20桁
        $tocsData['custInfo']['yobi1'] = (isset($form['job'])) ? $form['job'] : '0';                                                        // 職業 2桁（可変）
        $tocsData['custInfo']['yobi2'] = (isset($form['favorite_genre'])) ? $form['favorite_genre'] : '000000000000000000000000000000';     // 好みのジャンル 30桁（可変）

		$tocsData['billInfo']['sameKbn'] = '0';																								// 請求先同一区分　0：契約者と同じ
		$tocsData['billInfo']['payMethod'] = '4';																							// 支払い方法　　　4：窓口
		$tocsData['billInfo']['settlementAgentKbn'] = '1';																					// 決済種別　　　　1：GMO

        if($type == 'itv')
        {
            $tocsData['billInfo']['billInfoUpdate'] = 1;
            $tocsData['billInfo']['cardMemberNum'] = (isset($form['cardMemberNum'])) ? $form['cardMemberNum'] : '';
            $tocsData['billInfo']['creditCardNo4'] = (isset($form['creditCardNo4'])) ? $form['creditCardNo4'] : '';
            $tocsData['billInfo']['creditCardLimit'] = (isset($form['creditCardLimit'])) ? $form['creditCardLimit'] : '';
        }

        $plan_id = null;
        // MSC
        if (is_null($type)) {
            $plan_id = '3104000012';
            // MSC以外
        } else {
            // ITV
            if ($type == 'itv') {
                $plan_id = '3104000012';
            } else {
                $plan_id = $TOCS_PLAN_ID_TBL[$type];
            }
        }

		$tocsData['keiyakuInfo'][0]['planId'] = $plan_id;																				// 申込プラン

		$custEntry = new CustEntry();
		$bssCastID = $custEntry->entry($tocsData);
		if (!$bssCastID) {
			$db->rollback();
            return false;
		}

		// memberテーブル更新（既存データ更新、新tocs_idをmemberテーブルに反映）
		$form['tocs_id'] = $bssCastID;

		$member->setRow($form);
        $result = $member->update();
        if(!$result){
            $db->rollback();
            return false;
        }

        $db->commit();
        return true;
    }

    /**
     * 既存会員データの更新
     *
     * （利用箇所）
     * ・既存会員の本登録フロー
     * ・既存会員の新規会員登録（仮登録実行）フロー
     */
    function updateOldMember($form, $type = null)
    {
        $db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        $dao = new MemberDAO;

        // パスワード SHA256 対応(2013/07)
        $form['password'] =hash('SHA256',$form['password']);

        $member = $dao->getMemberById($form["member_id"]);

		// 仮会員登録
        // TOCS連携API：新規登録(データがあっても更新)
        global $PREF_TBL, $TOCS_PLAN_ID_TBL;
        $tocsData = array();
		$tocsData['commonInfo']['frontCustID'] = $member->member_id;																		// フロント顧客ID

		$tocsData['custInfo']['kanyuMmousikomiDate'] = date("Ymd", time());																	// 加入申込日
        $nameKana = (!empty($form['name_sei_kana']) or !empty($form['name_mei_kana'])) ? $form['name_sei_kana'] . '　' . $form['name_mei_kana'] : null;
        $tocsData['custInfo']['custNameKana'] = (!empty($nameKana)) ? $nameKana : 'ミニュウリョク';				                			// お客様氏名カナ
        $name = (!empty($form['name_sei']) and !empty($form['name_mei'])) ? $form['name_sei'] . '　' . $form['name_mei'] : null;
        $tocsData['custInfo']['custName'] = (!empty($name)) ? $name : '未入力';                                                             // お客様氏名
		$tocsData['custInfo']['zipCode'] = ($form["zip1"] != '' && $form["zip2"] != '') ? $form['zip1'] . $form['zip2'] : '0000000';		// 郵便番号
		$tocsData['custInfo']['todofuken'] = isset($PREF_TBL[$form['pref']]) ? $PREF_TBL[$form['pref']] : '';								// 都道府県
		$tocsData['custInfo']['siku'] = $form['address1'];																					// 市区
		$tocsData['custInfo']['tatemonoName'] = $form['address2'];																			// 建物名
		$tocsData['custInfo']['homeTelNum'] = preg_match("/^[0-9]{10}$/", $form["tel"]) ? $form["tel"] : '';								// 固定電話番号
		$tocsData['custInfo']['mobileTelNum'] = preg_match("/^[0-9]{11}$/", $form["tel"]) ? $form["tel"] : '';								// 携帯電話番号
		$tocsData['custInfo']['seibetu'] = $form['sex'];																					// 性別
		$tocsData['custInfo']['birthDate'] = $form['birth_y'] . $form['birth_m'] . $form['birth_d'];										// 生年月日
		$tocsData['custInfo']['mailAddrss1'] = $form['email1'];																				// メールアドレス
//		$tocsData['custInfo']['pfKanyuUmu'] = $form['subscriber_status'] == '1' ? '1' : '0';												// PF加入状況	　0：なし 1：あり
        $tocsData['custInfo']['pfKanyuUmu'] = $this->serviceTypeChangeToTOCS($form);                                                 // PF加入状況 20桁
        $tocsData['custInfo']['yobi1'] = (isset($form['job'])) ? $form['job'] : '0';                                                        // 職業 2桁（可変）
        $tocsData['custInfo']['yobi2'] = (isset($form['favorite_genre'])) ? $form['favorite_genre'] : '000000000000000000000000000000';     // 好みのジャンル 30桁（可変）

		$tocsData['billInfo']['sameKbn'] = '0';																								// 請求先同一区分　0：契約者と同じ
		$tocsData['billInfo']['payMethod'] = '4';																							// 支払い方法　　　4：窓口
		$tocsData['billInfo']['settlementAgentKbn'] = '1';																					// 決済種別　　　　1：GMO

        $plan_id = null;
        // MSC
        if (is_null($type)) {
            $plan_id = '3104000012';
            // MSC以外
        } else {
            // ITV
            if ($type == 'itv') {
                $plan_id = '3104000012';
            } else {
                $plan_id = $TOCS_PLAN_ID_TBL[$type];
            }
        }

		$tocsData['keiyakuInfo'][0]['planId'] = $plan_id;																				// 申込プラン

		$custEntry = new CustEntry();
		$bssCastID = $custEntry->entry($tocsData);
		if (!$bssCastID) {
			$db->rollback();
            return false;
		}

		// memberテーブル更新（既存データ更新、新tocs_idをmemberテーブルに反映）
		$form['tocs_id'] = $bssCastID;

		$this->_organizeRowFromForm($form);

        $member->setRow($form);
        $result = $member->update();
        if(!$result){
            $db->rollback();
            return false;
        }

        $db->commit();
        return true;
    }

    //**
    /* ワンタイムキー更新
    */
    function updateOneTimeKeyDate($preMember) {

        $db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        $result = $preMember->update();
        if(! $result){
            $db->rollback();
            return false;
        }
        $db->commit();
        return true;

    }

    /**
	 * 本会員登録
	 */
	function updateCompleteMember($form, $type = null) {

		$db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

		//$dao =& MemberDAO::getInstance();
        $dao = new MemberDAO;

		// 仮会員取得
		$member = $dao->getPreMemberById($form["member_id"]);
        if (is_null($member))
        {
            $db->rollback();
            return null;
        }
		$this->_organizeRowFromForm($form);

		// TOCS連携API：会員情報照会
		$tocsData['commonInfo']['bssCustID'] = $member->tocs_id;		// 基幹顧客ID(*)

		$custInfoGet = new CustInfoGet();
		$result = $custInfoGet->get($tocsData);
		if (!$result) {
			$db->rollback();
			return false;
		}

		$commonInfo = $custInfoGet->getCommonInfo();
		if (empty($commonInfo)) {
			$db->rollback();
			return false;
		}

		$custInfo = $custInfoGet->getCustInfo();
		if (empty($custInfo)) {
			$db->rollback();
			return false;
		}

		//update
		$memberTran = new MemberTran();
		$form["onetime_key"] = '';
		$form["onetime_key_date"] = '';
		$form["status"] = 1; // 本登録
        
        // セッション管理対応として　2018.2追加
        if($type = 'set_env')
        {
            $now = date("Y/m/d H:i:s", time());

            $form["last_login_time"] = $now;
            $form["update_date"]     = $now;

            $env = get_user_env();
            $form["career"]         = $env["career"];
            $form["user_agent"]     = $env["user_agent"];
            $form["remote_address"] = $env["remote_address"];
            $form["server_address"] = $env["server_address"];
            $form["referer"]        = $env["referer"];
            $form["sess_id"] = session_id();   
        }

        $member->setRow($form);
        $result = $member->update();
        if(!$result){
            $db->rollback();
            return false;
        }

        // custInfoをTOCS用に変換
        $custInfo = $this->custInfoChangeToTOCSSize($custInfo, $form);

		// TOCS連携API：会員情報更新
		$tocsData['commonInfo']['bssCustID'] = $member->tocs_id;							// 基幹顧客ID

		$tocsData['custInfo']['custInfoUpdate'] = '0';										// 更新要否		0：更新しない
		$tocsData['custInfo']['kanyuMmousikomiDate'] = $custInfo['kanyuMmousikomiDate'];	// 加入申込日
		$tocsData['custInfo']['custNameKana'] = $custInfo['custNameKana'];					// お客様氏名カナ
		$tocsData['custInfo']['custName'] = $custInfo['custName'];							// お客様氏名
		$tocsData['custInfo']['zipCode'] = $custInfo['zipCode'];							// 郵便番号
		$tocsData['custInfo']['pfKanyuUmu'] = $custInfo['pfKanyuUmu'];						// PF加入状況
        $tocsData['custInfo']['yobi1'] = $custInfo['yobi1'];						        // 職業
        $tocsData['custInfo']['yobi2'] = $custInfo['yobi2'];						        // 好みのジャンル

		$tocsData['billInfo']['billInfoUpdate'] = '0';										// 更新要否		0：更新しない
		$tocsData['billInfo']['sameKbn'] = '';												// 請求先同一区分
		$tocsData['billInfo']['payMethod'] = '';											// 支払い方法
		$tocsData['billInfo']['settlementAgentKbn'] = '';									// 決済種別

		$tocsData['keiyakuInfo'][0]['keiyakuInfoUpdate'] = '1';								// 更新要否		1：更新する
		$tocsData['keiyakuInfo'][0]['keiyakuStatus'] = '4';									// 契約ステータス　4：本登録

		$custReEntry = new CustReEntry();
		$result = $custReEntry->reentry($tocsData);
		if(!$result){
            $db->rollback();
            return false;
        }

		$commonInfo = $custReEntry->getCommonInfo();
		if (empty($commonInfo)) {
			$db->rollback();
			return false;
		}

		global $TOCS_PLAN_ID_TBL;

        // CATVの場合、CATV用の契約プランを追加申込
//        if ($type == 'catv'){
//            $tocsData = array();
//            $tocsData['commonInfo']['bssCustID'] = $member->tocs_id;																		// 基幹顧客ID
//            $tocsData['commonInfo']['frontCustID'] = $member->member_id;																	// フロント顧客ID
//
//            $tocsData['keiyakuInfo'][0]['planId'] = $TOCS_PLAN_ID_TBL[$type];                                                               // 申込プラン
//            $tocsData['keiyakuInfo'][0]['yobi4'] = '3';                                                                                     // 登録月課金区分　　　3：TOCSに従う
//
//            $custAdd = new CustAdd();
//            $bssCastID = $custAdd->add($tocsData);
//            if (!$bssCastID) {
//                $db->rollback();
//                return false;
//            }
//
//            $commonInfo = $custAdd->getCommonInfo();
//            if (empty($commonInfo)) {
//                $db->rollback();
//                return false;
//            }
//        }

		$db->commit();
        return true;
	}


    /**
     * ITV本会員登録
     */
    function updateCompleteMemberItv($form, $type = null, $member) {

        $db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        $dao = new MemberDAO;

        if($member->status != 1)
        {
            // 仮会員取得
            $member = $dao->getPreMemberById($form["member_id"]);
            if (is_null($member))
            {
                $db->rollback();
                return null;
            }
        }
        $this->_organizeRowFromForm($form);

        // TOCS連携API：会員情報照会
        $tocsData['commonInfo']['bssCustID'] = $member->tocs_id;		// 基幹顧客ID(*)

        $custInfoGet = new CustInfoGet();
        $result = $custInfoGet->get($tocsData);
        if (!$result) {
            $db->rollback();
            return false;
        }

        $commonInfo = $custInfoGet->getCommonInfo();
        if (empty($commonInfo)) {
            $db->rollback();
            return false;
        }

        $custInfo = $custInfoGet->getCustInfo();
        if (empty($custInfo)) {
            $db->rollback();
            return false;
        }

        //update
        $memberTran = new MemberTran();
        $form["onetime_key"] = '';
        $form["onetime_key_date"] = '';
        $form["status"] = 1; // 本登録
        
        // セッション管理対応として　2018.2追加
        if($type = 'set_env')
        {
            $now = date("Y/m/d H:i:s", time());

            $form["last_login_time"] = $now;
            $form["update_date"]     = $now;

            $env = get_user_env();
            $form["career"]         = $env["career"];
            $form["user_agent"]     = $env["user_agent"];
            $form["remote_address"] = $env["remote_address"];
            $form["server_address"] = $env["server_address"];
            $form["referer"]        = $env["referer"];
            $form["sess_id"] = session_id();   
        }

        $member->setRow($form);
        $result = $member->update();
        if(!$result){
            $db->rollback();
            return false;
        }

        // custInfoをTOCS用に変換
        $custInfo = $this->custInfoChangeToTOCSSize($custInfo, $form);

        // TOCS連携API：会員情報更新
        $tocsData['commonInfo']['bssCustID'] = $member->tocs_id;							// 基幹顧客ID

        $tocsData['custInfo']['custInfoUpdate'] = '0';										// 更新要否		0：更新しない
        $tocsData['custInfo']['kanyuMmousikomiDate'] = $custInfo['kanyuMmousikomiDate'];	// 加入申込日
        $tocsData['custInfo']['custNameKana'] = $custInfo['custNameKana'];					// お客様氏名カナ
        $tocsData['custInfo']['custName'] = $custInfo['custName'];							// お客様氏名
        $tocsData['custInfo']['zipCode'] = $custInfo['zipCode'];							// 郵便番号
        $tocsData['custInfo']['pfKanyuUmu'] = $custInfo['pfKanyuUmu'];						// PF加入状況
        $tocsData['custInfo']['yobi1'] = $custInfo['yobi1'];						        // 職業
        $tocsData['custInfo']['yobi2'] = $custInfo['yobi2'];						        // 好みのジャンル

        $tocsData['billInfo']['billInfoUpdate'] = '0';										// 更新要否		0：更新しない
        $tocsData['billInfo']['sameKbn'] = '';												// 請求先同一区分
        $tocsData['billInfo']['payMethod'] = '';											// 支払い方法
        $tocsData['billInfo']['settlementAgentKbn'] = '';									// 決済種別

        $tocsData['keiyakuInfo'][0]['keiyakuInfoUpdate'] = '1';								// 更新要否		1：更新する
        $tocsData['keiyakuInfo'][0]['keiyakuStatus'] = '4';									// 契約ステータス　4：本登録

        $custReEntry = new CustReEntry();
        $result = $custReEntry->reentry($tocsData);
        if(!$result){
            $db->rollback();
            return false;
        }

        $commonInfo = $custReEntry->getCommonInfo();
        if (empty($commonInfo)) {
            $db->rollback();
            return false;
        }

        $ret = $this->updateMember($form, true, 'itv');
        if ( ! $ret)
        {
            $db->rollback();
            return false;
        }

        $db->commit();
        return true;
    }


    /**
     * 登録
     *
     */
    function insertMember($form){

        $db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        //$dao =& MemberDAO::getInstance();
        $dao = new MemberDAO;
        $member_exist = $dao->checkMemberByEmail($form['email1']);

        if($member_exist) {
            $db->rollback();
            return null;
        }



        // パスワード SHA256 対応(2013/07)
        $form['password'] =hash('SHA256',$form['password']);


        $member = new Member();
        $row = $this->_organizeRowFromForm($form);

        $member->setRow($row);
        $result = $member->insert();
        if(!$result){
            $db->rollback();
            return false;
        }

        $db->commit();
        return true;
    }


    /**
     * 変更
     *
     */
    function updateMember($form, $info=false, $type = null){

        $db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        //$dao =& MemberDAO::getInstance();
        $dao = new MemberDAO;

        // メールアドレス
        if ($form['email1'])
        {
            $member_exist =
                $dao->checkMemberByEmail($form['email1'], $form["member_id"]);

            if($member_exist) {
                $db->rollback();
                return null;
            }
        }

        // パスワード SHA256 対応(2013/07)
        if($form['password']){
            $form['password'] =hash('SHA256',$form['password']);
        }else{
            unset($form['password']);
        }


        // メールアドレス（仮）
        if ($form['email_kari'])
        {
            $member_exist =
                $dao->checkMemberByEmail($form['email_kari'], $form["member_id"]);

            if($member_exist) {
                $db->rollback();
                return null;
            }
        }

        $member = $dao->getMemberById($form["member_id"]);
        $this->_organizeRowFromForm($form);
        $member->setRow($form);
        $result = $member->update();
        if(!$result){
            $db->rollback();
            return false;
        }

		if ( !is_null($form['password']) and $form['password'] != '') {
			if($member->subscriber_status == 9 and $member->status == 1 and $type == 'itv')
			{
				if ($info)
				{
					// TOCS連携API：会員情報更新(特定項目設定)
					if (!$this->updateTocsCustData($form, $type)) {
						$db->rollback();
						return false;
					}
				}
			}
			// パスワード変更の場合はTOCS反映なし
		} else if ( !is_null($form['email_kari']) and $form['email_kari'] != '') {
			// メールアドレス変更（仮変更）の場合はTOCS反映なし
		} else {

			$form['member_id'] = $member->member_id;	// フロント顧客ID
			$form['tocs_id']  = $member->tocs_id;		// 基幹顧客ID

			if ($info) {

				// TOCS連携API：会員情報更新(特定項目設定)
				if (!$this->updateTocsCustData($form, $type)) {
					$db->rollback();
					return false;
				}
			} else {
				// TOCS連携API：会員情報更新(全項目設定)
				if (!$this->updateTocsCustDataAll($form)) {
					$db->rollback();
					return false;
				}
			}
		}

        $db->commit();
        return true;
    }

	/**
	 * 既存会員専用登録（仮会員・本会員登録を一度に行う）
	 */
	function updateOldRegistMember($form, $type = null) {

		$db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        //$dao =& MemberDAO::getInstance();
        $dao = new MemberDAO;

        // パスワード SHA256 対応(2013/07)
        if($form['password']){
            $form['password'] =hash('SHA256',$form['password']);
        }else{
            unset($form['password']);
        }

        $member = $dao->getMemberById($form["member_id"]);
		if (is_null($member))
        {
            $db->rollback();
            return null;
        }

		if (!is_null($member->tocs_id) && $member->tocs_id != '') {
			// TOCS_IDありの場合はTOCS連携を行わない
		} else {
			// 仮会員登録
			// TOCS連携API：新規登録
            global $PREF_TBL, $TOCS_PLAN_ID_TBL;
			$tocsData = array();
			$tocsData['commonInfo']['frontCustID'] = $member->member_id;																		// フロント顧客ID

			$tocsData['custInfo']['kanyuMmousikomiDate'] = date("Ymd", time());																	// 加入申込日
			$nameKana = (!empty($form['name_sei_kana']) or !empty($form['name_mei_kana'])) ? $form['name_sei_kana'] . '　' . $form['name_mei_kana'] : null;
			$tocsData['custInfo']['custNameKana'] = (!empty($nameKana)) ? $nameKana : 'ミニュウリョク';				                			// お客様氏名カナ
			$name = (!empty($form['name_sei']) and !empty($form['name_mei'])) ? $form['name_sei'] . '　' . $form['name_mei'] : null;
			$tocsData['custInfo']['custName'] = (!empty($name)) ? $name : '未入力';                                                             // お客様氏名
			$tocsData['custInfo']['zipCode'] = ($form["zip1"] != '' && $form["zip2"] != '') ? $form['zip1'] . $form['zip2'] : '0000000';		// 郵便番号
			$tocsData['custInfo']['todofuken'] = isset($PREF_TBL[$form['pref']]) ? $PREF_TBL[$form['pref']] : '';								// 都道府県
			$tocsData['custInfo']['siku'] = $form['address1'];																					// 市区
			$tocsData['custInfo']['tatemonoName'] = $form['address2'];																			// 建物名
			$tocsData['custInfo']['homeTelNum'] = preg_match("/^[0-9]{10}$/", $form["tel"]) ? $form["tel"] : '';								// 固定電話番号
			$tocsData['custInfo']['mobileTelNum'] = preg_match("/^[0-9]{11}$/", $form["tel"]) ? $form["tel"] : '';								// 携帯電話番号
			$tocsData['custInfo']['seibetu'] = $form['sex'];																					// 性別
			$tocsData['custInfo']['birthDate'] = $form['birth_y'] . $form['birth_m'] . $form['birth_d'];										// 生年月日
			$tocsData['custInfo']['mailAddrss1'] = $form['email1'];																				// メールアドレス
//			$tocsData['custInfo']['pfKanyuUmu'] = $form['subscriber_status'] == '1' ? '1' : '0';												// PF加入状況	　0：なし 1：あり
            $tocsData['custInfo']['pfKanyuUmu'] = $this->serviceTypeChangeToTOCS($form);                                                 // PF加入状況 20桁
            $tocsData['custInfo']['yobi1'] = (isset($form['job'])) ? $form['job'] : '0';                                                        // 職業 2桁（可変）
            $tocsData['custInfo']['yobi2'] = (isset($form['favorite_genre'])) ? $form['favorite_genre'] : '000000000000000000000000000000';     // 好みのジャンル 30桁（可変）

			$tocsData['billInfo']['sameKbn'] = '0';																								// 請求先同一区分　0：契約者と同じ
			$tocsData['billInfo']['payMethod'] = '4';																							// 支払い方法　　　4：窓口
			$tocsData['billInfo']['settlementAgentKbn'] = '1';																					// 決済種別　　　　1：GMO

            $plan_id = null;
            // MSC
            if (is_null($type)) {
                $plan_id = '3104000012';
                // MSC以外
            } else {
                $plan_id = $TOCS_PLAN_ID_TBL[$type];
            }

            $tocsData['keiyakuInfo'][0]['planId'] = $plan_id;																				// 申込プラン

			$custEntry = new CustEntry();
			$bssCastID = $custEntry->entry($tocsData);
			if (!$bssCastID) {
				$db->rollback();
				return false;
			}

			// 本会員登録
			// TOCS連携API：会員情報更新
			$tocsData['commonInfo']['bssCustID'] = $bssCastID;									// 基幹顧客ID

			$tocsData['custInfo']['custInfoUpdate'] = '0';										// 更新要否		0：更新しない

			$tocsData['billInfo']['billInfoUpdate'] = '0';										// 更新要否		0：更新しない

			$tocsData['keiyakuInfo'][0]['keiyakuInfoUpdate'] = '1';								// 更新要否		1：更新する
			$tocsData['keiyakuInfo'][0]['keiyakuStatus'] = '4';									// 契約ステータス　4：本登録

			$custReEntry = new CustReEntry();
			$result = $custReEntry->reentry($tocsData);
			if(!$result){
				$db->rollback();
				return false;
			}

			// memberテーブル更新（既存データ更新、新tocs_idをmemberテーブルに反映）
			$form['tocs_id'] = $bssCastID;
		}

		$this->_organizeRowFromForm($form);

		// 更新
		$member->setRow($form);
        $result = $member->update();
        if(!$result){
            $db->rollback();
            return false;
        }

        $db->commit();
        return true;

	}

    /**
     * 退会処理
     *
     */
    function cancelMember($form, $type = null)
    {
        $db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        //$dao =& MemberDAO::getInstance();
        $dao = new MemberDAO;

        $member = $dao->getMemberById($form['member_id']);

        if (is_null($member))
        {
            $db->rollback();
            return null;
        }
        $this->_organizeRowFromForm($form);

        $member->setRow($form);
        $result = $member->update();
        if ( ! $result)
        {
            $db->rollback();
            return false;
        }

		// TOCS連携API：会員情報照会
		$tocsData['commonInfo']['bssCustID'] = $member->tocs_id;		// 基幹顧客ID(*)

		$custInfoGet = new CustInfoGet();
		$result = $custInfoGet->get($tocsData);
		if (!$result) {
			$db->rollback();
			return false;
		}

		$commonInfo = $custInfoGet->getCommonInfo();
		if (empty($commonInfo)) {
			$db->rollback();
			return false;
		}

		// TOCS連携API：解約申込
		$tocsData['commonInfo']['frontCustID'] = $member->member_id;		// フロント顧客ID(*)
		$tocsData['commonInfo']['bssCustID'] = $member->tocs_id;			// 基幹顧客ID(*)

        global $TOCS_PLAN_ID_TBL, $TOCS_PLAN_ID_ITV_LIST_TBL;
        $plan_id = null;
        // MSC
        if (is_null($type)) {
            $plan_id = '3104000012';
            // MSC以外
        } else {
            $plan_id = $TOCS_PLAN_ID_TBL[$type]; // 現状はCATVはプランが一つだけなのでこちらで対応(2017.08)
            if($type == 'itv')
            {
                // 契約情報から一致するITVプランを取得
                $keiyakuInfo = $custInfoGet->getKeiyakuInfo();
                if (!$keiyakuInfo) {
                    $db->rollback();
                    return false;
                }
                $checkPlanID = $this->checkPlanID($keiyakuInfo, $TOCS_PLAN_ID_ITV_LIST_TBL);
                if($checkPlanID['result']) {
                    $plan_id = $checkPlanID['planID'];
                }
            }
        }

		$tocsData['keiyakuInfo'][0]['planId'] = $plan_id;				// 申込プラン

		$custCancel = new CustCancel();
		$result = $custCancel->cancel($tocsData);
		if(!$result){
			$db->rollback();
			return false;
		}

		$commonInfo = $custCancel->getCommonInfo();
		if(empty($commonInfo)){
			$db->rollback();
			return false;
		}

        $db->commit();
        return true;
    }


    /**
     * 削除
     *
     */
    function deleteMember($form){

        $db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        $member = new Member();
        $member->setRow($form);
        //$result = $member->delete();
        $result = $member->update();

        if(!$result){
            $db->rollback();
            return false;
        }

        $db->commit();
        return true;
    }

	/**
	 * 管理画面用登録処理（仮会員・本会員登録を一度に行う）
	 */
	function registMemberAdmin($form, $type = null) {

		$db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        //$dao =& MemberDAO::getInstance();
        $dao = new MemberDAO;

        // パスワード SHA256 対応(2013/07)
        if($form['password']){
            $form['password'] =hash('SHA256',$form['password']);
        }else{
            unset($form['password']);
        }

        $member = $dao->getMemberById($form["member_id"]);
		if (is_null($member)) {
			// 新規登録
			$registMember = new Member();
			$row = $this->_organizeRowFromForm($form);

			$registMember->setRow($row);
			$result = $registMember->insert();
			if( ! $result) {
				$db->rollback();
				return false;
			}
			// 登録会員情報取得
            if ($form['status'] == '1')
            {
                $member = $dao->getMemberByEmail($registMember->email1);
            }
            else if ($form['status'] == '2')
            {
                $member = $dao->getOldMemberByEmail($registMember->email1);
            }

			if(!$member){
				$db->rollback();
				return false;
			}
		}

        // 仮会員登録
        // TOCS連携API：新規登録
        global $PREF_TBL, $TOCS_PLAN_ID_TBL;
        $tocsData = array();
        $tocsData['commonInfo']['frontCustID'] = $member->member_id;																		// フロント顧客ID

        $tocsData['custInfo']['kanyuMmousikomiDate'] = date("Ymd", time());																	// 加入申込日
        $nameKana = (!empty($form['name_sei_kana']) or !empty($form['name_mei_kana'])) ? $form['name_sei_kana'] . '　' . $form['name_mei_kana'] : null;
        $tocsData['custInfo']['custNameKana'] = (!empty($nameKana)) ? $nameKana : 'ミニュウリョク';				                			// お客様氏名カナ
        $name = (!empty($form['name_sei']) and !empty($form['name_mei'])) ? $form['name_sei'] . '　' . $form['name_mei'] : null;
        $tocsData['custInfo']['custName'] = (!empty($name)) ? $name : '未入力';                                                             // お客様氏名
        $tocsData['custInfo']['zipCode'] = ($form["zip1"] != '' && $form["zip2"] != '') ? $form['zip1'] . $form['zip2'] : '0000000';		// 郵便番号
        $tocsData['custInfo']['todofuken'] = isset($PREF_TBL[$form['pref']]) ? $PREF_TBL[$form['pref']] : '';								// 都道府県
        $tocsData['custInfo']['siku'] = $form['address1'];																					// 市区
        $tocsData['custInfo']['tatemonoName'] = $form['address2'];																			// 建物名
        $tocsData['custInfo']['homeTelNum'] = preg_match("/^[0-9]{10}$/", $form["tel"]) ? $form["tel"] : '';								// 固定電話番号
        $tocsData['custInfo']['mobileTelNum'] = preg_match("/^[0-9]{11}$/", $form["tel"]) ? $form["tel"] : '';								// 携帯電話番号
        $tocsData['custInfo']['seibetu'] = $form['sex'];																					// 性別
        $tocsData['custInfo']['birthDate'] = $form['birth_y'] . $form['birth_m'] . $form['birth_d'];										// 生年月日
        $tocsData['custInfo']['mailAddrss1'] = $form['email1'];																				// メールアドレス
//        $tocsData['custInfo']['pfKanyuUmu'] = $form['subscriber_status'] == '1' ? '1' : '0';												// PF加入状況	　0：なし 1：あり
        $tocsData['custInfo']['pfKanyuUmu'] = $this->serviceTypeChangeToTOCS($form);                                                 // PF加入状況 20桁
        $tocsData['custInfo']['yobi1'] = (isset($form['job'])) ? $form['job'] : '0';                                                        // 職業 2桁（可変）
        $tocsData['custInfo']['yobi2'] = (isset($form['favorite_genre'])) ? $form['favorite_genre'] : '000000000000000000000000000000';     // 好みのジャンル 30桁（可変）

        $tocsData['billInfo']['sameKbn'] = '0';																								// 請求先同一区分　0：契約者と同じ
        $tocsData['billInfo']['payMethod'] = '4';																							// 支払い方法　　　4：窓口
        $tocsData['billInfo']['settlementAgentKbn'] = '1';																					// 決済種別　　　　1：GMO

        $plan_id = null;
        // MSC
        if (is_null($type)) {
            $plan_id = '3104000012';
            // MSC以外
        } else {
            $plan_id = $TOCS_PLAN_ID_TBL[$type];
        }

        $tocsData['keiyakuInfo'][0]['planId'] = $plan_id;																				// 申込プラン

        $custEntry = new CustEntry();
        $bssCastID = $custEntry->entry($tocsData);
        if (!$bssCastID) {
            $db->rollback();
            return false;
        }

        // 本会員登録
        // TOCS連携API：会員情報更新
        $tocsData['commonInfo']['bssCustID'] = $bssCastID;									// 基幹顧客ID

        $tocsData['custInfo']['custInfoUpdate'] = '0';										// 更新要否		0：更新しない

        $tocsData['billInfo']['billInfoUpdate'] = '0';										// 更新要否		0：更新しない

        $tocsData['keiyakuInfo'][0]['keiyakuInfoUpdate'] = '1';								// 更新要否		1：更新する
        $tocsData['keiyakuInfo'][0]['keiyakuStatus'] = '4';									// 契約ステータス　4：本登録

        $custReEntry = new CustReEntry();
        $result = $custReEntry->reentry($tocsData);

        if(!$result){
            $db->rollback();
            return false;
        }

        // memberテーブル更新（既存データ更新、新tocs_idをmemberテーブルに反映）
        $form['tocs_id'] = $bssCastID;

		$this->_organizeRowFromForm($form);

		// 更新
		$member->setRow($form);
        $result = $member->update();
        if(!$result){
            $db->rollback();
            return false;
        }

        $db->commit();
        return true;

	}

	/**
     * 変更
     *
     */
    function updateMemberAdmin($form){

        $db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        //$dao =& MemberDAO::getInstance();
        $dao = new MemberDAO;

        // パスワード SHA256 対応(2013/07)
        if($form['status'] == '1') {
            if ($form['password'])
            {
                $form['password'] =hash('SHA256',$form['password']);
            }
            else
            {
                unset($form['password']);
            }
        }

        $member = $dao->getMemberById($form["member_id"]);
        $this->_organizeRowFromForm($form);
        $member->setRow($form);
        $result = $member->update();
        if(!$result){
            $db->rollback();
            return false;
        }

		$form['member_id'] = $member->member_id;	// フロント顧客ID
		$form['tocs_id']  = $member->tocs_id;		// 基幹顧客ID

		// TOCS連携API：会員情報更新(特定項目設定)
		if (!$this->updateTocsCustData($form)) {
			$db->rollback();
			return false;
		}

        $db->commit();
        return true;
    }

    /**
     * ログイン
     *
     * ログイン確認・ログイン処理＋最終ログイン日時更新
     */
    function authenticate ($login_id, $password, $auto_login=null) {

        $db = $this->getDB();
        $db->connect();
        $db->begin_transaction();
        $db->lock($db->TABLE_MEMBER, "EXCLUSIVE");

        // ログイン
        //$memberdao = MemberDAO::getInstance();
        $memberdao = new MemberDAO;

        $member = $memberdao->authenticate($login_id, $password, $auto_login);
        if (!$member) {
            $db->rollback();
            return false;
        }

        if(!fetch($_SESSION, "last_login_time")){
            $_SESSION["last_login_time"] = $member->last_login_time;
        }

        // ログイン日時更新
        $ret = $member->updateLastLogin($auto_login);
        if (!$ret) {
            $db->rollback();
            return false;
        }

        $db->commit();
        return $member->member_id;
    }

	/**
	 * TOCS連携API：会員情報更新(照会＋キャスト情報更新)
	 * ※APIに必要な項目を一部$formに設定してから実行するパターン
	 *
	 * 使用
	 * ・メールアドレス変更（本変更）
	 */
	function updateTocsCustData($form, $type = null) {

		// TOCS連携API：会員情報照会
		$tocsData['commonInfo']['bssCustID'] = $form['tocs_id'];		// 基幹顧客ID(*)

		$custInfoGet = new CustInfoGet();
		$result = $custInfoGet->get($tocsData);
		if (!$result) {
			return false;
		}

		$commonInfo = $custInfoGet->getCommonInfo();
		if (empty($commonInfo)) {
			return false;
		}

		$custInfo = $custInfoGet->getCustInfo();
		if (empty($custInfo)) {
			return false;
		}

        // custInfoをTOCS用に変換
        $custInfo = $this->custInfoChangeToTOCSSize($custInfo, $form);


        // ログ出力
        //TS_LOG::DEBUG('pfKanyuUmu test before pfKanyuUmu ' . $custInfo['pfKanyuUmu'] . ' memberID ' . $form['member_id'] . ' tocsID ' . $form['tocs_id']);
		/*
		 * TOCS　PF項目を新たに設定
		 * MSCプレ→ITV会員になる場合用に追加(2017.11.09)
		 */
		$custInfo['pfKanyuUmu'] = (isset($form['service_type']) && $form['service_type'] == 8) ? $this->serviceTypeChangeToTOCS($form) : $custInfo['pfKanyuUmu'];

        // ログ出力
        //TS_LOG::DEBUG('pfKanyuUmu test after pfKanyuUmu ' . $custInfo['pfKanyuUmu'] . ' memberID ' . $form['member_id'] . ' tocsID ' . $form['tocs_id']);


		// 更新に必須な項目を設定する
		$tocsData['commonInfo']['frontCustID'] = $form['member_id'];								// フロント顧客ID
		$tocsData['commonInfo']['bssCustID'] = $form['tocs_id'];									// 基幹顧客ID

		$tocsData['custInfo']['custInfoUpdate'] = '1';												// 更新要否		1：更新する
		$tocsData['custInfo']['kanyuMmousikomiDate'] = $custInfo['kanyuMmousikomiDate'];			// 加入申込日
		$tocsData['custInfo']['custNameKana'] = $custInfo['custNameKana'];							// お客様氏名カナ
		$tocsData['custInfo']['custName'] = $custInfo['custName'];									// お客様氏名
		$tocsData['custInfo']['zipCode'] = $custInfo['zipCode'];									// 郵便番号
//		$tocsData['custInfo']['pfKanyuUmu'] = $custInfo['pfKanyuUmu'];								// PF加入状況	　0：なし 1：あり
		$tocsData['custInfo']['mailAddrss1'] = $custInfo['mailAddrss1'];							// メールアドレス
		$tocsData['custInfo']['todofuken'] = $custInfo['todofuken'];								// 都道府県
		$tocsData['custInfo']['siku'] = $custInfo['siku'];											// 市区
		$tocsData['custInfo']['tatemonoName'] = $custInfo['tatemonoName'];							// 建物名
		$tocsData['custInfo']['homeTelNum'] = $custInfo['homeTelNum'];								// 固定電話番号
		$tocsData['custInfo']['mobileTelNum'] = $custInfo['mobileTelNum'];							// 携帯電話番号
		$tocsData['custInfo']['seibetu'] = $custInfo['seibetu'];									// 性別
		$tocsData['custInfo']['birthDate'] = $custInfo['birthDate'];								// 生年月日
        $tocsData['custInfo']['pfKanyuUmu'] = $custInfo['pfKanyuUmu'];						// PF加入状況
        $tocsData['custInfo']['yobi1'] = $custInfo['yobi1'];						        // 職業
        $tocsData['custInfo']['yobi2'] = $custInfo['yobi2'];						        // 好みのジャンル

		$tocsData['billInfo']['billInfoUpdate'] = '0';												// 更新要否		0：更新しない
		$tocsData['billInfo']['sameKbn'] = '';														// 請求先同一区分　0：契約者と同じ
		$tocsData['billInfo']['payMethod'] = '';													// 支払い方法　　　4：窓口
		$tocsData['billInfo']['settlementAgentKbn'] = '';											// 決済種別　　　　1：GMO

		$tocsData['keiyakuInfo'][0]['keiyakuInfoUpdate'] = '0';										// 更新要否		0：更新しない
		$tocsData['keiyakuInfo'][0]['keiyakuStatus'] = '';											// 契約ステータス

		// フォームの値を設定する
		global $PREF_TBL;
		if (isset($form['name_sei_kana']) or isset($form['name_mei_kana'])) {
            $nameKana = (!empty($form['name_sei_kana']) and !empty($form['name_mei_kana'])) ? $form['name_sei_kana'] . '　' . $form['name_mei_kana'] : null;
            $tocsData['custInfo']['custNameKana'] = (!empty($nameKana)) ? $nameKana : 'ミニュウリョク';				                			// お客様氏名カナ
		}
		if (isset($form['name_sei']) or isset($form['name_mei'])) {
            $name = (!empty($form['name_sei']) and !empty($form['name_mei'])) ? $form['name_sei'] . '　' . $form['name_mei'] : null;
            $tocsData['custInfo']['custName'] = (!empty($name)) ? $name : '未入力';                                                             // お客様氏名
		}
		if (isset($form["zip1"]) and isset($form["zip2"])) {
			$tocsData['custInfo']['zipCode'] = ($form["zip1"] != '' && $form["zip2"] != '') ? $form['zip1'] . $form['zip2'] : '0000000';	// 郵便番号
		}
		if (isset($form['pref'])) {
			$tocsData['custInfo']['todofuken'] = isset($PREF_TBL[$form['pref']]) ? $PREF_TBL[$form['pref']] : '';							// 都道府県
		}
		if (isset($form['address1'])) {
			$tocsData['custInfo']['siku'] = $form['address1'];																				// 市区
		}
		if (isset( $form['address2'])) {
			$tocsData['custInfo']['tatemonoName'] = $form['address2'];																		// 建物名
		}
		if  (isset($form["tel"])) {
			$tocsData['custInfo']['homeTelNum'] = preg_match("/^[0-9]{10}$/", $form["tel"]) ? $form["tel"] : '';							// 固定電話番号
			$tocsData['custInfo']['mobileTelNum'] = preg_match("/^[0-9]{11}$/", $form["tel"]) ? $form["tel"] : '';							// 携帯電話番号
		}
		if (isset($form['sex'])) {
			$tocsData['custInfo']['seibetu'] = $form['sex'];																				// 性別
		}
		if (isset($form['birth_y']) and isset($form['birth_m']) and isset($form['birth_d'])) {
			$tocsData['custInfo']['birthDate'] = $form['birth_y'] . $form['birth_m'] . $form['birth_d'];									// 生年月日
		}
		if (isset($form['email1'])) {
			$tocsData['custInfo']['mailAddrss1'] = $form['email1'];																			// メールアドレス
		}

		// 20171001 インターネットTV用に追加
		if($type == 'itv')
        {

            $tocsData['custInfo']['custInfoUpdate'] = (isset($form['custInfoUpdate'])) ? $form['custInfoUpdate'] : '0';
            $tocsData['billInfo']['billInfoUpdate'] = (isset($form['billInfoUpdate'])) ? $form['billInfoUpdate'] : '0';																					// 更新要否		0：更新しない
            $tocsData['billInfo']['sameKbn'] = (isset($form['sameKbn'])) ? $form['sameKbn'] : '0';																							// 請求先同一区分　0：契約者と同じ
            $tocsData['billInfo']['payMethod'] = (isset($form['payMethod'])) ? $form['payMethod'] : '4';																						// 支払い方法　　　4：窓口
            $tocsData['billInfo']['settlementAgentKbn'] = '1';																				// 決済種別　　　　1：GMO
            $tocsData['billInfo']['cardMemberNum'] = (isset($form['cardMemberNum'])) ? $form['cardMemberNum'] : '';
            $tocsData['billInfo']['creditCardNo4'] = (isset($form['creditCardNo4'])) ? $form['creditCardNo4'] : '';
            $tocsData['billInfo']['creditCardLimit'] = (isset($form['creditCardLimit'])) ? $form['creditCardLimit'] : '';

        }

		$custReEntry = new CustReEntry();
		$result = $custReEntry->reentry($tocsData);
		if (!$result) {
			return false;
		}

		$commonInfo = $custReEntry->getCommonInfo();
		if (empty($commonInfo)) {
			return false;
		}

		return $commonInfo;
	}

	/**
	 * TOCS連携API：会員情報更新(キャスト情報更新)
	 * ※APIに必要な項目を全て$formに設定してから実行するパターン
	 *
	 * 使用
	 * ・会員情報変更
	 */
	function updateTocsCustDataAll($form) {

		// TOCS連携API：会員情報更新
		global $PREF_TBL;
		$tocsData['commonInfo']['frontCustID'] = $form['member_id'];																	// フロント顧客ID
		$tocsData['commonInfo']['bssCustID'] = $form['tocs_id'];																		// 基幹顧客ID

		$tocsData['custInfo']['custInfoUpdate'] = '1';																					// 更新要否		1：更新する
		$tocsData['custInfo']['kanyuMmousikomiDate'] = $form['tocs_kanyu_date'];														// 加入申込日
        $nameKana = (!empty($form['name_sei_kana']) and !empty($form['name_mei_kana'])) ? $form['name_sei_kana'] . '　' . $form['name_mei_kana'] : null;
        $tocsData['custInfo']['custNameKana'] = (!empty($nameKana)) ? $nameKana : 'ミニュウリョク';				                			// お客様氏名カナ
        $name = (!empty($form['name_sei']) and !empty($form['name_mei'])) ? $form['name_sei'] . '　' . $form['name_mei'] : null;
        $tocsData['custInfo']['custName'] = (!empty($name)) ? $name : '未入力';                                                             // お客様氏名
		$tocsData['custInfo']['zipCode'] = ($form["zip1"] != '' && $form["zip2"] != '') ? $form['zip1'] . $form['zip2'] : '0000000';	// 郵便番号
		$tocsData['custInfo']['todofuken'] = isset($PREF_TBL[$form['pref']]) ? $PREF_TBL[$form['pref']] : '';							// 都道府県
		$tocsData['custInfo']['siku'] = $form['address1'];																				// 市区
		$tocsData['custInfo']['tatemonoName'] = $form['address2'];																		// 建物名
		$tocsData['custInfo']['homeTelNum'] = preg_match("/^[0-9]{10}$/", $form["tel"]) ? $form["tel"] : '';							// 固定電話番号
		$tocsData['custInfo']['mobileTelNum'] = preg_match("/^[0-9]{11}$/", $form["tel"]) ? $form["tel"] : '';							// 携帯電話番号
		$tocsData['custInfo']['seibetu'] = $form['sex'];																				// 性別
		$tocsData['custInfo']['birthDate'] = $form['birth_y'] . $form['birth_m'] . $form['birth_d'];									// 生年月日
		$tocsData['custInfo']['mailAddrss1'] = $form['email1'];																			// メールアドレス
//		$tocsData['custInfo']['pfKanyuUmu'] = $form['subscriber_status'] == '1' ? '1' : '0';											// PF加入状況	　0：なし 1：あり
        $tocsData['custInfo']['pfKanyuUmu'] = $this->serviceTypeChangeToTOCS($form);                                                 // PF加入状況 20桁
        $tocsData['custInfo']['yobi1'] = (isset($form['job'])) ? $form['job'] : '0';                                                        // 職業 2桁（可変）
        $tocsData['custInfo']['yobi2'] = (isset($form['favorite_genre'])) ? $form['favorite_genre'] : '000000000000000000000000000000';     // 好みのジャンル 30桁（可変）

		$tocsData['billInfo']['billInfoUpdate'] = '0';																					// 更新要否		0：更新しない
		$tocsData['billInfo']['sameKbn'] = '';																							// 請求先同一区分　0：契約者と同じ
		$tocsData['billInfo']['payMethod'] = '';																						// 支払い方法　　　4：窓口
		$tocsData['billInfo']['settlementAgentKbn'] = '';																				// 決済種別　　　　1：GMO

		$tocsData['keiyakuInfo'][0]['keiyakuInfoUpdate'] = '0';																			// 更新要否		0：更新しない
		$tocsData['keiyakuInfo'][0]['keiyakuStatus'] = '';																				// 契約ステータス

		$custReEntry = new CustReEntry();
		$result = $custReEntry->reentry($tocsData);
		if (!$result) {
			return false;
		}

		$commonInfo = $custReEntry->getCommonInfo();
		if (empty($commonInfo)) {
			return false;
		}

		return $commonInfo;
	}


    /**
     * TOCS連携API：追加申込クレジット支払いプラン
     * ※APIに必要な項目を一部$formに設定してから実行するパターン
     *
     */
    function setCustInfoAdd($form, $type = null) {

        // TOCS連携API：会員情報照会
        $tocsData['commonInfo']['bssCustID'] = $form['tocs_id'];		// 基幹顧客ID(*)

        $custInfoGet = new CustInfoGet();
        $result = $custInfoGet->get($tocsData);
        if (!$result) {
            return false;
        }

        $commonInfo = $custInfoGet->getCommonInfo();
        if (empty($commonInfo)) {
            return false;
        }

        $custInfo = $custInfoGet->getCustInfo();
        if (empty($custInfo)) {
            return false;
        }

        // custInfoをTOCS用に変換
        $custInfo = $this->custInfoChangeToTOCSSize($custInfo, $form);

        // 更新に必須な項目を設定する
        $tocsData['commonInfo']['frontCustID'] = $form['member_id'];								// フロント顧客ID
        $tocsData['commonInfo']['bssCustID'] = $form['tocs_id'];									// 基幹顧客ID
        $tocsData['commonInfo']['yobi1'] = '';
        $tocsData['commonInfo']['yobi2'] = '';
        $tocsData['commonInfo']['yobi3'] = '';
        $tocsData['commonInfo']['yobi4'] = '';
        $tocsData['commonInfo']['yobi5'] = '';

        $tocsData['custInfo']['custInfoUpdate'] = '0';												// 更新要否		1：更新する
        $tocsData['custInfo']['kanyuMmousikomiDate'] = $custInfo['kanyuMmousikomiDate'];			// 加入申込日
        $tocsData['custInfo']['custNameKana'] = $custInfo['custNameKana'];							// お客様氏名カナ
        $tocsData['custInfo']['custName'] = $custInfo['custName'];									// お客様氏名
        $tocsData['custInfo']['zipCode'] = $custInfo['zipCode'];									// 郵便番号
        $tocsData['custInfo']['mailAddrss1'] = $custInfo['mailAddrss1'];							// メールアドレス
        $tocsData['custInfo']['todofuken'] = $custInfo['todofuken'];								// 都道府県
        $tocsData['custInfo']['siku'] = $custInfo['siku'];											// 市区
        $tocsData['custInfo']['tatemonoName'] = $custInfo['tatemonoName'];							// 建物名
        $tocsData['custInfo']['homeTelNum'] = $custInfo['homeTelNum'];								// 固定電話番号
        $tocsData['custInfo']['mobileTelNum'] = $custInfo['mobileTelNum'];							// 携帯電話番号
        $tocsData['custInfo']['seibetu'] = $custInfo['seibetu'];									// 性別
        $tocsData['custInfo']['birthDate'] = $custInfo['birthDate'];								// 生年月日
        $tocsData['custInfo']['pfKanyuUmu'] = $custInfo['pfKanyuUmu'];						// PF加入状況
        $tocsData['custInfo']['yobi1'] = $custInfo['yobi1'];						        // 職業
        $tocsData['custInfo']['yobi2'] = $custInfo['yobi2'];						        // 好みのジャンル

//        $tocsData['billInfo']['billInfoUpdate'] = '1';												// 更新要否		0：更新しない
//        $tocsData['billInfo']['sameKbn'] = '';														// 請求先同一区分　0：契約者と同じ
//        $tocsData['billInfo']['payMethod'] = '';													// 支払い方法　　　4：窓口
//        $tocsData['billInfo']['settlementAgentKbn'] = '';											// 決済種別　　　　1：GMO

        global $TOCS_PLAN_ID_TBL;
        $plan_id = null;
        // MSC
        if (is_null($type)) {
            $plan_id = '3104000012';
            // MSC以外
        } else {
            $plan_id = $TOCS_PLAN_ID_TBL[$type];
        }

        $tocsData['keiyakuInfo'][0]['planId'] = $plan_id;																				// 申込プラン
        $tocsData['keiyakuInfo'][0]['yobi1'] = '';
        $tocsData['keiyakuInfo'][0]['yobi2'] = '';
        $tocsData['keiyakuInfo'][0]['yobi3'] = '';
        $tocsData['keiyakuInfo'][0]['yobi4'] = (isset($form['yobi4'])) ? $form['yobi4'] : 3;																				// 契約ステータス
        $tocsData['keiyakuInfo'][0]['yobi5'] = '';

        $custAdd = new CustAdd();
        $result = $custAdd->add($tocsData);
        if (!$result) {
            return false;
        }

        return true;
    }


    /**
     * フォームから入力された値をDBに登録できる形に補完する
     *
     * フォームでは一部のカラムが分割されていたりするため、連結したり
     * 情報を補ってやる必要がある
     */
    function &_organizeRowFromForm(&$form) {
//        if (array_key_exists("birth", $form)){
//            if ($form["birth"] != '') {
//                $form["birth"] =
//                    //date("Y/M/d", mktime(0,0,0,1,1,$form["birth"]));
//                    $form["birth"].'/1/1';
//            }
//        }
//

        if (array_key_exists("tel1", $form) ||
            array_key_exists("tel2", $form) ||
            array_key_exists("tel3", $form)) {
            $form["tel"] = $form["tel1"].'-'.$form["tel2"].'-'.$form["tel3"];
        }

        if (array_key_exists("sei", $form) ||
            array_key_exists("mei", $form)) {
                if($form["sei"] != '' && $form["mei"] != '') {
                    $form["sei"] = str_replace(' ','',$form["sei"]);
                    $form["mei"] = str_replace(' ','',$form["mei"]);
                    $form["name"] = $form["sei"].$form["mei"];
                }
        }
        if (array_key_exists("zip1", $form) ||
            array_key_exists("zip2", $form)) {
            $form["zip"] = $form["zip1"].$form["zip2"];
        }

        if (array_key_exists("email1_1", $form) ||
            array_key_exists("email1_2", $form)) {
            $form["email1"] = $form["email1_1"]."@".$form["email1_2"];
        }

        if (array_key_exists("service_user_id", $form))
        {
            if (is_array($form['service_user_id']))
            {
                $form['service_user_id'] = implode('', $form['service_user_id']);
            }
        }

        if (array_key_exists("email2_1", $form) ||
            array_key_exists("email2_2", $form)) {
            if($form['email2_1'] == '' || $form['email2_2'] == '') {
                $form["email2"] = '';
            } else {
                $form["email2"] = $form["email2_1"]."@".$form["email2_2"];
            }
        }

        if (array_key_exists("ic_card_id1", $form) ||
            array_key_exists("ic_card_id2", $form) ||
            array_key_exists("ic_card_id3", $form) ||
            array_key_exists("ic_card_id4", $form)) {
            $form["ic_card_id"] = $form["ic_card_id1"].$form["ic_card_id2"].
                                    $form["ic_card_id3"].$form["ic_card_id4"];
        }

        if (array_key_exists("bcas_card_id1", $form) ||
            array_key_exists("bcas_card_id2", $form) ||
            array_key_exists("bcas_card_id3", $form) ||
            array_key_exists("bcas_card_id4", $form) ||
            array_key_exists("bcas_card_id5", $form)) {
            $form["bcas_card_id"] = $form["bcas_card_id1"].$form["bcas_card_id2"].
              $form["bcas_card_id3"].$form["bcas_card_id4"].$form["bcas_card_id5"];
        }


        return $form;
    }

    function serviceTypeChangeToTOCS($form)
    {
        global $TOCS_PF_UP_KANYU_TYPE_TBL;

        // 桁数
        $max = 20;
        // 渡す文字列
        $pf_str = '';
        // 登録されているサービス分ループ
        foreach ($TOCS_PF_UP_KANYU_TYPE_TBL as $id => $service){
            // PF認証されているものと照合（0：なし、１：あり）
            if(isset($form['service_type']))
            {
                $pf_str .= ($form['service_type'] == $id) ? 1 : 0;
            }
        }
        // 残りの0埋め計算
        $loop = $max - strlen($pf_str);
        // 0埋め
        for ($i=0;$i<$loop;$i++){
            $pf_str .= 0;
        }
        return $pf_str;
    }

    function custInfoChangeToTOCSSize($custInfo, $form)
    {
        if(isset($custInfo)){

            // 201709 スカパーバッチの場合に加入を解除する
            if (isset($form['pf_cancel']) && $form['pf_cancel'] == '1')
            {
                $custInfo['pfKanyuUmu'] = '0';
            }

            // 加入サービス
            if(isset($custInfo['pfKanyuUmu'])){
                // 1桁 or nullは20桁に変更
                if ((($custInfo['pfKanyuUmu'] == null || $custInfo['pfKanyuUmu'] == '') || $this->eraseSpace($custInfo['pfKanyuUmu']) == '0')) {
                    $custInfo['pfKanyuUmu'] = '00000000000000000000';
                } elseif ($this->eraseSpace($custInfo['pfKanyuUmu']) == '1') {
                    // 加入サービスが取得できない場合は会員情報から取得する
                    if(!isset($form['service_type']))
                    {
                        $memberdao = new MemberDAO;
                        $member = $memberdao->getMemberByTocsId($form['tocs_id']);
                        if (!member) return false;

                        $form['service_type'] = $member->service_type;
                    }
                    // 正しい加入状況に変更
                    $custInfo['pfKanyuUmu'] = $this->serviceTypeChangeToTOCS($form);
                }
            }
            // 1桁 or nullは20桁に変更
            if(isset($custInfo['yobi1']) && ($custInfo['yobi1'] == null || $this->eraseSpace($custInfo['yobi1'] == '')))
            {
                $custInfo['yobi1'] = '0';
            }
            // 1桁 or nullは20桁に変更
            if(isset($custInfo['yobi2']) && ($custInfo['yobi2'] == null || $this->eraseSpace($custInfo['yobi2'] == '')))
            {
                $custInfo['yobi2'] = '000000000000000000000000000000';
            }

        }

        return $custInfo;
    }

    function eraseSpace($data)
    {
        $data  = preg_replace("/( |　)/", "", $data);
        return $data;
    }

    function checkPlanID($keiyakuInfo, $const)
    {
        // $keiyakuInfoが複数ある場合を考慮してforeachで順次見ていく
        $planID = 0;
        foreach ($keiyakuInfo as $keiyaku){
            foreach ($const as $cont)
            {
                if (($keiyaku['planId'] == $cont) && ($keiyaku['svcStatus'] == 4) && ($keiyaku['useStopDate'] == '99991231')) $planID = $cont;
            }
        }
        $checkPlanID['planID'] = $planID;
        if($planID != 0)
        {
            $checkPlanID['result'] = true;
            return $checkPlanID;
        }
        $checkPlanID['result'] = false;
        return $checkPlanID;
    }

}
?>
