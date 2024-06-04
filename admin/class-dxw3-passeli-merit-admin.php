<?php

class Dxw3_Passeli_Merit_Admin {

	private $plugin_name;
	private $version;
	private $start;
	private $end;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;

		$this->start = date( 'Ymd', strtotime( '20240130' ) ); // TESTING CHANGED TO START OF LIVE
		$this->end = date( 'Ymd' );
		
		add_action( 'wp', array( $this, 'dxw3_cron_schedule' ) );
		add_action( 'dxw3_pm_polling_paid_5min', array( $this, 'dxw3_pm_poll_for_invoices' ) );
	}

	public function enqueue_styles() {
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/dxw3-passeli-merit-admin.css', array(), $this->version, 'all' );
	}

	public function enqueue_scripts() {
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/dxw3-passeli-merit-admin.js', array( 'jquery' ), $this->version, false );
	}

	public function dxw3_cron_schedule() {
        if ( ! wp_next_scheduled( 'dxw3_pm_polling_paid_5min' ) ) {
			wp_schedule_event( time(), '5min', 'dxw3_pm_polling_paid_5min' );
		}
    }

	// Paid invoices need to be polled with scheduled cron runs
	public function dxw3_cron_schedules($schedules){
		if( ! isset( $schedules[ "5min" ] ) ){
			$schedules[ "5min" ] = array(
				'interval' => 5*60,
				'display' => __( 'Once every 5 minutes' ) );
		}
		if( ! isset( $schedules[ "30min" ] ) ){
			$schedules[ "30min" ] = array(
				'interval' => 30*60,
				'display' => __( 'Once every 30 minutes' ) );
		}
		return $schedules;
	}

	// Check the invoice status and change WC order status accordingly
	public function dxw3_pm_poll_for_invoices() {
		$payment_statuses = $this->dxw3_pm_get_invoices_payment_status( $this->start, $this->end );				// Get paid/not paid status from PM for the date window
		foreach( $payment_statuses as $order_id => $status ) {
			if( ! empty( $order_id ) ) {
				$order = wc_get_order( $order_id ) ? wc_get_order( $order_id ) : null;
				if( $order && $status && ( $order->get_status() === 'processing' ) ) $order->update_status( 'completed' );		// If paid, update order status to completed
			}
		}
		$credit_invoices = $this->dxw3_pm_get_credit_invoices( $this->start, $this->end );
		foreach( $credit_invoices as $order_id ) {
			$order = wc_get_order( $order_id ) ? wc_get_order( $order_id ) : null;
			if( $order && ( $order->get_status() !== 'refunded' ) ) $order->update_status( 'refunded' );
		}
		$this->dxw3_pm_set_sent_invoices( $this->start, $this->end );
		return $payment_statuses;
	}

	// Add settings page for API id and key 
	public function dxw3_pm_menu() {
		add_menu_page(
			'dxw3 Passeli Merit',
			'dxw3 Passeli Merit',
			'manage_options',
			'dxw3-passeli-merit',
			array( $this, 'dxw3_pm_settings' ),
			'dashicons-rest-api',
			100
		);
	}

	public function dxw3_pm_settings() {
		include_once 'partials/dxw3-passeli-merit-admin-display.php';

		// In addition to the scheduled status update, visit on the menu page triggers an update
		$payments_status = [];
		$payments_status = $this->dxw3_pm_poll_for_invoices();	
		echo "<br>While visiting this page";
		echo " the payment status of " . ( count( $payments_status ) ? count( $payments_status ) : "none of the" ) . " invoices was received from Passeli Merit.";
		if( count( $payments_status ) ) echo "<br>The status of WooCommerce orders have been updated accordingly.";
	}

	// Admin to show invoice status and id
	function dxw3_pm_show_order_id_admin( $order ) {
		$guid = $order->get_meta( '_pm_invoice' ) ? $order->get_meta( '_pm_invoice' )[ 'InvoiceId' ] : 'Not set';
		$sent  = $order->get_meta( '_pm_invoice_sent' ) ? $order->get_meta( '_pm_invoice_sent' ) : 'Not polled.';
		echo '<p><strong>' . __( 'Order ID Passeli Merit' ).':</strong><br>' . $guid . '</p>';
		echo '<p><strong>' . __( 'Passeli Merit Invoice Sent' ).'?</strong><br>' . $sent . '</p>';
	}

	// Create invoice when order goes to processing
	public function dxw3_pm_order_to_processing( $order_id, $order ) {
		if( did_action( 'woocommerce_order_status_processing' ) > 1 ) return;
		if( $order->get_total() > 0 ) $this->create_invoice( $order );		
	}

	// Add notification to accounting + copy for action, no automatic handling
	public function dxw3_refunded_order_email_to_accounting( $headers, $email_id, $order ) {
		if( $email_id  === 'customer_refunded_order' ) {
			$headers .= "CC: XXX <xxx@xxx.com>\r\n";
			$headers .= "BCC: YYY <yyy@yyy.yyy>\r\n";
		}
		return $headers;
	}

	// Add notification to accounting on cancellation for accounting action, no automatic handling
	public function dxw3_cancelled_order_email_to_accounting( $order_id, $old_status, $new_status, $order ){
		if( $new_status == 'cancelled' ) {
			$wc_emails = WC()->mailer()->get_emails();
			$wc_emails['WC_Email_Cancelled_Order']->recipient .= ',' . 'xxx@xxx.xxx';
			$wc_emails['WC_Email_Cancelled_Order']->trigger( $order_id );
		} 
	}

	// WC is the prefix of a WooCommerce invoice in PM prepended to the order ID; WCC is the prefix of a WooCommerce refunded invoice prepended to the invoice ID
	private function dxw3_pm_get_invoices_payment_status( $start, $end ) {
		$invoices = $invoices_status = [];
		$get_invoices = [
			"PeriodStart" => $start, 
			"PeriodEnd" => $end,
			"UnPaid" => false 
		];
		$invoices = json_decode( $this->post_data( $get_invoices, "v2/getinvoices" ) );
		foreach( $invoices as $invoice ) if( substr( $invoice->InvoiceNo, 0, 2 ) === 'WC' && substr( $invoice->InvoiceNo, 0, 3 ) !== 'WCC' ) 
			$invoices_status[ substr( $invoice->InvoiceNo, 2 ) ] = $invoice->Paid;
		return $invoices_status;
	}

	// Routine to get an invoice of an order not in use
	private function dxw3_pm_get_invoice( $order ) {
		$invoice = null;
		if( $order && $order->get_meta( '_pm_invoice' ) ) {
			$guid = $order->get_meta( '_pm_invoice' )[ 'InvoiceId' ];
			$get_invoice = [
				"Id" => $guid,
				"AddAttachment" => false
			];
			$invoice = json_decode( $this->post_data( $get_invoice, "v2/getinvoice" ) );
		}
		return $invoice;
	}

	// Routine to get the credited invoices of a time period not in use
	private function dxw3_pm_get_credit_invoices( $start, $end ) {
		$invoices = $credited = [];
		$get_invoices = [
			"PeriodStart" => $start, 
			"PeriodEnd" => $end,
			"UnPaid" => false 
		];
		$invoices = json_decode( $this->post_data( $get_invoices, "v2/getinvoices" ) );
		foreach( $invoices as $invoice ) if( substr( $invoice->InvoiceNo, 0, 3 ) === 'WCC' ) $credited[] = substr( $invoice->InvoiceNo, 3 );
		return $credited;
	}

	// Routine to set if the invoice was sent and the method of sending
	private function dxw3_pm_set_sent_invoices( $start, $end ) {
		$invoices = [];
		$get_invoices = [
			"PeriodStart" => $start, 
			"PeriodEnd" => $end,
			"UnPaid" => true 
		];
		$invoices = json_decode( $this->post_data( $get_invoices, "v2/getinvoices" ) );
		foreach( $invoices as $invoice ) { 
			if( substr( $invoice->InvoiceNo, 0, 2 ) === 'WC' && substr( $invoice->InvoiceNo, 0, 3 ) !== 'WCC' ) {
				$order = wc_get_order( substr( $invoice->InvoiceNo, 2 ) );
				if( $order ) {
					if( $invoice->EInvSent ) { 
						$order->update_meta_data( '_pm_invoice_sent', 'Sent as e-invoice.' );
						$order->save();
					} elseif( $invoice->EmailSent ) {
						$order->update_meta_data( '_pm_invoice_sent', 'Sent as email.' );
						$order->save();
					} else {
						$order->update_meta_data( '_pm_invoice_sent', 'The invoice has not been sent.' );
						$order->save();
					}
				}
			}
		}
	}

	// Get a customer from PM, not in use
	private function dxw3_pm_get_customer( $order, $reg_no ) {
		$guid = $order ? $order->get_meta( '_pm_invoice' )[ 'CustomerId' ] : '';
		$get_customer = $guid ? [ "Id" => $guid ] : [ "RegNo" => $reg_no ];
		$customer = $this->post_data( $get_customer, "v1/getcustomers" );
		return $customer;
	}

	// Delete an invoice from PM, not in use
	private function dxw3_pm_delete_invoice( $order ) {
		$guid = $order ? $order->get_meta( '_pm_invoice' )[ 'InvoiceId' ] : '';
		$delete_invoice = [
			"Id" => $guid
		];
		$response = $this->post_data( $delete_invoice, "v2/deleteinvoice" );
		return $response;	
	}

	// Get the tax id from PM for the fixed 24% VAT rate
	private function dxw3_pm_get_tax_id( $tax_base ) {
		$tax_id = '';
		$get_tax_id = $this->post_data( '', "v1/gettaxes" );
		$response_array = json_decode( $get_tax_id, true );
		foreach( $response_array as $tax ) {
			if( $tax[ 'Code' ] === $tax_base ) $tax_id = $tax[ 'Id' ];
		}
		return $tax_id;
	}

	// Creates an invoice in PM, automatic invoicing is disabled
	private function create_invoice( $order ) {

		if(false) $invoice = $this->dxw3_pm_get_invoice( $order );
		if(false) $deleted = $this->dxw3_pm_delete_invoice( $order );
		if(false) $customer = $this->dxw3_pm_get_customer( $order, null );
		if(false) {
			echo "<pre>"; var_dump( json_decode( $customer ) ); echo "</pre>";
			die();
		}
		
		$tax_id = $this->dxw3_pm_get_tax_id( '24 %' );
		$fields = $this->create_invoice_fields( $order, $tax_id );
		$createResponse = $this->post_data( $fields, "v2/sendinvoice" );
		$response_array = json_decode( $createResponse, true );

		if( $response_array == null ) {
			$message = "Laskunluonnin HTTP-vastaus ei ollut odotettua json-muotoa. Vastauksen sisältö: \n\r";
			$this->call_error( $message, $response_array );
			exit;		
		}

		if( is_array( $response_array ) ) {
			$guid = strtoupper( $response_array[ 'InvoiceId' ] );
			if( preg_match( '/^\{?[A-Z0-9]{8}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{12}\}?$/', $guid ) ) {	
				$order->update_meta_data( '_pm_invoice', $response_array );
				$order->save();

				// Do not send invoices automatically
				if( false ) { 
					$send_invoice_electronically = [
						"Id" => $guid,
						"DelivNote" => false
					];
					$sent_response = '';
					if( ! ( $order->get_meta( '_invoice_type' ) === 'email' ) ) {
						$sent_response = $this->post_data( $send_invoice_electronically, "v2/sendinvoicebyemail" );
					} else {
						$sent_response = $this->post_data( $send_invoice_electronically, "v2/sendinvoiceaseinv" );
					}
					if( $sent_response !== '"OK"' ) {
						$message = "Sähköisen lähetyksen vastaus oli odottamaton. Vastauksen sisältö: \n\r";
						$this->call_error( $message, $sent_response );
						exit;		
					}
				}
			}
		}
	}

	// Create the data for the invoice from respective WooCommerce fields
	private function create_invoice_fields( $order, $tax_id ) {
		$current_date = date( "Ymd" );
		$due_date = date( 'Ymd', strtotime( $current_date. ' + 14 days' ) );
		$items = [];
		$order_id = $order->get_id();
		$discount = $order->get_discount_total() ? $order->get_discount_total() : 0;
		$discount_per = ( $discount / $order->get_subtotal() ) * 100;
		foreach ( $order->get_items() as $item ) {
			$price = $item->get_product()->get_price();
			$items[] = [
				"Item" => [
					"Code" => (string) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() ),
					"Description" => $item->get_name(),
					"Type" => 3
				],
				"Quantity" => $item->get_quantity(),
				"Price" => $price,
				"DiscountPct" => number_format( $discount_per, 2, '.', ''  ),
				"DiscountAmount" => number_format( ( ( $discount_per / 100 ) * $price * $item->get_quantity() ), 2, '.', ''  ),
				"TaxId" => $tax_id
			];
		}

		$invoicing_type = '';
		if( $order->get_meta( '_invoice_type' ) === 'email' ) {
			$invoicing_type = "Verkkolasku\nVerkkolaskuosoite: " . $order->get_meta( '_einvoice_address' ) . "\nVerkkolaskuoperaattori: " . ( $order->get_meta( '_einvoice_operator' ) ? $order->get_meta( '_einvoice_operator' ) : '' );
		} else {
			$invoicing_type = "Sähköpostilasku: " . $order->get_billing_email() ? $order->get_billing_email() : '';
		}
		$created_invoice_fields = [
			"Customer" => [
				"Name" => $order->get_billing_company() ? $order->get_billing_company() : ( $order->get_billing_first_name() . " " . $order->get_billing_last_name() ),
				//"RegNo" => $order->get_meta( '_business_id' ), // Merit Aktiva searches for e-invoicing company details based on business id
				//"VatRegNo" => "FI" . str_replace( "-", "", $order->get_meta( '_business_id' ) ),
				"NotTDCustomer" => false, // required, when customer added, false = legal person/company
				"Address" => $order->get_billing_address_1() ? ( $order->get_billing_address_1() . ( $order->get_billing_address_2() ? ", " . $order->get_billing_address_2() : "" )  ) : '',
				"City" => $order->get_billing_city() ? $order->get_billing_city() : '',
				"County" => $order->get_billing_state() ? $order->get_billing_state() : '',
				"PostalCode" => $order->get_billing_postcode() ? $order->get_billing_postcode() : '',
				"CountryCode" => $order->get_billing_country() ? $order->get_billing_country() : 'FI',
				"PhoneNo" => $order->get_billing_phone() ? $order->get_billing_phone() : '',
				"Email" => $order->get_billing_email() ? $order->get_billing_email() : '',
				"EInvOperator" => 1, // $order->get_meta( '_einvoice_operator' ), // 1 = not exist; 3 = Bank/full extent e-invoice / e-invoicing operator name
				"EInvPaymId" => $order->get_meta( '_einvoice_address' ) ? $order->get_meta( '_einvoice_address' ) : '', // e-invoicing address
				"Contact" => ( strlen( $order->get_billing_first_name() . " " . $order->get_billing_last_name() ) > 35 ) ? mb_substr( $order->get_billing_last_name(), 0, 35 ) : ( $order->get_billing_first_name() . " " . $order->get_billing_last_name() )
				],
			"DocDate" => $current_date,
			"DueDate" => $due_date,
			"TransactionDate" => $current_date,
			"InvoiceNo" => "WC" . $order_id,
			"CurrencyCode" => $order->get_currency(),
			"InvoiceRow" => $items,
			"TotalAmount" => number_format( ( $order->get_subtotal() - $discount ), 2, '.', '' ),
			"TaxAmount" => [
								[
									"TaxId" => $tax_id,
									"Amount" => $order->get_total_tax()
								]
							],
			"HComment" =>  "Laskutustapa: " . $invoicing_type . "\n\nMaksaja: " . ( $order->get_meta( '_business_id' ) ? $order->get_meta( '_business_id' ) : '' ),
			"FComment" => "Kommentit:\n" . ( $order->get_customer_note() ? $order->get_customer_note() : '' ),
		];
	return $created_invoice_fields;
	}

	// Create signature for the API request
	private function sign_url( $id, $key, $timestamp, $json ) {
		$signable = $id . $timestamp . $json;
		$rawSig = hash_hmac( 'sha256', $signable, $key, true );
		$base64Sig = base64_encode( $rawSig );
		return $base64Sig;		
	}

	// Routine for all API calls
	private function post_data( $merit_data, $endpoint ) {
		
		$resp_string = "";
		$error_msg = "";
		$response = "";
	
		$ch = curl_init();
		$APIID = get_option( 'dxw3_api_id' );		// API ID from the settings
		$APIKEY = get_option( 'dxw3_api_key' );		// API KEY from the settings
		if( (! $APIID) || (! $APIKEY) ) { 
			$this->call_error( 'API credentials missing.', '' );
			exit;
		}
		$TIMESTAMP = date( "YmdHis" );
	
		$signature = $this->sign_url( $APIID,$APIKEY, $TIMESTAMP,  json_encode( $merit_data ) );
		$url = "https://aktiva.meritaktiva.fi/api/" . $endpoint . "?ApiId=" . $APIID."&timestamp=" . $TIMESTAMP . "&signature=" . $signature;
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Accept: application/json' ) );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $merit_data ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_HEADER, 1 );
		$response = curl_exec( $ch );
		if( curl_errno( $ch ) ) $error_msg = curl_error( $ch );

		$header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$body = substr( $response, $header_size );
	
		if( curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ) != 200 ) {
			$message = "HTTP error " . curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ) . " Vastauksen sisältö: \n\r";
			$response = $error_msg . " - " . $response;
			$this->call_error( $message, $response );
			exit;		
		} else {		
			$resp_string = stripslashes( $body );
		}
		curl_close( $ch );
		return $resp_string;
	}

	// In case of an error, send an email to admin
	private function call_error( $message, $response ) {
		$to = get_option( 'admin_email' );
		$subject = "Verkkokaupan virhe";
		$message .= "<pre>" . $response . "</pre>";
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		//$headers[] = 'CC: '. $cc . "\r\n";
		wp_mail( $to, $subject, $message, $headers );
	}

}
