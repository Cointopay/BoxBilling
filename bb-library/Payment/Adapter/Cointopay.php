<?php

class Payment_Adapter_Cointopay
{

    private $config = [];

    public function __construct($config)
    {
        $this->config = $config;

        if ( ! function_exists('curl_exec')) {
            throw new Exception('PHP Curl extension must be enabled in order to use Cointopay.com gateway');
        }

        if ( ! $this->config['cointopay_merchant_id']) {
            throw new Exception('Cointopay.com Merchant ID is not configured properly. Please update under: "Configuration -> Payments"');
        }

        if ( ! $this->config['cointopay_security_code']) {
            throw new Exception('Cointopay.com Security Code is not configured properly. Please update under: "Configuration -> Payments"');
        }

    }

    public static function getConfig(){

        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => false,
            'description'                => 'Enter your Cointopay.com Merchant ID, Security Code and API Key to start accepting payments.',
            'form'                       => [

                'cointopay_merchant_id'		=> ['text',['label' => 'Cointopay.com Merchant ID']],
                'cointopay_security_code'   => ['text',['label' => 'Cointopay.com Security Code']]

            ]
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription){

        $invoice = $api_admin->invoice_get(['id' => $invoice_id]);

    	$prams = array();
    	$prams = $this->getOneTimePaymentFields($invoice);
    	$curl_result = $this->_processCURL($prams);

        return $this->_generateForm($curl_result['RedirectURL'], $data);
    }

    public function getOneTimePaymentFields(array $invoice){

        $data = array();

    	//ETRAS
        $data['item_name']          = $this->getInvoiceTitle($invoice);
        $data['item_number']        = $invoice['nr'];
        $data['tax']                = $this->moneyFormat($invoice['tax'], $invoice['currency']);
        $data['bn']                 = "BoxBilling_SP";
        $data['charset']            = "utf-8";

    	//REQUIRED FIELDS FOR COINTOPAY API
    	$data['Checkout']	            = "true";
    	$data['MerchantID']	            = $this->config['cointopay_merchant_id'];
    	$data['Amount']	    	            = $this->moneyFormat($invoice['subtotal'], $invoice['currency']);
    	$data['AltCoinID']	   	    = "1";
    	$data['CustomerReferenceNr']	    = $invoice['id'];
    	$data['SecurityCode']	            = $this->config['cointopay_security_code'];
    	$data['output']	    		    = "json";
    	$data['inputCurrency']	    	    = $invoice['currency'];
        $data['returnurl']                  = $this->config['return_url'];
        //$data['transactionfailurl']         = $this->config['cancel_url'];
        $data['transactionfailurl']         = $this->config['notify_url'];
        $data['transactionconfirmurl']      = $this->config['notify_url'];
        $data['ConfirmURL']      	    = $this->config['notify_url'];

        return $data;
    }

    public function getInvoiceTitle(array $invoice){

        $p = array(
            ':id'=>sprintf('%05s', $invoice['nr']),
            ':serie'=>$invoice['serie'],
            ':title'=>$invoice['lines'][0]['title']
        );
        return __('Payment for invoice :serie:id [:title]', $p);
    }

    private function moneyFormat($amount, $currency){

        //HUF currency do not accept decimal values
        if ($currency == 'HUF') {
            return number_format($amount, 0);
        }

        return number_format($amount, 2, '.', '');
    }

    private function _processCURL($parms){

    	$url  = "https://app.cointopay.com/MerchantAPI";
        if (count($parms) > 0) {
            $url .= '?' . http_build_query($parms);
        }
    	$ch = curl_init($url);
    	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    	$json_response = curl_exec($ch);
        if ($json_response === false) {

            $this->last_curl_error = curl_error($ch);
            $this->last_curl_errno = curl_errno($ch);

            curl_close($ch);

            return false;
        }

        $response = json_decode($json_response, true);
		if (is_string($response)){
				echo '<h3>BadCredentials:'.$response.'</h3>';
		}
        curl_close($ch);

        return $response;
    }

    private function _generateForm($url, $data, $method = 'post'){

        $form  = '';
        $form .= '<form name="payment_form" action="'.$url.'" method="'.$method.'">' . PHP_EOL;
        foreach($data as $key => $value) {
            $form .= sprintf('<input type="hidden" name="%s" value="%s" />', $key, $value) . PHP_EOL;
        }
        $form .=  '<input class="bb-button bb-button-submit" type="submit" value="Pay with Cointopay.com" id="payment_button"/>'. PHP_EOL;
        $form .=  '</form>' . PHP_EOL . PHP_EOL;

        if(isset($this->config['auto_redirect']) && $this->config['auto_redirect']) {
            $form .= sprintf('<h2>%s</h2>', __('Redirecting to Cointopay.com'));
            $form .= "<script type='text/javascript'>$(document).ready(function(){document.getElementById('payment_button').style.display='none';document.forms['payment_form'].submit();});</script>";
        }

        return $form;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id){
        
        $ipn = $data['get'];
	    $tx = $api_admin->invoice_transaction_get(array('id'=>$id));
        
        if(!$tx['invoice_id']) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'invoice_id'=>$data['get']['bb_invoice_id']));
        }
        
        if(!$tx['txn_id'] && isset($ipn['TransactionID'])) {
            $api_admin->invoice_transaction_update(array('id'=>$id, 'txn_id'=>$ipn['TransactionID']));
        }

    	//SETUP PAYMENT VARIABLES
    	$invoice = $api_admin->invoice_get(array('id'=>$data['get']['bb_invoice_id']));
    	$client_id = $invoice['client']['id'];
    	$bd = array(
    	    'id'            =>  $client_id,
    	    'amount'        =>  $this->moneyFormat($invoice['subtotal'], $invoice['currency']),
    	    'description'   =>  'Cointopay.com transaction',
    	    'type'          =>  'Cointopay',
    	);
    	$ipn_status = "Processed";
    	$back_url = "http://" . $_SERVER['SERVER_NAME'] . "/bb/index.php?_url=/";
    	$auto_redirect = false;

        if(!$tx['txn_status'] && isset($ipn['status'])) {
			$transactionData = $this->getTransactiondetail($ipn);
			if(200 !== $transactionData['status_code']){
			throw new Exception($transactionData['message']);
			}
			else{
					if($transactionData['data']['Security'] != $ipn['ConfirmCode']){
						throw new Exception("Data mismatch! ConfirmCode doesn\'t match");
					}
					elseif($transactionData['data']['CustomerReferenceNr'] != $ipn['CustomerReferenceNr']){
						throw new Exception("Data mismatch! CustomerReferenceNr doesn\'t match");
					}
					elseif($transactionData['data']['TransactionID'] != $ipn['TransactionID']){
						throw new Exception("Data mismatch! TransactionID doesn\'t match");
					}
					elseif($transactionData['data']['AltCoinID'] != $ipn['AltCoinID']){
						throw new Exception("Data mismatch! AltCoinID doesn\'t match");
					}
					elseif($transactionData['data']['MerchantID'] != $ipn['MerchantID']){
						throw new Exception("Data mismatch! MerchantID doesn\'t match");
					}
					elseif($transactionData['data']['coinAddress'] != $ipn['CoinAddressUsed']){
						throw new Exception("Data mismatch! coinAddress doesn\'t match");
					}
					elseif($transactionData['data']['SecurityCode'] != $ipn['SecurityCode']){
						throw new Exception("Data mismatch! SecurityCode doesn\'t match");
					}
					elseif($transactionData['data']['inputCurrency'] != $ipn['inputCurrency']){
						throw new Exception("Data mismatch! inputCurrency doesn\'t match");
					}
					elseif($transactionData['data']['Status'] != $ipn['status']){
						throw new Exception("Data mismatch! status doesn\'t match. Your order status is ".$transactionData['data']['Status']);
					}
					
				}

	        $ipn_status = $ipn['status'];

    	    if(strtolower($ipn_status)=="paid" && (!isset($ipn['notenough']) || $ipn['notenough']!="1")) {

    	        //MAKE PAYMENT
    	        $api_admin->client_balance_add_funds($bd);
    	        if($tx['invoice_id']) {
    		        $api_admin->invoice_pay_with_credits(array('id'=>$tx['invoice_id']));
    	        }
    	        $api_admin->invoice_batch_pay_with_credits(array('client_id'=>$client_id));

	            $ipn_status = "Paid";
        		$auto_redirect = true;
        		echo "<h3>Payment Received Successfully. <br><br>&nbsp;&nbsp;Redirecting...<h3>";

	        } else if(strtolower($ipn_status)=="cancel") {

        		$ipn_status = "Failed";
        		echo "<h3>Payment Failed. Contact site admin.<h3>";
        		echo "<a href='".$back_url."'>Home</a>";

	        } else if(isset($ipn['notenough']) && $ipn['notenough']=="1") {

        		$ipn_status = "Not Enough";
        		echo "<h3>Payment Received but not enough. Contact site admin.<h3>";
        		echo "<a href='".$back_url."'>Home</a>";
        		error_log("Transaction#".$ipn['TransactionID']." Freezed due to not enough payment. Cointopay: Line#205");

	        } else { 

        		$ipn_status = $ipn['status'];
        		echo "<h3>Payment Failed. Contact site admin.<h3>";
        		echo "<a href='".$back_url."'>Home</a>";

	        }

            $api_admin->invoice_transaction_update(array('id'=>$id, 'txn_status'=>$ipn_status));
        }

        $d = array(
            'id'        => $id, 
            'error'     => '',
            'error_code'=> '',
	        'status'    => $ipn_status,
            'updated_at'=> date('Y-m-d H:i:s'),
        );

        $api_admin->invoice_transaction_update($d);

    	if($auto_redirect) header("Refresh: 3; URL=".$back_url);
    	exit;
    }
	public function getTransactiondetail($data) {
        $validate = true;

        $merchant_id =  $this->config['cointopay_merchant_id'];
        $confirm_code = $data['ConfirmCode'];
        $url = "https://cointopay.com/v2REAPI?Call=Transactiondetail&MerchantID=".$merchant_id."&output=json&ConfirmCode=".$confirm_code."&APIKey=a";
        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 0
        ));
        $result = curl_exec($curl);
        $result = json_decode($result, true);
        return $result;
    }

}