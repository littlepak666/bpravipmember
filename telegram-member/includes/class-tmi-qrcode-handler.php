<?php
/**
 * TMI QR Code Handler
 *
 * This class encapsulates all functionality related to the QR Code scanner
 * and myCred points adjustment admin page. It handles the creation of the
 * admin menu, rendering the frontend interface, and processing AJAX requests.
 *
 * @package    Telegram_Member_Integration
 * @author     b-pra.com
 * @version    1.0.0
 */

// Security: Prevent direct file access.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Final class TMI_QRCODE_HANDLER.
 */
final class TMI_QRCODE_HANDLER {

	/**
	 * Nonce action name for security checks.
	 *
	 * @var string
	 */
	private $nonce_action = 'tmi_adjust_points_nonce';

	/**
	 * AJAX action name.
	 *
	 * @var string
	 */
	private $ajax_action = 'tmi_adjust_points';


	/**
	 * Constructor.
	 *
	 * Hooks all necessary actions for the QR code functionality.
	 */
	public function __construct() {
		// Add the admin menu page.
		add_action('admin_menu', [$this, 'add_qr_scanner_page']);

		// Add the AJAX handler for points adjustment.
		add_action('wp_ajax_' . $this->ajax_action, [$this, 'handle_adjust_points_ajax']);
	}

	/**
	 * Creates the admin menu page for the QR scanner.
	 *
	 * Uses add_menu_page to register the top-level menu item.
	 */
	public function add_qr_scanner_page() {
		add_menu_page(
			__('會員積分調整 (QR Code)', 'telegram-member-integration'), // Page Title
			__('會員積分調整', 'telegram-member-integration'),           // Menu Title
			'manage_options',                                           // Capability
			'tmi-qr-points-adjust',                                     // Menu Slug
			[$this, 'render_scanner_page_html'],                        // Callback function to render the page
			'dashicons-camera',                                         // Icon
			80                                                          // Position
		);
	}

	/**
	 * Renders the HTML and JavaScript for the QR scanner page.
	 *
	 * This includes the html5-qrcode library, UI elements for scanning,
	 * the points adjustment form, and the JavaScript logic to tie it all together.
	 */
	public function render_scanner_page_html() {
		// Security check: Ensure the user has the required permissions.
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}
		?>
		<!-- External library for QR code scanning -->
		<script src="https://unpkg.com/html5-qrcode"></script>

		<style>
			#tmi-qr-scanner-wrapper { max-width: 600px; }
			#qr-scanner-container { max-width: 500px; margin-bottom: 20px; border: 1px solid #ccc; background: #f9f9f9; }
			#qr-reader { width: 100%; }
			#tmi-feedback-message { padding: 10px 15px; border-radius: 4px; border-width: 1px; border-style: solid; margin-top: 15px; display: none; }
			#tmi-feedback-message.success { background-color: #dff0d8; border-color: #d6e9c6; color: #3c763d; }
			#tmi-feedback-message.error { background-color: #f2dede; border-color: #ebccd1; color: #a94442; }
			#tmi-feedback-message.info { background-color: #d9edf7; border-color: #bce8f1; color: #31708f; }
			#points-adjustment-section .spinner { float: none; visibility: hidden; opacity: 0; transition: all 0.2s; margin: 0 5px; }
			#points-adjustment-section.is-busy .spinner { visibility: visible; opacity: 1; }
		</style>

		<div class="wrap" id="tmi-qr-scanner-wrapper">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<!-- =======================
				QR Code Scanner Area
			======================== -->
			<div id="scanner-controls">
				<p><?php _e('Click "Start Scanning" to activate the camera, or select an image file with a QR Code.', 'telegram-member-integration'); ?></p>
				<div id="qr-scanner-container" style="display: none;">
					<div id="qr-reader"></div>
				</div>
				<button id="start-scan-btn" class="button button-primary"><?php _e('Start Camera Scan', 'telegram-member-integration'); ?></button>
				<label for="qr-input-file" class="button">
					<?php _e('Scan from Image File', 'telegram-member-integration'); ?>
					<input type="file" id="qr-input-file" accept="image/*" style="display: none;">
				</label>
				<button id="stop-scan-btn" class="button" style="display: none;"><?php _e('Stop Scanning', 'telegram-member-integration'); ?></button>
			</div>

			<hr>

			<!-- =======================
				Points Adjustment Form
				(Hidden by default, shown on successful scan)
			======================== -->
			<div id="points-adjustment-section" style="display: none;">
				<h2><?php _e('Adjust Points', 'telegram-member-integration'); ?></h2>
				<p><?php _e('Scanned Member ID:', 'telegram-member-integration'); ?> <strong id="scanned-user-display" style="color: #0073aa;">N/A</strong></p>

				<form id="points-form" onsubmit="return false;">
					<input type="hidden" id="scanned-user-id" name="user_id" value="">
					<?php // Security Nonce Field ?>
					<input type="hidden" id="tmi-nonce" value="<?php echo esc_attr(wp_create_nonce($this->nonce_action)); ?>">
					
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row"><label for="points-to-adjust"><?php _e('Points to Adjust', 'telegram-member-integration'); ?></label></th>
								<td>
									<input name="points" type="number" step="1" min="1" id="points-to-adjust" class="regular-text" placeholder="<?php _e('e.g., 100', 'telegram-member-integration'); ?>" required>
									<p class="description"><?php _e('Enter a positive number. The action (add/deduct) is determined by the button you press.', 'telegram-member-integration'); ?></p>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit">
						<button type="button" id="add-points-btn" class="button button-primary"><?php _e('Add Points', 'telegram-member-integration'); ?></button>
						<button type="button" id="deduct-points-btn" class="button button-secondary"><?php _e('Deduct Points', 'telegram-member-integration'); ?></button>
						<button type="button" id="rescan-btn" class="button"><?php _e('Scan New Code', 'telegram-member-integration'); ?></button>
						<span class="spinner"></span>
					</p>
				</form>
			</div>

			<!-- Feedback message area -->
			<div id="tmi-feedback-message"></div>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			// DOM Elements
			const scannerControls = document.getElementById('scanner-controls');
			const qrReaderContainer = document.getElementById('qr-scanner-container');
			const startScanBtn = document.getElementById('start-scan-btn');
			const stopScanBtn = document.getElementById('stop-scan-btn');
			const fileInput = document.getElementById('qr-input-file');
			const pointsSection = document.getElementById('points-adjustment-section');
			const scannedUserDisplay = document.getElementById('scanned-user-display');
			const scannedUserIdInput = document.getElementById('scanned-user-id');
			const pointsInput = document.getElementById('points-to-adjust');
			const addPointsBtn = document.getElementById('add-points-btn');
			const deductPointsBtn = document.getElementById('deduct-points-btn');
			const rescanBtn = document.getElementById('rescan-btn');
			const feedbackDiv = document.getElementById('tmi-feedback-message');
			const nonceInput = document.getElementById('tmi-nonce');

			// Init QR Code Scanner
			const html5QrCode = new Html5Qrcode("qr-reader");

			const onScanSuccess = (decodedText, decodedResult) => {
				console.log(`Scan successful: ${decodedText}`);
				stopScanning();
				scannedUserDisplay.textContent = decodedText;
				scannedUserIdInput.value = decodedText;
				scannerControls.style.display = 'none';
				pointsSection.style.display = 'block';
				showFeedback(`Successfully scanned member ID: ${decodedText}`, 'success');
			};

			const onScanFailure = (error) => { /* console.warn(`QR scan failed: ${error}`); */ };

			const startCameraScan = () => {
				qrReaderContainer.style.display = 'block';
				stopScanBtn.style.display = 'inline-block';
				startScanBtn.disabled = true;
				const config = { fps: 10, qrbox: { width: 250, height: 250 } };
				html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess, onScanFailure)
					.catch(err => {
						showFeedback('Failed to start camera. Please check permissions.', 'error');
						stopScanning();
					});
			};

			const stopScanning = () => {
				html5QrCode.stop().then(() => {
					qrReaderContainer.style.display = 'none';
					stopScanBtn.style.display = 'none';
					startScanBtn.disabled = false;
				}).catch(err => { /* console.error("Failed to stop scanning.", err); */ });
			};

			const showFeedback = (message, type = 'info') => {
				feedbackDiv.textContent = message;
				feedbackDiv.className = type;
				feedbackDiv.style.display = 'block';
				setTimeout(() => { feedbackDiv.style.display = 'none'; }, 5000);
			};
			
			const resetUi = () => {
				pointsSection.style.display = 'none';
				scannerControls.style.display = 'block';
				pointsInput.value = '';
				scannedUserIdInput.value = '';
				showFeedback('Ready to scan a new QR code.', 'info');
			};

			const handlePointsAdjustment = (operation) => {
				const userId = scannedUserIdInput.value;
				const points = Math.abs(parseInt(pointsInput.value, 10));

				if (!userId) {
					showFeedback('Error: No User ID found.', 'error');
					return;
				}
				if (isNaN(points) || points <= 0) {
					showFeedback('Please enter a valid positive number for points.', 'error');
					return;
				}

				pointsSection.classList.add('is-busy');
				addPointsBtn.disabled = true;
				deductPointsBtn.disabled = true;

				const data = new URLSearchParams();
				data.append('action', '<?php echo esc_js($this->ajax_action); ?>');
				data.append('nonce', nonceInput.value);
				data.append('user_id', userId);
				data.append('points', points);
				data.append('operation', operation); // 'add' or 'deduct'

				fetch(ajaxurl, { method: 'POST', body: data })
					.then(response => response.json())
					.then(result => {
						if (result.success) {
							showFeedback(result.data.message, 'success');
							pointsInput.value = ''; // Clear input on success
						} else {
							showFeedback(result.data.message || 'An unknown error occurred.', 'error');
						}
					})
					.catch(error => {
						showFeedback('Request failed. Please check your network connection.', 'error');
						console.error('AJAX Error:', error);
					})
					.finally(() => {
						pointsSection.classList.remove('is-busy');
						addPointsBtn.disabled = false;
						deductPointsBtn.disabled = false;
					});
			};

			// Event Listeners
			startScanBtn.addEventListener('click', startCameraScan);
			stopScanBtn.addEventListener('click', stopScanning);
			fileInput.addEventListener('change', e => {
				if (e.target.files.length === 0) return;
				html5QrCode.scanFile(e.target.files[0], true)
					.then(onScanSuccess)
					.catch(err => showFeedback(`Could not recognize QR Code from image. ${err}`, 'error'));
			});
			
			addPointsBtn.addEventListener('click', () => handlePointsAdjustment('add'));
			deductPointsBtn.addEventListener('click', () => handlePointsAdjustment('deduct'));
			rescanBtn.addEventListener('click', resetUi);
		});
		</script>
		<?php
	}

	/**
	 * Handles the AJAX request to adjust myCred points.
	 *
	 * Performs security checks, validates data, and calls the appropriate
	 * myCred function to add or subtract points.
	 */
	public function handle_adjust_points_ajax() {
		// 1. Security Verification
		if (!check_ajax_referer($this->nonce_action, 'nonce', false)) {
			wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'telegram-member-integration')], 403);
		}
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission Denied: You do not have sufficient permissions.', 'telegram-member-integration')], 403);
		}

		// 2. Data Retrieval and Validation
		$user_id   = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
		$operation = isset($_POST['operation']) ? sanitize_text_field($_POST['operation']) : '';
		$points    = isset($_POST['points']) ? abs(intval($_POST['points'])) : 0;

		if ($user_id <= 0) {
			wp_send_json_error(['message' => __('Error: Invalid or missing User ID.', 'telegram-member-integration')]);
		}
		if (!in_array($operation, ['add', 'deduct'], true)) {
			wp_send_json_error(['message' => __('Error: Invalid operation specified. Must be "add" or "deduct".', 'telegram-member-integration')]);
		}
		if ($points <= 0) {
			wp_send_json_error(['message' => __('Error: Points must be a positive number greater than zero.', 'telegram-member-integration')]);
		}
		if (!function_exists('mycred')) {
			wp_send_json_error(['message' => __('Error: The myCred plugin does not appear to be active.', 'telegram-member-integration')]);
		}

		// 3. myCred Points Operation
		$mycred    = mycred();
		$log_entry = __('Adjusted by Admin via QR Code Scan', 'telegram-member-integration');
		$admin_id  = get_current_user_id();
		$result    = false;
		
		// Check if the user is excluded from the point type
		if ( $mycred->is_excluded( $user_id ) ) {
			wp_send_json_error( [ 'message' => __( 'This user is excluded from myCred.', 'telegram-member-integration' ) ] );
		}

		if ($operation === 'add') {
			$result = $mycred->add_creds(
				'admin_qr_adjustment', // Reference
				$user_id,              // User ID
				$points,               // Amount
				$log_entry,            // Log Entry
				$admin_id              // Reference ID (Admin)
			);
		} elseif ($operation === 'deduct') {
			$result = $mycred->remove_creds(
				'admin_qr_adjustment', // Reference
				$user_id,              // User ID
				$points,               // Amount
				$log_entry,            // Log Entry
				$admin_id              // Reference ID (Admin)
			);
		}

		// 4. Return JSON Response
		if ($result) {
			$action_text = ($operation === 'add') ? __('added', 'telegram-member-integration') : __('deducted', 'telegram-member-integration');
			$success_message = sprintf(
				__('Success! %1$d points were %2$s for user ID %3$d.', 'telegram-member-integration'),
				$points,
				$action_text,
				$user_id
			);
			wp_send_json_success(['message' => $success_message]);
		} else {
			$error_message = __('Failed to adjust points. The transaction may have been blocked by myCred (e.g., insufficient funds).', 'telegram-member-integration');
			wp_send_json_error(['message' => $error_message]);
		}
	}
}