<?php

namespace SendinblueWoocommerce\Clients;

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
		$args = array(
			'method'    => self::HTTP_METHOD_POST,
			'timeout'   => 30,
			'blocking'  => false,
			'headers'   => array(
				'Content-Type' => 'application/json',
				'ma-key'       => $ma_key,
				'User-Agent'   => self::USER_AGENT,
			),
			'body'    => wp_json_encode($body),
		);

		wp_remote_request(self::AUTOMATION_URL, $args);
	}

	public function send($data, $ma_key)
	{
		return $this->makeHttpRequest($data, $ma_key);
	}
}
