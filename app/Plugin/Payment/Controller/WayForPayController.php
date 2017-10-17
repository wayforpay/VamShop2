<?php 

App::uses('PaymentAppController', 'Payment.Controller');

class WayForPayController extends PaymentAppController {

	const SIGNATURE_SEPARATOR = ';';
	const PURCHASE_URL = 'https://secure.wayforpay.com/pay';

	protected $keysForPurchaseSignature = array(
		'merchantAccount',
		'merchantDomainName',
		'orderReference',
		'orderDate',
		'amount',
		'currency',
		'productName',
		'productCount',
		'productPrice'
	);

	protected $keysForResponseSignature = array(
		'merchantAccount',
		'orderReference',
		'amount',
		'currency',
		'authCode',
		'cardPan',
		'transactionStatus',
		'reasonCode'
	);

	public $uses = array('PaymentMethod', 'Order');
	public $components = array('OrderBase');

	public $module_name = 'WayForPay';
	public $icon = 'w4p.png';
	public $params = array('unit_id' => 0, 'account_id' => 0, 'online' => 1);

	public function settings ()
	{
		$this->set('data', $this->PaymentMethod->findByAlias($this->module_name));
	}

	public function install()
	{
 		$new_module = array();
		$new_module['PaymentMethod']['active'] = '1';
		$new_module['PaymentMethod']['default'] = '0';
		$new_module['PaymentMethod']['name'] = Inflector::humanize($this->module_name);
		$new_module['PaymentMethod']['icon'] = $this->icon;
		$new_module['PaymentMethod']['alias'] = $this->module_name;

		$new_module['PaymentMethodValue'][0]['payment_method_id'] = $this->PaymentMethod->id;
		$new_module['PaymentMethodValue'][0]['key'] = 'w4p_merchant';
		$new_module['PaymentMethodValue'][0]['value'] = 'test_merch_n1';

		$new_module['PaymentMethodValue'][1]['payment_method_id'] = $this->PaymentMethod->id;
		$new_module['PaymentMethodValue'][1]['key'] = 'w4p_secret_key';
		$new_module['PaymentMethodValue'][1]['value'] = 'flk3409refn54t54t*FNJRET';

		$this->Order->OrderStatus->unbindModel(array('hasMany' => array('OrderStatusDescription')));
		$this->Order->OrderStatus->bindModel(
			array('hasOne' => array(
				'OrderStatusDescription' => array(
					'className' => 'OrderStatusDescription',
					'conditions'   => 'language_id = ' . $this->Session->read('Customer.language_id')
				)
			)
			)
		);

		$status_list = $this->Order->OrderStatus->find('all', array('order' => array('OrderStatus.order ASC')));
		$w4p_order_status_list = array();

		foreach($status_list AS $status)
		{
			$status_key = $status['OrderStatus']['id'];
			$w4p_order_status_list[$status_key] = $status['OrderStatusDescription']['name'];
		}
		$this->set('w4p_order_status_list',$w4p_order_status_list);

		$this->PaymentMethod->saveAll($new_module);

		$this->Session->setFlash(__('Module Installed'));
		$this->redirect('/payment_methods/admin/');
	}

	public function uninstall()
	{

		$module_id = $this->PaymentMethod->findByAlias($this->module_name);

		$this->PaymentMethod->delete($module_id['PaymentMethod']['id'], true);
			
		$this->Session->setFlash(__('Module Uninstalled'));
		$this->redirect('/payment_methods/admin/');
	}

	public function before_process ()
	{
		$order = $this->OrderBase->get_order();

		$content = $this->_buildForm($order);
		$content .= '<button class="btn btn-default" type="submit" value="{lang}Confirm Order{/lang}"><i class="fa fa-check"></i> {lang}Confirm Order{/lang}</button></form>';

		return $content;
	}

	public function after_process()
	{
		if ($this->checkResponse($_REQUEST)) {
			$order_id = explode('_',$_REQUEST['orderReference']);
			$order_data = $this->Order->find('first', array('conditions' => array('Order.id' => $order_id[0])));

			$settings = $this->PaymentMethod->find('first', array('conditions' => array('name' => 'WayForPay')));
			$default_order_status = $settings['PaymentMethod']['order_status_id'];

			switch ($_REQUEST['reasonCode']) {
				case '1100' :
					$order_data['Order']['order_status_id'] = $default_order_status;
					break;
				default : $order_data['Order']['order_status_id'] = 1;
			}
			$this->Order->save($order_data);
		}
	}

	public function payment_after($order_id = 0)
	{
		if(!empty($order_id)){
			$order = $this->Order->read(null,$order_id);
			$content = $this->_buildForm($order);
			if ($order['Order']['order_status_id'] == 1) {
				$content .= '<button class="btn btn-default" type="submit" value="{lang}Pay Now{/lang}"><i class="fa fa-check"></i> {lang}Pay Now{/lang}</button></form>';
			}
			return $content;
		}
		return '';
	}

	private function getRequestSignature($options)
	{
		return $this->getSignature($options, $this->keysForPurchaseSignature);
	}

	private function getSignature($options, $keys)
	{
		$settings = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'w4p_secret_key')));
		$secret_key = $settings['PaymentMethodValue']['value'];

		$hash = array();
		foreach ($keys as $dataKey) {
			if (!isset($options[$dataKey])) {
				continue;
			}
			if (is_array($options[$dataKey])) {
				foreach ($options[$dataKey] as $v) {
					$hash[] = $v;
				}
			} else {
				$hash [] = $options[$dataKey];
			}
		}
		$hash = implode(self::SIGNATURE_SEPARATOR, $hash);

		return hash_hmac('md5', $hash, $secret_key);
	}

	private function checkResponse($data)
	{
		$signature = $this->getSignature($data, $this->keysForResponseSignature);

		return $signature == $data['merchantSignature'];
	}

	private function _buildForm($order)
	{
		$payment_url = $this::PURCHASE_URL;

		$settings = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'w4p_merchant')));
		$merchant = $settings['PaymentMethodValue']['value'];

		$amount = number_format($order['Order']['total'],2,'.','');

		$return_url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] .  BASE . '/orders/place_order/';
		$service_url = $_SERVER['HTTP_HOST'];

		$content = $this->validationJS;

		$productNames = array();
		$productQty = array();
		$productPrices = array();
		foreach ($order['OrderProduct'] as $_item) {
		$productNames[] = $_item['name'];
		$productPrices[] = $_item['price'];
		$productQty[] = $_item['quantity'];
		}

		$fields = [
			'merchantAccount' => $merchant,
			'merchantDomainName' => $_SERVER['HTTP_HOST'],
			'orderReference' => $order['Order']['id'] . '_' . time(),
			'orderDate' => strtotime($order['Order']['created']),
			'amount' => $amount,
			'currency' => 'UAH',
		];
		$fields['productName'] = $productNames;
		$fields['productPrice'] = $productPrices;
		$fields['productCount'] = $productQty;

		$fields['merchantSignature'] = $this->getRequestSignature($fields);

		$fields['merchantAuthType'] = 'simpleSignature';
		$fields['merchantTransactionSecureType'] = 'AUTO';
		$fields['returnUrl'] = $return_url;
		//			'serviceUrl' => $this->getConfigData('serviceUrl') ? $this->getConfigData('serviceUrl') : 'http://' . $_SERVER['HTTP_HOST'] . '/WayForPay/response/',
		//			'language' => $this->getConfigData('language'),
		/**
		 * Check phone
		 */
		$phone = str_replace(['+', ' ', '(', ')', '-'], ['', '', '', '', ''], $order['Order']['phone']);
		if (strlen($phone) == 10) {
			$phone = '38' . $phone;
		} elseif (strlen($phone) == 11) {
			$phone = '3' . $phone;
		}

		$fields['clientFirstName'] = $order['Order']['bill_name'];
		$fields['clientEmail'] = $order['Order']['email'];
		$fields['clientPhone'] = $phone;
		$fields['clientCity'] = $order['Order']['bill_city'];

		$content .= '<form name="WayForPay" id="WayForPayForm" method="post" action="' . $payment_url . '">';
		foreach ($fields as $name => $value) {
			if (!is_array($value)) {
				$content .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '">';
			} else {
				foreach ($value as $avalue) {
					$content .= '<input type="hidden" name="' . $name . '[]" value="' . htmlspecialchars($avalue) . '">';
				}
			}
		}

		return $content;
	}
	
}
