<?php
/**
* The contents of this file are subject to the Common Public Attribution License
* Version 1.0 (the "License"); you may not use this file except in compliance with
* the License. You may obtain a copy of the License at
* http://www.6vcommerce.ca/CPAL.html. The License is based on the
* Mozilla Public License Version 1.1 but Sections 14 and 15 have been added to cover
* use of software over a computer network and provide for limited attribution for
* the Original Developer. In addition, Exhibit A has been modified to be consistent
* with Exhibit B.
*
* Software distributed under the License is distributed on an "AS IS" basis, WITHOUT
* WARRANTY OF ANY KIND, either express or implied. See the License for the specific
* language governing rights and limitations under the License.
*
* The Original Code is 6vCommerce MerchantLink Module.
*
* The Initial Developer of the Original Code is 6vCommerce.
* The Original Developer is the Initial Developer.
*
* All portions of the code written by 6vCommerce are Copyright (C) 6vCommerce.
* All Rights Reserved.
*
* Contributor(s):
*
* @link      http://www.6vcommerce.ca
* @copyright (C) 6vCommerce
*/

/**
 * This class extends the oxPaymentGateway core class and should be configured in the
 * module extension settings of admin as given below.
 *
 * 		oxpaymentgateway => v6c_merchantlink/v6c_mlpaymentgateway
 */
class v6c_mlPaymentGateway extends v6c_mlPaymentGateway_parent
{
	/////////////////////// OVERRIDES ////////////////////////////


	/////////////////////// EXTENSIONS ////////////////////////////

    /**
    * Add support for integrated linked merchent gateways.
    *
    * @param double $dAmount Goods amount
    * @param object &$oOrder User ordering object
    *
    * @return bool
    */
    public function executePayment( $dAmount, & $oOrder )
    {
        $bRes = parent::executePayment($dAmount, $oOrder);

        // Thanks to extension v6c_ctrl_mlOrder::_executePayment, basket will be available with this call
        // Otherwise basket would only be available after a call to oxOrder::_sendOrderByEmail
        // TODO: Uncomment this line, update ctrl_mlorder and ctrl_mlpayment to head revision, and add support
        // for calculation costs and creating DESC field in the same way as done when setting up paypalexpress
        //$oBasket = $oOrder->getBasket();

        // TODO: chenge this to a if method_exists call _v6c_paypalxpr_executePayment
        // Check for PayPal Express payment
        $oPayment = oxNew('oxPayment');
        if ($oPayment->load($this->_oPaymentInfo->oxuserpayments__oxpaymentsid->value) && strcmp($oPayment->oxpayments__v6link->value, 'v6c_paypalxpr') == 0)
        {
            $bRes = true;
            $this->_blActive = true;
            // Make sure token is available
            $sToken = oxSession::getVar('v6c_sPaypalXprTkn');
            if (!isset($sToken))
            {
                $this->_iLastErrorNo = __LINE__;
                $this->_sLastError = 'Paypal NVP token not available.';
                $bRes = false;
            }

            if ($bRes)
            {
                // Server info
                $sServer = $this->_v6cIsTestMode() ? self::V6C_ML_PAYPAL_NVP_TSTSVR : self::V6C_ML_PAYPAL_NVP_SVR;
                $sScript = self::V6C_ML_PAYPAL_NVP_ADR;
                // Generate GetExpressCheckoutDetails request
                $sNvpVer = self::V6C_ML_PAYPAL_NVP_VER; //$this->getConfig()->getConfigParam('v6c_sPayPalNvpVer');
                $sBaseRequest = 'VERSION='.urlencode( number_format( doubleval($sNvpVer),1 ) );
                $sBaseRequest .= '&PWD='.urlencode($this->getConfig()->getConfigParam('v6c_sPayPalNvpPwd')).'&USER='.urlencode($this->getConfig()->getConfigParam('v6c_sPayPalNvpUsr'));
                $sBaseRequest .= '&SIGNATURE='.urlencode($this->getConfig()->getConfigParam('v6c_sPayPalNvpSig')).'&TOKEN='.urlencode($sToken);
                $sRequest = $sBaseRequest.'&METHOD=GetExpressCheckoutDetails';
                // Make request and verify response
                $aResponse = $this->_v6cPayPalNvpRequest($sServer, $sScript, $sRequest);
                if ($aResponse === false || !array_key_exists('ACK', $aResponse) || strcasecmp($aResponse['ACK'], 'Success') != 0)
                {
                    $this->_iLastErrorNo = __LINE__;
                    $this->_sLastError = 'Paypal response to GetExpressCheckoutDetails was unsuccessful.';
                    $bRes = false;
                } elseif (!isset($aResponse['TOKEN']) || strcmp($aResponse['TOKEN'], $sToken) != 0)
                {
                    $this->_iLastErrorNo = __LINE__;
                    $this->_sLastError = 'Paypal response token from GetExpressCheckoutDetails doesn\'t match session value.';
                    $bRes = false;
                } elseif ( (!isset($aResponse['PAYERID']) && $this->_v6c_paypalxpr_GetPayerId() == null) || !isset($aResponse['AMT']) || !isset($aResponse['CURRENCYCODE']) )
                // PayerID can come from query string or array
                {
                    $this->_iLastErrorNo = __LINE__;
                    $this->_sLastError = 'Paypal response from GetExpressCheckoutDetails missing values required to complete transaction.';
                    $bRes = false;
                }
            }

            // Complete immediate/up-front payments with DoExpressCheckoutPayment.  Not applicable if only services are being chekced out.
            if ($bRes && $aResponse['AMT'] > 0 )
            {
                // Prep request string
                $sPayerId = isset($aResponse['PAYERID']) ? $aResponse['PAYERID'] : $this->_v6c_paypalxpr_GetPayerId();
                $sRequest = $sBaseRequest.'&METHOD=DoExpressCheckoutPayment';
                $sRequest .= '&PAYERID='.urlencode($sPayerId).'&PAYMENTACTION=Sale';
                $sRequest .= '&AMT='.$aResponse['AMT'].'&CURRENCYCODE='.$aResponse['CURRENCYCODE'];
                // Make call and verify response
                $aResponse = $this->_v6cPayPalNvpRequest($sServer, $sScript, $sRequest);
                if ($aResponse === false || !array_key_exists('ACK', $aResponse) || strcasecmp($aResponse['ACK'], 'Success') != 0)
                {
                    $this->_iLastErrorNo = __LINE__;
                    $this->_sLastError = 'Paypal response to DoExpressCheckoutPayment was unsuccessful.';
                    $bRes = false;
                } elseif (!isset($aResponse['TOKEN']) || strcmp($aResponse['TOKEN'], $sToken) != 0)
                {
                    $this->_iLastErrorNo = __LINE__;
                    $this->_sLastError = 'Paypal response token from DoExpressCheckoutPayment doesn\'t match session value.';
                    $bRes = false;
                } else {
                    $this->_aGatewayParms = $aResponse;
                }
            }

            // Provide function for extensions
            if ($bRes && method_exists($this, '_v6cExecPayExt_End')) $this->_v6cExecPayExt_End($sBaseRequest);

            //TODO:
            //    Prevent > 10 services from basket

            $this->_blActive = false;
        }

        return $bRes;
    }

    /**
    * Sets _iGatewayType value otherwise set via self::confirmPayment
    * for other payment types.
    *
    * @param object $oUserpayment User payment object
    *
    * @return null
    */
    public function setPaymentParams( $oUserpayment )
    {
        parent::setPaymentParams( $oUserpayment );

        // Determine payment type
        $oPayment = oxNew('oxPayment');
        if ($oPayment->load($this->_oPaymentInfo->oxuserpayments__oxpaymentsid->value) && strcmp($oPayment->oxpayments__v6link->value, 'v6c_paypalxpr') == 0)
        {
            $this->_iGatewayType = self::V6C_ML_PAYPAL_NVP;
        }
    }


	/////////////////////// ADDITIONS ////////////////////////////

    // defining PayPal Express Checkout server address
    /**
     * PayPal Express Checkout domain
     * @var string
     */
    const V6C_ML_PAYPAL_NVP_SVR = 'api-3t.paypal.com';

    /**
    * PayPal Express Checkout domain
    * @var string
    */
    const V6C_ML_PAYPAL_NVP_TSTSVR = 'api-3t.sandbox.paypal.com';

    /**
     * PayPal Express Checkout address
     * @var string
     */
    const V6C_ML_PAYPAL_NVP_ADR = '/nvp';

    // defining supported linked payment gateways
    /**
     * PayPal PDT
     * @var int
     */
    const V6C_ML_PAYPAL_PDT = 0;

    /**
     * PayPal IPN
     * @var int
     */
    const V6C_ML_PAYPAL_IPN = 1;

    /**
    * PayPal IPN
    * @var int
    */
    const V6C_ML_PAYPAL_NVP = 2;

    /**
    * PayPal NVP Version
    * @var int
    */
    const V6C_ML_PAYPAL_NVP_VER = 60;

    /**
     * Gateway type
     * @var int
     */
    protected $_iGatewayType = null;

    /**
     * Gateway parameters
     * @var array
     */
    protected $_aGatewayParms = null;

    /**
     * Custom parameters
     * @var array
     */
    protected $_aCustomParms = null;

    /**
     * Test mode
     * @var bool
     */
    protected $_v6c_bTestMode = null;

    /**
     * Confirm payment.  Returns confirmed data.
     *
     * @param int $TYPE payment type
     *
     * @return null
     */
    //TODO: add prefix
    public function confirmPayment($TYPE)
    {
    	$this->_iGatewayType = $TYPE;

    	switch ($TYPE)
    	{
    		case self::V6C_ML_PAYPAL_PDT:
    			$this->_v6cProcessPayPalOrderPdt();
    			break;
    		case self::V6C_ML_PAYPAL_IPN:
    			$this->_v6cProcessPayPalOrderIpn();
    			break;
    		default:
    			break;
    	}
    }

    /**
     * Variable getter.
     *
     * @return array
     */
    //TODO: add prefix
    public function getCustomParms()
    {
    	return $this->_aCustomParms;
    }

    /**
     * Variable getter.
     *
     * @return array
     */
    //TODO: add prefix
    public function getGatewayParms()
    {
    	return $this->_aGatewayParms;
    }

    /**
     * Variable getter.
     *
     * @return string|null
     */
    //TODO: add prefix
    public function getGatewayOrderId()
    {
    	$sOrderNr = null;

    	if (isset($this->_aGatewayParms))
    	{
	    	switch ($this->_iGatewayType)
			{
				case self::V6C_ML_PAYPAL_PDT:
				case self::V6C_ML_PAYPAL_IPN:
					if (array_key_exists('txn_id', $this->_aGatewayParms)) $sOrderNr = $this->_aGatewayParms['txn_id'];
					break;
				case self::V6C_ML_PAYPAL_NVP:
				    if (array_key_exists('TRANSACTIONID', $this->_aGatewayParms)) $sOrderNr = $this->_aGatewayParms['TRANSACTIONID'];
				    break;
				default:
					// do nothing
			}
    	}

		return $sOrderNr;
    }

    /**
     * Variable getter.
     *
     * @return double|null
     */
    //TODO: add prefix
    public function getGatewayOrderAmount()
    {
    	$dOrderNr = null;

    	if (isset($this->_aGatewayParms))
    	{
	    	switch ($this->_iGatewayType)
			{
				case self::V6C_ML_PAYPAL_PDT:
				case self::V6C_ML_PAYPAL_IPN:
					if (array_key_exists('mc_gross', $this->_aGatewayParms)) $dOrderNr = (double)$this->_aGatewayParms['mc_gross'];
					break;
				case self::V6C_ML_PAYPAL_NVP:
				    if (array_key_exists('AMT', $this->_aGatewayParms)) $sOrderNr = (double)$this->_aGatewayParms['AMT'];
				    break;
				default:
					// do nothing
			}
    	}

		return $dOrderNr;
    }

    /**
    * Variable getter.
    *
    * @return string|null
    */
    //TODO: add prefix
    public function getGatewayPaymentStatus()
    {
        if (isset($this->_aGatewayParms))
        {
            switch ($this->_iGatewayType)
            {
                case self::V6C_ML_PAYPAL_PDT:
                case self::V6C_ML_PAYPAL_IPN:
                    if (array_key_exists('payment_status', $this->_aGatewayParms)) return $this->_aGatewayParms['payment_status'];
                case self::V6C_ML_PAYPAL_NVP:
                    if (array_key_exists('PAYMENTSTATUS', $this->_aGatewayParms)) return $this->_aGatewayParms['PAYMENTSTATUS'];
            }
        }
    }

    /**
    * Initiate a payment for merchant gateways that require it.
    * Return TRUE on success otherwise FALSE.
    *
    * @param oxPayment $oPayment payment type object
    * @param oxBasket $oBasket basket object
    *
    * @return bool
    */
    public function v6cInitPayment(oxPayment $oPayment, oxBasket $oBasket)
    {
        // allow payment types that don't require init to pass check
        $res = true;
        // reset any prev results
        oxSession::deleteVar('v6c_sPaypalXprTkn');

        // Init for PayPal Express
        if (strcmp($oPayment->oxpayments__v6link->value, 'v6c_paypalxpr') == 0)
        {
            $res = false;
            // Server info
            $sServer = $this->_v6cIsTestMode() ? self::V6C_ML_PAYPAL_NVP_TSTSVR : self::V6C_ML_PAYPAL_NVP_SVR;
            $sScript = self::V6C_ML_PAYPAL_NVP_ADR;
            // Generate general request string
            $sNvpVer = self::V6C_ML_PAYPAL_NVP_VER; //$this->getConfig()->getConfigParam('v6c_sPayPalNvpVer');
            $aQuery = array();
            $aQuery['METHOD'] = 'SetExpressCheckout';
            $aQuery['VERSION'] = number_format( doubleval($sNvpVer),1 );
            $aQuery['PWD'] = $this->getConfig()->getConfigParam('v6c_sPayPalNvpPwd');
            $aQuery['USER'] = $this->getConfig()->getConfigParam('v6c_sPayPalNvpUsr');
            $aQuery['SIGNATURE'] = $this->getConfig()->getConfigParam('v6c_sPayPalNvpSig');
            $aQuery['PAYMENTACTION'] = 'Sale';
            $aQuery['RETURNURL'] = htmlspecialchars_decode($this->getConfig()->getShopHomeURL()).'cl=order';
            $aQuery['CANCELURL'] = htmlspecialchars_decode($this->getConfig()->getShopHomeURL()).'cl=v6c_redirectpost&fnc=v6cLinkedPayCancel';
            $aQuery['CURRENCYCODE'] = $this->getConfig()->getActShopCurrencyObject()->name;
            $aQuery['NOSHIPPING'] = 1;
            $aQuery['ADDROVERRIDE'] = 0;
            $aQuery['ALLOWNOTE'] = 0;
            $aQuery['SOLUTIONTYPE'] ='Sole';
            $aQuery['LANDINGPAGE'] = 'Billing';
            $aLangMap = $this->getConfig()->getConfigParam('v6c_aPayPalLangMap');
            $sLang = strtoupper(oxLang::getInstance()->getLanguageAbbr());
            if (isset($aLangMap[$sLang])) $aQuery['LOCALECODE'] = $aLangMap[$sLang];
            // Generate user specific portion of request string (PayPal uses shipping fields to accomplish this)
            $oUser = $oBasket->getBasketUser();
            $oCountry = oxNew('oxCountry');
            $oCountry->load($oUser->oxuser__oxcountryid->value);
            $aQuery['SHIPTONAME'] = utf8_encode($oUser->oxuser__oxfname->rawValue.' '.$oUser->oxuser__oxlname->rawValue);
            $aQuery['SHIPTOSTREET'] = utf8_encode($oUser->oxuser__oxstreet->rawValue);
            $aQuery['SHIPTOSTREET2'] = utf8_encode($oUser->oxuser__oxaddinfo->rawValue);
            $aQuery['SHIPTOCITY'] = utf8_encode($oUser->oxuser__oxcity->rawValue);
            $aQuery['SHIPTOSTATE'] = $oUser->oxuser__oxstateid->value;
            $aQuery['SHIPTOZIP'] = $oUser->oxuser__oxzip->value;
            $aQuery['SHIPTOCOUNTRY'] = $oCountry->oxcountry__oxisoalpha2->value;
            $aQuery['SHIPTOPHONENUM'] = $oUser->oxuser__oxfon->value;
            $aQuery['EMAIL'] = utf8_encode($oUser->oxuser__oxusername->value);

            // Check if any parameters require special handling
            if (method_exists($oPayment, 'v6cSetCustomGatewayParms')) $oPayment->v6cSetCustomGatewayParms($aQuery);
            // Convert array to string query
            $sRequest = http_build_query($aQuery);

            // Generate basket specific portion of request string
            $dBillAmt = doubleval(0);
            $i = 0;
            $sDesc = '';
            foreach ($oBasket->getContents() as $oBasketItem)
            {
                /* NOTE:
                 * Do not bother defining each item (L_NAME, L_AMT, L_QTY) because PayPal doesn't support
                 * discounts.  As a result, only basket total is sent to PayPal.
                 */
                $oProduct = $oBasketItem->getArticle(false);
                $sDesc .= (empty($sDesc) ? '' : ', ').$oBasketItem->getAmount().' x '.$oProduct->oxarticles__oxtitle->rawValue.(empty($oProduct->oxarticles__oxvarselect->value) ? '' : ' - '.$oProduct->oxarticles__oxvarselect->rawValue);
                // Provide fn for extensions: can modify request value
                if (method_exists($this, '_v6cInitPayExt_PerArt')) $this->_v6cInitPayExt_PerArt($oBasketItem, $oProduct, $sRequest);
            }
            $oPrice = $oBasket->getPrice();
            // Fn provided for extensions: can modify request.
            if (method_exists($this, '_v6cInitPayExt_PreAmt')) $this->_v6cInitPayExt_PreAmt($oPrice, $sRequest);
            $sRequest .= '&DESC='.urlencode(utf8_encode(strlen($sDesc) > 120 ? substr($sDesc, 0, 117).'...' : $sDesc));
            $sRequest .= '&ITEMAMT='.number_format($oPrice->getNettoPrice(), 2);
            $sRequest .= '&TAXAMT='.number_format($oPrice->getVatValue(), 2);
            $sRequest .= '&AMT='.number_format($oPrice->getBruttoPrice(), 2);
            // Make request and check response
            $aRet = $this->_v6cPayPalNvpRequest($sServer, $sScript, $sRequest);
            if ($aRet !== false && array_key_exists('ACK', $aRet) && strcasecmp($aRet['ACK'], 'Success') == 0)
            {
                oxSession::setVar( 'v6c_sPaypalXprTkn', $aRet['TOKEN'] );
                $this->_aGatewayParms = $aRet;
                $res = true;
            }
            elseif ( $this->getConfig()->getConfigParam( 'iDebug' ) != 0 ) oxUtils::getInstance()->writeToLog("[".date('Y-m-d\TH:i:sP')."]\.".__CLASS__.".::".__FUNCTION__." (ln ".__LINE__.")\nNVP Init Failed! Response:\n".print_r($aRet, true)."\n\n", 'v6c_log.txt');
        }

        return $res;
    }

    /**
    * If available, return URL string of merchant link, else
    * returns false.
    *
    * @param oxPayment $oPayment payment type object
    *
    * @return string
    */
    public function v6cGetGatewayUrl(oxPayment $oPayment)
    {
        $sUrl = false;

        switch ($oPayment->oxpayments__v6link->value)
        {
            case 'v6c_paypalstd':
                $sUrl = $this->_v6cIsTestMode() ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';
                break;
            case 'v6c_paypalxpr':
                if (array_key_exists('TOKEN', $this->_aGatewayParms))
                {
                    $sUrl = $this->_v6cIsTestMode() ? 'https://www.sandbox.paypal.com/' : 'https://www.paypal.com/';
                    $sUrl .= 'cgi-bin/webscr?cmd=_express-checkout&token='.$this->_aGatewayParms['TOKEN'];
                }
                break;
            case 'v6c_googlechkout':
                break;
            default:
                // do nothing
        }

        return $sUrl;
    }

	/**
     * Process order data from PayPal gateway and return retrieved data.
     *
     * @return null
     */
	protected function _v6cProcessPayPalOrderPdt()
	{
		$sErr = null;

    	// read the post from PayPal system and add 'cmd'
		$req = 'cmd=_notify-synch';

		$tx_token = $_GET['tx'];
		$auth_token = $this->_v6cIsTestMode() ? $this->getConfig()->getConfigParam('v6c_sPayPalTstPdtTkn') : $this->getConfig()->getConfigParam('v6c_sPayPalPdtTkn');
		$req .= "&tx=$tx_token&at=$auth_token";

	    // post back to PayPal system to validate
		$header = "POST /cgi-bin/webscr HTTP/1.0\r\n";
		$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$header .= "Content-Length: " . strlen($req) . "\r\n\r\n";
		$domain = $this->_v6cIsTestMode() ? 'www.sandbox.paypal.com' : 'www.paypal.com';
		// If possible, securely post back to paypal using HTTPS
		// Your PHP server will need to be SSL enabled
		if ($this->getConfig()->getConfigParam('v6c_blPayPalSslPdt'))
		{ $fp = fsockopen ('ssl://'.$domain, 443, $errno, $errstr, 30); }
		else
		{ $fp = fsockopen ($domain, 80, $errno, $errstr, 30); }

		if (!$fp)
		{
			// HTTP ERROR
			$sErr = oxLang::getInstance()->translateString('V6C_PAYPAL_NOCONNECT');
		} else {
			fputs ($fp, $header . $req);
			// read the body data
			$res = '';
			$headerdone = false;
			while (!feof($fp))
			{
				$line = fgets ($fp, 1024);
				if (strcmp($line, "\r\n") == 0)
				{
					// read the header
					$headerdone = true;
				}
				else if ($headerdone)
				{
					// header has been read. now read the contents
					$res .= $line;
				}
			}

			// parse the data
			$lines = explode("\n", $res);
			$keyarray = array();
			if (strcmp ($lines[0], "SUCCESS") == 0)
			{
				for ($i=1; $i<count($lines);$i++)
				{
					list($key,$val) = explode("=", $lines[$i]);
					$keyarray[urldecode($key)] = urldecode($val);
				}
				$dummyvar = null;

				$sErr = $this->_v6cValidatePaypalData($keyarray);
			}
			else if (strcmp ($lines[0], "FAIL") == 0)
			{
				//TODO: get error msg/code that may follow after the FAIL line
				$sErr = oxLang::getInstance()->translateString('V6C_PAYPAL_PDTFAIL');
			}
			//TODO: change error msg
			else $sErr = oxLang::getInstance()->translateString('V6C_PAYPAL_PDTFAIL');
			fclose ($fp);
		}

		if ($sErr == null)
		{
			// All checks passed so init some vars
			$this->_aCustomParms = unserialize(stripslashes(htmlspecialchars_decode($keyarray['custom'])));
			$this->_aGatewayParms = $keyarray;
			if (oxLang::getInstance()->getBaseLanguage() != $this->_aCustomParms['lang'])
				$this->_v6cChangeShopLang($this->_aCustomParms['lang']);
		} else {
			throw new Exception($sErr);
		}
	}

    /**
     * Process order data from PayPal gateway using IPN
     *
     * @return mixed
     */
	protected function _v6cProcessPayPalOrderIpn()
	{
		$sErr = null;

		// read the post from PayPal system and add 'cmd'
		$req = 'cmd=_notify-validate';

		foreach ($_POST as $key => $value)
		{
			$value = urlencode(stripslashes($value));
			$req .= "&$key=$value";
		}

		// post back to PayPal system to validate
		$header .= "POST /cgi-bin/webscr HTTP/1.0\r\n";
		$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$header .= "Content-Length: " . strlen($req) . "\r\n\r\n";
		$domain = $this->_v6cIsTestMode() ? 'www.sandbox.paypal.com' : 'www.paypal.com';
		// If possible, securely post back to paypal using HTTPS
		// Your PHP server will need to be SSL enabled
		if ($this->getConfig()->getConfigParam('v6c_blPayPalSslPdt'))
		{ $fp = fsockopen ('ssl://'.$domain, 443, $errno, $errstr, 30); }
		else
		{ $fp = fsockopen ($domain, 80, $errno, $errstr, 30); }

		if (!$fp)
		{
			// HTTP ERROR
			$sErr = oxLang::getInstance()->translateString('V6C_PAYPAL_NOCONNECT');
		} else {
			fputs ($fp, $header . $req);
			while (!feof($fp))
			{
				$res = fgets ($fp, 1024);
				if (strcmp ($res, "VERIFIED") == 0)
				{
					$sErr = $this->_v6cValidatePaypalData($_POST);
				}
				else if (strcmp ($res, "INVALID") == 0)
				{
					$sErr = oxLang::getInstance()->translateString('V6C_PAYPAL_IPNINVALID');
				}
				//TODO: change error msg
				else $sErr = oxLang::getInstance()->translateString('V6C_PAYPAL_IPNINVALID');
			}
			fclose ($fp);
		}

		if ($sErr == null)
		{
			// All checks passed
		    $this->_aGatewayParms = $_POST;
		    // Required for linked gateways that are non-integrated
		    // Ensures language doesn't change IF customer returns to site
		    if (isset($_POST['custom']))
		    {
    			$this->_aCustomParms = unserialize(stripslashes(htmlspecialchars_decode($_POST['custom'])));
    			if (isset($this->_aCustomParms['lang']) && oxLang::getInstance()->getBaseLanguage() != $this->_aCustomParms['lang'])
    				$this->_v6cChangeShopLang($this->_aCustomParms['lang']);
		    }
		} else {
			throw new Exception($sErr);
		}
	}

	/**
     * Validate PayPal data
     *
     * @param array $aData PayPal data
     *
     * @return string
     */
	protected function _v6cValidatePaypalData($aData)
	{
		$sErr = null;

		// Verify that requried parameters are available
		// Should only fail if PayPal discontinues or changes a variable name.
		if (
			array_key_exists('payment_status', $aData)  &&
			array_key_exists('txn_type', $aData)  &&
			array_key_exists('txn_id', $aData)  &&
			//array_key_exists('receipt_id', $aData)  &&
			array_key_exists('mc_gross', $aData)  &&
			array_key_exists('custom', $aData) &&
			array_key_exists('receiver_email', $aData)
			)
		{
			// Only process completed cart payments
			if ( (strcmp(strtolower($aData['payment_status']), 'completed') == 0 || strcmp(strtolower($aData['payment_status']), 'pending')) &&
				strcmp(strtolower($aData['txn_type']), 'cart') == 0 )
			{
				$sEmail = $this->_v6cIsTestMode() ? $this->getConfig()->getConfigParam('v6c_sPayPalTstEmail') : $this->getConfig()->getConfigParam('v6c_sPayPalEmail');
				if ( strcmp(strtolower($aData['receiver_email']), strtolower($sEmail)) != 0 )
					$sErr = oxLang::getInstance()->translateString('V6C_PAYPAL_BADID');
			}
			else
			{
			$sErr = oxLang::getInstance()->translateString('V6C_PAYPAL_UNKWNRQ');
			}
		}
		else
		{
			$sErr = oxLang::getInstance()->translateString('V6C_PAYPAL_MISSINGPARMS');
		}

		return $sErr;
	}

	/**
     * Variable (config parm) getter
     *
     * @return mixed
     */
	protected function _v6cIsTestMode()
	{
		if ($this->_v6c_bTestMode === null)
		{ $this->_v6c_bTestMode = $this->getConfig()->getConfigParam('v6c_blMrchLnkTst'); }
		return $this->_v6c_bTestMode;
	}

	/**
     * Change language of eshop.  Important to reset object cache or change will
     * not take affect!
     *
     * @param int $iLang Lang number
     *
     * @return mixed
     */
	protected function _v6cChangeShopLang($iLang)
	{
			// Change lang for all new objects
			$_POST['lang'] = (string)$iLang;
			oxLang::getInstance()->resetBaseLanguage();
			oxUtilsObject::getInstance()->resetInstanceCache();

			// Reset lang for existing (static) objects, particularly those we know will be used
			// such as for order emails.
			oxConfig::getInstance()->getActiveShop(); // 'get' triggers lang reset
	}

	/**
	* Make request to PayPal server and return response parsed as array.
	* Failure returns FALSE.
	*
	* @param string $sServer Domain portion or request URL
	* @param string $sScript Address/query portion or request URL
	* @param string $sServer Request content
	*
	* @return array|false array may be empty if error occurred
	*/
	protected function _v6cPayPalNvpRequest($sServer, $sScript, $sRequest)
	{
	    $aResponse = false;

	    if (function_exists('curl_exec'))
	    {
	        $sResponse = $this->_v6cConnectByCURL($sServer.$sScript, $sRequest);
	    } else {
	        $sResponse = $this->_v6cConnectByFSOCK($sServer, $sScript, $sRequest);
	    }
	    if ($sResponse !== false)
	    {
	        if ( $this->getConfig()->getConfigParam( 'iDebug' ) != 0 ) oxUtils::getInstance()->writeToLog("[".date('Y-m-d\TH:i:sP')."]\n".__CLASS__.".::".__FUNCTION__." (ln ".__LINE__.")\nResponse:\n$sResponse\n\n", 'v6c_log.txt');
	        $aResponse = explode('&', $sResponse);
	        foreach ($aResponse as $key => $val)
	        {
	            $tmp = explode('=', $val);
	            if (!isset($tmp[1]))
	                $aResponse[$tmp[0]] = urldecode($tmp[0]);
	            else
	            {
	                $aResponse[$tmp[0]] = urldecode($tmp[1]);
	                unset($aResponse[$key]);
	            }
	        }
	    }

	    return $aResponse;
	}

	/**
	* Send request to URL using cURL.
	*
	* @param string $url URL
	* @param string $body Request content
	*
	* @return string|false
	*/
	protected function _v6cConnectByCURL($url, $body)
	{
	    $ch = @curl_init();
	    if (!$ch)
	    {
	        oxUtilsView::getInstance()->addErrorToDisplay('PayPal Express connect failed with cURL method');
	        if ( $this->getConfig()->getConfigParam( 'iDebug' ) != 0 ) oxUtils::getInstance()->writeToLog("[".date('Y-m-d\TH:i:sP')."]\.".__CLASS__.".::".__FUNCTION__." (ln ".__LINE__.")\nConnect failed at CURL init\n\n", 'v6c_log.txt');
	    }
	    else
	    {
	        if ( $this->getConfig()->getConfigParam( 'iDebug' ) != 0 )
	        {
	            $sLog = "[".date('Y-m-d\TH:i:sP')."]\nv6c_mlPaymentGateway::".__FUNCTION__." (ln ".__LINE__.")\nConnect with cURL method successful\nRequest:\n";
	            $sLog .= $body."\n\n";
	            oxUtils::getInstance()->writeToLog($sLog, 'v6c_log.txt');
	        }
	        @curl_setopt($ch, CURLOPT_URL, 'https://'.$url);
	        @curl_setopt($ch, CURLOPT_POST, true);
	        @curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	        @curl_setopt($ch, CURLOPT_HEADER, false);
	        @curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	        @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	        @curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	        @curl_setopt($ch, CURLOPT_VERBOSE, true);
	        $result = @curl_exec($ch);
	        if (!$result)
	        {
	            oxUtilsView::getInstance()->addErrorToDisplay('PayPal Express send failed with cURL. Error: ' . curl_error($ch));
	            if ( $this->getConfig()->getConfigParam( 'iDebug' ) != 0 ) oxUtils::getInstance()->writeToLog("[".date('Y-m-d\TH:i:sP')."]\n".__CLASS__."::".__FUNCTION__." (ln ".__LINE__.")\nRequest with CURL failed! Error: ".curl_error($ch)."\n\n", 'v6c_log.txt');
	        }
	        elseif ( $this->getConfig()->getConfigParam( 'iDebug' ) != 0 ) oxUtils::getInstance()->writeToLog("[".date('Y-m-d\TH:i:sP')."]\n".__CLASS__."::".__FUNCTION__." (ln ".__LINE__.")\nRequest with CURL successful\n\n", 'v6c_log.txt');
	        @curl_close($ch);
	    }
	    return (isset($result) ? $result : false);
	}

	/**
	* Send request to URL using fsock.
	*
	* @param string $host Domain portion or request URL
	* @param string $script Address/query portion or request URL
	* @param string $body Request content
	*
	* @return string|false
	*/
	protected function _v6cConnectByFSOCK($host, $script, $body)
	{
	    $fp = @fsockopen('ssl://'.$host, 443, $errno, $errstr, 4);
	    if (!$fp)
	    {
	        oxUtilsView::getInstance()->addErrorToDisplay('PayPal Express connect failed at fsockopen init');
	        if ( $this->getConfig()->getConfigParam( 'iDebug' ) != 0 ) oxUtils::getInstance()->writeToLog("[".date('Y-m-d\TH:i:sP')."]\n".__CLASS__."::".__FUNCTION__." (ln ".__LINE__.")\nConnect failed with fsockopen\n\n", 'v6c_log.txt');
	    }
	    else
	    {
	        $header = $this->_v6cMakeHeader($host, $script, strlen($body));
	        if ( $this->getConfig()->getConfigParam( 'iDebug' ) != 0 ) oxUtils::getInstance()->writeToLog("[".date('Y-m-d\TH:i:sP')."]\n".__CLASS__."::".__FUNCTION__." (ln ".__LINE__.")\nConnect with fsockopen successful\nRequest:\n".$header.$body."\n\n", 'v6c_log.txt');
	        @fputs($fp, $header.$body);
	        $tmp = '';
	        $isHdrDone = false;
	        while (!feof($fp))
	        {
	            $sLn = trim(fgets($fp, 1024));
	            if (strlen($sLn) == 0) $isHdrDone = true;
	            if ($isHdrDone) $tmp .= $sLn;
	        }
	        fclose($fp);
	        $result = $tmp;
	        if (!$result)
	        {
	            oxUtilsView::getInstance()->addErrorToDisplay('PayPal Express send failed with fsockopen');
	            if ( $this->getConfig()->getConfigParam( 'iDebug' ) != 0 ) oxUtils::getInstance()->writeToLog("[".date('Y-m-d\TH:i:sP')."]\n".__CLASS__."::".__FUNCTION__." (ln ".__LINE__.")\nRequest with fsockopen failed\n\n", 'v6c_log.txt');
	        }
	        elseif ( $this->getConfig()->getConfigParam( 'iDebug' ) != 0 ) oxUtils::getInstance()->writeToLog("[".date('Y-m-d\TH:i:sP')."]\n".__CLASS__."::".__FUNCTION__." (ln ".__LINE__.")\nRequest with fsockopen successful\n\n", 'v6c_log.txt');
	    }
	    return (isset($result) ? $result : false);
	}

	/**
	* Generate header for fsock connection.
	*
	* @param string $host Domain portion or request URL
	* @param string $script Address/query portion or request URL
	* @param int $length length of body content
	*
	* @return string
	*/
	protected function _v6cMakeHeader($host, $script, $length)
	{
	    $header =	'POST '.strval($script).' HTTP/1.0'."\r\n" .
						'Host: '.strval($host)."\r\n".
						'Content-Type: application/x-www-form-urlencoded'."\r\n".
						'Content-Length: '.(int)($length)."\r\n".
						'Connection: close'."\r\n\r\n";
	    return $header;
	}

	/**
	* Process any query string supplied by merchant gateway when returning
	* user to checkout to complete order.
	*
	* @return null
	*/
	public function v6c_paypalxpr_ProcessQueryStr()
	{
	    $sPayerId = oxConfig::getParameter('PayerID');
	    if (isset($sPayerId)) oxSession::setVar('v6cPaypalxprPayerId', $sPayerId);
	}

	/**
	* Get PayPal PayerID
	*
	* @return string
	*/
	protected function _v6c_paypalxpr_GetPayerId()
	{
	    return oxSession::getVar('v6cPaypalxprPayerId');
	}
}