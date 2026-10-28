<?php


namespace SendinblueWoocommerce\Managers;

use SendinblueWoocommerce\Clients\AutomationClient;
use SendinblueWoocommerce\Managers\ApiManager;
use SendinblueWoocommerce\Clients\SendinblueClient;
use WP_REST_Response;


require_once SENDINBLUE_WC_ROOT_PATH . '/src/clients/automation-client.php';
require_once SENDINBLUE_WC_ROOT_PATH . '/src/managers/api-manager.php';
require_once SENDINBLUE_WC_ROOT_PATH . '/src/clients/sendinblue-client.php';
require_once SENDINBLUE_WC_ROOT_PATH . '/src/managers/logging-manager.php';

/**
 * Class CartEventsManagers
 *
 * @package SendinblueWoocommerce\Managers
 */
class CartEventsManagers
{
    /**
     * Cross-device abandoned-cart recovery (ECOMM-212). The cart_updated event
     * carries a cart/checkout URL with a recovery token; opening that URL
     * rebuilds the exact cart server-side, so the email CTA works even on a
     * different device or in incognito, where the session cookie that normally
     * holds the cart is absent. The token is a random per-cart value issued for
     * logged-in customers and guests alike (a guest cart_updated is a tracked
     * event too); it is never derived from the email, so it can't select another
     * session's cart. It is cleared when the cart is emptied or the order is
     * placed, so a stale link stops working rather than replaying.
     */
    // Transient key stays short: '_transient_timeout_' (19) + prefix (7) + 32
    // hex = 58, under the 64-char option_name column of WordPress < 4.4.
    const CART_RECOVERY_PARAM         = 'sib_bac';
    const CART_RECOVERY_PREFIX        = 'sib_cr_';
    const CART_RECOVERY_SESSION_KEY   = 'sib_cart_recovered';
    const CART_RECOVERY_TOKEN_SESSION = 'sib_cart_recovery_token';

    private $automation_manager;

    private $api_manager;

    /**
     * Raised while we repopulate the cart during recovery, so our own
     * woocommerce_add_to_cart handler does not emit a cart_updated event for
     * each restored line (noise, and it could re-arm the abandoned-cart timer).
     */
    private $is_restoring_cart = false;

    function __construct()
    {
        $this->automation_manager = new AutomationClient();
        $this->api_manager = new ApiManager();
    }

    public function save_anonymous_user_as_blacklisted(){
        $is_valid_call = $this->the_action_function();

        if (!$is_valid_call) {
            return false;
        }

        $tracking_email = sanitize_text_field($_POST['tracking_email']);
        $email_id = $this->get_email_id($tracking_email);
        $subscription_location = isset($_POST['subscription_location']) ? sanitize_text_field($_POST['subscription_location']) : '';

        LoggingManager::instance()->info('contact', 'anonymous contact captured', array(
            'email'                 => $email_id,
            'subscription_location' => $subscription_location,
        ));

        $client = new SendinblueClient();
        $client->eventsSync(SendinblueClient::CONTACT_CREATED, [
            "subscribed"=>"false",
            "email"=>$email_id,
            "is_anonymous_user"=>true,
            "subscription_location" => $subscription_location,
        ]);
    }

    public function the_action_function()
    {
        $logger = LoggingManager::instance();

        if (!isset($_POST['tracking_email']) || empty($_POST['tracking_email'])) {
            $logger->debug('cart', 'cart tracking skipped: no tracking email posted');

            return false;
        }

        if (!(WC()->cart)) {
            $logger->debug('cart', 'cart tracking skipped: no cart on this request');

            return false;
        }

        $settings = $this->api_manager->get_settings();

        if (
            empty($settings[SendinblueClient::MA_KEY]) ||
            empty($settings[SendinblueClient::IS_ABANDONED_CART_ENABLED])
        ) {
            // The two settings behind every "abandoned cart is not working"
            // report. Presence only — the MA key itself is a credential.
            $logger->debug('cart', 'cart tracking skipped: not configured', array(
                'have_ma_key'            => !empty($settings[SendinblueClient::MA_KEY]),
                'abandoned_cart_enabled' => !empty($settings[SendinblueClient::IS_ABANDONED_CART_ENABLED]),
            ));

            return false;
        }

        $ma_key = $settings[SendinblueClient::MA_KEY];

        $tracking_email = sanitize_text_field($_POST['tracking_email']);
        $email_id = $this->get_email_id($tracking_email);

        if (empty($tracking_email) || empty($email_id)) {
            // get_email_id() deliberately returns empty for administrators, so
            // this fires when a shop owner tests the feature on their own login.
            $logger->debug('cart', 'cart tracking skipped: no usable email', array(
                'is_administrator' => $this->is_administrator(),
            ));

            return false;
        }

        return $this->trigger_cart_tracking_anonymous_users($email_id, $ma_key);
    }

    public function trigger_cart_tracking_anonymous_users($email_id, $ma_key)
    {
        try {
            $tracking_event_data = array();
            $cart_id = $this->get_wc_cart_id();

            if (empty(WC()->cart->cart_contents) && !empty(WC()->cart->removed_cart_contents)) {
                $tracking_event_data = $this->get_tracking_data_cart_deleted($cart_id);
                $tracking_event_data['event'] = 'cart_deleted';
                $this->invalidate_cart_recovery_snapshot();
            } elseif (!empty(WC()->cart->cart_contents)) {
                $tracking_event_data = $this->get_tracking_data_cart($cart_id, $email_id);
                $tracking_event_data['event'] = 'cart_updated';
            }

            if (isset($tracking_event_data['event'])) {
                $event_id = 'evt_' . wp_generate_password(12, false, false);
                $tracking_event_data['event_id'] = $event_id;
                LoggingManager::instance()->debug('cart', 'event built', array(
                    'event'    => $tracking_event_data['event'],
                    'event_id' => $event_id,
                ));
            }

            // Per-cart-mutation hot path — buffered into the request summary.
            LoggingManager::instance()->debug('cart', 'cart tracking event built', array(
                'event' => isset($tracking_event_data['event']) ? $tracking_event_data['event'] : null,
                'email' => $email_id,
                'items' => isset($tracking_event_data['eventdata']['data']['items'])
                    ? count($tracking_event_data['eventdata']['data']['items'])
                    : 0,
            ));

            $this->automation_manager->send($tracking_event_data, $ma_key);
        } catch (Exception $e) {
            LoggingManager::instance()->error('cart', 'cart tracking threw', array(
                'error' => $e->getMessage(),
            ));

            return false;
        }
        return true;
    }

    public function get_email_id($tracking_email = "")
    {
        $current_user   = wp_get_current_user();
        $found_email_id = '';
        $cookie_email = '';

        if (!empty($tracking_email)) {
            $cookie_email = $tracking_email;
        } else if (isset($_COOKIE['email_id'])) {
            $cookie_email = $_COOKIE['email_id'];
        } else if (isset($_COOKIE['tracking_email'])) {
            $cookie_email = $_COOKIE['tracking_email'];
        }

        if ($this->is_administrator($current_user) && $current_user->user_email == $cookie_email) {

            return $found_email_id;
        } elseif (
            (0 == $current_user->ID || $this->is_administrator($current_user)) &&
            '' != $cookie_email
        ) {
            $found_email_id = esc_attr(sanitize_text_field($cookie_email));
        } else {
            $found_email_id = $current_user->user_email;
        }

        return $found_email_id;
    }

    public function is_administrator($wp_user = null)
    {
        if (!$wp_user) {
            $wp_user = wp_get_current_user();
        }

        return !empty($wp_user->roles) && in_array('administrator', $wp_user->roles);
    }

    public function wp_login_action($username, $obj_WP_User)
    {
        if (!empty(trim($obj_WP_User->data->user_email)) && !$this->is_administrator($obj_WP_User)) {
                $this->set_email_id_cookie(trim($obj_WP_User->data->user_email));
            }
    }

    public function set_email_id_cookie($email = '')
    {
        if('' == $email) {
            $current_user = wp_get_current_user();
            if(0 != $current_user->ID && !$this->is_administrator($current_user)) {
                    $email = $current_user->user_email;
            }
        }
        if('' != $email) {
            setcookie('email_id', $_COOKIE['email_id'] = $email, time() + 86400, '/');
        }
    }

    public function ws_cart_custom_fragment_load()
    {
            echo "<input id='ws_ma_event_type' type='hidden' style='display: none' />";
            echo "<input id='ws_ma_event_data' type='hidden' style='display: none' />";
    }

    public function ws_cart_custom_fragment($cart_fragments)
    {
        $ma_key = $this->get_ma_key();

        if (empty($this->get_email_id()) || empty($ma_key)) {
            return $cart_fragments;
        }

        $tracking_event_data = array();
        $cart_id = $this->get_wc_cart_id();

        if (empty(WC()->cart->cart_contents) && !empty(WC()->cart->removed_cart_contents)) {
            $tracking_event_data = $this->get_tracking_data_cart_deleted($cart_id);
            $tracking_event_data['event'] = 'cart_deleted';
            $this->invalidate_cart_recovery_snapshot();
        } else {
            return $cart_fragments;
        }

        $event_id = 'evt_' . wp_generate_password(12, false, false);
        $tracking_event_data['event_id'] = $event_id;
        LoggingManager::instance()->debug('cart', 'event built', array(
            'event'    => $tracking_event_data['event'],
            'event_id' => $event_id,
        ));

        LoggingManager::instance()->info('cart', 'cart emptied');

        $this->automation_manager->send($tracking_event_data, $ma_key);
        return $cart_fragments;
    }

    public function get_ma_key()
    {
        $settings = $this->api_manager->get_settings();

        if (
            empty($settings[SendinblueClient::MA_KEY]) ||
            empty($settings[SendinblueClient::IS_ABANDONED_CART_ENABLED]) ||
            !$settings[SendinblueClient::IS_ABANDONED_CART_ENABLED]
        ) {
            return false;
        }

        return $settings[SendinblueClient::MA_KEY];
    }

    public function handle_cart_update_event($cart_updated)
    {
        // Don't echo a cart_updated while we are rebuilding the cart from a
        // recovery link; the lines are being restored, not freshly changed.
        if ($this->is_restoring_cart) {
            return $cart_updated;
        }

        $ma_key = $this->get_ma_key();

        if (empty($this->get_email_id()) || empty($ma_key)) {
            LoggingManager::instance()->debug('cart', 'cart update skipped', array(
                'reason' => empty($ma_key) ? 'no ma key' : 'no email id',
            ));

            return $cart_updated;
        }

        $tracking_event_data = array();
        $cart_id = $this->get_wc_cart_id();

        if (!empty(WC()->cart->cart_contents)) {
            $tracking_event_data = $this->get_tracking_data_cart($cart_id);
            $tracking_event_data['event'] = 'cart_updated';

            $event_id = 'evt_' . wp_generate_password(12, false, false);
            $tracking_event_data['event_id'] = $event_id;
            LoggingManager::instance()->debug('cart', 'event built', array(
                'event'    => $tracking_event_data['event'],
                'event_id' => $event_id,
            ));
        } else {
            // Cart emptied: drop any recovery snapshot so a previously issued
            // link can't restore items the shopper has removed.
            $this->invalidate_cart_recovery_snapshot();
            LoggingManager::instance()->debug('cart', 'cart update skipped', array('reason' => 'cart empty'));

            return $cart_updated;
        }

        // Per-cart-mutation hot path — buffered into the request summary.
        LoggingManager::instance()->debug('cart', 'cart updated', array(
            'items' => count(WC()->cart->cart_contents),
        ));

        $this->automation_manager->send($tracking_event_data, $ma_key);
        return $cart_updated;
    }

    /**
     * Order statuses that mean the order is confirmed for the customer:
     * paid online (processing/completed), cash on delivery (processing),
     * free orders (processing/completed) and manual payments awaiting
     * confirmation (on-hold).
     */
    private static $order_completed_statuses = array('processing', 'completed', 'on-hold');

    const ORDER_COMPLETED_SENT_META = '_thankyou_action_done';
    const ORDER_CART_ID_META = '_sib_cart_id';
    const ORDER_TRACKING_EMAIL_META = '_sib_tracking_email';

    /**
     * Runs in the customer's own checkout request (classic and Blocks), before
     * the order is first saved. The cart id and tracking email only exist as
     * cookies in that request, so they are copied onto the order for the
     * server-side order_completed event, which may be sent later from a
     * gateway webhook, WP-Cron or the admin.
     */
    public function store_checkout_context($order)
    {
        if (!is_object($order) || !method_exists($order, 'update_meta_data')) {
            return;
        }

        try {
            $cart_id = $this->get_wc_cart_id();
            if ('' !== $cart_id) {
                $order->update_meta_data(self::ORDER_CART_ID_META, $cart_id);
            }

            $email = $this->resolve_tracking_email($order);
            if ('' !== $email) {
                $order->update_meta_data(self::ORDER_TRACKING_EMAIL_META, $email);
            }
        } catch (\Throwable $e) {
            $this->log_order_completed_failure('store checkout context', $e);
        } catch (\Exception $e) {
            // PHP 5.6 has no \Throwable; this arm is dead on 7+.
            $this->log_order_completed_failure('store checkout context', $e);
        }
    }

    /**
     * Primary trigger for order_completed: the order reaching a confirmed status.
     * Covers online payment (incl. gateway webhooks when the customer never
     * returns to the site), cash on delivery and free orders.
     */
    public function on_order_status_changed($order_id, $old_status = '', $new_status = '', $order = null)
    {
        if (!in_array($new_status, self::$order_completed_statuses, true)) {
            return;
        }

        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            $order = wc_get_order($order_id);
        }

        if (!$order) {
            LoggingManager::instance()->log(LoggingManager::LEVEL_ERROR, 'order', 'order status changed but order not loadable', array(
                'order_id'   => $order_id,
                'new_status' => $new_status,
            ));
            return;
        }

        $this->try_send_order_completed($order, 'status:' . $new_status);
    }

    /**
     * Fallback trigger on the thank-you page. The status hook normally sends
     * first; this only catches orders that reached a confirmed status without
     * the status hook running. Never sends for pending or failed orders.
     */
    public function ws_checkout_completed($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            LoggingManager::instance()->log(LoggingManager::LEVEL_ERROR, 'order', 'checkout completed but order not loadable', array(
                'order_id' => $order_id,
            ));
            return;
        }

        if (!$order->has_status(self::$order_completed_statuses)) {
            LoggingManager::instance()->debug('cart', 'order completed event skipped: order not confirmed yet', array(
                'order_id' => (int) $order_id,
                'status'   => $order->get_status(),
            ));
            return;
        }

        $this->try_send_order_completed($order, 'thankyou');
    }

    /**
     * Error boundary: the status hook runs inside checkout and gateway
     * webhooks, so a failure while building or sending the event is logged
     * and swallowed. It must never break the order or the customer's site.
     */
    private function try_send_order_completed($order, $source)
    {
        try {
            return $this->send_order_completed($order, $source);
        } catch (\Throwable $e) {
            $this->log_order_completed_failure($source, $e);
        } catch (\Exception $e) {
            // PHP 5.6 has no \Throwable; this arm is dead on 7+.
            $this->log_order_completed_failure($source, $e);
        }

        return false;
    }

    private function log_order_completed_failure($source, $e)
    {
        LoggingManager::instance()->log(LoggingManager::LEVEL_ERROR, 'order', 'order completed event failed', array(
            'source'  => $source,
            'error'   => $e->getMessage(),
            'file'    => basename($e->getFile()) . ':' . $e->getLine(),
        ));
    }

    private function send_order_completed($order, $source)
    {
        $ma_key = $this->get_ma_key();
        $logger = LoggingManager::instance();
        $order_id = (int) $order->get_id();

        if (empty($ma_key)) {
            $logger->debug('cart', 'order completed event skipped: abandoned cart tracking off', array(
                'order_id' => $order_id,
                'source'   => $source,
            ));

            return false;
        }

        // Sent once per order: a status can be reached more than once
        // (on-hold -> processing -> completed) and the thank-you page can be
        // reloaded any number of times. Read through the order so it works
        // with HPOS as well as post meta.
        if ($order->get_meta(self::ORDER_COMPLETED_SENT_META)) {
            $logger->debug('cart', 'order completed event skipped: already sent', array(
                'order_id' => $order_id,
                'source'   => $source,
            ));

            return false;
        }

        $tracking_event_data = $this->get_tracking_data_order($order_id);

        if (empty($tracking_event_data['email'])) {
            $logger->warn('cart', 'order completed event dropped: no email on order', array(
                'order_id' => $order_id,
                'source'   => $source,
            ));

            return false;
        }

        // Mark as sent once the payload is complete and before the HTTP call,
        // so a second trigger for the same order (next status change,
        // thank-you page) skips, while a failure above leaves the order
        // eligible for the fallback trigger.
        $order->update_meta_data(self::ORDER_COMPLETED_SENT_META, true);
        $order->save();

        $logger->info('cart', 'order completed event sent', array(
            'order_id' => $order_id,
            'email'    => $tracking_event_data['email'],
            'source'   => $source,
            'status'   => $order->get_status(),
        ));

        $this->automation_manager->send($tracking_event_data, $ma_key);

        return true;
    }

    /**
     * Email the automation event is attributed to. Logged-in customers use
     * their tracked email; guests and staff (anyone who can manage the shop,
     * shop managers included) use the billing email.
     */
    private function resolve_tracking_email($order)
    {
        $email = !empty($this->get_email_id()) ? $this->get_email_id() : '';

        if (!$this->is_user_logged_in() || current_user_can('manage_woocommerce')) {
            $email = !empty($order->get_billing_email()) ? $order->get_billing_email() : '';
        }

        return is_string($email) ? $email : '';
    }

    public function get_wc_cart_id()
    {
        $cookie_id = 'wp_woocommerce_session_';
        $cart_id   = '';
        foreach ($_COOKIE as $key => $val) {
            if (false !== strpos($key, $cookie_id)) {
                $cart_id = $key;
            }
        }

        return $cart_id;
    }

    public function get_tracking_data_cart_deleted($cart_id)
    {
        $email = !empty($this->get_email_id()) ? $this->get_email_id() : '';
        $data = array();

        $data['items'] = array();
        $data_track = array(
            'email'     => $email,
            'event'     => '',
            'eventdata' => array(
                'id'   => 'cart:' . $cart_id,
                'data' => $data,
            ),
        );

        return $data_track;
    }

    public function get_tracking_data_cart($cart_id, $email = "")
    {
        $data = array();
        WC()->cart->calculate_totals(); //Force WC to recalculate the cart to avoid discrepancies
        $cartitems = WC()->cart->get_cart();
        $totals = WC()->cart->get_totals();

        if (empty($email)) {
            $email = !empty($this->get_email_id()) ? $this->get_email_id() : '';
        }

        if ('' != $email){
            $this->set_email_id_cookie($email);
        }

        $data['affiliation'] = (!empty(get_bloginfo('name')) && is_string(get_bloginfo('name'))) ? get_bloginfo( 'name' ) : '';
        $data['subtotal'] = (!empty($totals['subtotal']) && is_numeric($totals['subtotal']) && !is_nan($totals['subtotal'])) ? $totals['subtotal'] : 0;
        $data['discount'] = (!empty($totals['discount_total']) && is_numeric($totals['discount_total']) && ! is_nan( $totals['discount_total'])) ? $totals['discount_total'] : 0;
        $data['shipping'] = (!empty($totals['shipping_total']) && is_numeric($totals['shipping_total']) && ! is_nan($totals['shipping_total'])) ? $totals['shipping_total'] : 0;
        $data['total_before_tax'] = (!empty( $totals['cart_contents_total']) && is_numeric($totals['cart_contents_total']) && !is_nan($totals['cart_contents_total'])) ? $totals['cart_contents_total'] : 0;
        $data['tax'] = (!empty($totals['total_tax']) && is_numeric($totals['total_tax']) && !is_nan($totals['total_tax'])) ? $totals['total_tax'] : 0;
        $data['total'] = (!empty( $totals['total']) && is_numeric($totals['total']) && !is_nan($totals['total'])) ? $totals['total'] : 0;
        
        $data['subtotal_parsed'] = self::parsePrice($data['subtotal']);
        $data['discount_parsed'] = self::parsePrice($data['discount']);
        $data['shipping_parsed'] = self::parsePrice($data['shipping']);
        $data['total_before_tax_parsed'] = self::parsePrice($data['total_before_tax']);
        $data['tax_parsed'] = self::parsePrice($data['tax']);
        $data['total_parsed'] = self::parsePrice($data['total']);
        $data['currency'] = is_string(get_woocommerce_currency()) ? get_woocommerce_currency() : '';

        // Plain cart/checkout URLs. The recovery token is appended after the
        // items loop below, and only when a non-empty snapshot was actually
        // stored, so a cart whose lines are all unrecoverable (custom item data)
        // never ships a token that would restore a stale, unrelated cart.
        $data['url'] = is_string(wc_get_cart_url()) ? wc_get_cart_url() : '';
        $data['checkouturl'] = is_string(wc_get_checkout_url()) ? wc_get_checkout_url() : '';

        $data['items'] = array();
        $recovery_snapshot = array();
        foreach ($cartitems as $key => $cartitem) {
            $item = array();
            // Evaluate now, before the lines below add plugin-specific keys
            // (e.g. variation_sku) to the local $cartitem copy.
            $line_has_custom_data = $this->cart_item_has_custom_data($cartitem);
            $item['name'] = (!empty($cartitem['data']->get_title()) && is_string( $cartitem['data']->get_title() ) ) ? $cartitem['data']->get_title() : '';
            $item['sku'] = (!empty( $cartitem['data']->get_sku()) && is_string($cartitem['data']->get_sku())) ? $cartitem['data']->get_sku() : '';
            $item['id'] = (!empty($cartitem['product_id']) && is_numeric($cartitem['product_id']) && ! is_nan( $cartitem['product_id'])) ? $cartitem['product_id'] : '';
            $cats_array = wp_get_post_terms( $item['id'], 'product_cat', array('fields' => 'names'));
            $item['category'] = is_array($cats_array) ? implode(',', $cats_array) : '';
            $item['variant_id'] = (!empty( $cartitem['variation_id']) && is_numeric($cartitem['variation_id'] ) && ! is_nan($cartitem['variation_id'])) ? $cartitem['variation_id'] : '';
            $variation = new \WC_Product_Variation($item['variant_id']);
            $cartitem['variation_sku'] = $variation->get_sku();
            $item['variant_sku']  = (!empty($cartitem['variation_sku']) && is_string($cartitem['variation_sku'])) ? $cartitem['variation_sku'] : '';
            $variant_name = is_array($cartitem['variation']) ? implode(',', $cartitem['variation']) : $cartitem['variation'] ?? '';
            $item['variant_name'] = (!empty($variant_name) && is_string($variant_name)) ? $variant_name : '';
            $item['quantity'] = (!empty( $cartitem['quantity'] ) && is_numeric($cartitem['quantity']) && ! is_nan($cartitem['quantity'])) ? $cartitem['quantity'] : 0;
            $unit_price = $cartitem['data']->is_on_sale() ? $cartitem['data']->get_sale_price() : $cartitem['data']->get_regular_price();
            $final_price = round((float) $unit_price * (float) $item['quantity'], 2);
            $item['price'] = (is_numeric($final_price) && !is_nan($final_price)) ? $final_price : 0;

            $price_taxinc = 0.0;
            if (!empty($cartitem['data'])) {
                $raw_price_taxinc = wc_get_price_including_tax($cartitem['data'], ['qty' => $item['quantity']]);
                $price_taxinc = (is_numeric($raw_price_taxinc) && !is_nan($raw_price_taxinc)) ? round((float) $raw_price_taxinc, 2) : 0.0;
            }
            $item['price_taxinc'] = $price_taxinc;
            
            $item['price_parsed'] = self::parsePrice($item['price']);
            $item['price_taxinc_parsed'] = self::parsePrice($item['price_taxinc']);

            $product = wc_get_product($cartitem['product_id']);
            $image_id = $variation->get_image_id() ? $variation->get_image_id() : $product->get_image_id();
            $item['image'] = wp_get_attachment_image_url($image_id, 'full');
            $dyn_img = $this->get_dynamic_img($cartitem['data']->get_image());
            if (filter_var($dyn_img, FILTER_VALIDATE_URL)) {
                $item['image'] = $dyn_img;
            }

            $item['url'] = (!empty($cartitem['data']->get_permalink()) && is_string($cartitem['data']->get_permalink())) ? $cartitem['data']->get_permalink() : '';
            array_push($data['items'], $item);

            // Snapshot only lines we can faithfully rebuild from product,
            // variation and quantity (no prices or PII are stored). Lines with
            // custom item data can't be reconstructed from these fields alone;
            // restoring them would risk a wrong line, price or false duplicate,
            // so they stay in the event payload but out of the recovery snapshot.
            if ($line_has_custom_data) {
                continue;
            }
            $recovery_snapshot[] = array(
                'product_id'   => (!empty($cartitem['product_id']) && is_numeric($cartitem['product_id'])) ? (int) $cartitem['product_id'] : 0,
                'variation_id' => (!empty($cartitem['variation_id']) && is_numeric($cartitem['variation_id'])) ? (int) $cartitem['variation_id'] : 0,
                'quantity'     => (!empty($cartitem['quantity']) && is_numeric($cartitem['quantity']) && $cartitem['quantity'] > 0) ? $cartitem['quantity'] : 1,
                'variation'    => (isset($cartitem['variation']) && is_array($cartitem['variation'])) ? $cartitem['variation'] : array(),
            );
        }

        // Persist the snapshot and tag the URLs only when there is something to
        // restore. The customer (logged-in or guest) gets a per-cart recovery
        // token; if every line was unrecoverable (custom item data) the snapshot
        // is empty, so we drop any stale snapshot and leave the URLs plain rather
        // than shipping a token that would restore an unrelated older cart.
        if (!empty($recovery_snapshot)) {
            $recovery_token = $this->get_or_create_cart_recovery_token();
            if ('' !== $recovery_token && $this->store_cart_recovery_snapshot($recovery_token, $recovery_snapshot)) {
                if ('' !== $data['url']) {
                    $data['url'] = add_query_arg(self::CART_RECOVERY_PARAM, $recovery_token, $data['url']);
                }
                if ('' !== $data['checkouturl']) {
                    $data['checkouturl'] = add_query_arg(self::CART_RECOVERY_PARAM, $recovery_token, $data['checkouturl']);
                }
            }
        } else {
            $this->invalidate_cart_recovery_snapshot();
        }

        $data_track = array(
            'email'     => $email,
            'event'     => '',
            'eventdata' => array(
                'id'   => 'cart:' . $cart_id,
                'data' => $data,
            ),
        );

        return $data_track;
    }

    public function get_tracking_data_order($order_id)
    {
        $order = wc_get_order($order_id);
        $data = array();

        // Prefer the cart id and email captured in the checkout request: when
        // the event is sent from a gateway webhook or the admin, the customer's
        // cookies are not available and the event would not close out the cart.
        $stored_cart_id = $order->get_meta(self::ORDER_CART_ID_META);
        $cart_id = !empty($stored_cart_id) ? $stored_cart_id : $this->get_wc_cart_id();

        $stored_email = $order->get_meta(self::ORDER_TRACKING_EMAIL_META);
        $email = !empty($stored_email) ? $stored_email : $this->resolve_tracking_email($order);

        // Only refresh the tracking cookie in the customer's own browser; staff
        // confirming payment must not get the customer's email cookie.
        if ('' != $email && !current_user_can('manage_woocommerce')) {
            $this->set_email_id_cookie($email);
        }

        $data['id'] = $order->get_order_number();
        $data['affiliation'] = (!empty(get_bloginfo('name')) && is_string(get_bloginfo('name'))) ? get_bloginfo('name') : '';
        $data['date'] = (!empty($order->get_date_created()->date(DATE_ATOM)) && is_string($order->get_date_created()->date(DATE_ATOM))) ? $order->get_date_created()->date(DATE_ATOM) : '';
        $data['subtotal'] = (!empty($order->get_subtotal()) && is_numeric($order->get_subtotal()) && !is_nan( $order->get_subtotal())) ? (float) $order->get_subtotal() : 0;
        $data['total'] = (!empty($order->get_total()) && is_numeric($order->get_total()) && !is_nan( $order->get_total())) ? (float) $order->get_total() : 0;
        $data['discount'] = (!empty($order->get_total_discount()) && is_numeric($order->get_total_discount()) && !is_nan($order->get_total_discount())) ? $order->get_total_discount() : 0;
        $data['shipping'] = (!empty($order->get_shipping_total()) && is_numeric($order->get_shipping_total()) && !is_nan($order->get_shipping_total())) ? (float) $order->get_shipping_total() : 0;
        $data['total_before_tax'] = (float) ($data['subtotal'] - $data['discount']);
        $data['tax'] = (!empty($order->get_total_tax()) && is_numeric($order->get_total_tax()) && !is_nan($order->get_total_tax())) ? round($order->get_total_tax(), 2) : 0;
        $data['revenue'] = (!empty($order->get_total()) && is_numeric($order->get_total()) && !is_nan($order->get_total())) ? (float) $order->get_total() : 0;
        
        $data['subtotal_parsed'] = self::parsePrice($data['subtotal']);
        $data['total_parsed'] = self::parsePrice($data['total']);
        $data['discount_parsed'] = self::parsePrice($data['discount']);
        $data['shipping_parsed'] = self::parsePrice($data['shipping']);
        $data['total_before_tax_parsed'] = self::parsePrice($data['total_before_tax']);
        $data['tax_parsed'] = self::parsePrice($data['tax']);
        $data['revenue_parsed'] = self::parsePrice($data['revenue']);
        $data['currency'] = (!empty($order->get_currency()) && is_string($order->get_currency())) ? $order->get_currency() : '';
        $data['url'] = (!empty($order->get_checkout_order_received_url()) && is_string($order->get_checkout_order_received_url())) ? $order->get_checkout_order_received_url() : '';

        $data['items'] = array();
        foreach ($order->get_items() as $item_key => $orderitem) {
            $product = wc_get_product($orderitem->get_product_id());

            $item = array();
            $item['name'] = (!empty($orderitem->get_name()) && is_string($orderitem->get_name())) ? $orderitem->get_name() : '';
            $item['sku'] = (!empty($product->get_sku()) && is_string($product->get_sku())) ? $product->get_sku() : '';

            $item['id'] = (!empty($orderitem->get_product_id()) && is_numeric($orderitem->get_product_id()) && ! is_nan($orderitem->get_product_id())) ? $orderitem->get_product_id() : '';
            $cats_array = wp_get_post_terms($item['id'], 'product_cat', array('fields' => 'names'));
            $item['category'] = is_array($cats_array) ? implode(',', $cats_array) : '';
            $item['variant_id'] = (!empty($orderitem->get_variation_id()) && is_numeric($orderitem->get_variation_id()) && !is_nan($orderitem->get_variation_id())) ? $orderitem->get_variation_id() : '';
            $variation = new \WC_Product_Variation($item['variant_id']);
            $item['variant_sku'] = (!empty($variation->get_sku()) && is_string($variation->get_sku())) ? $variation->get_sku() : '';
            $attributes = $variation->get_attributes();
            $item['variant_name'] = is_array($attributes) ? implode(',', $attributes) : '';
            $item['price'] = (!empty($orderitem->get_total()) && is_numeric($orderitem->get_total()) && ! is_nan($orderitem->get_total())) ? round($orderitem->get_total(), 2) : '';
            $item['tax'] = (!empty($orderitem->get_total_tax()) && is_numeric($orderitem->get_total_tax()) && ! is_nan($orderitem->get_total_tax())) ? round($orderitem->get_total_tax(), 2) : '';
            $item['price_taxinc'] = round((float) $item['price'] + (float) $item['tax'], 2);
            
            $item['price_parsed'] = self::parsePrice($item['price']);
            $item['tax_parsed'] = self::parsePrice($item['tax']);
            $item['price_taxinc_parsed'] = self::parsePrice($item['price_taxinc']);
            $item['quantity'] = (!empty($orderitem->get_quantity()) && is_numeric($orderitem->get_quantity()) && !is_nan($orderitem->get_quantity())) ? (float) $orderitem->get_quantity() : '';
            $product = wc_get_product($orderitem['product_id']);
            $image_id = $variation->get_image_id() ? $variation->get_image_id() : $product->get_image_id();
            $item['image'] = wp_get_attachment_image_url($image_id, 'full');

            $item['url'] = (!empty( $product->get_permalink()) && is_string($product->get_permalink())) ? $product->get_permalink() : '';
            array_push($data['items'], $item);
        }

        $shipping_address = array();
        $shipping_address['firstname'] = (!empty($order->get_shipping_first_name()) && is_string($order->get_shipping_first_name())) ? $order->get_shipping_first_name() : '';
        $shipping_address['lastname']  = (!empty($order->get_shipping_last_name()) && is_string($order->get_shipping_last_name())) ? $order->get_shipping_last_name() : '';
        $shipping_address['company']   = (!empty($order->get_shipping_company()) && is_string($order->get_shipping_company())) ? $order->get_shipping_company() : '';
        $shipping_address['phone']     = '';
        $shipping_address['address1']  = (!empty($order->get_shipping_address_1()) && is_string($order->get_shipping_address_1())) ? $order->get_shipping_address_1() : '';
        $shipping_address['address2']  = (!empty($order->get_shipping_address_2()) && is_string($order->get_shipping_address_2())) ? $order->get_shipping_address_2() : '';
        $shipping_address['city']      = (!empty( $order->get_shipping_city()) && is_string($order->get_shipping_city())) ? $order->get_shipping_city() : '';
        $shipping_address['country']   = (!empty($order->get_shipping_country()) && is_string($order->get_shipping_country())) ? $order->get_shipping_country() : '';
        $shipping_address['state']     = (!empty($order->get_shipping_state()) && is_string($order->get_shipping_state())) ? $order->get_shipping_state() : '';
        $shipping_address['zipcode']   = (!empty($order->get_shipping_postcode()) && is_string($order->get_shipping_postcode())) ? $order->get_shipping_postcode() : '';

        $billing_address = array();
        $billing_address['firstname'] = (!empty($order->get_billing_first_name()) && is_string($order->get_billing_first_name())) ? $order->get_billing_first_name() : '';
        $billing_address['lastname']  = (!empty($order->get_billing_last_name()) && is_string($order->get_billing_last_name())) ? $order->get_billing_last_name() : '';
        $billing_address['company']   = (!empty($order->get_billing_company()) && is_string($order->get_billing_company())) ? $order->get_billing_company() : '';
        $billing_address['phone']     = (!empty( $order->get_billing_phone()) && is_string($order->get_billing_phone())) ? $order->get_billing_phone() : '';
        $billing_address['address1']  = (!empty( $order->get_billing_address_1()) && is_string($order->get_billing_address_1())) ? $order->get_billing_address_1() : '';
        $billing_address['address2']  = (!empty( $order->get_billing_address_2()) && is_string($order->get_billing_address_2())) ? $order->get_billing_address_2() : '';
        $billing_address['city']      = (!empty( $order->get_billing_city()) && is_string($order->get_billing_city())) ? $order->get_billing_city() : '';
        $billing_address['country']   = (!empty( $order->get_billing_country()) && is_string($order->get_billing_country())) ? $order->get_billing_country() : '';
        $billing_address['state']     = (!empty( $order->get_billing_state()) && is_string($order->get_billing_state())) ? $order->get_billing_state() : '';
        $billing_address['zipcode']   = (!empty( $order->get_billing_postcode()) && is_string($order->get_billing_postcode())) ? $order->get_billing_postcode() : '';
        $billing_address['email']     = (!empty( $order->get_billing_email()) && is_string($order->get_billing_email())) ? $order->get_billing_email() : '';
        $data['shipping_address']     = $shipping_address;
        $data['billing_address']      = $billing_address;

        $data['payment_method']       = (!empty( $order->get_payment_method_title()) && is_string($order->get_payment_method_title())) ? $order->get_payment_method_title() : '';
        $data['customer_note']        = (!empty( $order->get_customer_note()) && is_string($order->get_customer_note())) ? $order->get_customer_note() : '';
        $data['shipping_method'] = '';
        foreach ($order->get_shipping_methods() as $item_id => $shipping_item) {
            $data['shipping_method'] = $shipping_item->get_method_title();
        }

        $data['shipping_tax'] = $order->get_shipping_tax() ?? "";
        $data['discount_tax'] = $order->get_discount_tax() ?? "";
        
        $data['shipping_tax_parsed'] = self::parsePrice($data['shipping_tax']);
        $data['discount_tax_parsed'] = self::parsePrice($data['discount_tax']);
        $data['discount_code'] = $order->get_coupon_codes() ?? "";
        $data['fee_lines'] = [];
        $fees = $order->get_fees();

        if (!empty($fees)) {
            foreach ($fees as $key => $fee) {
                $item = array();
                $item['fee_name'] = $fee->get_name() ?? "";
                $item['fee_total'] = $fee->get_total() ?? "";
                $item['fee_tax'] = $fee->get_total_tax() ?? "";
                
                $item['fee_total_parsed'] = self::parsePrice($item['fee_total']);
                $item['fee_tax_parsed'] = self::parsePrice($item['fee_tax']);
                
                array_push($data['fee_lines'], $item);
            }
        }

        $data_track = array(
            'email'     => $email,
            'event'     => 'order_completed',
            'eventdata' => array(
                'id'   => 'cart:' . $cart_id,
                'data' => $data,
            ),
        );

        $event_id = 'evt_' . wp_generate_password(12, false, false);
        $data_track['event_id'] = $event_id;
        LoggingManager::instance()->debug('cart', 'event built', array(
            'event'    => $data_track['event'],
            'event_id' => $event_id,
        ));

        return $data_track;
    }

    public function is_user_logged_in()
    {
        $current_user = wp_get_current_user();
        return $current_user->ID;
    }

    /**
     * Recovery token for the current cart. Issued for both logged-in customers
     * and guests (a WooCommerce guest cart_updated is a tracked event too, so it
     * gets cross-device recovery like any other). A random, unguessable token is
     * generated once per cart session and kept in WooCommerce session data, so it
     * is never derived from the email (the_ajax_hook `tracking_email`, the
     * `email_id` cookie) and cannot be used to select another session's snapshot:
     * the link only ever re-adds this session's own items to the opener's cart.
     * Reused for the life of the cart and cleared when the cart is emptied/
     * ordered, so a stale link stops working rather than replaying forever.
     * Administrators/staff are excluded. Returns '' when no WooCommerce session
     * is available.
     */
    private function get_or_create_cart_recovery_token()
    {
        $user = wp_get_current_user();
        if ($this->is_administrator($user)) {
            return '';
        }

        if (!function_exists('WC') || is_null(WC()->session)) {
            return '';
        }

        $token = WC()->session->get(self::CART_RECOVERY_TOKEN_SESSION);
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            // 128-bit random hex; uniqid(more_entropy) + wp_rand + salt keep it
            // unpredictable on PHP 5.6 (no random_bytes dependency).
            $token = substr(hash('sha256', uniqid((string) wp_rand(), true) . wp_salt('auth')), 0, 32);
            WC()->session->set(self::CART_RECOVERY_TOKEN_SESSION, $token);
        }

        return $token;
    }

    /**
     * Drop the recovery snapshot for the current cart and forget its token, so a
     * previously issued link can't restore items after the cart is emptied or the
     * order is placed. No-op when there is no token. The next cart gets a fresh
     * token.
     */
    private function invalidate_cart_recovery_snapshot()
    {
        if (!function_exists('WC') || is_null(WC()->session)) {
            return;
        }

        $token = WC()->session->get(self::CART_RECOVERY_TOKEN_SESSION);
        if (is_string($token) && '' !== $token) {
            delete_transient(self::CART_RECOVERY_PREFIX . $token);
            WC()->session->set(self::CART_RECOVERY_TOKEN_SESSION, null);
        }
    }

    /**
     * Fired on woocommerce_cart_emptied, which covers order placement (classic
     * and Blocks) as well as an explicit "empty cart". The cart-update hooks
     * don't run on that path, so the snapshot would otherwise survive and a
     * recovery link could refill already-purchased items.
     */
    public function on_cart_emptied()
    {
        $this->invalidate_cart_recovery_snapshot();
    }

    /**
     * Cart-item keys populated by WooCommerce core. A line carrying any key
     * outside this set holds custom data (add-ons, bundles, gift options,
     * subscriptions, ...) that can't be rebuilt from product/variation/quantity
     * alone, so it is excluded from the recovery snapshot.
     */
    private static $standard_cart_item_keys = array(
        'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash',
        'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax',
    );

    private function cart_item_has_custom_data($cartitem)
    {
        if (!is_array($cartitem)) {
            return false;
        }

        return !empty(array_diff(array_keys($cartitem), self::$standard_cart_item_keys));
    }

    /**
     * Persist a lean snapshot of the cart lines so the recovery link can rebuild
     * the cart later. Best-effort: a storage failure must never break the event.
     * Returns true only when the snapshot was stored, so the caller knows whether
     * it is safe to put the token on the URLs.
     */
    private function store_cart_recovery_snapshot($token, $snapshot)
    {
        if ('' === $token || empty($snapshot) || !is_array($snapshot)) {
            return false;
        }

        try {
            // 30 days without MONTH_IN_SECONDS (WP >= 4.4 only); DAY_IN_SECONDS
            // ships since 3.5.
            return (bool) set_transient(self::CART_RECOVERY_PREFIX . $token, $snapshot, 30 * DAY_IN_SECONDS);
        } catch (\Throwable $e) {
            LoggingManager::instance()->error('cart', 'cart recovery snapshot not stored', array('error' => $e->getMessage()));
        } catch (\Exception $e) {
            // PHP 5.6 has no \Throwable; this arm is dead on 7+.
            LoggingManager::instance()->error('cart', 'cart recovery snapshot not stored', array('error' => $e->getMessage()));
        }

        return false;
    }

    /**
     * Front-end entry point (wp_loaded): when the request carries a cart recovery
     * token, rebuild the cart from the stored snapshot. Guarded and swallowed so
     * it can never take the storefront down.
     */
    public function maybe_restore_abandoned_cart()
    {
        // Cheapest guard first: the token must be present on the request.
        if (empty($_GET[self::CART_RECOVERY_PARAM])) {
            return;
        }

        // Never run in admin, AJAX, cron or REST contexts.
        if (
            is_admin() ||
            (function_exists('wp_doing_ajax') && wp_doing_ajax()) ||
            (defined('DOING_CRON') && DOING_CRON) ||
            (defined('REST_REQUEST') && REST_REQUEST)
        ) {
            return;
        }

        try {
            $token = sanitize_text_field(wp_unslash($_GET[self::CART_RECOVERY_PARAM]));
            $this->restore_abandoned_cart($token);
        } catch (\Throwable $e) {
            LoggingManager::instance()->error('cart', 'cart recovery failed', array('error' => $e->getMessage()));
        } catch (\Exception $e) {
            // PHP 5.6 has no \Throwable; this arm is dead on 7+.
            LoggingManager::instance()->error('cart', 'cart recovery failed', array('error' => $e->getMessage()));
        }
    }

    /**
     * Rebuild the cart from a stored snapshot. Idempotent per session, merges
     * with (never clobbers) an existing cart, and silently skips products that
     * no longer exist or can't be purchased.
     */
    private function restore_abandoned_cart($token)
    {
        // Defensive: the token is a sha256 hex digest.
        if (!is_string($token) || !preg_match('/^[a-f0-9]{16,64}$/', $token)) {
            return;
        }

        if (!function_exists('WC') || is_null(WC()->cart) || is_null(WC()->session)) {
            return;
        }

        // Restore each token at most once per session, so navigating the site
        // or reloading the cart page does not keep re-adding items, while a
        // different recovery link opened later in the same session still works.
        $handled = WC()->session->get(self::CART_RECOVERY_SESSION_KEY);
        $handled = is_array($handled) ? $handled : array();
        if (in_array($token, $handled, true)) {
            return;
        }

        $snapshot = get_transient(self::CART_RECOVERY_PREFIX . $token);
        if (empty($snapshot) || !is_array($snapshot)) {
            // Nothing to restore: an expired/missing snapshot needs no marker.
            // The token only rides on this one request, so there is no reload to
            // guard against, and a cookie-less first-time visitor could not
            // persist the marker anyway.
            return;
        }

        // Keep the restore silent: WC_Cart::add_to_cart() raises error notices
        // for out-of-stock / unpurchasable / insufficient-stock lines, which
        // would render a red banner on the cart page. We pre-filter those lines,
        // and restore the pre-existing notices afterwards as a belt-and-braces
        // guard so nothing our restore triggered leaks onto the page.
        $prior_notices = function_exists('wc_get_notices') ? wc_get_notices() : array();

        $restored = 0;
        // Suppress our own cart_updated echo while we repopulate the cart.
        $this->is_restoring_cart = true;
        try {
            foreach ($snapshot as $line) {
                if (!is_array($line) || empty($line['product_id'])) {
                    continue;
                }

                $product_id   = (int) $line['product_id'];
                $variation_id = isset($line['variation_id']) ? (int) $line['variation_id'] : 0;
                $quantity     = (isset($line['quantity']) && is_numeric($line['quantity']) && $line['quantity'] > 0) ? $line['quantity'] : 1;
                $variation    = (isset($line['variation']) && is_array($line['variation'])) ? $line['variation'] : array();

                // Skip products that can't be added right now: gone, not
                // purchasable, out of stock, or short on stock. This is what
                // keeps add_to_cart() from emitting an error notice.
                $product = wc_get_product($variation_id ? $variation_id : $product_id);
                if (!$product || !$product->exists() || !$product->is_purchasable() || !$product->is_in_stock()) {
                    continue;
                }

                // Sold-individually products cap at one; clamp before adding.
                if ($product->is_sold_individually()) {
                    $quantity = 1;
                }

                if (!$product->has_enough_stock($quantity)) {
                    continue;
                }

                // Don't duplicate a line the shopper already has in their cart.
                $cart_item_id = WC()->cart->generate_cart_id($product_id, $variation_id, $variation);
                if (WC()->cart->find_product_in_cart($cart_item_id)) {
                    continue;
                }

                if (WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation)) {
                    $restored++;
                }
            }
        } finally {
            $this->is_restoring_cart = false;
            // Drop anything add_to_cart() may have queued and reinstate the
            // notices that existed before the restore ran.
            if (function_exists('wc_clear_notices') && function_exists('wc_set_notices')) {
                wc_clear_notices();
                wc_set_notices($prior_notices);
            }
        }

        $handled[] = $token;
        WC()->session->set(self::CART_RECOVERY_SESSION_KEY, $handled);

        if ($restored > 0) {
            LoggingManager::instance()->info('cart', 'abandoned cart restored', array('items' => $restored));
        }
    }

    private function checkout_label($settings)
    {
        $label = __('Subscribe to our newsletter', SENDINBLUE_WC_TEXTDOMAIN);
        if (!empty($settings[SendinblueClient::DISPLAY_OPT_IN_LABEL])) {
            $label = $settings[SendinblueClient::DISPLAY_OPT_IN_LABEL];
        }

        return $label;
    }

    public function add_optin_terms($checkout_fields)
    {
        $settings = $this->api_manager->get_settings();

        if (
            !empty($settings[SendinblueClient::IS_DISPLAY_OPT_IN_ENABLED]) &&
            $settings[SendinblueClient::DISPLAY_OPT_IN_LOCATION] == 3
        ) {
            ?>
            <p class="form-row terms woocommerce-validated" id="ws_opt_in_field" style="clear:both;">
                <label class="checkbox">
                    <input type="checkbox" class="input-checkbox" name="ws_opt_in" <?php echo ('checked' == empty($settings[SendinblueClient::IS_DISPLAY_OPT_IN_CHECKED]) ? '' : 'checked'); ?>>
            <?php echo esc_attr($this->checkout_label($settings)); ?>
                </label>
            </p>
            <?php
        }
    }

    public function add_optin_billing($checkout_fields)
    {
        $settings = $this->api_manager->get_settings();

        if (
            !empty($settings[SendinblueClient::IS_DISPLAY_OPT_IN_ENABLED]) &&
            $settings[SendinblueClient::DISPLAY_OPT_IN_LOCATION] == 1
        ) {
            $checkout_fields['billing']['ws_opt_in'] = array(
                'type'    => 'checkbox',
                'label'   => esc_attr($this->checkout_label($settings)),
                'default' => 'checked' == empty($settings[SendinblueClient::IS_DISPLAY_OPT_IN_CHECKED]) ? 0 : 1,
            );
        }

        if (
            !empty($settings[SendinblueClient::IS_DISPLAY_OPT_IN_ENABLED]) &&
            $settings[SendinblueClient::DISPLAY_OPT_IN_LOCATION] == 2
        ) {
            $checkout_fields['order']['ws_opt_in'] = array(
                'type'    => 'checkbox',
                'label'   => esc_attr($this->checkout_label($settings)),
                'default' => 'checked' == empty($settings[SendinblueClient::IS_DISPLAY_OPT_IN_CHECKED]) ? 0 : 1,
            );
        }

        return $checkout_fields;
    }

    public function add_optin_order($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $opt_in = isset($_POST['ws_opt_in']) ? 'yes' : 'no';
        $order->update_meta_data('ws_opt_in', $opt_in);
        $order->save();

        // What the shopper actually ticked at checkout. Decides later whether
        // the contact is synced as subscribed, so it is worth capturing at the
        // moment it is recorded rather than inferring it afterwards.
        LoggingManager::instance()->info('contact', 'checkout opt-in recorded', array(
            'order_id' => (int) $order_id,
            'opt_in'   => $opt_in,
        ));
    }

    public function add_optin_wc_checkout_block() 
    {
        $settings = $this->api_manager->get_settings();
        $checkout_label = $this->checkout_label($settings);
        $location = "";

        //Mapping of old Under billing & Under T & C to Under contact info
        if (
            !empty($settings[SendinblueClient::IS_DISPLAY_OPT_IN_ENABLED]) && (
            $settings[SendinblueClient::DISPLAY_OPT_IN_LOCATION] == 1 || $settings[SendinblueClient::DISPLAY_OPT_IN_LOCATION] == 3 )
        ) {
            $location = "contact";
            $this->register_checkout_fields($checkout_label, $location);
        }

        //Mapping of Under Order Info to Under Order Info
        if (
            !empty($settings[SendinblueClient::IS_DISPLAY_OPT_IN_ENABLED]) &&
            $settings[SendinblueClient::DISPLAY_OPT_IN_LOCATION] == 2
        ) {
            $location = "order";
            $this->register_checkout_fields($checkout_label, $location);
        }
    }

    public function register_checkout_fields($checkout_label, $location) {
        woocommerce_register_additional_checkout_field(
            array(
                'id'            => 'SendinblueWoocommerce/newsletter_opt_in',
                'label'         => $checkout_label,
                'location'      => $location,
                'required'      => false,
                    'type'     => 'checkbox',
            ),
        );
    }

    private function get_dynamic_img($html_tags)
    {
        if (!class_exists("DOMDocument") || empty($html_tags)) {
            return null;
        }

        $doc = new \DOMDocument();
        $doc->loadHTML($html_tags, LIBXML_NOWARNING | LIBXML_NOERROR);
        $tags = $doc->getElementsByTagName('img');
        foreach ($tags as $tag) {
            return $tag->getAttribute('src');
        }

        return null;
    }
    public static function parsePrice($priceInput) {
        // If input is already numeric, convert to float directly
        if (is_numeric($priceInput)) {
            return round((float)$priceInput, 2);
        }
        
        // If input is null or empty, return 0.0
        if ($priceInput === null || $priceInput === '') {
            return 0.0;
        }
        
        // Convert to string and process
        $priceStr = trim((string) $priceInput);

        $priceStr = preg_replace('/[^\d.,\s]/u', '', $priceStr);

        $priceStr = preg_replace('/\s+/u', '', $priceStr);

        $hasComma = strpos($priceStr, ',') !== false;
        $hasDot = strpos($priceStr, '.') !== false;

        if ($hasComma && $hasDot) {
            $lastComma = strrpos($priceStr, ',');
            $lastDot = strrpos($priceStr, '.');

            if ($lastComma > $lastDot) {
                // Format: 1.234,56 → dot is thousands, comma is decimal
                $priceStr = str_replace('.', '', $priceStr);
                $priceStr = str_replace(',', '.', $priceStr);
            } else {
                // Format: 1,234.56 → comma is thousands, dot is decimal
                $priceStr = str_replace(',', '', $priceStr);
            }
        } elseif ($hasComma) {
            // Format: 1000,50 → comma is decimal
            $priceStr = str_replace(',', '.', $priceStr);
        }
        // If only dot or none, assume dot is decimal and proceed
        if (is_numeric($priceStr)) {
            $normalized = str_replace(',', '.', $priceStr);
            $floatVal = (float)$normalized;
            return round($floatVal, 2);
        }

        return 0.0;
    }
}
