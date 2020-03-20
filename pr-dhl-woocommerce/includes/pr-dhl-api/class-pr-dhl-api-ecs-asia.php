<?php

use PR\DHL\REST_API\DHL_eCS_Asia\Auth;
use PR\DHL\REST_API\DHL_eCS_Asia\Client;
use PR\DHL\REST_API\DHL_eCS_Asia\Item_Info;
use PR\DHL\REST_API\Drivers\JSON_API_Driver;
use PR\DHL\REST_API\Drivers\Logging_Driver;
use PR\DHL\REST_API\Drivers\WP_API_Driver;
use PR\DHL\REST_API\Interfaces\API_Auth_Interface;
use PR\DHL\REST_API\Interfaces\API_Driver_Interface;

// Exit if accessed directly or class already exists
if ( ! defined( 'ABSPATH' ) || class_exists( 'PR_DHL_API_eCS_Asia', false ) ) {
	return;
}

class PR_DHL_API_eCS_Asia extends PR_DHL_API {
	/**
	 * The URL to the API.
	 *
	 * @since [*next-version*]
	 */
	const API_URL_PRODUCTION = 'https://api.dhlecommerce.dhl.com/';

	/**
	 * The URL to the sandbox API.
	 *
	 * @since [*next-version*]
	 */
	const API_URL_SANDBOX = 'https://sandbox.dhlecommerce.asia/';

	/**
	 * The transient name where the API access token is stored.
	 *
	 * @since [*next-version*]
	 */
	const ACCESS_TOKEN_TRANSIENT = 'pr_dhl_ecs_asia_access_token';

	/**
	 * The API driver instance.
	 *
	 * @since [*next-version*]
	 *
	 * @var API_Driver_Interface
	 */
	public $api_driver;
	/**
	 * The API authorization instance.
	 *
	 * @since [*next-version*]
	 *
	 * @var Auth
	 */
	public $api_auth;
	/**
	 * The API client instance.
	 *
	 * @since [*next-version*]
	 *
	 * @var Client
	 */
	public $api_client;

	/**
	 * Constructor.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $country_code The country code.
	 *
	 * @throws Exception If an error occurred while creating the API driver, auth or client.
	 */
	public function __construct( $country_code ) {
		$this->country_code = $country_code;

		try {
			$this->api_driver = $this->create_api_driver();
			$this->api_auth = $this->create_api_auth();
			$this->api_client = $this->create_api_client();
		} catch ( Exception $e ) {
			throw $e;
		}
	}

	/**
	 * Initializes the API client instance.
	 *
	 * @since [*next-version*]
	 *
	 * @return Client
	 *
	 * @throws Exception If failed to create the API client.
	 */
	protected function create_api_client() {
		// Create the API client, using this instance's driver and auth objects
		return new Client(
			$this->get_api_url(),
			$this->api_driver,
			$this->api_auth
		);
	}

	/**
	 * Initializes the API driver instance.
	 *
	 * @since [*next-version*]
	 *
	 * @return API_Driver_Interface
	 *
	 * @throws Exception If failed to create the API driver.
	 */
	protected function create_api_driver() {
		// Use a standard WordPress-driven API driver to send requests using WordPress' functions
		$driver = new WP_API_Driver();

		// This will log requests given to the original driver and log responses returned from it
		$driver = new Logging_Driver( PR_DHL(), $driver );

		// This will prepare requests given to the previous driver for JSON content
		// and parse responses returned from it as JSON.
		$driver = new JSON_API_Driver( $driver );

		//, decorated using the JSON driver decorator class
		return $driver;
	}

	/**
	 * Initializes the API auth instance.
	 *
	 * @since [*next-version*]
	 *
	 * @return API_Auth_Interface
	 *
	 * @throws Exception If failed to create the API auth.
	 */
	protected function create_api_auth() {
		// Get the saved DHL customer API credentials
		list( $client_id, $client_secret ) = $this->get_api_creds();
		
		// Create the auth object using this instance's API driver and URL
		return new Auth(
			$this->api_driver,
			$this->get_api_url(),
			$client_id,
			$client_secret,
			static::ACCESS_TOKEN_TRANSIENT
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since [*next-version*]
	 */
	public function is_dhl_ecs_asia() {
		return true;
	}

	/**
	 * Retrieves the API URL.
	 *
	 * @since [*next-version*]
	 *
	 * @return string
	 *
	 * @throws Exception If failed to determine if using the sandbox API or not.
	 */
	public function get_api_url() {
		$is_sandbox = $this->get_setting( 'dhl_sandbox' );
		$is_sandbox = filter_var($is_sandbox, FILTER_VALIDATE_BOOLEAN);
		$api_url = ( $is_sandbox ) ? static::API_URL_SANDBOX : static::API_URL_PRODUCTION;

		return $api_url;
	}

	/**
	 * Retrieves the API credentials.
	 *
	 * @since [*next-version*]
	 *
	 * @return array The client ID and client secret.
	 *
	 * @throws Exception If failed to retrieve the API credentials.
	 */
	public function get_api_creds() {
		return array(
			$this->get_setting( 'dhl_api_key' ),
			$this->get_setting( 'dhl_api_secret' ),
		);
	}

	/**
	 * Retrieves a single setting.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $key     The key of the setting to retrieve.
	 * @param string $default The value to return if the setting is not saved.
	 *
	 * @return mixed The setting value.
	 */
	public function get_setting( $key, $default = '' ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Retrieves all of the Deutsche Post settings.
	 *
	 * @since [*next-version*]
	 *
	 * @return array An associative array of the settings keys mapping to their values.
	 */
	public function get_settings() {
		return get_option( 'woocommerce_pr_dhl_ecs_asia_settings', array() );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since [*next-version*]
	 */
	public function dhl_test_connection( $client_id, $client_secret ) {
		try {
			// Test the given ID and secret
			$token = $this->api_auth->test_connection( $client_id, $client_secret );
			// Save the token if successful
			$this->api_auth->save_token( $token );
			
			return $token;
		} catch ( Exception $e ) {
			$this->api_auth->save_token( null );
			throw $e;
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since [*next-version*]
	 */
	public function dhl_reset_connection() {
		return $this->api_auth->revoke();
	}

	public function get_dhl_content_indicator() {
		return array(
			'00' => __('Does not contain Lithium Batteries', 'pr-shipping-dhl' ),
			'01' => __('Lithium Batteries in item', 'pr-shipping-dhl' ),
			'02' => __('Lithium Batteries packed with item', 'pr-shipping-dhl' ),
			'03' => __('Lithium Batteries only', 'pr-shipping-dhl' ),
			'04' => __('Rechargeable Batteries in item', 'pr-shipping-dhl' ),
			'05' => __('Rechargeable Batteries packed with item', 'pr-shipping-dhl' ),
			'06' => __('Rechargeable Batteries only', 'pr-shipping-dhl' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since [*next-version*]
	 */
	public function get_dhl_products_international() {

		$country_code 	= $this->country_code;
		$products 	    = $this->list_dhl_products_international();

		$accepted_products = array();

		foreach( $products as $product_code => $product ){
			//if( strpos( $product['dest_countries'],  $country_code ) !== false ){
				$accepted_products[ $product_code ] = $product['name'];
			//}
		}

		return $accepted_products;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since [*next-version*]
	 */
	public function get_dhl_products_domestic() {

		$country_code 	= $this->country_code;

		$products 	= $this->list_dhl_products_domestic();

		$accepted_products = array();

		foreach( $products as $product_code => $product ){
			//if( strpos( $product['dest_countries'],  $country_code ) !== false ){
				$accepted_products[ $product_code ] = $product['name'];
			//}
		}

		return $accepted_products;
	}

	public function list_dhl_products_international() {

		$products = array(
			'PPM' => array(
				'name' 	    => __( 'Packet Plus International Priority Manifest', 'pr-shipping-dhl' ),
				'dest_countries' => 'AT,BE,BG,HR,CY,CZ,DK,EE,FI,FR,DE,GR,HU,IE,IT,LV,LT,LU,MT,NL,PL,PT,RO,SK,SI,ES,SE,GB'
			),
			'PPS' => array(
				'name' 	    => __( 'Packet Plus International Standard', 'pr-shipping-dhl' ),
				'dest_countries' => ''
			),
			'PKM' => array(
				'name' 	    => __( 'Packet International Priority Manifest', 'pr-shipping-dhl' ),
				'dest_countries' => 'AT,BE,BG,HR,CY,CZ,DK,EE,FI,FR,DE,GR,HU,IE,IT,LV,LT,LU,MT,NL,PL,PT,RO,SK,SI,ES,SE,GB'
			),
			'PKD' => array(
				'name' 	    => __( 'Packet International Standard', 'pr-shipping-dhl' ),
				'dest_countries' => ''
			),
			'PLT' => array(
				'name' 	    => __( 'Parcel International Direct Standard', 'pr-shipping-dhl' ),
				'dest_countries' => 'AT,BE,HR,CZ,DK,FI,GR,HU,IE,IT,NL,PL,PT,SK,SI,SE,CY,LU,LV,MT,EE,LT,BG,RO,GB,FR,DE,ES,MX,US,MY,CA,TH,PH,VN,IL,AU,IN,JP,KR,ID,SG,CN,NZ'
			),
			'PLE' => array(
				'name' 	    => __( 'Parcel International Direct Expedited', 'pr-shipping-dhl' ),
				'dest_countries' => 'US,TH,IT,IN,FR,ES,DE'
			),
			'PLD' => array(
				'name' 	    => __( 'Parcel International Standard', 'pr-shipping-dhl' ),
				'dest_countries' => 'AT,AU,BE,BG,BR,CA,CH,CL,CM,CY,CZ,DE,DK,EE,EG,ES,FI,FR,GB,GH,GR,HR,HU,CI,ID,IE,IL,IT,JP,KE,LT,LU,LV,MA,MD,MT,MX,MY,NG,NL,NO,NZ,PH,PL,PT,RO,RU,SE,SG,SI,SK,SN,TH,TR,TW,UA,UG,US,VN'
			),
			'PKG' => array(
				'name' 	    => __( 'Packet International Economy', 'pr-shipping-dhl' ),
				'dest_countries' => 'AT,BE,HR,CZ,DK,FI,GR,HU,IE,IT,NL,PL,PT,SK,SI,SE,CY,LU,LV,MT,EE,LT,BG,RO,GB,FR,DE,ES,AD,AE,AF,AG,AI,AL,AM,AO,AQ,AR,AS,AU,AW,AX,AZ,BA,BB,BD,BF,BH,BI,BJ,BL,BM,BN,BO,BQ,BR,BS,BT,BV,BW,BY,BZ,CA,CC,CD,CF,CG,CH,CI,CK,CL,CM,CN,CO,CR,CV,CW,CX,DJ,DM,DO,DZ,EC,EG,EH,ER,ET,FJ,FK,FM,FO,GA,GD,GE,GF,GG,GH,GI,GL,GM,GN,GP,GQ,GS,GT,GU,GW,GY,HK,HM,HN,HT,ID,IL,IM,IN,IO,IQ,IS,JE,JM,JO,JP,KE,KG,KH,KI,KM,KN,KR,KW,KY,KZ,LA,LB,LC,LI,LK,LR,LS,LY,MA,MC,MD,ME,MF,MG,MH,MK,ML,MM,MN,MO,MP,MQ,MR,MS,MU,MV,MW,MX,MY,MZ,NA,NC,NE,NF,NG,NI,NO,NP,NR,NU,NZ,OM,PA,PE,PF,PG,PH,PK,PM,PN,PR,PS,PW,PY,QA,RE,RS,RW,SA,SB,SC,SG,SH,SJ,SL,SM,SN,SO,SR,SS,ST,SV,SX,SZ,TC,TD,TF,TG,TH,TJ,TK,TL,TM,TN,TO,TR,TT,TV,TW,TZ,UA,UG,UM,UY,UZ,VA,VC,VE,VG,VI,VN,VU,WF,WS,XK,YE,YT,ZA,ZM,ZW'
			),
		);

		return $products;
	}

	public function list_dhl_products_domestic() {

		$products = array(
			'PDO' => array(
				'name' 	    => __( 'Parcel Domestic', 'pr-shipping-dhl' ),
				'dest_countries' => 'TH,VN,AU,MY'
			),
			'PDE' => array(
				'name' 	    => __( 'Parcel Domestic Expedited', 'pr-shipping-dhl' ),
				'dest_countries' => 'AU,VN'
			),
			'PDR' => array(
				'name' 	    => __( 'Parcel Return', 'pr-shipping-dhl' ),
				'dest_countries' => 'TH,VN,MY'
			),
			'SDP' => array(
				'name' 	    => __( 'DHL Parcel Metro', 'pr-shipping-dhl' ),
				'dest_countries' => 'VN,TH,MY'
			),
		);

		return $products;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since [*next-version*]
	 */
	public function get_dhl_label( $args ) {
		error_log( 'ARGS:' );
		error_log( print_r( $args, true ) );

		$settings = $args[ 'dhl_settings' ];

		$order_id = isset( $args[ 'order_details' ][ 'order_id' ] )
			? $args[ 'order_details' ][ 'order_id' ]
			: null;

		$uom = get_option( 'woocommerce_weight_unit' );
		try {
			$item_info = new Item_Info( $args, $uom );
		} catch (Exception $e) {
			throw $e;
		}

		// Create the shipping label
		$this->api_client->reset_current_shipping_label();
		$this->api_client->add_item( $item_info );
		$this->api_client->update_account_id( $args );
		$this->api_client->update_label_info( $args );
		$this->api_client->update_pickup_address( $args );
		$this->api_client->update_shipper_address( $args );
		$this->api_client->update_access_token();
		//error_log( "test ecs asia" );
		//error_log( print_r( get_option( 'pr_dhl_ecs_asia_label'), true ) );
		$label_response 	= $this->api_client->create_shipping_label( $order_id );
		error_log( 'RESPONSE:' );
		error_log( print_r( $label_response, true ) );
		$label_response 	= json_decode( $label_response );

		$response_status 	= $label_response->labelResponse->bd->responseStatus;
		if( $response_status->code != 200 ){
			throw new Exception( 
				"Error: " . $response_status->message . "<br /> " .
				"Detail: " . $response_status->messageDetails[0]->messageDetail 
			);
		}

		if( isset( $label_response->labelResponse->bd->labels[0]->pieces ) ){
			$label_pieces 		= $label_response->labelResponse->bd->labels[0]->pieces;
			foreach( $label_pieces as $piece_id => $piece ){

				$label_pdf_data 	= base64_decode( $piece->content );
				$item_barcode 		= $piece->deliveryConfirmationNo . '.' . $piece->shipmentPieceID;
				$tracking_num 		= $piece->deliveryConfirmationNo;
				$item_file_info 	= $this->save_dhl_label_file( 'item', $item_barcode, $label_pdf_data );
				
			}
		}else{
			$labels_info 		= $label_response->labelResponse->bd->labels[0];
			$label_pdf_data 	= base64_decode( $labels_info->content );
			$item_barcode 		= $labels_info->deliveryConfirmationNo;
			if( empty( $item_barcode ) ){
				$item_barcode = $labels_info->shipmentID;	
			}
			$tracking_num 		= $item_barcode;
			$item_file_info 	= $this->save_dhl_label_file( 'item', $item_barcode, $label_pdf_data );
		}
		
		//$this->save_dhl_label_file( 'item', $item_barcode, $label_pdf_data );

		return array(
			'label_path' => $item_file_info->path,
			'label_url' 	=> $item_file_info->url,
			'item_barcode' => $item_barcode,
			'tracking_number' => $tracking_num,
			'tracking_status' => '',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since [*next-version*]
	 */
	public function delete_dhl_label( $label_info ) {
		if ( ! isset( $label_info['label_path'] ) ) {
			throw new Exception( __( 'DHL Label has no path!', 'pr-shipping-dhl' ) );
		}

		$label_path = $label_info['label_path'];

		if ( file_exists( $label_path ) ) {
			$res = unlink( $label_path );

			if ( ! $res ) {
				throw new Exception( __( 'DHL Label could not be deleted!', 'pr-shipping-dhl' ) );
			}
		}
	}

	/**
	 * Retrieves the filename for DHL item label files.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $barcode The DHL item barcode.
	 * @param string $format The file format.
	 *
	 * @return string
	 */
	public function get_dhl_item_label_file_name( $barcode, $format = 'pdf' ) {
		return sprintf('dhl-label-%s.%s', $barcode, $format);
	}

	/**
	 * Retrieves the filename for DHL order label files (a.k.a. merged AWB label files).
	 *
	 * @since [*next-version*]
	 *
	 * @param string $order_id The DHL order ID.
	 * @param string $format The file format.
	 *
	 * @return string
	 */
	public function get_dhl_order_label_file_name( $order_id, $format = 'pdf' ) {
		return sprintf('dhl-waybill-order-%s.%s', $order_id, $format);
	}

	/**
	 * Retrieves the file info for a DHL item label file.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $barcode The DHL item barcode.
	 * @param string $format The file format.
	 *
	 * @return object An object containing the file "path" and "url" strings.
	 */
	public function get_dhl_item_label_file_info( $barcode, $format = 'pdf' ) {
		$file_name = $this->get_dhl_item_label_file_name($barcode, $format);

		return (object) array(
			'path' => PR_DHL()->get_dhl_label_folder_dir() . $file_name,
			'url' => PR_DHL()->get_dhl_label_folder_url() . $file_name,
		);
	}

	/**
	 * Retrieves the file info for DHL order label files (a.k.a. merged AWB label files).
	 *
	 * @since [*next-version*]
	 *
	 * @param string $order_id The DHL order ID.
	 * @param string $format The file format.
	 *
	 * @return object An object containing the file "path" and "url" strings.
	 */
	public function get_dhl_order_label_file_info( $order_id, $format = 'pdf') {
		$file_name = $this->get_dhl_order_label_file_name( $order_id, $format);

		return (object) array(
			'path' => PR_DHL()->get_dhl_label_folder_dir() . $file_name,
			'url' => PR_DHL()->get_dhl_label_folder_url() . $file_name,
		);
	}

	/**
	 * Retrieves the file info for any DHL label file, based on type.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $type The label type: "item" or "order".
	 * @param string $key The key: barcode for type "item", and order ID for type "order".
	 *
	 * @return object An object containing the file "path" and "url" strings.
	 */
	public function get_dhl_label_file_info( $type, $key ) {

		// Return file info for "order" type
		if ( $type === 'order' ) {
			return $this->get_dhl_order_label_file_info( $key );
		}

		// Return info for "item" type
		return $this->get_dhl_item_label_file_info( $key );
	}

	/**
	 * Saves an item label file.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $type The label type: "item", or "order".
	 * @param string $key The key: barcode for type "item", and order ID for type "order".
	 * @param string $data The label file data.
	 *
	 * @return object The info for the saved label file, containing the "path" and "url".
	 *
	 * @throws Exception If failed to save the label file.
	 */
	public function save_dhl_label_file( $type, $key, $data ) {
		// Get the file info based on type
		$file_info = $this->get_dhl_label_file_info( $type, $key );

		if ( validate_file( $file_info->path ) > 0 ) {
			throw new Exception( __( 'Invalid file path!', 'pr-shipping-dhl' ) );
		}

		$file_ret = file_put_contents( $file_info->path, $data );

		if ( empty( $file_ret ) ) {
			throw new Exception( __( 'DHL label file cannot be saved!', 'pr-shipping-dhl' ) );
		}

		return $file_info;
	}

	/**
	 * Deletes an AWB label file.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $type The label type: "item", "awb" or "order".
	 * @param string $key The key: barcode for type "item", AWB for type "awb" and order ID for type "order".
	 *
	 * @throws Exception If the file could not be deleted.
	 */
	public function delete_dhl_label_file( $type, $key )
	{
		// Get the file info based on type
		$file_info = $this->get_dhl_label_file_info( $type, $key );

		// Do nothing if file does not exist
		if ( ! file_exists( $file_info->path ) ) {
			return;
		}

		// Attempt to delete the file
		$res = unlink( $file_info->path );

		// Throw error if the file could not be deleted
		if (!$res) {
			throw new Exception(__('DHL AWB Label could not be deleted!', 'pr-shipping-dhl'));
		}
	}

	/**
	 * Checks if an order label file already exist, and if not fetches it from the API and saves it.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $order_id The DHL order ID.
	 *
	 * @return object An object containing the "path" and "url" to the label file.
	 *
	 * @throws Exception
	 */
	public function create_dhl_order_label_file( $order_id )
	{
		$file_info = $this->get_dhl_order_label_file_info( $order_id );

		// Skip creating the file if it already exists
		if ( file_exists( $file_info->path ) ) {
			return $file_info;
		}

		// Get the order with the given ID
		$order = $this->api_client->get_order( $order_id );
		if ($order === null) {
			throw new Exception("DHL order {$order_id} does not exist.");
		}

		// For multiple shipments, maybe create each label file and then merge them
		$loader = PR_DHL_Libraryloader::instance();
		$pdfMerger = $loader->get_pdf_merger();

		if( $pdfMerger === null ){

			throw new Exception( __('Library conflict, could not merge PDF files. Please download PDF files individually.', 'pr-shipping-dhl') );
		}

		foreach ( $order['shipments'] as $shipment ) {
			// Create the single AWB label file
			$awb_label_info = $this->create_dhl_awb_label_file( $shipment->awb );

			// Ensure file exists
			if ( ! file_exists( $awb_label_info->path ) ) {
				continue;
			}

			// Ensure it is a PDF file
			$ext = pathinfo($awb_label_info->path, PATHINFO_EXTENSION);
			if ( stripos($ext, 'pdf') === false) {
				throw new Exception( __('Not all the file formats are the same.', 'pr-shipping-dhl') );
			}

			// Add to merge queue
			$pdfMerger->addPDF( $awb_label_info->path, 'all' );
		}

		// Merge all files in the queue
		$pdfMerger->merge( 'file',  $file_info->path );

		return $file_info;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since [*next-version*]
	 */
	public function dhl_validate_field( $key, $value ) {
	}

	/**
	 * Finalizes and creates the current Deutsche Post order.
	 *
	 * @since [*next-version*]
	 *
	 * @return string The ID of the created DHL order.
	 *
	 * @throws Exception If an error occurred while and the API failed to create the order.
	 */
	public function create_order()
	{
		// Create the DHL order
		$response = $this->api_client->create_order();

		$this->get_settings();

		// Get the current DHL order - the one that was just submitted
		$order = $this->api_client->get_order($response->orderId);
		$order_items = $order['items'];

		// Get the tracking note type setting
		$tracking_note_type = $this->get_setting('dhl_tracking_note', 'customer');
		$tracking_note_type = ($tracking_note_type == 'yes') ? '' : 'customer';

		// Go through the shipments retrieved from the API and save the AWB of the shipment to
		// each DHL item's associated WooCommerce order in post meta. This will make sure that each
		// WooCommerce order has a reference to the its DHL shipment AWB.
		// At the same time, we will be collecting the AWBs to merge the label PDFs later on, as well
		// as adding order notes for the AWB to each WC order.
		$awbs = array();
		foreach ($response->shipments as $shipment) {
			foreach ($shipment->items as $item) {
				if ( ! isset( $order_items[ $item->barcode ] ) ) {
					continue;
				}

				// Get the WC order for this DHL item
				$item_wc_order_id = $order_items[ $item->barcode ];
				$item_wc_order = wc_get_order( $item_wc_order_id );

				// Save the AWB to the WC order
				update_post_meta( $item_wc_order_id, 'pr_dhl_dp_awb', $shipment->awb );

				// An an order note for the AWB
				$item_awb_note = __('Shipment AWB: ', 'pr-shipping-dhl') . $shipment->awb;
				$item_wc_order->add_order_note( $item_awb_note, $tracking_note_type, true );

				// Save the AWB in the list.
				$awbs[] = $shipment->awb;

				// Save the DHL order ID in the WC order meta
				update_post_meta( $item_wc_order_id, 'pr_dhl_ecs_asia_order', $response->orderId );
			}
		}

		// Generate the merged AWB label file
		$this->create_dhl_order_label_file( $response->orderId );

		return $response->orderId;
	}
}
