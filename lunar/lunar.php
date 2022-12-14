<?php

defined ('_JEXEC') or die('Restricted access');
if ( ! class_exists(  'Lunar\\Client' ) ) {
	include_once( __DIR__ .'/lib/Client.php' );
}
if ( ! class_exists( 'LunarCurrency' ) ) {
	include_once( __DIR__ .'/lib/LunarCurrency.php' );
}
if ( ! class_exists( 'vmPSPlugin' ) ) {
	require( JPATH_VM_PLUGINS . DS . 'vmpsplugin.php' );
}
/**
 * lunar payment plugin
 * @package VirtueMart
 * @subpackage payment
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

use Joomla\CMS\Factory;

class plgVmPaymentLunar extends vmPSPlugin {

	public $version = '1.0.0';
	static $IDS = array();
	protected $_isInList = false;
	function __construct (& $subject, $config) {

		parent::__construct ($subject, $config);
		// 		vmdebug('Plugin stuff',$subject, $config);
		$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = $this->getVarsToPush ();
		$this->addVarsToPushCore($varsToPush,1);
		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
		$this->setConvertable(array('min_amount','max_amount','cost_per_transaction','cost_min_transaction'));
		$this->setConvertDecimal(array('min_amount','max_amount','cost_per_transaction','cost_min_transaction','cost_percent_total'));

		/**
		 * Hide test fields from view when not debug mode.
		 */
		/** Get payment id from database. */
		$db   = Factory::getDbo();
		$prefix = $db->getPrefix();
		$query = "SELECT * FROM " . $prefix . "virtuemart_paymentmethods WHERE payment_element LIKE '%lunar%'";
		$result = $db->setQuery($query)->execute()->fetch_array();
		$extensionId = $result['virtuemart_paymentmethod_id'];

		if (!isset($_GET['debug']) && $_GET['cid'][0] == $extensionId) {
			$document = Factory::getDocument();
			$document->addScriptDeclaration('
				jQuery().ready(() => {
					jQuery("#params_test_mode").closest(".control-group").hide();
					jQuery("#params_test_api_key").closest(".control-group").hide();
					jQuery("#params_test_public_key").closest(".control-group").hide();
				});
			');
		}
	}

	/**
	 * Create the table for this plugin if it does not yet exist.
	 */
	public function getVmPluginCreateTableSQL () {

		return $this->createTableSQL ('Payment Lunar Table');
	}

	/**
	 * Fields to create the payment table
	 *
	 * @return string SQL Fileds
	 */
	function getTableSQLFields () {

		$SQLfields = array(
			'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'int(1) UNSIGNED',
			'order_number'                => 'char(64)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'payment_name'                => 'varchar(5000)',
			'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'            => 'char(3)',
			'email_currency'              => 'char(3)',
			'cost_per_transaction'        => 'decimal(10,2)',
			'cost_min_transaction'        => 'decimal(10,2)',
			'cost_percent_total'          => 'decimal(10,2)',
			'tax_id'                      => 'smallint(1)',
			'lunar_data'                => 'varchar(65000)'

		);

		return $SQLfields;
	}

	/**
	 *
	 */
	function plgVmConfirmedOrder ($cart, $order) {

		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}

		vmLanguage::loadJLang('com_virtuemart',true);
		vmLanguage::loadJLang('com_virtuemart_orders', TRUE);

		$this->getPaymentCurrency($method);

		$emailCurrencyId = $this->getEmailCurrency($method);
		$emailCurrency = shopFunctions::getCurrencyByID($emailCurrencyId, 'currency_code_3');

		$lunarCurrency = new LunarCurrency();

		$orderTotal = $order['details']['BT']->order_total;
		$price = vmPSPlugin::getAmountValueInCurrency($orderTotal, $method->payment_currency);
		$currency = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
		$precision = $lunarCurrency->getLunarCurrency($currency)['exponent'] ?? 2;
		$priceInCents = (int) ceil( round($price * $lunarCurrency->getLunarCurrencyMultiplier($currency), $precision));

		if (!empty($method->payment_info)) {
			$lang = JFactory::getLanguage ();
			if ($lang->hasKey ($method->payment_info)) {
				$method->payment_info = vmText::_ ($method->payment_info);
			}
		}

		$transactionId ='';

		//verify the session
		if($method->checkout_mode === 'before') {
			$session = JFactory::getSession();
			$transactionId = $session->get( 'lunar.transactionId','');
			$hasError = true;
			if($transactionId) {
				$this->setKey($method);
				// verify transaction amount + currency
				$response = \Lunar\Transaction::fetch( $transactionId);
				$transactionAmount = (int)$response['transaction']['amount'];
				$transactionCurrency = $response['transaction']['currency'];

				if($transactionAmount == $priceInCents && $transactionCurrency == $currency) {
					$hasError = false;
				}
			}
			// return to cart and don't save transaction values, if we don't get the right values;
			if($hasError) {
				$msg = 'Lunar Transaction not found '.$transactionId;
				$app = JFactory::getApplication();
				$app->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart'), $msg);
			}
		}

		$dbValues['payment_name'] = $this->renderPluginName ($method);
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
		$dbValues['cost_min_transaction'] = $method->cost_min_transaction;
		$dbValues['cost_percent_total'] = $method->cost_percent_total;
		$dbValues['payment_currency'] = $currency;
		$dbValues['email_currency'] = $emailCurrency;
		$dbValues['payment_order_total'] = $price;
		$dbValues['tax_id'] = $method->tax_id;
		$dbValues['lunar_data'] = $transactionId;//before transaction has the ID
		$this->storePSPluginInternalData ($dbValues);

		$orderlink='';
		$tracking = VmConfig::get('ordertracking','guests');

		if($tracking !='none' and !($tracking =='registered' and empty($order['details']['BT']->virtuemart_user_id) )) {

			$orderlink = 'index.php?option=com_virtuemart&view=orders&layout=details&order_number=' . $order['details']['BT']->order_number;
			if ($tracking == 'guestlink' or ($tracking == 'guests' and empty($order['details']['BT']->virtuemart_user_id))) {
				$orderlink .= '&order_pass=' . $order['details']['BT']->order_pass;
			}
		}

		$currencyInstance = CurrencyDisplay::getInstance($method->payment_currency, $order['details']['BT']->virtuemart_vendor_id);
		$priceDisplayWithCurrency = $price . ' ' . $currencyInstance->getSymbol();

		//after-payment need specific render and scripts
		if($method->checkout_mode === 'after') {
			$html = $this->renderByLayout('pay_after', array(
				'method'=>$method,
				'cart'=>$cart,
				'billingDetails' =>$order['details']['BT'],
				'payment_name' => $dbValues['payment_name'],
				'displayTotalInPaymentCurrency' => $priceDisplayWithCurrency,
				'orderlink' =>$orderlink
			));
		} else {

			$html = $this->renderByLayout('order_done', array(
				'method'=>$method,
				'cart'=>$cart,
				'billingDetails' =>$order['details']['BT'],
				'payment_name' => $dbValues['payment_name'],
				'displayTotalInPaymentCurrency' => $priceDisplayWithCurrency,
				'orderlink' =>$orderlink
			));
			//before payment display
			//We delete the cart content
			$cart->emptyCart ();
			// and send Status email if needed
			$details = $order['details']['BT'];
			$order['order_status'] = $this->getNewStatus ($method);
			$order['customer_notified'] = 1;
			$order['comments'] = '';

			/**
			 * There is no VM config setting for os_trigger_paid
			 * In the future, we must set the status for capture on vmConfig
			 * Add the additional info here
			 */
			if ($method->capture_mode === 'instant') {
				$date = JFactory::getDate();
				$today = $date->toSQL();
				$order['paid_on'] = $today;
				$order['paid'] = $orderTotal;
			}

			$modelOrder = VmModel::getModel ('orders');
			$modelOrder->updateStatusForOneOrder ($details->virtuemart_order_id, $order, TRUE);
		}
		vRequest::setVar ('html', $html);
		return TRUE;
	}

	/**
	 * Keep backwards compatibility
	 * a new parameter has been added in the xml file
	 */
	function getNewStatus ($method) {
		//instant payment directly capture
		if($method->capture_mode === 'instant') {
			if (isset($method->status_capture) and $method->status_capture!="") {
				return $method->status_capture;
			} else {
				return 'S';
			}
		} else {
			if (isset($method->status_success) and $method->status_success!="") {
				return $method->status_success;
			} else {
				return 'C';
			}
		}
	}

	/**
	 * Display stored payment data for an order
	 *
	 */
	function plgVmOnShowOrderBEPayment ($virtuemart_order_id, $virtuemart_payment_id) {

		if (!$this->selectedThisByMethodId ($virtuemart_payment_id)) {
			return NULL; // Another method was selected, do nothing
		}

		if (!($paymentTable = $this->getDataByOrderId ($virtuemart_order_id))) {
			return NULL;
		}
		vmLanguage::loadJLang('com_virtuemart');

		$orderTotalInPaymentCurrency = number_format($paymentTable->payment_order_total, 2);

		$html = '<table class="adminlist table">' . "\n";
		$html .= $this->getHtmlHeaderBE ();
		$html .= $this->getHtmlRowBE ('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE ('LUNAR_PAYMENT_TOTAL_CURRENCY', $orderTotalInPaymentCurrency . ' ' . $paymentTable->payment_currency);
		if ($paymentTable->email_currency) {
			$html .= $this->getHtmlRowBE ('LUNAR_EMAIL_CURRENCY', $paymentTable->email_currency );
		}
		$html .= $this->getHtmlRowBE ('Transaction', $paymentTable->lunar_data );
		$html .= '</table>' . "\n";
		return $html;
	}



/*
* We must reimplement this triggers for joomla 1.7
*/

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 */
	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}

	/**
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	 *
	 * @param VirtueMartCart $cart: the actual cart
	 * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
	 *
	 */
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {

		return $this->OnSelectCheck ($cart);
	}

	/**
	 * plgVmDisplayListFEPayment
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
	 *
	 * @param object  $cart Cart object
	 * @param integer $selected ID of the method selected
	 * @return boolean True on succes, false on failures, null when this plugin was not selected.
	 * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
	 *
	 */
	public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		$this->_isInList = true;
		return $this->displayListFE ($cart, $selected, $htmlIn);

	}

	/**
	 * Calculate the price (value, tax_id) of the selected method
	 * It is called by the calculator
	 * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
	 * @cart: VirtueMartCart the current cart
	 * @cart_prices: array the new cart prices
	 * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
	 *
	 */
	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 *
	 */
	function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}
		$this->getPaymentCurrency ($method);

		$paymentCurrencyId = $method->payment_currency;
		return;
	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 *
	 * @param VirtueMartCart cart: the cart object
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 *
	 */
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {

		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 *
	 * @param integer $order_id The order ID
	 * @return mixed Null for methods that aren't active, text (HTML) otherwise
	 */
	public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {

		$this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	/**
	 * @param $orderDetails
	 * @param $data
	 * @return null
	 */
	function plgVmOnUserInvoice ($orderDetails, &$data) {

		if (!($method = $this->getVmPluginMethod ($orderDetails['virtuemart_paymentmethod_id']))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return NULL;
		}
		//vmdebug('plgVmOnUserInvoice',$orderDetails, $method);

		if (!isset($method->send_invoice_on_order_null) or $method->send_invoice_on_order_null==1 or $orderDetails['order_total'] > 0.00){
			return NULL;
		}

		if ($orderDetails['order_salesPrice']==0.00) {
			$data['invoice_number'] = 'reservedByPayment_' . $orderDetails['order_number']; // Never send the invoice via email
		}
	}

	/**
	 * @param $virtuemart_paymentmethod_id
	 * @param $paymentCurrencyId
	 * @return bool|null
	 */
	function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId) {

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return FALSE;
		}

		if(empty($method->email_currency)){

		} else if($method->email_currency == 'vendor'){
			$vendor_model = VmModel::getModel('vendor');
			$vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
			$emailCurrencyId = $vendor->vendor_currency;
		} else if($method->email_currency == 'payment'){
			$emailCurrencyId = $this->getPaymentCurrency($method);
		}
	}

	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id  method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 */
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {

		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}
	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

		return $this->setOnTablePluginParams ($name, $id, $table);
	}


	protected function renderPluginName( $plugin ) {

		$return ='
		<style>.lunar-wrapper .payment_logo img {
				height: 30px;
				padding: 2px;
			}</style>';
		$return .= "<div class='lunar-wrapper' style='display: inline-block'><div class='lunar_title' >" . $plugin->title . "</div>";
		$return .= "<div class='payment_logo' >";

		$path = JURI::root().'plugins/vmpayment/lunar/images/' ;
		$allcards = array('mastercard' =>'mastercard','maestro' =>'maestro','visa' =>'visa','visaelectron' =>'visaelectron');

		if (empty($plugin->card)) {
			$cards = $allcards;
		} else {
			$cards = $plugin->card;
		}

		foreach($cards as $card) {
			if(isset($allcards[$card]) && isset($plugin->$card)){
				$return .= "<img src='" . $path . $plugin->$card . "' />";
			}
		}

		$return .= "</div></div>";
		$return .= '<div class="lunar_desc" >' . $plugin->description . '</div>';

		if ( $plugin->test_mode == 1 ) {
			$return .= '<div class="payment_box payment_method_lunar"><p>TEST MODE ENABLED. In test mode, you can use the card number 4100 0000 0000 0000 with any CVC and a valid expiration date. "<a href="https://github.com/paylike/sdk">See Documentation</a>".</p></div>';
		}

		$layout = vRequest::getCmd('layout', 'default');
		$view = vRequest::getCmd('view', '');

		if($plugin->checkout_mode === 'before' && $view === 'cart') {
			if(!isset(plgVmPaymentLunar::$IDS[$plugin->virtuemart_paymentmethod_id])) {
				$return .= $this->renderByLayout('pay_before', array(
					'method'=>$plugin
				));
			//$return .="<pre>".print_r($plugin,TRUE)."</pre>";
			}
			plgVmPaymentLunar::$IDS[$plugin->virtuemart_paymentmethod_id] = true;
		}
		return $return;
	}

	/* get current joomla version */
	function getJoomlaVersions() {
		jimport('joomla.version');
		$version = new JVersion();
		return $version->RELEASE;
	}

	/* get current Virtuemart version */
	function getVirtuemartVersions() {
		return vmVersion::$RELEASE;
	}

	function setKey($method){
		if ( $method->test_mode == 1 ) {
			$privateKey = $method->test_api_key;
			$publicKey = $method->test_public_key;
		} else { // if live mode
			$privateKey = $method->live_api_key;
			$publicKey = $method->live_public_key;
		}
		\Lunar\Client::setKey( $privateKey ); // set private key for further lunar functions
		$this->publicKey = $publicKey;
		return $publicKey;
	}

	/**
	 *  Used for many different purposes (Payment Capture, Refund, Half Refund and Void)
	 */
	function plgVmOnSelfCallFE( $type, $name, &$render ) {
		$id = vRequest::getInt('virtuemart_paymentmethod_id',0);
		if ( ! ( $method = $this->getVmPluginMethod( $id ) ) ) {
			return NULL;
		}
		// Another method was selected, do nothing
		if ( ! $this->selectedThisElement( $method->payment_element ) ) {
			return false;
		}
		$transactionId = vRequest::get('transactionId');

		$this->getPaymentCurrency($method);

		$json = new stdClass;
		$json->error = '';
		$json->success = '0';
		$this->setKey( $method ); // set private key for further lunar functions

		if($method->checkout_mode === 'after') {

			//$response have all sent datas, so we can compare
			$response = \Lunar\Transaction::fetch( $transactionId);
			if(isset($response['transaction']['custom'])) {
				$transactionAmount = (int)$response['transaction']['amount'];
				$transactionCurrency = $response['transaction']['currency'];
				//get original values from cart session
				$cart = VirtueMartCart::getCart(false);
				$modelOrder = VmModel::getModel ('orders');
				if(!empty($cart->virtuemart_order_id)) {
					$order = $modelOrder->getOrder($cart->virtuemart_order_id);
					$details = $order['details']['BT'];

					$lunarCurrency = new LunarCurrency();

					$orderTotal = $details->order_total;
					$price = vmPSPlugin::getAmountValueInCurrency($orderTotal, $method->payment_currency);
					$currency = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
					$precision = $lunarCurrency->getLunarCurrency($currency)['exponent'] ?? 2;
					$priceInCents = (int) ceil( round($price * $lunarCurrency->getLunarCurrencyMultiplier($currency), $precision));

					if($transactionAmount !== $priceInCents || $transactionCurrency !== $currency ) {
						$json->error = 'Error in Order amount ' . $priceInCents .' '. $currency;
					} else if((int)$cart->virtuemart_order_id !== (int)$response['transaction']['custom']['orderId']) {
						$json->error = 'Error transaction not for this order' . $cart->virtuemart_order_id;
					} else {
						$json->cart_order_id  = $cart->virtuemart_order_id;
						$cart->emptyCart ();
						$oldStatut = $details->order_status;
						// we clean the cart now and update the order
						$order['order_status'] = $this->getNewStatus ($method);
						$order['customer_notified'] = 1;
						$order['comments'] = '';
						$this->updateTransactionId($transactionId,$json->cart_order_id);

						/**
						 * There is no VM config setting for os_trigger_paid
						 * In the future, we must set the status for capture on vmConfig
						 * Add the additional info here
						 */
						if ($method->capture_mode === 'instant') {
							$date = JFactory::getDate();
							$today = $date->toSQL();
							$order['paid_on'] = $today;
							$order['paid'] = $orderTotal;
						}

						$modelOrder->updateStatusForOneOrder ($details->virtuemart_order_id, $order, TRUE);
						$json->order_id  = $details->virtuemart_order_id;
						$json->oldStatut  = $oldStatut;
						$json->status  = $order['order_status'];
						$json->success = '1';
					}
				} else {
					$json->error = 'No order id found';

					$json->order = $modelOrder->getOrder((int)$response['transaction']['custom']['orderId']);
				}
			} else {
				$json->error = 'Cannot fetch Transaction';
			}
		} else {
			$task = vRequest::get('lunarTask');
			$session = JFactory::getSession();
			if($task === 'cartData') {

				$lunarID = uniqid('lunar_');
				$session->set( 'lunar.uniqid', $lunarID);
				$cart = VirtueMartCart::getCart(false);
				$cart->prepareCartData();
				$billingDetail = $cart->BT;
				$lunarCurrency = new LunarCurrency();
				$this->getPaymentCurrency( $method );

				$orderTotal = $cart->cartPrices['billTotal'];
				$price = vmPSPlugin::getAmountValueInCurrency($orderTotal, $method->payment_currency);
				$currency = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
				$precision = $lunarCurrency->getLunarCurrency($currency)['exponent'] ?? 2;
				$priceInCents = (int) ceil( round($price * $lunarCurrency->getLunarCurrencyMultiplier($currency), $precision));

				$lang = JFactory::getLanguage();
				$languages = JLanguageHelper::getLanguages( 'lang_code' );
				$locale = $languages[ $lang->getTag() ]->sef;
				$json->publicKey = $this->publicKey;
				$json->testMode = $method->test_mode;
				$json->locale = $locale;
				$json->currency = $currency;
				$json->amount = $priceInCents;
				$json->exponent = $lunarCurrency->getLunarCurrency($currency)['exponent'];
				$json->customer = new stdClass();
				$json->customer->name = $billingDetail['first_name'] . " " . $billingDetail['last_name'];
				$json->customer->email = $billingDetail['email'];
				$json->customer->phoneNo = $billingDetail['phone_1'];
				$json->customer->IP = $_SERVER["REMOTE_ADDR"];
				$json->platform = array(
					'name' => 'Joomla',
					'version' => $this->getJoomlaVersions(),
					);
				$json->ecommerce = array(
					'name' => 'VirtueMart',
					'version' => $this->getVirtuemartVersions(),
					);
				$json->version = array(
					'name' => 'Lunar',
					'version' => $this->version,
					);
				$json->lunarID = $lunarID; // this is session ID to secure the transaction, it's fetch after to validate

			} else if ($task === 'saveInSession') {

				$response = \Lunar\Transaction::fetch( $transactionId);
				$sessionLunarID = $session->get( 'lunar.uniqid','');
				$lunarID = $response['transaction']['custom']['lunarID'];
				if($lunarID !== $sessionLunarID ) {
					$json->error = 1;
					$json->msg = 'Bad Transaction !'.$sessionLunarID;
				} else {
					$json->success ='1';
					//we set here the real transactionId in session
					$session->set( 'lunar.transactionId', $transactionId);
				}
			}
		}
		$jAp = JFactory::getApplication();
		$json->JoomMsg = $jAp->getMessageQueue();
		echo json_encode($json);
		jexit();
	}

	/**
	 *
	 */
	function updateTransactionId($transactionId,$orderid){
			$data = new stdClass();
			$data->lunar_data = $transactionId;
			$data->virtuemart_order_id = $orderid;
			$db	= JFactory::getDBO();
			$db->updateObject($this->_tablename, $data, 'virtuemart_order_id');
	}

	/**
	 * Update lunar on update status
	 */
	function plgVmOnUpdateOrderPayment( $order, $old_order_status ) {

		if (!($method = $this->getVmPluginMethod ($order->virtuemart_paymentmethod_id))) {
			return NULL;
		}
		// Another method was selected, do nothing
		if ( ! $this->selectedThisElement( $method->payment_element ) ) {
			return NULL;
		}

		//@TODO half refund $order->order_status != $method->status_half_refund

		if ($order->order_status != $method->status_capture
			&& $order->order_status != $method->status_success
			&& $order->order_status != $method->status_refunded
			) {
			// vminfo('Order_status not found '.$order->order_status.' in '.$method->status_capture.', '.$method->status_success.', '.$method->status_refunded );
			return null;
		}

		// order exist for lunar ?
		if (!($paymentTable = $this->getDataByOrderId($order->virtuemart_order_id))) {
			return NULL;
		}

		$this->setKey( $method );
		$transactionid = $paymentTable->lunar_data;
		$response = \Lunar\Transaction::fetch( $transactionid );
		vmdebug('Lunar Transaction::fetch',$response);

		if($order->order_status == $method->status_refunded) {
			/* refund payment if already captured */
			if ( !empty($response['transaction']['capturedAmount'])) {
				$amount = $response['transaction']['capturedAmount'];
				$data = array(
					'amount'     => $amount,
					'descriptor' => ""
				);
				$response = \Lunar\Transaction::refund($transactionid, $data );
				vmdebug('Lunar Transaction::refund',$response);
			} else {
				/* void payment if not already captured */
				$data = array(
					'amount' => $response['transaction']['amount']
				);
				$response = \Lunar\Transaction::void( $transactionid, $data );
				vmdebug('Lunar Transaction::void',$response);
			}
		} elseif($order->order_status == $method->status_capture) {
			if ( empty($response['transaction']['capturedAmount'])) {
				$amount = $response['transaction']['amount'];
				$data = array(
					'amount'     => $amount,
					'descriptor' => ""
				);

				$response = \Lunar\Transaction::capture( $transactionid, $data );

				vmdebug('Lunar Transaction::capture',$response);
			}
		}
	}
}
