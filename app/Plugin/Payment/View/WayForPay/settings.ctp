<?php

echo $this->Form->input('wayforpay.w4p_merchant', array(
	'label' => __d('wayforpay','Merchant'),
	'type' => 'text',
	'value' => $data['PaymentMethodValue'][0]['value']
	));

echo $this->Form->input('wayforpay.w4p_secret_key', array(
	'label' => __d('wayforpay','Secret key'),
	'type' => 'text',
	'value' => $data['PaymentMethodValue'][1]['value']
	));

?>