<?php
/**
 * 
 */
class shopify extends model
{
	public $header;
	public $status_code;
	public $shopifyerror;
	public function get($name, $method)
	{
		$starttime = date('Y-m-d H:i:s');
		$credentials = $this->get_all_credentials();
		$token = $credentials['shopify_token'] ?? null;
		$shopify_url = $credentials['shopify_url'] ?? null;
		if (!$token || !$shopify_url) {
			return false;
		}
		$url = $shopify_url . $method;
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array('X-Shopify-Access-Token:' . $token),
		));
		$response = curl_exec($curl);
		$status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		if (curl_error($curl)) {
			$error_msg = curl_error($curl);
			$this->save_api_log($starttime, $url, '', $error_msg, $name, 'failed', 'GET', '0');
			unset($curl);
			return false;
		} else {
			$this->save_api_log($starttime, $url, '', $response, $name, 'success', 'GET', $status_code);
			$res = json_decode($response, true);
			unset($curl);
			return $res;
		}
	}
	public function paginate($name, $method)
	{
		$starttime = date('Y-m-d H:i:s');
		$credentials = $this->get_all_credentials();
		$token = $credentials['shopify_token'] ?? null;
		$shopify_url = $credentials['shopify_url'] ?? null;
		if (!$token || !$shopify_url) {
			return false;
		}
		$url = $shopify_url . $method;
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "GET",
			CURLOPT_HTTPHEADER => array(
				'X-Shopify-Access-Token: ' . $token,
				'Content-Type: application/json',
			),
			CURLOPT_HEADER => true,
		));
		$response = curl_exec($curl);
		$status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $header_size);
		$body = substr($response, $header_size);
		$parsed = array_map(function ($x) {
			return array_map("trim", explode(":", $x, 2));
		}, array_filter(array_map("trim", explode("\n", $header))));
		$_header = [];
		foreach ($parsed as $key => $value) {
			if (isset($value[1])) {
				$_header[$value[0]] = $value[1];
			}
		}
		if ($status_code == '200') {
			$statusmsg = 'success';
		} else {
			$statusmsg = 'failed';
		}
		$this->header = $_header;
		$this->save_api_log($starttime, $url, '', $body, $name, $statusmsg, 'GET', $status_code);
		$response = json_decode($body, true);
		unset($curl);
		return $response;
	}
	public function post($name, $method, $request)
	{
		$starttime = date('Y-m-d H:i:s');
		$credentials = $this->get_all_credentials();
		$token = $credentials['shopify_token'] ?? null;
		$shopify_url = $credentials['shopify_url'] ?? null;
		if (!$token || !$shopify_url) {
			return false;
		}
		$url = $shopify_url . $method;
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "POST",
			CURLOPT_POSTFIELDS => json_encode($request),
			CURLOPT_HTTPHEADER => array('X-Shopify-Access-Token:' . $token, 'Content-Type: application/json'),
		));
		$response = curl_exec($curl);
		if (curl_error($curl)) {
			$error_msg = curl_error($curl);
			$this->shopifyerror = $error_msg;
			$this->save_api_log($starttime, $url, json_encode($request), $error_msg, $name, 'failed', 'POST', '000');
			unset($curl);
		} else {
			$responseobj = json_decode($response, true);
			$this->status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			if (isset($responseobj['errors'])) {
				$this->shopifyerror = $response;
				$this->save_api_log($starttime, $url, json_encode($request), $response, $name, 'failed', 'POST', $this->status_code);
				unset($curl);
			} else {
				$this->status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				$this->shopifyerror = false;
				$this->save_api_log($starttime, $url, json_encode($request), $response, $name, 'success', 'POST', $this->status_code);
				unset($curl);
				return $responseobj;
			}
		}
		return false;
	}
	public function graphql_post($request, $name = '')
	{
		$starttime = date('Y-m-d H:i:s');
		$credentials = $this->get_all_credentials();
		$header = array(
			'X-Shopify-Access-Token: ' . ($credentials['shopify_token'] ?? ''),
			'Content-Type: application/json',
		);
		$url = $credentials['graphql_url'] ?? null;
		if (!$url) {
			return false;
		}
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => json_encode($request),
			CURLOPT_HTTPHEADER => $header,
		));
		$response = curl_exec($curl);
		$this->status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$header = '{}';
		$this->save_api_log($starttime, $url, json_encode($request), $response, $name, 'success', 'POST', $this->status_code);
		unset($curl);
		$response = json_decode($response);
		$response->status_code = $this->status_code;
		return $response;
	}
	public function put($name, $method, $request)
	{
		$starttime = date('Y-m-d H:i:s');
		$credentials = $this->get_all_credentials();
		$token = $credentials['shopify_token'] ?? null;
		$shopify_url = $credentials['shopify_url'] ?? null;
		if (!$token || !$shopify_url) {
			return false;
		}
		$url = $shopify_url . $method;
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "PUT",
			CURLOPT_POSTFIELDS => json_encode($request),
			CURLOPT_HTTPHEADER => array('X-Shopify-Access-Token:' . $token, 'Content-Type: application/json'),
		));
		$response = curl_exec($curl);
		if (curl_error($curl)) {
			$error_msg = curl_error($curl);
			$this->shopifyerror = $error_msg;
			$this->save_api_log($starttime, $url, json_encode($request), $error_msg, $name, 'failed', 'PUT', '000');
			unset($curl);
		} else {
			$responseobj = json_decode($response, true);
			$this->status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			if (isset($responseobj['errors'])) {
				$this->shopifyerror = $response;
				$this->save_api_log($starttime, $url, json_encode($request), $response, $name, 'failed', 'PUT', $this->status_code);
				unset($curl);
			} else {
				$this->status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				$this->shopifyerror = false;
				$this->save_api_log($starttime, $url, json_encode($request), $response, $name, 'success', 'PUT', $this->status_code);
				unset($curl);
				return $responseobj;
			}
		}
		return false;
	}
	public function del($name, $method)
	{
		$starttime = date('Y-m-d H:i:s');
		$credentials = $this->get_all_credentials();
		$token = $credentials['shopify_token'] ?? null;
		$shopify_url = $credentials['shopify_url'] ?? null;
		if (!$token || !$shopify_url) {
			return false;
		}
		$url = $shopify_url . $method;
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "DELETE",
			CURLOPT_HTTPHEADER => array('X-Shopify-Access-Token:' . $token, 'Content-Type: application/json'),
		));
		$response = curl_exec($curl);
		if (curl_error($curl)) {
			$error_msg = curl_error($curl);
			$this->shopifyerror = $error_msg;
			$this->save_api_log($starttime, $url, '{}', $error_msg, $name, 'failed', 'DELETE', '000');
			unset($curl);
		} else {
			$responseobj = json_decode($response, true);
			$this->status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			if (isset($responseobj['errors'])) {
				$this->shopifyerror = $response;
				$this->save_api_log($starttime, $url, '{}', $response, $name, 'failed', 'DELETE', $this->status_code);
				unset($curl);
			} else {
				$this->status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				$this->shopifyerror = false;
				$this->save_api_log($starttime, $url, '{}', $response, $name, 'success', 'DELETE', $this->status_code);
				unset($curl);
				return $responseobj;
			}
		}
		return false;
	}
	public function get_customers($params = [])
	{
		$path = 'customers.json';
		if (!empty($params)) {
			$path .= '?' . http_build_query($params);
		}
		return $this->paginate('get customers', $path);
	}
	public function get_customer($id)
	{
		$response = $this->get('get customer', "customers/{$id}.json");
		return $response['customer'] ?? null;
	}
	public function get_products($params = [])
	{
		$path = 'products.json';
		if (!empty($params)) {
			$path .= '?' . http_build_query($params);
		}
		return $this->paginate('get products', $path);
	}
	public function webhooks()
	{
		return $this->get('get webhooks', 'webhooks.json');
	}
	public function enablewebhooks($id)
	{
		$hook = $this->from('hook_list')->where('id', $id)->fetch();
		if ($hook['topic'] && $hook['function_name']) {
			if (!$hook['sh_hook_id']) {
				$request = [];
				$address = BASEPATH . 'client/hook/' . $hook['function_name'];
				$request['webhook'] = ['address' => $address, 'topic' => $hook['topic'], 'format' => 'json'];
				$responce = $this->post('create webhooks', 'webhooks.json', $request);
				if ($responce) {
					$this->update('hook_list')->set(['status' => '1', 'sh_hook_id' => $responce['webhook']['id']])->where('id', $id);
				}
			}
		}
	}
	public function disablewebhooks($id)
	{
		$hook = $this->from('hook_list')->where('id', $id)->fetch();
		if ($hook['sh_hook_id']) {
			$request = [];
			$responce = $this->del('delete webhooks', 'webhooks/' . $hook['sh_hook_id'] . '.json');
			$this->update('hook_list')->set(['status' => '0', 'sh_hook_id' => ''])->where('id', $id);

		}
	}
	public function get_product($id)
	{
		$product = $this->get('get product', "products/{$id}.json");
		if ($product) {
			return $product['product'];
		} else {
			return false;
		}
	}
	public function get_varient($id)
	{
		$variant = $this->get('get varient', "variants/{$id}.json");
		if ($variant) {
			return $variant['variant'];
		} else {
			return false;
		}
	}
	public function get_fulfillment_order($order_id)
	{
		$fulfillment_orders = $this->get('get fulfillment orders', 'orders/' . $order_id . '/fulfillment_orders.json');
		if ($fulfillment_orders) {
			return $fulfillment_orders;
		} else {
			return false;
		}
	}
	public function get_order($order_id)
	{
		// echo 'Im herse';
		$order = $this->get('get order', 'orders/' . $order_id . '.json');
		if ($order) {
			//  $this->entityBody = $order;
			//$this->order_create();
			//return true;
			if (isset($order['order'])) {
				return $order['order'];
			}
		}
		return false;
	}
	public function get_shopify_stock($location_id, $inventory_item_id)
	{
		$path = "inventory_levels.json?inventory_item_ids=$inventory_item_id&location_ids=$location_id";
		$inventory_level = $this->get('get inventory_level', $path);
		if ($inventory_level) {
			if (isset($inventory_level['inventory_levels'])) {
				if (isset($inventory_level['inventory_levels'][0])) {
					return $inventory_level['inventory_levels'][0]['available'];
				}
			}
		}
		return false;
	}
	public function cost_update($inventory_item_id, $cost)
	{
		$request = [];
		$request['inventory_item']['cost'] = $cost;
		$request['inventory_item']['id'] = $inventory_item_id;
		$this->put('Update inventory item cost', 'inventory_items/' . $inventory_item_id . '.json', $request);
	}
	public function save_api_log($starttime, $url, $request, $result, $name, $status, $method, $status_code = '001')
	{
		if ($method != 'GET') {
			$values = [];
			$values['starttime'] = $starttime;
			$values['endtime'] = date('Y-m-d H:i:s');
			$values['request'] = $request;
			$values['response'] = $result;
			$values['url'] = $url;
			$values['method'] = $method;
			$values['http_code'] = $status_code;
			$values['title'] = $name;
			$values['_status'] = $status;
			return $this->insertInto('logs_shopify')->values($values)->execute(); // avishyathinu mathram on cheyuka - 12/26/2024				
		}
	}
}
