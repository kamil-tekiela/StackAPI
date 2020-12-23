<?php

namespace Dharman;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\Message;

class StackAPI {
	/**
	 * Guzzle client
	 *
	 * @var Guzzle
	 */
	private $client;

	/**
	 * Time the next request can be made at.
	 *
	 * @var float
	 */
	private $nextRqPossibleAt = 0.0;

	public $lastQuota = null;

	public function __construct(Guzzle $client) {
		$this->client = $client;
	}

	public function request(string $method, string $url, array $args): \stdClass {
		// handle backoff properly
		$timeNow = microtime(true);
		if ($timeNow < $this->nextRqPossibleAt) {
			$backoffTime = ceil($this->nextRqPossibleAt - $timeNow);
			sleep($backoffTime);
		}

		// make the call
		try {
			if ($method == 'GET') {
				$rq = $this->client->request($method, $url, ['query' => $args]);
			} else {
				$rq = $this->client->request($method, $url, ['form_params' => $args]);
			}
		} catch (RequestException $e) {
			$response = $e->getResponse();
			if (isset($response)) {
				if (($json = json_decode($response->getBody()->getContents())) && isset($json->error_id) && $json->error_id == 502) {
					sleep(10 * 60);
					return $this->request($method, $url, $args);
				} else {
					throw new \Exception(Message::toString($e->getResponse()));
				}
			} else {
				throw $e;
			}
		}

		if (isset($rq)) {
			$body = $rq->getBody()->getContents();
		} else {
			throw new \Exception("Response is empty");
		}

		$contents = json_decode($body);

		$this->nextRqPossibleAt = microtime(true);
		if (isset($contents->backoff)) {
			$this->nextRqPossibleAt + $contents->backoff;
		}

		$this->lastQuota = $contents->quota_remaining;

		return $contents;
	}
}
