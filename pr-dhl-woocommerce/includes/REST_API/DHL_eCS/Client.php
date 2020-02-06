<?php

namespace PR\DHL\REST_API\DHL_eCS;

use Exception;
use PR\DHL\REST_API\API_Client;
use PR\DHL\REST_API\Interfaces\API_Auth_Interface;
use PR\DHL\REST_API\Interfaces\API_Driver_Interface;
use stdClass;

/**
 * The API client for DHL eCS.
 *
 * @since [*next-version*]
 */
class Client extends API_Client {
	/**
	 * The customer EKP.
	 *
	 * @since [*next-version*]
	 *
	 * @var string
	 */
	protected $ekp;

	/**
	 * The contact name to use for creating orders.
	 *
	 * @since [*next-version*]
	 *
	 * @var string
	 */
	protected $contact_name;

	/**
	 * {@inheritdoc}
	 *
	 * @since [*next-version*]
	 *
	 * @param string $ekp The customer EKP.
	 * @param string $contact_name The contact name to use for creating orders.
	 */
	public function __construct( $ekp, $contact_name, $base_url, API_Driver_Interface $driver, API_Auth_Interface $auth = null ) {
		parent::__construct( $base_url, $driver, $auth );

		$this->ekp = $ekp;
		$this->contact_name = $contact_name;
	}

	/**
	 * Creates an item on the remote API.
	 *
	 * @since [*next-version*]
	 *
	 * @param Item_Info $item_info The information of the item to be created.
	 *
	 * @return stdClass The item information as returned by the remote API.
	 *
	 * @throws Exception
	 */
	public function create_item( Item_Info $item_info ) {
		// Prepare the request route and data
		$route = $this->customer_route( 'items' );
		$data = $this->item_info_to_request_data( $item_info );

		// Send the request and get the response
		$response = $this->post( $route, $data );

		// Return the response body on success
		if ( $response->status === 200 ) {
			return $response->body;
		}

		// Otherwise throw an exception using the response's error messages
		$message = ! empty( $response->body->messages )
			? implode( ', ', $response->body->messages )
			: strval( $response->body );

		throw new Exception(
			sprintf( __( 'API error: %s', 'pr-shipping-dhl' ), $message )
		);
	}

	/**
	 * Deletes an item from the remote API.
	 *
	 * @since [*next-version*]
	 *
	 * @param int $item_id The ID of the item to delete.
	 *
	 * @return stdClass The response.
	 *
	 * @throws Exception
	 */
	public function delete_item( $item_id ) {
		// Compute the route to the API endpoint
		$route = $this->customer_route( 'items/' . $item_id );

		// Send the DELETE request
		$response = $this->delete( $route );

		// Return the response body on success
		if ( $response->status === 200 ) {
			return $response->body;
		}

		// Otherwise throw an exception using the response's error messages
		$message = ! empty( $response->body->messages )
			? implode( ', ', $response->body->messages )
			: strval( $response->body );

		throw new Exception(
			sprintf( __( 'API error: %s', 'pr-shipping-dhl' ), $message )
		);
	}

	/**
	 * Retrieves the label for a DHL item, by its barcode.
	 *
	 * @param string $item_barcode The barcode of the item whose label to retrieve.
	 *
	 * @return string The raw PDF data for the item's label.
	 *
	 * @throws Exception
	 */
	public function get_item_label($item_barcode)
	{
		$route = sprintf('items/%s/label', $item_barcode);

		$response = $this->get(
			$this->customer_route( $route ),
			array(),
			array(
				'Accept' => 'application/pdf'
			)
		);

		if ($response->status === 200) {
			return $response->body;
		}

		$message = ! empty( $response->body->messages )
			? implode( ', ', $response->body->messages )
			: strval( $response->body );

		throw new Exception(
			sprintf( __( 'API error: %s', 'pr-shipping-dhl' ), $message )
		);
	}

	/**
	 * Retrieves items from the remote API.
	 *
	 * @since [*next-version*]
	 *
	 * @return array The list of items.
	 *
	 * @throws Exception
	 */
	public function get_items() {
		$response = $this->get( $this->customer_route( 'items' ) );

		if ( $response->status === 200 ) {
			return (array) $response->body;
		}

		throw new Exception(
			sprintf(
				__( 'Failed to get items from the API: %s', 'pr-shipping-dhl' ),
				implode( ', ', $response->body->messages )
			)
		);
	}

	/**
	 * Retrieves the current DHL order, or an existing one if an ID is given.
	 *
	 * @since [*next-version*]
	 *
	 * @param int|null $orderId Optional DHL order ID.
	 *
	 * @return array
	 */
	public function get_order($orderId = null)
	{
		$current = get_option( 'pr_dhl_ecs_order', $this->get_default_order_info() );

		if (empty($orderId)) {
			return $current;
		}

		return get_option( 'pr_dhl_ecs_order_' . $orderId, $current );
	}

	/**
	 * Adds an item to the current DHL order.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $item_barcode The barcode of the item to add.
	 * @param string $wc_order The ID of the WooCommerce order.
	 */
	public function add_item_to_order( $item_barcode, $wc_order )
	{
		$order = $this->get_order();

		$order['items'][$item_barcode] = $wc_order;

		update_option( 'pr_dhl_ecs_order', $order );
	}

	/**
	 * Adds an item to the current DHL order.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $item_barcode The barcode of the item to add.
	 */
	public function remove_item_from_order( $item_barcode )
	{
		$order = $this->get_order();

		unset( $order['items'][$item_barcode] );

		update_option( 'pr_dhl_ecs_order', $order );
	}

	/**
	 * Resets the current order.
	 *
	 * @since [*next-version*]
	 */
	public function reset_current_order()
	{
		update_option( 'pr_dhl_ecs_order', $this->get_default_order_info() );
	}

	/**
	 * Creates the DHL eCS order for the current local order of items.
	 *
	 * @since [*next-version*]
	 *
	 * @return object The response data.
	 *
	 * @throws Exception
	 */
	public function create_order()
	{
		$order = $this->get_order();
		$items = $order['items'];
		$barcodes = array_keys( $items );

		$route = $this->customer_route( 'orders' );
		$data = array(
			'itemBarcodes' => $barcodes,
			'paperwork' => array(
				'awbCopyCount' => 1,
				'contactName' => $this->contact_name,
			),
		);

		$response = $this->post($route, $data);

		if ( $response->status === 200 ) {
			$this->close_order( $response->body );

			return $response->body;
		}

		throw new Exception(
			sprintf(
				__( 'Failed to create order: %s', 'pr-shipping-dhl' ),
				implode( ', ', $response->body->messages )
			)
		);
	}

	/**
	 * Closes the current order with information received from the REST API.
	 *
	 * @since [*next-version*]
	 *
	 * @param object $info The information received from the REST API.
	 */
	protected function close_order($info)
	{
		// Get the current order from the options
		$order = $this->get_order();
		// Save the response info in the order
		$order['id'] = $info->orderId;
		$order['shipments'] = $info->shipments;
		// Save the order status if it's given
		if ( ! empty( $info->orderStatus ) ) {
			$order['status'] = $info->orderStatus;
		}
		// Save the order in a new option
		update_option( 'pr_dhl_dp_order_' . $info->orderId, $order );
		// Reset the current order
		$this->reset_current_order();
	}

	/**
	 * Retrieves tracking info for a shipment by its AWB
	 *
	 * @since [*next-version*]
	 *
	 * @param string $awb The AWB of the shipment.
	 *
	 * @return object The tracking info as returned by the remote API.
	 *
	 * @throws Exception
	 */
	public function get_shipment_tracking_info( $awb )
	{
		$response = $this->get( sprintf( 'dpi/tracking/v1/trackings/awb/%s', $awb ) );

		if ( $response->status === 200 ) {
			return $response->body;
		}

		$message = ! empty( $response->body->messages )
			? implode( ', ', $response->body->messages )
			: strval( $response->body );

		throw new Exception(
			sprintf( __( 'API error: %s', 'pr-shipping-dhl' ), $message )
		);
	}

	/**
	 * Transforms an item info object into a request data array.
	 *
	 * @param Item_Info $item_info The item info object to transform.
	 *
	 * @return array The request data for the given item info object.
	 */
	protected function item_info_to_request_data( Item_Info $item_info ) {
		$contents = array();
		foreach ( $item_info->contents as $content_info ) {
			$data = array(
				'contentPieceAmount' => $content_info[ 'qty' ],
				'contentPieceDescription' => $content_info[ 'description' ],
				'contentPieceIndexNumber' => $content_info[ 'product_id' ],
				'contentPieceNetweight' => $content_info[ 'weight' ],
				'contentPieceOrigin' => $content_info[ 'origin' ],
				'contentPieceValue' => $content_info[ 'value' ],
				'contentPieceHsCode' => trim( $content_info[ 'hs_code' ] )
			);
			// Only include HS code if it's not empty
			if ( empty( $content_info[ 'contentPieceHsCode' ] ) ) {
				unset( $data[ 'contentPieceHsCode' ] );
			}
			$contents[] = $data;
		}

		return array(
			'serviceLevel'        => 'PRIORITY',
			'product'             => $item_info->shipment[ 'product' ],
			'custRef'             => $item_info->shipment[ 'label_ref' ],
			'custRef2'            => $item_info->shipment[ 'label_ref_2' ],
			'shipmentAmount'      => $item_info->shipment[ 'value' ],
			'shipmentCurrency'    => $item_info->shipment[ 'currency' ],
			'shipmentGrossWeight' => $item_info->shipment[ 'weight' ],
			'shipmentNaturetype'  => $item_info->shipment[ 'nature_type' ],
			'recipient'           => $item_info->recipient[ 'name' ],
			'recipientPhone'      => $item_info->recipient[ 'phone' ],
			'recipientEmail'      => $item_info->recipient[ 'email' ],
			'addressLine1'        => $item_info->recipient[ 'address_1' ],
			'addressLine2'        => $item_info->recipient[ 'address_2' ],
			'city'                => $item_info->recipient[ 'city' ],
			'postalCode'          => $item_info->recipient[ 'postcode' ],
			'state'               => $item_info->recipient[ 'state' ],
			'destinationCountry'  => $item_info->recipient[ 'country' ],
			'contents'            => $contents
		);
	}

	/**
	 * @return array
	 */
	protected function get_default_order_info() {
		return array(
			'id' => null,
			'status' => null,
			'items' => array(),
			'shipments' => array(),
		);
	}

	/**
	 * Prepares an API route with the customer namespace and EKP.
	 *
	 * @since [*next-version*]
	 *
	 * @param string $route The route to prepare.
	 *
	 * @return string
	 */
	protected function customer_route( $route ) {
		return sprintf( 'dpi/shipping/v1/customers/%s/%s', $this->ekp, $route );
	}
}
