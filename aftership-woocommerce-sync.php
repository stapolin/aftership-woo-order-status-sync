<?php
/**
 * Plugin Name: AfterShip WooCommerce Order Status Sync
 * Plugin URI:  https://stapolin.com
 * Description: Map AfterShip tracking tags to WooCommerce order statuses.
 * Version:     1.1.0
 * Author:      Eoin Healy
 * Text Domain: aftership-wc-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class Stapolin_AfterShip_WC_Sync {
    const OPTION_KEY      = 'stapolin_aftership_status_map';
    const OPTION_SECRET   = 'stapolin_aftership_webhook_secret';
    const OPTION_LOGGING  = 'stapolin_aftership_logging_enabled';
    const LOG_FILE        = 'stapolin-aftership-webhook.log';

    // AfterShip tags we'll expose in settings (Option B: all common tags)
    private $aftership_tags = [
        'InfoReceived',
        'InTransit',
        'OutForDelivery',
        'AttemptFail',
        'FailedAttempt',
        'Exception',
        'Delivered',
        'AvailableForPickup',
        'ReturnedToSender',
        'Cancelled',
        'Lost',
        'Expired',
        'Pending',
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'maybe_save_settings']);

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('AfterShip Sync', 'aftership-wc-sync'),
            __('AfterShip Sync', 'aftership-wc-sync'),
            'manage_woocommerce',
            'stapolin-aftership-sync',
            [$this, 'settings_page']
        );
    }

    public function maybe_save_settings() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (!isset($_POST['aftership_sync_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['aftership_sync_nonce'], 'aftership_sync_save')) {
            return;
        }

        $action = isset($_POST['stapolin_aftership_action'])
            ? sanitize_text_field($_POST['stapolin_aftership_action'])
            : 'save';

        // Handle clear log action
        if ($action === 'clear_log') {
            $file = $this->get_log_path();
            if (file_exists($file)) {
                @unlink($file);
            }

            wp_safe_redirect(
                add_query_arg(
                    'aftership_log_cleared',
                    '1',
                    admin_url('admin.php?page=stapolin-aftership-sync')
                )
            );
            exit;
        }

        // Default: save settings (mappings, secret, logging toggle)
        if ($action === 'save') {
            // Save mapping for each tag
            $map = [];
            foreach ($this->aftership_tags as $tag) {
                $key = 'map_' . sanitize_key($tag);
                if (isset($_POST[$key]) && is_string($_POST[$key])) {
                    $map[$tag] = sanitize_text_field($_POST[$key]);
                } else {
                    $map[$tag] = '';
                }
            }
            update_option(self::OPTION_KEY, $map);

            // Save secret token (optional)
            $secret = isset($_POST['aftership_secret']) ? sanitize_text_field($_POST['aftership_secret']) : '';
            update_option(self::OPTION_SECRET, $secret);

            // Save logging enabled/disabled (checkbox)
            $logging_enabled = isset($_POST['stapolin_aftership_logging_enabled']) ? '1' : '0';
            update_option(self::OPTION_LOGGING, $logging_enabled);

            // redirect to avoid resubmit
            wp_safe_redirect(
                add_query_arg(
                    'aftership_saved',
                    '1',
                    admin_url('admin.php?page=stapolin-aftership-sync')
                )
            );
            exit;
        }
    }

    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient privileges', 'aftership-wc-sync'));
        }

        $saved      = isset($_GET['aftership_saved']);
        $log_cleared = isset($_GET['aftership_log_cleared']);
        $map        = get_option(self::OPTION_KEY, []);
        $secret     = get_option(self::OPTION_SECRET, '');
        $logging_enabled = get_option(self::OPTION_LOGGING, '1'); // default: enabled

        // get WC statuses
        if (function_exists('wc_get_order_statuses')) {
            $wc_statuses = wc_get_order_statuses();
        } else {
            // fallback
            $wc_statuses = [
                'wc-pending'    => 'Pending',
                'wc-processing' => 'Processing',
                'wc-on-hold'    => 'On hold',
                'wc-completed'  => 'Completed',
                'wc-cancelled'  => 'Cancelled',
                'wc-refunded'   => 'Refunded',
                'wc-failed'     => 'Failed',
            ];
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AfterShip → WooCommerce status mapping', 'aftership-wc-sync'); ?></h1>

            <?php if ($saved): ?>
                <div class="updated notice is-dismissible">
                    <p><?php esc_html_e('Settings saved.', 'aftership-wc-sync'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($log_cleared): ?>
                <div class="updated notice is-dismissible">
                    <p><?php esc_html_e('Log file cleared.', 'aftership-wc-sync'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('aftership_sync_save', 'aftership_sync_nonce'); ?>
                <input type="hidden" name="stapolin_aftership_action" value="save" />

                <h2><?php esc_html_e('Webhook security (optional)', 'aftership-wc-sync'); ?></h2>
                <p><?php esc_html_e('Enter a secret token to require the header "X-AfterShip-Token" with this value on incoming webhooks. Leave empty to accept webhooks without a token (not recommended).', 'aftership-wc-sync'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="aftership_secret">
                                <?php esc_html_e('Webhook secret token', 'aftership-wc-sync'); ?>
                            </label>
                        </th>
                        <td>
                            <input name="aftership_secret" id="aftership_secret" type="text"
                                   value="<?php echo esc_attr($secret); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Status mappings', 'aftership-wc-sync'); ?></h2>
                <p><?php esc_html_e('Map AfterShip tags to your WooCommerce order statuses. Choose the status you want the order set to when the corresponding AfterShip tag is received.', 'aftership-wc-sync'); ?></p>

                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('AfterShip Tag', 'aftership-wc-sync'); ?></th>
                            <th><?php esc_html_e('WooCommerce Order Status', 'aftership-wc-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($this->aftership_tags as $tag):
                        $sel = isset($map[$tag]) ? $map[$tag] : '';
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($tag); ?></strong></td>
                            <td>
                                <select name="<?php echo esc_attr('map_' . sanitize_key($tag)); ?>">
                                    <option value=""><?php esc_html_e('-- Do nothing --', 'aftership-wc-sync'); ?></option>
                                    <?php foreach ($wc_statuses as $status_key => $status_name): ?>
                                        <option value="<?php echo esc_attr($status_key); ?>"
                                            <?php selected($sel, $status_key); ?>>
                                            <?php echo esc_html($status_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Logging settings', 'aftership-wc-sync'); ?></h2>
                <p><?php esc_html_e('Control whether webhook events are written to the log file. You can also clear the existing log below.', 'aftership-wc-sync'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Enable logging', 'aftership-wc-sync'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="stapolin_aftership_logging_enabled" value="1"
                                    <?php checked($logging_enabled, '1'); ?> />
                                <?php esc_html_e('Write webhook events to the log file.', 'aftership-wc-sync'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Save settings', 'aftership-wc-sync'); ?>
                    </button>
                </p>
            </form>

            <h2><?php esc_html_e('Webhook endpoint & logs', 'aftership-wc-sync'); ?></h2>
            <p>
                <?php esc_html_e('Use the following URL for your AfterShip webhook (Tracking Update):', 'aftership-wc-sync'); ?><br/>
                <code><?php echo esc_url(rest_url('stapolin-aftership/v1/webhook')); ?></code>
            </p>
            <p>
                <?php esc_html_e('If you set a webhook secret above, add the header "X-AfterShip-Token" with that value to the webhook configuration in AfterShip.', 'aftership-wc-sync'); ?>
            </p>

            <h3><?php esc_html_e('Recent logs', 'aftership-wc-sync'); ?></h3>
            <?php $log = $this->get_log_contents(200); ?>
            <?php if (!empty($log)): ?>
                <pre style="background:#fff;padding:12px;border:1px solid #ddd;max-height:300px;overflow:auto;"><?php echo esc_html($log); ?></pre>
                <p>
                    <a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=stapolin_aftership_download_log')); ?>">
                        <?php esc_html_e('Download full log', 'aftership-wc-sync'); ?>
                    </a>
                </p>
            <?php else: ?>
                <p><?php esc_html_e('No logs yet.', 'aftership-wc-sync'); ?></p>
            <?php endif; ?>

            <form method="post" action="" style="margin-top:10px;">
                <?php wp_nonce_field('aftership_sync_save', 'aftership_sync_nonce'); ?>
                <input type="hidden" name="stapolin_aftership_action" value="clear_log" />
                <button type="submit" class="button">
                    <?php esc_html_e('Clear log', 'aftership-wc-sync'); ?>
                </button>
            </form>
        </div>
        <?php
    }

    private function get_log_contents($lines = 100) {
        $file = $this->get_log_path();
        if (!file_exists($file)) {
            return '';
        }
        // read last N lines (simple approach)
        $content = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$content) {
            return '';
        }
        $start = max(0, count($content) - $lines);
        $slice = array_slice($content, $start);
        return implode("\n", $slice);
    }

    private function get_log_path() {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . self::LOG_FILE;
    }

    public function register_routes() {
        register_rest_route('stapolin-aftership/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'webhook_handler'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function webhook_handler(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (empty($body) || empty($body['msg'])) {
            $this->log('Invalid payload received');
            return new WP_REST_Response(['error' => 'Invalid payload'], 400);
        }

        // optional: verify secret token header
        $saved_secret = get_option(self::OPTION_SECRET, '');
        if (!empty($saved_secret)) {
            $header_secret = '';
            // check both common header names
            $headers = $request->get_headers();
            if (!empty($headers['x-aftership-token'])) {
                $header_secret = is_array($headers['x-aftership-token']) ? reset($headers['x-aftership-token']) : $headers['x-aftership-token'];
            } elseif (!empty($headers['x_aftership_token'])) {
                $header_secret = is_array($headers['x_aftership_token']) ? reset($headers['x_aftership_token']) : $headers['x_aftership_token'];
            }

            if (empty($header_secret) || !hash_equals($saved_secret, $header_secret)) {
                $this->log('Webhook rejected due to missing/invalid secret');
                return new WP_REST_Response(['error' => 'Unauthorized'], 401);
            }
        }

        $msg = $body['msg'];

        // Get order id/number
        // AfterShip sometimes provides order_number and order_id; prefer order_number
        $order_id = 0;
        if (!empty($msg['order_number'])) {
            $order_id = intval($msg['order_number']);
        } elseif (!empty($msg['order_id'])) {
            $order_id = intval($msg['order_id']);
        } elseif (!empty($msg['title'])) {
            // sometimes title is "#123" - try to extract
            if (preg_match('/#(\d+)/', $msg['title'], $m)) {
                $order_id = intval($m[1]);
            }
        }

        if (!$order_id) {
            $this->log('No order id found in payload: ' . json_encode($msg));
            return new WP_REST_Response(['error' => 'Order id not found'], 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log('Order #' . $order_id . ' not found');
            return new WP_REST_Response(['error' => 'Order not found'], 404);
        }

        // Determine tag. Prefer msg.tag, else use latest checkpoint tag if provided.
        $tag = '';
        if (!empty($msg['tag'])) {
            $tag = $msg['tag'];
        } elseif (!empty($msg['subtag'])) {
            $tag = $msg['subtag'];
        } elseif (!empty($msg['checkpoints']) && is_array($msg['checkpoints'])) {
            // take last checkpoint's tag if present
            $last = end($msg['checkpoints']);
            if (!empty($last['tag'])) {
                $tag = $last['tag'];
            }
        }

        if (empty($tag)) {
            $this->log('No tag available for order #' . $order_id);
            return new WP_REST_Response(['success' => true], 200);
        }

        $this->log('Order #' . $order_id . ' — AfterShip tag: ' . $tag);

        // load mapping
        $map = get_option(self::OPTION_KEY, []);
        if (empty($map) || !is_array($map)) {
            $this->log('No status mapping configured. Skipping update.');
            return new WP_REST_Response(['success' => true], 200);
        }

        // If mapping exists for the exact tag, apply. Otherwise do nothing.
        if (!empty($map[$tag])) {
            $wc_status_key = sanitize_text_field($map[$tag]);

            // Validate status exists. wc_get_order_statuses returns names keyed by e.g. 'wc-completed'
            $valid_statuses = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : [];

            if (!in_array($wc_status_key, $valid_statuses)) {
                // allow saving raw status keys too (in case admin entered without wc- prefix)
                $this->log("Configured status '$wc_status_key' for tag '$tag' not found in WooCommerce statuses. Skipping.");
                return new WP_REST_Response(['success' => true], 200);
            }

            // Convert 'wc-xxx' to 'xxx' for update_status convenience
            if (strpos($wc_status_key, 'wc-') === 0) {
                $status_to_set = substr($wc_status_key, 3);
            } else {
                $status_to_set = $wc_status_key;
            }

            $order->update_status(
                $status_to_set,
                sprintf(
                    __('Updated by AfterShip webhook (tag: %s)', 'aftership-wc-sync'),
                    $tag
                )
            );

            $this->log('Order #' . $order_id . ' status changed to ' . $wc_status_key);

            return new WP_REST_Response(['success' => true], 200);
        }

        $this->log('No mapping for tag ' . $tag . '. Skipping.');
        return new WP_REST_Response(['success' => true], 200);
    }

    private function log($message) {
        // Check if logging is enabled
        $enabled = get_option(self::OPTION_LOGGING, '1');
        if ($enabled !== '1') {
            return;
        }

        $file = $this->get_log_path();
        $date = date('Y-m-d H:i:s');
        // ensure uploads directory exists
        $dir = dirname($file);
        if (!file_exists($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($file, "[" . $date . "] " . $message . "\n", FILE_APPEND | LOCK_EX);
    }

    // Download log action
    public static function download_log() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient privileges', 'aftership-wc-sync'));
        }
        $self = new self();
        $file = $self->get_log_path();
        if (!file_exists($file)) {
            wp_die(__('Log file not found', 'aftership-wc-sync'));
        }
        header('Content-Description: File Transfer');
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="stapolin-aftership-webhook.log"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

// initialize
new Stapolin_AfterShip_WC_Sync();

// register download action
add_action('admin_post_stapolin_aftership_download_log', ['Stapolin_AfterShip_WC_Sync', 'download_log']);

