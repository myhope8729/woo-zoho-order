<?php
/*
* Plugin Name: WooCommerce Zoho Order Integration Plugin
* Description: Integrates WooCommerce Order with Zoho Contacts module and Zoho Sales Order module.
* Version: 1.0.0
* Author: myhope1227
* Plugin URI: 
*
* 
*/ 
// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;

if( !class_exists( 'woo_zoho_order' ) ):
class woo_zoho_order{

	public function instance(){
		add_action('admin_menu', array($this, 'plugin_admin_page'));
		add_action('woocommerce_order_status_processing', array($this, 'process_contact_module'));
	}

	public function plugin_admin_page() {
		add_menu_page('WooCommerce Zoho Order Integration', 'Zoho Order', 'manage_options', 'zoho_order', array($this, 'plugin_setting'));
		add_action('admin_init', array($this, 'register_plugin_setting'));
	}

	public function register_plugin_setting(){
		register_setting('woo_zoho_order_settings', 'zoho_client_id');
		register_setting('woo_zoho_order_settings', 'zoho_client_secret');
		register_setting('woo_zoho_order_settings', 'zoho_callback_url');
		register_setting('woo_zoho_order_settings', 'zoho_grant_token');

		$client_id 		= get_option('zoho_client_id');
		$client_secret 	= get_option('zoho_client_secret');
		$callback_url 	= get_option('zoho_callback_url');
		$grant_token 	= get_option('zoho_grant_token', '');
		$refresh_token 	= get_option('zoho_refresh_token', '');

		if ($refresh_token != ''){
			$url = "https://accounts.zoho.com/oauth/v2/token/revoke";
			$args=array(
				'method' => "POST",
				'body' => 'token='.$refresh_token
			);
			$response = wp_remote_request( $url , $args); 
		}

		if ($grant_token != ''){
			$url = "https://accounts.zoho.com/oauth/v2/token";
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url); 
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS,
			            "grant_type=authorization_code&client_id=".$client_id."&client_secret=".$client_secret."&redirect_uri=".$callback_url."&code=".$grant_token);

			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

			$response = curl_exec($ch);
	  
			$tokenRes = json_decode($response);
			if (empty($tokenRes->error)){
				update_option('zoho_access_token', $tokenRes->access_token);
				update_option('zoho_refresh_token', $tokenRes->refresh_token);
				update_option('zoho_token_created', time());
			}	
		}
	}

	public function plugin_setting(){
?>
		<div class="wrap">
			<h2>Zoho Order Integration Setting</h2>
			<form action="options.php" method="post" id="woo_zoho_order_settings">
		<?php 
			settings_fields( 'woo_zoho_order_settings' ); 
			do_settings_sections( 'woo_zoho_order_settings' );
		?>
				<table class="form-table">
					<tbody>
						<tr>
							<th><label for="zoho_client_id">Client ID</label></th>
							<td><input type="text" name="zoho_client_id" id="zoho_client_id" class="regular-text" value="<?php echo esc_attr( get_option('zoho_client_id') ) ?>" /></td>
						</tr>
						<tr>
							<th><label for="zoho_client_secret">Client Secret</label></th>
							<td><input type="text" name="zoho_client_secret" id="zoho_client_secret" class="regular-text" value="<?php echo esc_attr( get_option('zoho_client_secret') ) ?>" /></td>
						</tr>
						<tr>
							<th><label for="zoho_callback_url">Callback URL</label></th>
							<td><input type="text" name="zoho_callback_url" id="zoho_callback_url" class="regular-text" value="<?php echo esc_attr( get_option('zoho_callback_url') ) ?>" /></td>
						</tr>
						<tr>
							<th><label for="zoho_grant_token">Grant Token</label></th>
							<td><input type="text" name="zoho_grant_token" id="zoho_grant_token" class="regular-text" value="<?php echo esc_attr( get_option('zoho_grant_token') ) ?>" /></td>
						</tr>
					</tbody>
				</table>
				<p class="submit"><input type="submit" name="submit" value="Generate Token" /></p>
			</form>
		</div>
<?php
	}

	public function process_contact_module($order_id){
		
		if (get_option('zoho_access_token', '') != ''){
			$order 			= wc_get_order( $order_id );
			$user_email 	= $order->get_billing_email();
			$contact_id		= 0;

			$zoho_token_created = get_option( 'zoho_token_created', '');
			$time = time();
			$expiry=intval($zoho_token_created)+3600;

			if($expiry<$time){
			    $this->get_access_token(); 
			}  

			$response = $this->zoho_search_contact($user_email);

			$searchResult = wp_remote_retrieve_body($response);

			if(isset($response['response']['code']) && $response['response']['code'] == 200) { 
				$result = json_decode($searchResult);
				$contact_id = $result->data[0]->id;

				if ($order->get_billing_address_1() != '' || $order->get_billing_address_2() != '' ){
					$this->update_order_field($order_id, '_billing_first_name', $result->data[0]->First_Name);
					$this->update_order_field($order_id, '_billing_last_name', 	$result->data[0]->Last_Name);
					$this->update_order_field($order_id, '_billing_phone', 		$result->data[0]->Phone);
					$this->update_order_field($order_id, '_billing_address_1', 	$result->data[0]->Mailing_Street);
					$this->update_order_field($order_id, '_billing_address_2', 	$result->data[0]->Other_Street);
					$this->update_order_field($order_id, '_billing_city', 		$result->data[0]->Mailing_City);
					$this->update_order_field($order_id, '_billing_state', 		$result->data[0]->Mailing_State);
					$this->update_order_field($order_id, '_billing_country', 	$result->data[0]->Mailing_Country);
					$this->update_order_field($order_id, '_billing_postcode', 	$result->data[0]->Mailing_Zip);
					$this->update_order_field($order_id, 'Customer', 			$result->data[0]->Category);
				}else{
					$this->update_order_field($order_id, '_shipping_first_name', 	$result->data[0]->First_Name);
					$this->update_order_field($order_id, '_shipping_last_name', 	$result->data[0]->Last_Name);
					$this->update_order_field($order_id, '_billing_phone', 			$result->data[0]->Phone);
					$this->update_order_field($order_id, '_shipping_address_1', 	$result->data[0]->Mailing_Street);
					$this->update_order_field($order_id, '_shipping_address_2', 	$result->data[0]->Other_Street);
					$this->update_order_field($order_id, '_shipping_city', 			$result->data[0]->Mailing_City);
					$this->update_order_field($order_id, '_shipping_state', 		$result->data[0]->Mailing_State);
					$this->update_order_field($order_id, '_shipping_country', 		$result->data[0]->Mailing_Country);
					$this->update_order_field($order_id, '_shipping_postcode', 		$result->data[0]->Mailing_Zip);
					$this->update_order_field($order_id, 'Customer', 				$result->data[0]->Category);
				}
			}else if ($response['response']['code'] == 204){
				$res = $this->zoho_create_contact($order);
				if ($res['response']['code'] == 201){
					$insertResult = wp_remote_retrieve_body($res);
					$result = json_decode($insertResult);
					$contact_id = $result->data[0]->details->id;
				}
			}

			if ($contact_id != 0){
				$resSales = $this->zoho_create_sales($contact_id, $order_id);
			}
		}
	}

	public function zoho_search_contact($email){
		$access_token 	= get_option('zoho_access_token');
		$url = "https://www.zohoapis.com/crm/v2/Contacts/search?email=".urlencode($email);

		$header['Authorization']='Zoho-oauthtoken ' .$access_token; 
		$args=array(
			'method' => "GET",
			'headers' => $header
		);
		$response = wp_remote_request( $url , $args); 
		return $response;
	}

	public function zoho_create_contact($order){
		$access_token 	= get_option('zoho_access_token');
		$input_data = array();
		if ($order->get_billing_address_1() != '' || $order->get_billing_address_2() != '' ){
			$input_data['data'][0]['First_Name'] 		= $order->get_billing_first_name();
			$input_data['data'][0]['Last_Name']			= $order->get_billing_last_name();
			$input_data['data'][0]['Mailing_Street']	= $order->get_billing_address_1();
			$input_data['data'][0]['Other_Street']		= $order->get_billing_address_2();
			$input_data['data'][0]['Mailing_City']		= $order->get_billing_city();
			$input_data['data'][0]['Mailing_State']		= $order->get_billing_state();
			$input_data['data'][0]['Mailing_Country']	= $order->get_billing_country();
			$input_data['data'][0]['Mailing_Zip']		= $order->get_billing_postcode();
		}else{
			$input_data['data'][0]['First_Name'] 		= $order->get_shipping_first_name();
			$input_data['data'][0]['Last_Name']			= $order->get_shipping_last_name();
			$input_data['data'][0]['Mailing_Street']	= $order->get_shipping_address_1();
			$input_data['data'][0]['Other_Street']		= $order->get_shipping_address_2();
			$input_data['data'][0]['Mailing_City']		= $order->get_shipping_city();
			$input_data['data'][0]['Mailing_State']		= $order->get_shipping_state();
			$input_data['data'][0]['Mailing_Country']	= $order->get_shipping_country();
			$input_data['data'][0]['Mailing_Zip']		= $order->get_shipping_postcode();
		}
		$input_data['data'][0]['Phone']	=	 $order->get_billing_phone();
		$input_data['data'][0]['Email']	= 	$order->get_billing_email();

		$url = "https://www.zohoapis.com/crm/v2/Contacts";
		$header['Authorization']='Zoho-oauthtoken ' .$access_token;
		$body = json_encode($input_data);

		$args = array(
			'method' 	=> "POST",
			'headers' 	=> $header,
			'body'		=> $body
		);
		$response = wp_remote_request( $url, $args );
		return $response;
	}

	public function zoho_create_sales($contact_id, $order_id){
		$access_token 	= get_option('zoho_access_token');

		$sales_data = array();
		$order = wc_get_order( $order_id );

		$sales_data['data'][0]['Subject'] 				= strval($order_id);
    	$sales_data['data'][0]['Contact_Name']['id'] 	= $contact_id;
    	$sales_data['data'][0]['Account_Name']['name']	= "Customers";
    	$sales_data['data'][0]['Billing_Street'] 		= $order->get_billing_address_1();
    	$sales_data['data'][0]['Billing_City'] 			= $order->get_billing_city();
    	$sales_data['data'][0]['Billing_State'] 		= $order->get_billing_state();
    	$sales_data['data'][0]['Billing_Country']		= $order->get_billing_country();
    	$sales_data['data'][0]['Billing_Zip']			= $order->get_billing_postcode();
    	$sales_data['data'][0]['Shipping_Street'] 		= $order->get_billing_address_1();
    	$sales_data['data'][0]['Shipping_City'] 		= $order->get_billing_city();
    	$sales_data['data'][0]['Shipping_State'] 		= $order->get_billing_state();
    	$sales_data['data'][0]['Shipping_Country']		= $order->get_billing_country();
    	$sales_data['data'][0]['Shipping_Zip']			= $order->get_billing_postcode();  

    	$itemCnt = 0;

		foreach ($order->get_items() as $item_id => $item_data) {
		    $product = $item_data->get_product();
		    $product_name = $product->get_name();

		    $item_quantity = $item_data->get_quantity();

		    $url = "https://www.zohoapis.com/crm/v2/Products/search?criteria=(Product_Name:equals:".urlencode($product_name).")";

			$header['Authorization']='Zoho-oauthtoken ' .$access_token; 
			$args=array(
				'method' => "GET",
				'headers' => $header
			);
			$response = wp_remote_request( $url , $args); 

			$product_detail = null;

			if ($response['response']['code'] == 200){
				$product_res = wp_remote_retrieve_body($response);
				$product_detail = json_decode($product_res);
			}
			else{
				continue;
			}

		    for ($i = 0; $i < $item_quantity; $i++){
		    	$sales_data['data'][0]['Product_Details'][$itemCnt]['product'] 			= $product_detail->data[0]->id;
		    	$sales_data['data'][0]['Product_Details'][$itemCnt]['quantity']			= 1;
		    	$sales_data['data'][0]['Product_Details'][$itemCnt]['list_price']		= ($product->get_price()!="")?floatval($product->get_price()):0;
		    	$sales_data['data'][0]['Product_Details'][$itemCnt]['unit_price']		= ($product->get_price()!="")?floatval($product->get_price()):0;
		    	$itemCnt++;
		    }
		}

		$response = array();

		if (isset($sales_data['data'][0]['Product_Details'])){
			$url = "https://www.zohoapis.com/crm/v2/Sales_Orders";
			$header['Authorization']='Zoho-oauthtoken ' .$access_token;
			$body = json_encode($sales_data);

			$args = array(
				'method' 	=> "POST",
				'headers' 	=> $header,
				'body'		=> $body
			);
			$response = wp_remote_request( $url, $args );
		}
	
		return $response;
	}

	public function get_access_token(){
		$client_id = get_option('zoho_client_id');
		$client_secret = get_option('zoho_client_secret');
		$refresh_token = get_option('zoho_refresh_token');
		$url = "https://accounts.zoho.com/oauth/v2/token";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url); 
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS,
		            "grant_type=refresh_token&client_id=".$client_id."&client_secret=".$client_secret."&refresh_token=".$refresh_token);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$response = curl_exec($ch);

		$tokenRes = json_decode($response);
		if (empty($tokenRes->error)){
			update_option('zoho_access_token', $tokenRes->access_token);
			update_option('zoho_token_created', time());
		}
	}

	public function update_order_field($order_id, $fieldName, $contact_val){
		if (!empty($contact_val)){
			if (!empty(get_post_meta($order_id, $fieldName, true))){
				update_post_meta($order_id, $fieldName, $contact_val);	
			}else{
				add_post_meta($order_id, $fieldName, $contact_val);
			}
			
		}
	}
	
}

$woo_zoho=new woo_zoho_order(); 
$woo_zoho->instance();

endif;
