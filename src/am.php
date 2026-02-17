<?php
	/**
	 * 
	 */
	class am extends model
	{	
		public $pagination;
		public $errorresponce;
		public $http_status;
		public $logid;
		public function save_api_log($starttime,$url,$request,$result,$name,$status,$method,$status_code='001',$savelog=false){
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
			//if($savelog){
				return $this->insertInto('logs_am')->values($values)->execute();
			//}
		}
		public function get($path,$parameter=[],$name=''){
			$credentials = $this->get_all_credentials();
			$token = $credentials['am_token'] ?? null;
			$am_url = $credentials['am_url'] ?? null;
			if(!$token || !$am_url){ return []; }
			$starttime = date('Y-m-d H:i:s');
			$serve_parameters=[];
			$serve_parameters['time']=time();
			$serve_parameters['token']=$token;
			if(is_array($parameter)){
			$serve_parameters['parameters'] = $parameter;
			$serve_parameters  = '?' . http_build_query($serve_parameters);   
			}
			else{
			$serve_parameters =  '?' . http_build_query($serve_parameters).$parameter;
			}
			$url = $am_url.'api/json/'.$path.'/'.$serve_parameters; 
			$ch = curl_init();
			$curlConfig = array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "GET",
			CURLOPT_HTTPHEADER => array("cache-control: no-cache"));
			curl_setopt_array($ch, $curlConfig);
			$result = curl_exec($ch);
			$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if (curl_error($ch)) {
				$error_msg = curl_error($ch);
				$this->save_api_log($starttime,$url,'',$error_msg,$name,'failed','GET',$http_status);
				unset($ch);
				return [];
			}
			elseif($http_status == 200){
				unset($ch);
				$response =  json_decode($result,true);
				$this->save_api_log($starttime,$url,'',  $result,$name,'success','GET',$http_status);
				return $response['response'];
			}
			else{
				$this->save_api_log($starttime,$url,'',  $result,$name,'failed','GET',$http_status);
				unset($ch);
				return [];
			}
		}
		public function paginate($path,$parameter=[],$pagination=[],$name=''){
			$credentials = $this->get_all_credentials();
			$token = $credentials['am_token'] ?? null;
			$am_url = $credentials['am_url'] ?? null;
			if(!$token || !$am_url){ return false; }
			$starttime = date('Y-m-d H:i:s');
			$serve_parameters=[];
			$serve_parameters['time']=time();
			$serve_parameters['token']=$token;
			if(is_array($parameter)){
			$serve_parameters['pagination'] = $pagination;
			$serve_parameters['parameters'] = $parameter;
			$serve_parameters  = '?' . http_build_query($serve_parameters);   
			}
			else{
			$serve_parameters =  '?' . http_build_query($serve_parameters).$parameter;
			}
			$url = $am_url.'api/json/'.$path.'/'.$serve_parameters; 
			$ch = curl_init();
			$curlConfig = array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "GET",
			CURLOPT_HTTPHEADER => array("cache-control: no-cache"));
			curl_setopt_array($ch, $curlConfig);
			$result = curl_exec($ch);
			$this->http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if (curl_error($ch)) {
			$error_msg = curl_error($ch);
			$this->logid = $this->save_api_log($starttime,$url,'',$error_msg,$name,'failed','GET',$this->http_status);
			unset($ch);
			}
			else{
			unset($ch);
			$response =  json_decode($result,true);
			$this->logid = $this->save_api_log($starttime,$url,'',  $result,$name,'success','GET',$this->http_status);
			$this->pagination = $response['meta']['pagination'];
			return $response['response'];
			}
			return false; 
		}
		public function post($path,$request=[],$name=''){			
			$starttime = date('Y-m-d H:i:s');
			$serve_parameters=[];
			$credentials = $this->get_all_credentials();
			$token = $credentials['am_token'] ?? null;
			$am_url = $credentials['am_url'] ?? null;
			if(!$token || !$am_url){ return false; }
			$request['time']="".time();
			$request['token']=$token;
			$url = $am_url.'api/json/'.$path;
			$headers = array('Content-Type: application/json');
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			$result = curl_exec($ch);
			$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$response = json_decode($result,true);
			if($response){
			if(count($response['meta']['errors'])){
			$this->errorresponce = $response['meta']['errors'];
			$this->save_api_log($starttime,$url,json_encode($request),json_encode($response),$name,'failed','POST',$http_status );
			unset($ch);  
			return false;
			}
			else{
			$this->save_api_log($starttime,$url,json_encode($request),json_encode($response),$name,'success','POST',$http_status );
			unset($ch);  
			return $response['response']; 
			}  
			}
			else{
				unset($ch);
				return false;
				$this->save_api_log($starttime,$url,json_encode($request),'no response',$name,'failed','POST',$http_status );
			}
		}
		public function put($path,$request='',$name=''){			
			$starttime = date('Y-m-d H:i:s');
			$serve_parameters=[];
			$credentials = $this->get_all_credentials();
			$token = $credentials['am_token'] ?? null;
			$am_url = $credentials['am_url'] ?? null;
			if(!$token || !$am_url){ return false; }
			$request['time']=time();
			$request['token']=$token;
			$url = $am_url.'api/json/'.$path;
			$headers = array('Content-Type: application/json');
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL,$url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
			$result = curl_exec($ch);
			$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$this->http_status = $http_status;
			$response = json_decode($result,true);
			if(count($response['meta']['errors'])){
				$this->errorresponce = json_encode($response);
				$this->save_api_log($starttime,$url,json_encode($request),json_encode($response),$name,'failed','PUT',$http_status,true);  
			}
			else{
				$this->save_api_log($starttime,$url,json_encode($request),json_encode($response),$name,'success','PUT',$http_status );
			}
			unset($ch);  
			return $response['response']; 
		}
		public function get_raw_product($product_id){
			$parameters=[];
			$parameters[] = ['field' => 'product_id','operator' => '=','include_type'=>'AND','value' => $product_id ];
			$response = $this->get('products',$parameters,'get product');
			return $response[0];
		}
		public function get_product($product_id){
			$parameters=[];
			$parameters[] = ['field' => 'product_id','operator' => '=','include_type'=>'AND','value' => $product_id ];
			$parameters[] = ['field' => 'shopify_flag','operator' => '=','include_type'=>'AND','value' => '1'];//onwardreserve venda ennu paranju
			$response = $this->get('products',$parameters,'get product');
			return $response;
		}
		public function get_am_inventory_attr($product_id,$attr_2){
			$parameters=[];
			$parameters[] = ['field' => 'product_id','operator' => '=','include_type'=>'AND','value' => $product_id ];
			$parameters[] = ['field' => 'attr_2','operator' => '=','include_type'=>'AND','value' => $attr_2 ];
			$parameters[] = ['field' => 'shopify_variant_sync','operator' => '=','include_type'=>'AND','value' => '1'];//onwardreserve venda ennu paranju
			$response = $this->get('inventory',$parameters,'get inventory');
			return $response;
		}
		public function get_am_inventory($product_id){
			$parameters=[];
			$parameters[] = ['field' => 'product_id','operator' => '=','include_type'=>'AND','value' => $product_id ];
			$parameters[] = ['field' => 'shopify_variant_sync','operator' => '=','include_type'=>'AND','value' => '1'];//onwardreserve venda ennu paranju
			$response = $this->get('inventory',$parameters,'get inventory');
			return $response;
		}
		public function getproduct_attributes($product_id){
			$parameters=[];
			$parameters[] = ['field' => 'product_id','operator' => '=','include_type'=>'AND','value' => $product_id ];
			$response = $this->get('product_attributes',$parameters,'get product_attributes');
			return $response;
		}
		public function get_shopify_flag_products($am_style_number)		{
			$parameters=[];
			$parameters[] = ['field' => 'style_number','operator' => '=','include_type'=>'AND','value' => $am_style_number];
			$parameters[] = ['field' => 'shopify_flag','operator' => '=','include_type'=>'AND','value' => '1']; //onwardreserve venda ennu paranju
			return $this->get('products',$parameters,'get products for shopify');	
		}
		public function get_upc_display_products($upc_display)		{
			$parameters=[];
			$parameters[] = ['field' => 'upc_display','operator' => '=','include_type'=>'AND','value' => $upc_display];
			return $this->get('inventory',$parameters,'get inventory');	
		}
		public function get_inventory_by_sku($sku_id)		{
			$parameters=[];
			$inventory = $this->get('inventory/'.$sku_id,$parameters,'get inventory by sku');	
			if(count($inventory)){
				return $inventory[0];
			}
			return false;
		}
		public function get_inventory_by_skuconcat($sku_concat){
			$parameters=[];
			$parameters[] = ['field' => 'sku_concat','operator' => '=','include_type'=>'AND','value' => $sku_concat];
			$inventory = $this->get('inventory',$parameters,'get products for shopify');
			if(count($inventory)){
				return $inventory[0];
			}
			return false;
		}
		public function get_customer_po_order($customer_po)		{
			$parameters=[];
			$parameters[] = ['field' => 'customer_po','operator' => '=','include_type'=>'AND','value' => $customer_po];
			return $this->get('orders',$parameters,'get orders user customer_po');	
		}
		public function get_atsresponse($sku_id,$warehouse)		{
			$parameters=[];
			$parameters[] = ['field' => 'sku_id','operator' => '=','include_type'=>'AND','value' => $sku_id];
			$parameters[] = ['field' => 'warehouse_id','operator' => '=','include_type'=>'AND','value' => $warehouse];
			return $this->get('sku_warehouse',$parameters,'get sku_warehouse');	
		}
		public function get_inventory($product_id){
			$parameters=[];
			$parameters[] = ['field' => 'product_id','operator' => '=','include_type'=>'AND','value' => $product_id ];
			$parameters[] = ['field' => 'shopify_variant_sync','operator' => '=','include_type'=>'AND','value' => '1'];//onwardreserve venda ennu paranju
			$response = $this->get('inventory',$parameters,'get inventory by product_id');
			return $response;
		}
		public function get_inventory_attr($product_id,$attr_2){
			$parameters=[];
			$parameters[] = ['field' => 'product_id','operator' => '=','include_type'=>'AND','value' => $product_id ];
			$parameters[] = ['field' => 'attr_2','operator' => '=','include_type'=>'AND','value' => $attr_2 ];
			$parameters[] = ['field' => 'shopify_variant_sync','operator' => '=','include_type'=>'AND','value' => '1'];//onwardreserve venda ennu paranju
			$response = $this->get('inventory',$parameters,'get inventory attr');
			return $response;
		}
    	public function get_am_stock_sku($sku_id,$warehouse_id=null){
			$parameters=[];
			$parameters[] = ['field' => 'sku_id','operator' => '=','include_type'=>'AND','value' => $sku_id ];
			if($warehouse_id){
				$parameters[] = ['field' => 'warehouse_id','operator' => '=','include_type'=>'AND','value' => $warehouse_id ];
			}
			$response = $this->get('sku_warehouse/',$parameters,'get stock by sku');
			if(count($response)){
			    $qty_cal = $response[0]['qty_inventory'] - $response[0]['qty_open_sales'];
			return $qty_cal;
			}
			
			return false;
		}
		public function get_picktickets_with_sent($order_id){
			$parameters=[];
			$parameters[] = ['field' => 'order_id','operator' => '=','include_type'=>'AND','value' => $order_id];
			$response = $this->get('pick_tickets/',$parameters,'get pick_tickets');
			if(count($response)){
				return $response;
			}
			return false;
		}
		public function get_picktickets_by_orderid($order_id){
			$parameters=[];
			$parameters[] = ['field' => 'order_id','operator' => '=','include_type'=>'AND','value' => $order_id];
			$response = $this->get('pick_tickets/',$parameters,'get pick_tickets');
			if(count($response)){
				return $response;
			}
			return false;
		}
		public function get_picktickets($order_id){
			$parameters=[];
			$parameters[] = ['field' => 'order_id','operator' => '=','include_type'=>'AND','value' => $order_id];
			$parameters[] = ['field' => 'sent_to_shipstation','operator' => '=','include_type'=>'AND','value' => 'NULL'];
			$response1 = $this->get('pick_tickets/',$parameters,'get pick_tickets1');
			$parameters=[];
			$parameters[] = ['field' => 'order_id','operator' => '=','include_type'=>'AND','value' => $order_id];
			$parameters[] = ['field' => 'sent_to_shipstation','operator' => '=','include_type'=>'AND','value' => '0'];
			$response2 = $this->get('pick_tickets/',$parameters,'get pick_tickets2');
			$response = array_merge($response1,$response2);
			if(count($response)){
				return $response;
			}
			return false;
		}
		public function get_all_picktickets($order_id){
			$parameters=[];
			$parameters[] = ['field' => 'order_id','operator' => '=','include_type'=>'AND','value' => $order_id];
			return $this->get('pick_tickets/',$parameters,'get pick_tickets1');
		}
		public function updatesent_to_shipstation($pickticket_id){
			$request = [];
            $request = ['sent_to_shipstation'=>'1'];
			$response = $this->put('pick_tickets/'.$pickticket_id,$request,'update sent_to_shipstation');
		}
		public function get_order($order_id){
			$parameters=[];
			$response = $this->get('orders/'.$order_id,$parameters,'get orders');
			if(count($response)){
				return $response[0];
			}
			return false;
		}
		public function ship_methods(){
			$parameters=[];
			$response = $this->get('ship_methods/',$parameters,'get ship_methods');
			if(count($response)){
				return $response;
			}
			return false;
		}
		public function get_pickticket($id){
			$parameters=[];
			$response = $this->get('pick_tickets/'.$id,$parameters,'get pick_tickets');
			if(count($response)){
				return $response[0];
			}
			return false;
		}
		public function getshipment($id){
			$parameters=[];
			$response = $this->get('shipments/'.$id,$parameters,'get shipments');
			if(count($response)){
				return $response[0];
			}
			return false;
		}
		public function getinvoice($id){
			$parameters=[];
			$response = $this->get('invoices/'.$id,$parameters,'get invoice');
			if(count($response)){
				return $response[0];
			}
			return false;
		}
		public function getinvoicequick($id){
			$parameters=[];
			$parameters[] = ['field' => 'qb_sync','operator' => '=','include_type'=>'AND','value' => '1'];
			$response = $this->get('invoices/'.$id,$parameters,'get invoice');
			if(count($response)){
				return $response[0];
			}
			return false;
		}
		public function get_invoice_via_order($order_id){
			$parameters=[];
			$parameters[] = ['field' => 'order_id','operator' => '=','include_type'=>'AND','value' => $order_id];
			$response = $this->get('invoices',$parameters,'get invoice');
			if(count($response)){
				return $response[0];
			}
			return false;
		}
		public function create_shipment($request){
			$response = $this->post('shipments/',$request,'create shipments');
			if($response){
				return $response[0];
			}
			return false;
		}
		public function get_receiver($receiver_id){
			$parameters=[];
			$response = $this->get('receivers/'.$receiver_id,$parameters,'get receivers');
			if(count($response)){
				return $response[0];
			}
			return false;
		}
		public function get_customers($customerid){
			$parameters=[];
			$response = $this->get('customers/'.$customerid,$parameters,'get customer');
			if(count($response)){
				return $response[0];
			}
			return false;
		}
		public function get_all_warehouse(){
			$parameters=[];
			$response = $this->get('warehouses',$parameters,'get warehouses');
			if(count($response)){
				return $response;
			}
			return false;
		}
		public function get_all_division(){
			$parameters=[];
			$response = $this->get('divisions',$parameters,'get division');
			if(count($response)){
				return $response;
			}
			return false;
		}
		public function get_all_customers(){
			$parameters=[];
			$response = $this->get('customers',$parameters,'get customers');
			if(count($response)){
				return $response;
			}
			return false;
		}
		public function get_alloweed_warehouse($warehouseid){
			$parameters=[];
			$response = $this->get('warehouses/'.$warehouseid,$parameters,'get warehouses');
			if(count($response)){
				return $response;
			}
			return false;
		}
		public function get_all_vendors(){
			$parameters=[];
			$response = $this->get('vendors',$parameters,'get vendors');
			if(count($response)){
				return $response;
			}
			return false;
		}
		public function get_all_currencies(){
			$parameters=[];
			$response = $this->get('currencies',$parameters,'get vendors');
			if(count($response)){
				return $response;
			}
			return false;
		}
		public function get_skuids_for_stock($style_number){
			$parameters=[];
			$parameters[] = ['field' => 'active','operator' => '=','include_type'=>'AND','value' => '1'];
			$parameters[] = ['field' => 'style_number','operator' => '=','include_type'=>'AND','value' => $style_number];
			$inventory = $this->get('inventory',$parameters,'get inventory');
			$blank_sku_details = array_column($inventory,'blank_sku_detail','sku_id');

			$skuproductmap = array_column($inventory,'product_id','sku_id');
			return ['sku_ids'=>array_column($inventory, 'sku_id'),'product_ids'=>array_unique(array_column($inventory, 'product_id')),'blank_sku_details'=>$blank_sku_details,'skuproductmap'=>$skuproductmap];
		}

}
