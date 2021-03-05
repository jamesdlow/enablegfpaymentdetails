<?php
GFForms::include_addon_framework();

class Enable_GF_PaymentDetails extends GFAddOn {
	protected $_version = '0.7.4';
	protected $_min_gravityforms_version = '2.0';
	protected $_slug = 'enablegfpaymentdetails';
	protected $_path = 'enablegfpaymentdetails/enablegfpaymentdetails.php';
	protected $_full_path = __FILE__;
	protected $_title = 'Enable Gravity Forms Payment Details';
	protected $_short_title = 'GF Payment Details';

	protected $_capabilities_form_settings = 'enablegfpaymentdetails_settings';
	protected $_capabilities_uninstall = 'enablegfpaymentdetails_uninstall';
	protected $_capabilities = array( 'enablegfpaymentdetails_settings', 'enablegfpaymentdetails_uninstall' );

	private static $_instance = null;

	/**
	 * Get an instance of this class.
	 *
	 * @return Enable_GF_PaymentDetails
	 */
	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Include the hooks.
	 */
	public function init_admin() {
		parent::init_admin();
		if ( $this->is_gravityforms_supported() ) {
			add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'add_payment_details_meta_box' ), 10, 3 );

			//add actions to allow the payment status to be modified
			add_action( 'gform_payment_status', array( $this, 'admin_edit_payment_status' ), 10, 3 );
			add_action( 'gform_payment_date', array( $this, 'admin_edit_payment_date' ), 10, 3 );
			add_action( 'gform_payment_transaction_id', array( $this, 'admin_edit_payment_transaction_id' ), 10, 3 );
			add_action( 'gform_payment_amount', array( $this, 'admin_edit_payment_amount' ), 10, 3 );
			add_action( 'gform_after_update_entry', array( $this, 'admin_update_payment' ), 10, 2 );

			add_filter( 'gform_notification_events', array( $this, 'notification_events' ), 10, 2 );
		}
	}

	public function get_current_form() {

		return $this->is_entry_view() || $this->is_entry_edit() ? GFEntryDetail::get_current_form() : parent::get_current_form();
	}

	public function payment_details_enabled( $form = false ) {
		if ( empty( $form ) ) {
			$form = $this->get_current_form();
		}

		$settings = $this->get_form_settings( $form );

		return rgar( $settings, 'payment_details_enabled', false );
	}

	public function payment_details_allfeeds( $form = false ) {
		if ( empty( $form ) ) {
			$form = $this->get_current_form();
		}

		$settings = $this->get_form_settings( $form );

		return rgar( $settings, 'payment_details_allfeeds', false );
	}

	public function form_settings_fields( $form ) {
		return array(
			array(
				'title'  => $this->get_short_title(),
				'fields' => array(
					array(
						'label'   => esc_html__( 'Entry Detail Payment Details', 'simpleaddon' ),
						'type'    => 'checkbox',
						'name'    => 'payment_details_enabled',
						'choices' => array(
							array(
								'label'         => esc_html__( 'Enabled', 'simpleaddon' ),
								'name'          => 'payment_details_enabled',
								'default_value' => false,
							),
						),
					),
					array(
						'label'   => esc_html__( 'Enable For All Feeds', 'simpleaddon' ),
						'type'    => 'checkbox',
						'name'    => 'payment_details_allfeeds',
						'choices' => array(
							array(
								'label'         => esc_html__( 'All Feeds', 'simpleaddon' ),
								'name'          => 'payment_details_allfeeds',
								'default_value' => false,
							),
						),
					),
				)
			)
		);
	}

	public function add_payment_details_meta_box( $meta_boxes, $entry, $form ) {
		if ( ! isset( $meta_boxes['payment'] ) && $this->payment_details_enabled( $form ) ) {
			//We maybe shouldn't override status here, because some entries do have a blank status
			//if (!isset($entry['payment_status'])) {
			//	GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );
			//	$entry['payment_status']   = 'Processing';
			//}
			if (!isset($entry['transaction_type'])) {
				GFAPI::update_entry_property( $entry['id'], 'transaction_type', '1' );
				$entry['transaction_type'] = '1';
			}
			GFEntryDetail::set_current_entry( $entry );

			$meta_boxes['payment'] = array(
				'title'    => esc_html__( 'Payment Details', 'gravityforms' ),
				'callback' => array( 'GFEntryDetail', 'meta_box_payment_details' ),
				'context'  => 'side',
			);
		}

		return $meta_boxes;
	}

	public function admin_edit_payment_status( $payment_status, $form, $entry ) {
		if ( $this->payment_details_editing_disabled( $entry ) ) {
			return $payment_status;
		}

		//create drop down for payment status
		if (strpos($payment_status, '<select') === false) {
			$status = array ('', 'Processing', 'Paid', 'Active');
			$payment_string = '<select id="payment_status" name="payment_status">';
			foreach ($status as $s) {
				$payment_string .= '<option value="'.$s.'"'.($payment_status==$s?' selected':'').'>'.$s.'</option>';
			}
			$payment_string .= '</select>';
			//remove_action('gform_payment_status', array($this, 'admin_edit_payment_status'));
			return $payment_string;
		} else {
			return $payment_status;
		}
	}

	public function admin_edit_payment_date( $payment_date, $form, $entry ) {
		if ( $this->payment_details_editing_disabled( $entry ) ) {
			return $payment_date;
		}

		$payment_date = $entry['payment_date'];
		if ( empty( $payment_date ) ) {
			$payment_date = gmdate( 'y-m-d H:i:s' );
		}

		if (strpos($payment_date, '<input') === false) {
			$input = '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';
			//remove_action('gform_payment_date', array($this, 'admin_edit_payment_date'));
			return $input;
		} else {
			return $payment_date;
		}
	}

	public function admin_edit_payment_transaction_id( $transaction_id, $form, $entry ) {
		if ( $this->payment_details_editing_disabled( $entry ) ) {
			return $transaction_id;
		}
		if (strpos($transaction_id, '<input') === false) {
			$input = '<input type="text" id="custom_transaction_id" name="custom_transaction_id" value="' . $transaction_id . '">';
			//remove_action('gform_payment_transaction_id', array($this, 'admin_edit_payment_transaction_id'));
			return $input;
		} else {
			return $transaction_id;
		}
	}

	public function admin_edit_payment_amount( $payment_amount, $form, $entry ) {
		if ( $this->payment_details_editing_disabled( $entry ) ) {
			return $payment_amount;
		}

		if ( empty( $payment_amount ) ) {
			$payment_amount = GFCommon::get_order_total( $form, $entry );
		}
		
		if (strpos($payment_amount, '<input') === false) {
			$payment_amount = GFCommon::to_money( $payment_amount, $entry['currency'] );
			$html = '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="' . $payment_amount . '">';
			//remove_action('gform_payment_amount', array($this, 'admin_edit_payment_amount'));
		} else {
			$html = $payment_amount;
		}
		if (strpos($html, '<select') === false) {
			$type = $entry['transaction_type'];
			$type = $type == null ? '' : $type;
			$types = array('' => '', '1' => 'Payment', '2' => 'Subscription');
			$html .= '
				</span>
			</div>
			<div id="gf_transaction_type" class="gf_payment_detail">
				Transaction Type:
				<span id="gform_transaction_type">
				<select id="transaction_type" name="transaction_type">';
				foreach ($types as $key => $value) {
					$html .= '<option value="'.$key.'"'.($type==$key?' selected':'').'>'.$value.'</option>';
				}
			$html .= '</select>';
		}
		return $html;
	}

	public function admin_update_payment( $form, $entry_id ) {
		check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' );

		//update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
		$entry = GFFormsModel::get_lead( $entry_id );

		if ( $this->payment_details_editing_disabled( $entry, 'update' ) ) {
			return;
		}

		//get payment fields to update
		$payment_status = rgpost( 'payment_status' );
		//when updating, payment status may not be editable, if no value in post, set to lead payment status
		if ( empty( $payment_status ) ) {
			$payment_status = $entry['payment_status'];
		}

		$payment_amount      = GFCommon::to_number( rgpost( 'payment_amount' ), $entry['currency'] );
		$payment_transaction = rgpost( 'custom_transaction_id' );
		$payment_date        = rgpost( 'payment_date' );
		$transaction_type    = rgpost( 'transaction_type' );
		$transaction_type = $transaction_type === '' ? null : $transaction_type;
		if ( empty( $payment_date ) ) {
			$payment_date = gmdate( 'y-m-d H:i:s' );
		} else {
			//format date entered by user
			$payment_date = date( 'Y-m-d H:i:s', strtotime( $payment_date ) );
		}

		//updating the entry properties
		$params = array('payment_status' => $payment_status,
			'payment_amount' => $payment_amount,
			'payment_date' => $payment_date,
			'transaction_id' => $payment_transaction,
			'transaction_type' => $transaction_type
		);
		$updated = array();
		$note = 'Payment information was manually updated.';
		foreach ($params as $key => $value) {
			if ($value != $entry[$key]) {
				GFAPI::update_entry_property( $entry['id'], $key, $value );
				$updated[$key] = $value;
				$note .= ' '.$key.': '.$value;
			}
		}
		
		if ($transaction_type === null) {
			$type = '(None)';
		} else if ($transaction_type == 1) {
			$type = 'Payment';
		} else if ($transaction_type == 2) {
			$type = 'Recurring';
		} else {
			$type = ''.$transaction_type;
		}
		
		if (count($updated) > 0) {
			//adding a note
			$current_user = wp_get_current_user();
			if ($current_user->ID == 0) {
				//https://docs.gravityforms.com/adding-note-when-using-addon-framework/#add-note-
				$this->add_note( $entry['id'], __( $note ));
			} else {
				//https://docs.gravityforms.com/managing-notes-with-the-gfapi/#add-note
				GFAPI::add_note( $entry['id'], $current_user->ID, $current_user->user_login, __( $note ));
			}
		}
		
		//Only send notifcation if payment status is actually updated to paid, not if that is the current status
		if ( $updated['payment_status'] === 'Paid' ) {
			GFAPI::send_notifications( $form, $entry, 'complete_payment' );
		}
	}

	public function payment_details_editing_disabled( $entry, $action = 'edit' ) {
		if ( ! $this->payment_details_enabled() ) {
			return true;
		}

		$gateway = gform_get_meta( $entry['id'], 'payment_gateway' );
		if (!$this->payment_details_allfeeds() && ( !empty( $gateway ) || rgar( $entry, 'payment_status' ) !== 'Processing' )) {
			// Entry was processed by a payment add-on, don't allow editing.
			return true;
		}

		if ( $action == 'edit' && rgpost( 'screen_mode' ) == 'edit' ) {
			// Editing is allowed for this entry.
			return false;
		}

		if ( $action == 'update' && rgpost( 'screen_mode' ) == 'view' && rgpost( 'action' ) == 'update' ) {
			// Updating the payment details for this entry is allowed.
			return false;
		}

		// In all other cases editing is not allowed.

		return true;
	}

	/**
	 * Add notifications events supported by Add-On to notification events list.
	 *
	 * @access public
	 *
	 * @param array $events
	 * @param array $form
	 *
	 * @return array $events
	 */
	public function notification_events( $events, $form ) {
		if ( ! isset( $events['complete_payment'] ) ) {
			$events['complete_payment'] = esc_html__( 'Payment Completed', 'gravityformspaypal' );
		}

		return $events;

	}

}
