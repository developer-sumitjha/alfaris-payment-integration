<?php
/*
Plugin Name: Alfaris CrediMax/MPGS for Woocommerce
Plugin URI: http://intopros.com/
Description: CrediMax/MPGS payment gateway for woocommerce
Version: 1.0
Author: Intopros Technology
Author URI: http://intopros.com
Domain Path: /languages/
License: GPL
*/

/**
 * CrediMax/MPGS Payment plugin for Woocommerce
 *
 * @package PM/Woocommerce
 * @subpackage Gateways
 */

/**
 * This file is part of CrediMax/MPGS For Woocommerce.
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// https://credimax.gateway.mastercard.com/api/documentation/integrationGuidelines/hostedCheckout/integrationModelHostedCheckout.html
// https://credimax.gateway.mastercard.com/api/documentation/apiDocumentation/rest-json/version/latest/operation/Session%3a%20Create%20Checkout%20Session.html?locale=en_US
// https://credimax.gateway.mastercard.com/api/documentation/integrationGuidelines/supportedFeatures/testAndGoLive.html?locale=en_US

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Loads the Payment class for CrediMax/MPGS
 *
 * @since 1.0
 */
function init_pm_wc_mpgs() {

	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

	load_plugin_textdomain( 'pm-wc-credimax', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	/**
	 * CrediMax/MPGS Payment Gateway
	 *
	 * Provides Hosted Checkout Integration.
	 *
	 * @class 		PM_WC_MPGS
	 * @extends		WC_Payment_Gateway
	 * @version		1.0
	 * @package		PM/Woocommerce/Gateways
	 * @author 		Pluginsmaker
	 */
	class PM_WC_MPGS extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 *
		 * @access public
		 * @return void
		 */
		function __construct() {

			// Unique ID for mpgs gateway
			$this->id					= 'mpgs';

			// URL to credimax logo
			$this->icon					= plugins_url( 'images/logo.png', __FILE__ );

			// Direct integration
			$this->has_fields			= false;
			$this->notify_url			= str_replace( 'http:', 'https:', add_query_arg( 'wc-api', 'PM_WC_MPGS', home_url( '/' ) ) );

			// Title of the payment method shown on the admin page
			$this->method_title			= __( 'CrediMax MPGS', 'pm-wc-credimax' );
			$this->method_description	= __( 'CrediMax Payment method', 'pm-wc-credimax' );

			// Loads settings fields:
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title			= $this->get_option( 'title' );
			$this->description		= $this->get_option( 'description' );

			$this->merchant_id		= $this->get_option( 'merchant_id' );
			$this->api_password		= $this->get_option( 'api_password' );
			$this->lightbox			= false; //$this->get_option( 'lightbox' ) == 'yes';

			$this->debug			= $this->get_option( 'debug' ) == 'yes';
			$this->test_mode		= $this->get_option( 'test_mode' ) == 'yes';

			// Logs
			if ( $this->debug ) $this->log = new WC_Logger();

			// Actions
			add_action( 'woocommerce_receipt_' . $this->id							, array( $this, 'receipt_page' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id	, array( $this, 'process_admin_options' ) );

			// Payment listener/API hook
			add_action( 'woocommerce_api_pm_wc_' . $this->id, array( $this, 'check_notify_response' ) );
		}

		function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title'			=> __( 'Enable/Disable', 'woocommerce' ),
					'type'			=> 'checkbox',
					'label'			=> __( 'Enable CrediMax', 'woocommerce' ),
					'default'		=> 'yes'
				),
				'title' => array(
					'title'			=> __( 'Title', 'woocommerce' ),
					'type'			=> 'text',
					'description'	=> __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default'		=> 'CrediMax',
					'desc_tip'		=> true,
				),
				'description' => array(
					'title'			=> __( 'Customer Message', 'woocommerce' ),
					'type'			=> 'textarea',
					'default'		=> 'Pay using CrediMax'
				),

				// CrediMax custom fields
				'merchant_id' => array(
					'title'			=> __( 'Merchant ID', 'pm-wc-credimax' ),
					'type'			=> 'text',
					'default'		=> ''
				),
				'api_password' => array(
					'title'			=> __( 'API Password', 'pm-wc-credimax' ),
					'type'			=> 'text',
					'default'		=> ''
				),
				/*'lightbox'		=> array(
					'title'			=> __( 'Lightbox enabled', 'pm-wc-credimax' ),
					'type'			=> 'checkbox',
					'label'			=> __( 'Enable Lightbox button', 'woocommerce' ),
					'default'		=> 'no',
				),*/

				'debug' => array(
					'title'			=> __( 'Debug', 'woocommerce' ),
					'type'			=> 'checkbox',
					'label'			=> __( 'Enable logging', 'woocommerce' ),
					'default'		=> 'no',
					'description'	=> sprintf( __( 'Log MPGS events, such as requests, inside <code>woocommerce/logs/mpgs-%s.txt</code>', 'pm-wc-credimax' ), sanitize_file_name( wp_hash( 'mpgs' ) ) ),
				),
				'test_mode' => array(
					'title'			=> __( 'Test Mode', 'woocommerce' ),
					'type'			=> 'checkbox',
					'label'			=> __( 'Enable test mode', 'woocommerce' ),
					'default'		=> 'no',
					'description'	=> __( 'Disable when the gateway is in production.', 'woocommerce' ),
				)				
			);
		}

		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' ...
		 *
		 * @since 1.0
		 */
		public function admin_options() {
?>
			<h3><?php _e( 'MPGS', 'pm-wc-credimax' ); ?></h3>
			<p><?php _e( 'MPGS works by sending the user to MPGS to enter their payment information.', 'pm-wc-credimax' ); ?></p>

			<table class="form-table">
			<?php
    			// Generate the HTML For the settings form.
    			$this->generate_settings_html();
			?>
			</table><!--.form-table-->

			<p>
				<a href="https://credimax.gateway.mastercard.com/ma/" target="_blank">CrediMax MPGS console</a>
			</p>
<?php
		}

		function process_payment( $order_id ) {
			//$order	= new WC_Order( $order_id );
			//$redirect	= add_query_arg( 'order', $order->get_id(), add_query_arg( 'key', $order->get_order_key(), get_permalink( woocommerce_get_page_id( 'pay' ) ) ) );
			$order		= wc_get_order( $order_id );
			$redirect	= add_query_arg( 'order-pay', $order->get_id(), $order->get_checkout_payment_url( true ) );

			delete_post_meta( $order_id, '_successIndicator' );

			$this->print_log( 'process_payment func url=' . $redirect );

			return array(
				'result' 	=> 'success',
				'redirect'	=> $redirect
			);
			
		}

		function receipt_page( $order_id ) {

			$this->print_log( 'receipt_page ' . $order_id );
			$this->print_log( print_r( $_REQUEST, true ) );

			$order = new WC_Order( $order_id );
			$currency = get_woocommerce_currency();
			$operation = 'PURCHASE'; //$this->test_mode ? 'AUTHORIZE' : 'PURCHASE';
			$return_url = add_query_arg( 'order_id', $order_id, $this->notify_url );
			//$return_url = $this->notify_url;
			$session_url = "https://credimax.gateway.mastercard.com/api/rest/version/73/merchant/{$this->merchant_id}/session";
			$amount = $currency == 'BHD' ? number_format( $order->get_total(), 3, '.', '' ) : number_format( $order->get_total(), 2, '.', '' );
			$description = sprintf( __( 'Purchase from %s (Order No. %s)', 'pm-wc-credimax' ), get_bloginfo( 'name' ), $order_id );
			$merchant_name = get_bloginfo( 'name' );
			$merchant_url = home_url();

			if ( strlen( $merchant_name ) > 40 ) {
				$merchant_name = substr( $merchant_name, 0, 40 );
			}

			$data = json_encode( array(
				'apiOperation'	=> 'INITIATE_CHECKOUT',
				//'apiPassword'	=> $this->api_password,
				//'apiUsername'	=> 'merchant.' . $this->merchant_id,
				//'merchant'		=> $this->merchant_id,
				'interaction'	=> array(
					'operation' 	=> $operation,
					'returnUrl'		=> $return_url,
					'cancelUrl'		=> $return_url,
					'merchant'		=> array(
						'name'		=> $merchant_name,
						'url'		=> $merchant_url
					)
				),
				'order'			=> array(
					'id'			=> 's' . $order_id,
					'amount'		=> $amount,
					'currency'		=> $currency,
					'description'	=> $description
				),
			) );

			$this->print_log( '$data' );
			$this->print_log( print_r( $data, true ) );

			$username = 'merchant.' . $this->merchant_id;
			$password = $this->api_password;

			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $session_url );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC );
			curl_setopt( $ch, CURLOPT_USERPWD, "$username:$password" );
			$response = curl_exec( $ch );
			curl_close( $ch );

			$this->print_log( '$response' );
			$this->print_log( print_r( $response, true ) );

			$response = json_decode( $response );
			//parse_str( $response, $response );
			$this->print_log( print_r( $response, true ) );

			/*if ( $response['result'] != 'SUCCESS' || $response['merchant'] != $this->merchant_id ) {
				$this->print_log( 'cause:' . $response['error_cause'] );
				$this->print_log( 'explanation:' . $response['error_explanation'] );
				$txt = sprintf( '%s: %s (Support code: %s)', $response['error_cause'], $response['error_explanation'], array_key_exists( 'error_supportCode', $response) ? $response['error_supportCode'] : '' );
				$this->print_log( $txt );
				$this->cancel_request( $order_id, sprintf( __( 'Error in Create Checkout Session operation: %s', 'pm-wc-credimax'), $txt ) );

			} else {*/

			//if ( $response->error->result != 'SUCCESS' || $response->merchant != $this->merchant_id ) {
			if ( isset( $response->error ) ) {
				$txt = sprintf( '%s: %s (Support code: %s)', $response->error->cause, $response->error->explanation, isset( $response->error->supportCode ) ? $response->error->supportCode : '' );
				$this->print_log( $txt );
				$this->cancel_request( $order_id, sprintf( __( 'Error in Create Checkout Session operation: %s', 'pm-wc-credimax'), $txt ) );

			} else {
				$this->print_log( 'SUCCESS' );
			}

			add_post_meta( $order_id, '_successIndicator', $response->successIndicator );
			$this->print_log( '_successIndicator Adding ' . $response->successIndicator );
			$this->print_log( '_successIndicator: ' . print_r( get_post_meta( $order_id, '_successIndicator' ), true ) );
			$this->print_log( 'merchant_name=' . $merchant_name );
			$url = $this->get_return_url( $order );
?>
<script src="https://credimax.gateway.mastercard.com/static/checkout/checkout.min.js"
	data-error="errorCallback"
	data-cancel="<?php echo $return_url; ?>"
	data-complete="<?php echo $url; ?>"
	data-beforeRedirect="Checkout.saveFormFields"
	data-afterRedirect="Checkout.restoreFormFields"></script>

<p class="waiting_message"><?php _e( 'Waiting...', 'credimax' ); ?></p>
<?php if ( $this->lightbox ) : ?>
<button onclick="jQuery( '.mpgs-pay-button' ).hide();Checkout.showLightbox();" class="mpgs-pay-button lightbox" style="display:none;"><?php _e( 'Pay via CrediMax', 'pm-wc-credimax' ); ?></button>
<?php else: ?>
<button onclick="jQuery( '.mpgs-pay-button' ).hide();Checkout.showPaymentPage();" class="mpgs-pay-button paymentpage" style="display:none;"><?php _e( 'Pay via CrediMax', 'pm-wc-credimax' ); ?></button>
<?php endif; ?>

<script type="text/javascript">
function errorCallback( error ) {
	console.log( 'errorCallback' );
	console.log( JSON.stringify( error ) );
	alert(JSON.stringify( error ))
}

jQuery( function( $ ) {
	Checkout.configure( {
		
		session: {
			id: '<?php echo $response->session->id; ?>',
		}
		
	} );

	$( '.waiting_message' ).hide();
	$( '.mpgs-pay-button' ).show();

<?php if ( ! $this->lightbox ) : ?>
	$( 'body' ).block( {
		message: '<?php esc_js( __( 'Thank you for your order. We are now redirecting you to Credimax to make payment.', 'pm-wc-credimax' ) ); ?>',
		baseZ: 99999,
		overlayCSS:
		{
			background: '#fff',
			opacity: 0.6
		},
		css: {
			padding:        '20px',
			zindex:         '9999999',
			textAlign:      'center',
			color:          '#555',
			border:         '3px solid #aaa',
			backgroundColor:'#fff',
			cursor:         'wait',
			lineHeight:		'24px',
		}
	} );
<?php endif; ?>
	<?php //if ( ! $exists_indicator ) : 
// 		$this->successful_request( $order_id );
		?>
		$( '.mpgs-pay-button' ).click();
	<?php //endif; ?>
} );
</script>
<?php
		}

		function check_notify_response() {

			$this->print_log( 'check_notify_response' );
			$this->print_log( print_r( $_GET, true ) );

			$order_id = isset( $_GET['order_id'] ) ? $_GET['order_id'] : false;
			$resultIndicator = isset( $_GET['resultIndicator'] ) ? $_GET['resultIndicator'] : false;

			if ( $resultIndicator === false ) {
				$this->cancel_request( $order_id, 'No Result indicator.' );
			}

			$successIndicator = get_post_meta( $order_id, '_successIndicator' );
			delete_post_meta( $order_id, '_successIndicator' );

			$this->print_log( 'order_id=' . $order_id );
			$this->print_log( 'resultIndicator (GET) =' . print_r( $resultIndicator, true ) );
			$this->print_log( 'successIndicator (saved) =' . print_r( $successIndicator, true ) );

			//if ( strlen( $resultIndicator ) > 0 and in_array( $resultIndicator, $successIndicator ) ) {
			if ( in_array( $resultIndicator, $successIndicator ) ) {
				$this->successful_request( $order_id );

			} else {
				$this->cancel_request( $order_id, 'Result indicator error.' );
			}
		}

		function successful_request( $order_id, $response_message = false ) {
			
			$this->print_log( '1. CrediMax: payment authorized for Order ID: ' . $order_id );

			$this->print_log( '2. removing success indicator' );
			delete_post_meta( $order_id, '_successIndicator' );

			$this->print_log( '3. getting order using ' . $order_id );
			$order = new WC_Order( $order_id );
			
			if ( $response_message ) {
				$response_message = sprintf( __( 'Payment Authorized: %s', 'pm-wc-credimax' ), $response_message );

			} else {
				$response_message = __( 'Payment Authorized', 'pm-wc-credimax' );
			}

			$this->print_log( $response_message );

			$this->print_log( '4. adding order note' );
			$order->add_order_note( $response_message );

			$this->print_log( '5. adding notice' );
			wc_add_notice( $response_message );

			
			$this->print_log( '6. completing order' );
			$order->payment_complete();

			$url = $this->get_return_url( $order );
			$this->print_log( '7. getting url order URL:' . $url );

			wp_safe_redirect( $url );
			exit();
		}

		function cancel_request( $order_id, $response_message = false ) {

			$this->print_log( 'CrediMax: Payment error for Order ID: ' . $order_id );
			$response_message = sprintf( __( 'Payment cancelled: %s', 'pm-wc-cedimax' ), $response_message );
// 			$test_message = sprintf( __('Test Message'), $authorization_code);

			$this->print_log( $response_message );
			delete_post_meta( $order_id, '_successIndicator' );

			$order = new WC_Order( $order_id );
			$order->add_order_note( $response_message );
			$order->cancel_order( $response_message );

			wc_add_notice( $response_message, 'error' );
			wp_safe_redirect( $order->get_cancel_order_url() );
			exit();
		}

		private function print_log( $txt ) {

			if ( $this->debug ) {
				$this->log->add( 'mpgs', $txt );
			}
		}

		private function convert_country_code( $country ) {
			$countries = array(
			'AF' => 'AFG', //Afghanistan
			'AX' => 'ALA', //&#197;land Islands
			'AL' => 'ALB', //Albania
			'DZ' => 'DZA', //Algeria
			'AS' => 'ASM', //American Samoa
			'AD' => 'AND', //Andorra
			'AO' => 'AGO', //Angola
			'AI' => 'AIA', //Anguilla
			'AQ' => 'ATA', //Antarctica
			'AG' => 'ATG', //Antigua and Barbuda
			'AR' => 'ARG', //Argentina
			'AM' => 'ARM', //Armenia
			'AW' => 'ABW', //Aruba
			'AU' => 'AUS', //Australia
			'AT' => 'AUT', //Austria
			'AZ' => 'AZE', //Azerbaijan
			'BS' => 'BHS', //Bahamas
			'BH' => 'BHR', //Bahrain
			'BD' => 'BGD', //Bangladesh
			'BB' => 'BRB', //Barbados
			'BY' => 'BLR', //Belarus
			'BE' => 'BEL', //Belgium
			'BZ' => 'BLZ', //Belize
			'BJ' => 'BEN', //Benin
			'BM' => 'BMU', //Bermuda
			'BT' => 'BTN', //Bhutan
			'BO' => 'BOL', //Bolivia
			'BQ' => 'BES', //Bonaire, Saint Estatius and Saba
			'BA' => 'BIH', //Bosnia and Herzegovina
			'BW' => 'BWA', //Botswana
			'BV' => 'BVT', //Bouvet Islands
			'BR' => 'BRA', //Brazil
			'IO' => 'IOT', //British Indian Ocean Territory
			'BN' => 'BRN', //Brunei
			'BG' => 'BGR', //Bulgaria
			'BF' => 'BFA', //Burkina Faso
			'BI' => 'BDI', //Burundi
			'KH' => 'KHM', //Cambodia
			'CM' => 'CMR', //Cameroon
			'CA' => 'CAN', //Canada
			'CV' => 'CPV', //Cape Verde
			'KY' => 'CYM', //Cayman Islands
			'CF' => 'CAF', //Central African Republic
			'TD' => 'TCD', //Chad
			'CL' => 'CHL', //Chile
			'CN' => 'CHN', //China
			'CX' => 'CXR', //Christmas Island
			'CC' => 'CCK', //Cocos (Keeling) Islands
			'CO' => 'COL', //Colombia
			'KM' => 'COM', //Comoros
			'CG' => 'COG', //Congo
			'CD' => 'COD', //Congo, Democratic Republic of the
			'CK' => 'COK', //Cook Islands
			'CR' => 'CRI', //Costa Rica
			'CI' => 'CIV', //Côte d\'Ivoire
			'HR' => 'HRV', //Croatia
			'CU' => 'CUB', //Cuba
			'CW' => 'CUW', //Curaçao
			'CY' => 'CYP', //Cyprus
			'CZ' => 'CZE', //Czech Republic
			'DK' => 'DNK', //Denmark
			'DJ' => 'DJI', //Djibouti
			'DM' => 'DMA', //Dominica
			'DO' => 'DOM', //Dominican Republic
			'EC' => 'ECU', //Ecuador
			'EG' => 'EGY', //Egypt
			'SV' => 'SLV', //El Salvador
			'GQ' => 'GNQ', //Equatorial Guinea
			'ER' => 'ERI', //Eritrea
			'EE' => 'EST', //Estonia
			'ET' => 'ETH', //Ethiopia
			'FK' => 'FLK', //Falkland Islands
			'FO' => 'FRO', //Faroe Islands
			'FJ' => 'FIJ', //Fiji
			'FI' => 'FIN', //Finland
			'FR' => 'FRA', //France
			'GF' => 'GUF', //French Guiana
			'PF' => 'PYF', //French Polynesia
			'TF' => 'ATF', //French Southern Territories
			'GA' => 'GAB', //Gabon
			'GM' => 'GMB', //Gambia
			'GE' => 'GEO', //Georgia
			'DE' => 'DEU', //Germany
			'GH' => 'GHA', //Ghana
			'GI' => 'GIB', //Gibraltar
			'GR' => 'GRC', //Greece
			'GL' => 'GRL', //Greenland
			'GD' => 'GRD', //Grenada
			'GP' => 'GLP', //Guadeloupe
			'GU' => 'GUM', //Guam
			'GT' => 'GTM', //Guatemala
			'GG' => 'GGY', //Guernsey
			'GN' => 'GIN', //Guinea
			'GW' => 'GNB', //Guinea-Bissau
			'GY' => 'GUY', //Guyana
			'HT' => 'HTI', //Haiti
			'HM' => 'HMD', //Heard Island and McDonald Islands
			'VA' => 'VAT', //Holy See (Vatican City State)
			'HN' => 'HND', //Honduras
			'HK' => 'HKG', //Hong Kong
			'HU' => 'HUN', //Hungary
			'IS' => 'ISL', //Iceland
			'IN' => 'IND', //India
			'ID' => 'IDN', //Indonesia
			'IR' => 'IRN', //Iran
			'IQ' => 'IRQ', //Iraq
			'IE' => 'IRL', //Republic of Ireland
			'IM' => 'IMN', //Isle of Man
			'IL' => 'ISR', //Israel
			'IT' => 'ITA', //Italy
			'JM' => 'JAM', //Jamaica
			'JP' => 'JPN', //Japan
			'JE' => 'JEY', //Jersey
			'JO' => 'JOR', //Jordan
			'KZ' => 'KAZ', //Kazakhstan
			'KE' => 'KEN', //Kenya
			'KI' => 'KIR', //Kiribati
			'KP' => 'PRK', //Korea, Democratic People\'s Republic of
			'KR' => 'KOR', //Korea, Republic of (South)
			'KW' => 'KWT', //Kuwait
			'KG' => 'KGZ', //Kyrgyzstan
			'LA' => 'LAO', //Laos
			'LV' => 'LVA', //Latvia
			'LB' => 'LBN', //Lebanon
			'LS' => 'LSO', //Lesotho
			'LR' => 'LBR', //Liberia
			'LY' => 'LBY', //Libya
			'LI' => 'LIE', //Liechtenstein
			'LT' => 'LTU', //Lithuania
			'LU' => 'LUX', //Luxembourg
			'MO' => 'MAC', //Macao S.A.R., China
			'MK' => 'MKD', //Macedonia
			'MG' => 'MDG', //Madagascar
			'MW' => 'MWI', //Malawi
			'MY' => 'MYS', //Malaysia
			'MV' => 'MDV', //Maldives
			'ML' => 'MLI', //Mali
			'MT' => 'MLT', //Malta
			'MH' => 'MHL', //Marshall Islands
			'MQ' => 'MTQ', //Martinique
			'MR' => 'MRT', //Mauritania
			'MU' => 'MUS', //Mauritius
			'YT' => 'MYT', //Mayotte
			'MX' => 'MEX', //Mexico
			'FM' => 'FSM', //Micronesia
			'MD' => 'MDA', //Moldova
			'MC' => 'MCO', //Monaco
			'MN' => 'MNG', //Mongolia
			'ME' => 'MNE', //Montenegro
			'MS' => 'MSR', //Montserrat
			'MA' => 'MAR', //Morocco
			'MZ' => 'MOZ', //Mozambique
			'MM' => 'MMR', //Myanmar
			'NA' => 'NAM', //Namibia
			'NR' => 'NRU', //Nauru
			'NP' => 'NPL', //Nepal
			'NL' => 'NLD', //Netherlands
			'AN' => 'ANT', //Netherlands Antilles
			'NC' => 'NCL', //New Caledonia
			'NZ' => 'NZL', //New Zealand
			'NI' => 'NIC', //Nicaragua
			'NE' => 'NER', //Niger
			'NG' => 'NGA', //Nigeria
			'NU' => 'NIU', //Niue
			'NF' => 'NFK', //Norfolk Island
			'MP' => 'MNP', //Northern Mariana Islands
			'NO' => 'NOR', //Norway
			'OM' => 'OMN', //Oman
			'PK' => 'PAK', //Pakistan
			'PW' => 'PLW', //Palau
			'PS' => 'PSE', //Palestinian Territory
			'PA' => 'PAN', //Panama
			'PG' => 'PNG', //Papua New Guinea
			'PY' => 'PRY', //Paraguay
			'PE' => 'PER', //Peru
			'PH' => 'PHL', //Philippines
			'PN' => 'PCN', //Pitcairn
			'PL' => 'POL', //Poland
			'PT' => 'PRT', //Portugal
			'PR' => 'PRI', //Puerto Rico
			'QA' => 'QAT', //Qatar
			'RE' => 'REU', //Reunion
			'RO' => 'ROU', //Romania
			'RU' => 'RUS', //Russia
			'RW' => 'RWA', //Rwanda
			'BL' => 'BLM', //Saint Barth&eacute;lemy
			'SH' => 'SHN', //Saint Helena
			'KN' => 'KNA', //Saint Kitts and Nevis
			'LC' => 'LCA', //Saint Lucia
			'MF' => 'MAF', //Saint Martin (French part)
			'SX' => 'SXM', //Sint Maarten / Saint Matin (Dutch part)
			'PM' => 'SPM', //Saint Pierre and Miquelon
			'VC' => 'VCT', //Saint Vincent and the Grenadines
			'WS' => 'WSM', //Samoa
			'SM' => 'SMR', //San Marino
			'ST' => 'STP', //S&atilde;o Tom&eacute; and Pr&iacute;ncipe
			'SA' => 'SAU', //Saudi Arabia
			'SN' => 'SEN', //Senegal
			'RS' => 'SRB', //Serbia
			'SC' => 'SYC', //Seychelles
			'SL' => 'SLE', //Sierra Leone
			'SG' => 'SGP', //Singapore
			'SK' => 'SVK', //Slovakia
			'SI' => 'SVN', //Slovenia
			'SB' => 'SLB', //Solomon Islands
			'SO' => 'SOM', //Somalia
			'ZA' => 'ZAF', //South Africa
			'GS' => 'SGS', //South Georgia/Sandwich Islands
			'SS' => 'SSD', //South Sudan
			'ES' => 'ESP', //Spain
			'LK' => 'LKA', //Sri Lanka
			'SD' => 'SDN', //Sudan
			'SR' => 'SUR', //Suriname
			'SJ' => 'SJM', //Svalbard and Jan Mayen
			'SZ' => 'SWZ', //Swaziland
			'SE' => 'SWE', //Sweden
			'CH' => 'CHE', //Switzerland
			'SY' => 'SYR', //Syria
			'TW' => 'TWN', //Taiwan
			'TJ' => 'TJK', //Tajikistan
			'TZ' => 'TZA', //Tanzania
			'TH' => 'THA', //Thailand    
			'TL' => 'TLS', //Timor-Leste
			'TG' => 'TGO', //Togo
			'TK' => 'TKL', //Tokelau
			'TO' => 'TON', //Tonga
			'TT' => 'TTO', //Trinidad and Tobago
			'TN' => 'TUN', //Tunisia
			'TR' => 'TUR', //Turkey
			'TM' => 'TKM', //Turkmenistan
			'TC' => 'TCA', //Turks and Caicos Islands
			'TV' => 'TUV', //Tuvalu     
			'UG' => 'UGA', //Uganda
			'UA' => 'UKR', //Ukraine
			'AE' => 'ARE', //United Arab Emirates
			'GB' => 'GBR', //United Kingdom
			'US' => 'USA', //United States
			'UM' => 'UMI', //United States Minor Outlying Islands
			'UY' => 'URY', //Uruguay
			'UZ' => 'UZB', //Uzbekistan
			'VU' => 'VUT', //Vanuatu
			'VE' => 'VEN', //Venezuela
			'VN' => 'VNM', //Vietnam
			'VG' => 'VGB', //Virgin Islands, British
			'VI' => 'VIR', //Virgin Island, U.S.
			'WF' => 'WLF', //Wallis and Futuna
			'EH' => 'ESH', //Western Sahara
			'YE' => 'YEM', //Yemen
			'ZM' => 'ZMB', //Zambia
			'ZW' => 'ZWE', //Zimbabwe
			);
			$iso_code = isset( $countries[$country] ) ? $countries[$country] : $country;
			return $iso_code;
		}
	}
}

add_action( 'plugins_loaded', 'init_pm_wc_mpgs' );

/**
 * Adds class 'PM_WC_MPGS' to the mathods list.
 *
 * As well as defining your class, you need to also tell WC that it exists
 *
 * @since 1.0
 * @param array $methods, list of classes
 */
function pm_add_wc_mpgs_class( $methods ) {

	if ( class_exists( 'WC_Payment_Gateway' ) ) {
		$methods[] = 'PM_WC_MPGS';
	}
	return $methods;
}

add_filter( 'woocommerce_payment_gateways', 'pm_add_wc_mpgs_class' );
