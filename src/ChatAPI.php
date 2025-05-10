<?php

namespace Dharman;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Cookie\FileCookieJar;

class ChatAPI {
	private $siteUrl = 'https://stackoverflow.com';

	/**
	 * Guzzle client
	 *
	 * @var Guzzle
	 */
	private $client = null;

	private $rooms = [];

	public function __construct(string $chatUserEmail, string $chatUserPassword, string $cookieJarLocation) {
		$sessionCookieJar = new FileCookieJar($cookieJarLocation, true);
		$this->client = new Guzzle([
			'cookies' => $sessionCookieJar
		]);

		$loginPage = $this->siteUrl.'/users/login';
		$rq = $this->client->request('GET', $loginPage, [
			'cookies' => $sessionCookieJar,
			'allow_redirects' => false,
		]);

		if ($rq->getStatusCode() == 200) {
			$rq = $this->client->get($loginPage);
			$fkey = $this->getFKey($rq->getBody()->getContents());

			$rq = $this->client->request('POST', $loginPage, [
				'form_params' => [
					'fkey' => $fkey,
					'email' => $chatUserEmail,
					'password' => $chatUserPassword
				]
			]);
			// var_dump('Logged in!');
			$sessionCookieJar->save($cookieJarLocation);
		}
	}

	public function sendMessage(int $roomId, string $message): string {
		if (strlen($message) > 500) {
			$messages = wordwrap($message, 500, "\n", true);
			$ret = '';
			foreach (explode("\n", $messages) as $partialMessage) {
				$ret .= $this->sendMessage($roomId, $partialMessage);
			}
			return $ret;
		}

		$chatFKey = $this->rooms[$roomId] ?? $this->joinRoom($roomId);

		// Send message
		try {
			// try once. If rate limited then wait and try again
			$rq = $this->client->request('POST', 'https://chat.stackoverflow.com/chats/'.$roomId.'/messages/new', [
				'form_params' => [
					'text' => $message,
					'fkey' => $chatFKey
				]
			]);
			return $rq->getBody()->getContents();
		} catch (RequestException $e) {
			$ex_contents = $e->getResponse()->getBody()->getContents();
			$sleepTime = (int) filter_var($ex_contents, FILTER_SANITIZE_NUMBER_INT);
			$sleepTime = max(1, $sleepTime);
			sleep($sleepTime);
			// retry
			$rq = $this->client->request('POST', 'https://chat.stackoverflow.com/chats/'.$roomId.'/messages/new', [
				'form_params' => [
					'text' => $message,
					'fkey' => $chatFKey
				]
			]);
			return $rq->getBody()->getContents();
		}
	}

	private function joinRoom($roomId) {
		//get fkey for chat
		$rq = $this->client->get('https://chat.stackoverflow.com/rooms/'.$roomId);
		$fKey = $this->getFKey($rq->getBody()->getContents());
		$this->rooms[$roomId] = $fKey;
		return $fKey;
	}

	private function getFKey(string $html) {
		libxml_use_internal_errors(true);
		$doc = new \DOMDocument();
		$doc->loadHTML($html);
		libxml_use_internal_errors(false);

		$xpath = new \DomXpath($doc);
		foreach ($xpath->query('//input[@name="fkey"]') as $link) {
			return $link->getAttribute('value');
		}
	}
}
