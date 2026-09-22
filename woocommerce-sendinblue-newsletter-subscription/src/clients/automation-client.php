<?php

namespace SendinblueWoocommerce\Clients;

use SendinblueWoocommerce\Managers\LoggingManager;

require_once SENDINBLUE_WC_ROOT_PATH . '/src/managers/logging-manager.php';

/**
 * Class AutomationClient
 *
 * @package SendinblueWoocommerce\Clients
 */
class AutomationClient
{
	private const AUTOMATION_URL   = 'https://in-automate.brevo.com/api/v2/trackEvent';
	private const HTTP_METHOD_POST = 'POST';
	private const USER_AGENT       = 'sendinblue_plugins/woocommerce_common';

	private function makeHttpRequest($body, $ma_key)
	{
		$logging = LoggingManager::instance()->is_enabled();

		$headers = array(
			'Content-Type' => 'application/json',
			'ma-key'       => $ma_key,
			'User-Agent'   => self::USER_AGENT,
		);
		// Omitted rather than sent empty: some WAFs reject empty headers.
		if (!empty($body['event_id'])) {
			$headers['X-Brevo-Event-Id'] = $body['event_id'];
		}

		$args = array(
			'method'    => self::HTTP_METHOD_POST,
			'timeout'   => $logging ? 5 : 30,
			'blocking'  => $logging,
			'headers'   => $headers,
			'body'    => wp_json_encode($body),
		);

		$started  = microtime(true);
		$response = wp_remote_request(self::AUTOMATION_URL, $args);

		// Non-blocking in normal operation, so there is no status code to
		// report there — only that the plugin handed it to WordPress. Inside
		// a logging window the request blocks (5s cap: a slow Brevo cannot
		// stall checkout) so status/timing are real and delivery is
		// confirmable, not just dispatch.
		LoggingManager::instance()->log(
			(is_wp_error($response) || ($logging && (int) wp_remote_retrieve_response_code($response) >= 400))
				? LoggingManager::LEVEL_ERROR : LoggingManager::LEVEL_INFO,
			'cart',
			'automation event dispatched',
			array(
				'event'    => isset($body['event']) ? $body['event'] : null,
				'event_id' => isset($body['event_id']) ? $body['event_id'] : null,
				'blocking' => $logging,
				'status'   => $logging ? (int) wp_remote_retrieve_response_code($response) : null,
				'ms'       => $logging ? (int) round((microtime(true) - $started) * 1000) : null,
				'wp_error' => is_wp_error($response) ? $response->get_error_message() : null,
			)
		);
	}

	public function send($data, $ma_key)
	{
		return $this->makeHttpRequest($data, $ma_key);
	}
}
