<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Social\Service;


use daita\MySmallPhpTools\Model\Request;
use daita\MySmallPhpTools\Traits\TArrayTools;
use Exception;
use OCA\Social\AP;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\NotesRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\EmptyQueueException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Exceptions\QueueStatusException;
use OCA\Social\Exceptions\Request410Exception;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\RequestQueue;

class ActivityService {


	use TArrayTools;


	const REQUEST_INBOX = 1;

	const TIMEOUT_LIVE = 2;
	const TIMEOUT_ASYNC = 5;
	const TIMEOUT_SERVICE = 10;

	const CONTEXT_ACTIVITYSTREAMS = 'https://www.w3.org/ns/activitystreams';
	const CONTEXT_SECURITY = 'https://w3id.org/security/v1';

	const TO_PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

	const DATE_FORMAT = 'D, d M Y H:i:s T';
	const DATE_DELAY = 30;


	/** @var NotesRequest */
	private $notesRequest;

	/** @var FollowsRequest */
	private $followsRequest;

	/** @var QueueService */
	private $queueService;

	/** @var AccountService */
	private $accountService;

	/** @var ConfigService */
	private $configService;

	/** @var CurlService */
	private $curlService;

	/** @var MiscService */
	private $miscService;


	/** @var array */
	private $failInstances;


	/**
	 * ActivityService constructor.
	 *
	 * @param NotesRequest $notesRequest
	 * @param FollowsRequest $followsRequest
	 * @param QueueService $queueService
	 * @param AccountService $accountService
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NotesRequest $notesRequest, FollowsRequest $followsRequest, QueueService $queueService,
		AccountService $accountService,
		CurlService $curlService, ConfigService $configService, MiscService $miscService
	) {
		$this->notesRequest = $notesRequest;
		$this->followsRequest = $followsRequest;
		$this->queueService = $queueService;
		$this->accountService = $accountService;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param Person $actor
	 * @param ACore $item
	 * @param ACore $activity
	 *
	 * @return string
	 * @throws Exception
	 */
	public function createActivity(Person $actor, ACore $item, ACore &$activity = null): string {

		$activity = new Create();

//		$this->activityStreamsService->initCore($activity);

		$activity->setObject($item);
		$activity->setId($item->getId() . '/activity');
		$activity->setInstancePaths($item->getInstancePaths());

//		if ($item->getToArray() !== []) {
//			$activity->setToArray($item->getToArray());
//		} else {
//			$activity->setTo($item->getTo());
//		}

		$activity->setActor($actor);

		return $this->request($activity);
	}


	/**
	 * @param ACore $item
	 *
	 * @return string
	 * @throws Exception
	 */
	public function deleteActivity(ACore $item): string {
		$delete = new Delete();
		$delete->setId($item->getId() . '#delete');
		$delete->setActorId($item->getActorId());

		$tombstone = new Tombstone($delete);
		$tombstone->setId($item->getId());

		$delete->setObject($tombstone);
		$delete->addInstancePaths($item->getInstancePaths());

		return $this->request($delete);
	}


	/**
	 * @param string $id
	 *
	 * @return ACore
	 * @throws InvalidResourceException
	 */
	public function getItem(string $id): ACore {
		if ($id === '') {
			throw new InvalidResourceException();
		}

		$requests = [
			'Note'
		];

		foreach ($requests as $request) {
			try {
				$interface = AP::$activityPub->getInterfaceFromType($request);

				return $interface->getItemById($id);
			} catch (Exception $e) {
			}
		}

		throw new InvalidResourceException();
	}


	/**
	 * @param ACore $activity
	 *
	 * @return string
	 * @throws Exception
	 */
	public function request(ACore $activity): string {
		$this->saveActivity($activity);

		$author = $this->getAuthorFromItem($activity);
		$instancePaths = $this->generateInstancePaths($activity);
		$token = $this->queueService->generateRequestQueue($instancePaths, $activity, $author);
		$this->manageInit();

		try {
			$directRequest = $this->queueService->getPriorityRequest($token);
			$directRequest->setTimeout(self::TIMEOUT_LIVE);
			$this->manageRequest($directRequest);
		} catch (RequestException $e) {
		} catch (NoHighPriorityRequestException $e) {
		} catch (EmptyQueueException $e) {
			return '';
		}

		$this->curlService->asyncWithToken($token);

		return $token;
	}


	public function manageInit() {
		$this->failInstances = [];
	}


	/**
	 * @param RequestQueue $queue
	 *
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function manageRequest(RequestQueue $queue) {
		$host = $queue->getInstance()
					  ->getAddress();
		if (in_array($host, $this->failInstances)) {
			throw new RequestException();
		}

		try {
			$this->queueService->initRequest($queue);
		} catch (QueueStatusException $e) {
			return;
		}

		try {
			$result = $this->generateRequestFromQueue($queue);
		} catch (ActorDoesNotExistException $e) {
			$this->queueService->deleteRequest($queue);

			return;
		} catch (Request410Exception $e) {
			$this->queueService->deleteRequest($queue);

			return;
		}

		try {
			$accepted = [200, 202];
			if (in_array($this->getint('_code', $result, 500), $accepted)) {
				$this->queueService->endRequest($queue, true);
			} else {
				$this->queueService->endRequest($queue, false);
				$this->failInstances[] = $host;
			}
		} catch (QueueStatusException $e) {
		}
	}


	/** // ====> instanceService
	 *
	 * @param ACore $activity
	 *
	 * @return InstancePath[]
	 */
	private function generateInstancePaths(ACore $activity): array {
		$instancePaths = [];
		foreach ($activity->getInstancePaths() as $instancePath) {
			if ($instancePath->getType() === InstancePath::TYPE_FOLLOWERS) {
				$instancePaths = array_merge(
					$instancePaths, $this->generateInstancePathsFollowers($instancePath)
				);
			} else {
				$instancePaths[] = $instancePath;
			}
		}

		return $instancePaths;
	}


	/**
	 * @param InstancePath $instancePath
	 *
	 * @return InstancePath[]
	 */
	private function generateInstancePathsFollowers(InstancePath $instancePath): array {
		$follows = $this->followsRequest->getByFollowId($instancePath->getUri());

		$sharedInboxes = [];
		$instancePaths = [];
		foreach ($follows as $follow) {
			if (!$follow->gotActor()) {
				// TODO - check if cache can be empty at this point ?
				continue;
			}

			$sharedInbox = $follow->getActor()
								  ->getSharedInbox();
			if (in_array($sharedInbox, $sharedInboxes)) {
				continue;
			}

			$sharedInboxes[] = $sharedInbox;
			$instancePaths[] = new InstancePath(
				$sharedInbox, InstancePath::TYPE_GLOBAL, $instancePath->getPriority()
			);
//			$result[] = $this->generateRequest(
//				new InstancePath($sharedInbox, InstancePath::TYPE_GLOBAL), $activity
//			);
		}

		return $instancePaths;
	}


	/**
	 * @param RequestQueue $queue
	 *
	 * @return Request[]
	 * @throws ActorDoesNotExistException
	 * @throws Request410Exception
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function generateRequestFromQueue(RequestQueue $queue): array {
		//InstancePath $path, string $activity, string $author
//		$queue->getInstance(), $queue->getActivity(), $queue->getAuthor()
//			);
		$path = $queue->getInstance();

//		$document = json_encode($activity);
		$date = gmdate(self::DATE_FORMAT);
		$localActor = $this->getActorFromAuthor($queue->getAuthor());

		// TODO:  move this to SignatureService ?
		$localActorLink =
			$this->configService->getUrlSocial() . '@' . $localActor->getPreferredUsername();
		$signature = "(request-target): post " . $path->getPath() . "\nhost: " . $path->getAddress()
					 . "\ndate: " . $date;
		openssl_sign($signature, $signed, $localActor->getPrivateKey(), OPENSSL_ALGO_SHA256);

		$signed = base64_encode($signed);
		$header =
			'keyId="' . $localActorLink . '",headers="(request-target) host date",signature="'
			. $signed . '"';

		$requestType = Request::TYPE_GET;
		if ($path->getType() === InstancePath::TYPE_INBOX
			|| $path->getType() === InstancePath::TYPE_GLOBAL
			|| $path->getType() === InstancePath::TYPE_FOLLOWERS) {
			$requestType = Request::TYPE_POST;
		}

		$request = new Request($path->getPath(), $requestType);
		$request->setTimeout($queue->getTimeout());
		$request->addHeader('Host: ' . $path->getAddress());
		$request->addHeader('Date: ' . $date);
		$request->addHeader('Signature: ' . $header);

		$request->setDataJson($queue->getActivity());
		$request->setAddress($path->getAddress());

		return $this->curlService->request($request);
	}


	/**
	 * @param ACore $activity
	 *
	 * @return string
	 */
	private function getAuthorFromItem(Acore $activity): string {
		if ($activity->gotActor()) {
			return $activity->getActor()
							->getId();
		}

		return $activity->getActorId();
	}


	/**
	 * @param string $author
	 *
	 * @return Person
	 * @throws SocialAppConfigException
	 * @throws ActorDoesNotExistException
	 */
	private function getActorFromAuthor(string $author): Person {
		return $this->accountService->getFromId($author);
	}


//	/**
//	 * @param IRequest $request
//	 *
//	 * @return string
//	 * @throws InvalidResourceException
//	 * @throws MalformedArrayException
//	 * @throws Request410Exception
//	 * @throws RequestException
//	 * @throws SignatureException
//	 * @throws SocialAppConfigException
//	 * @throws UrlCloudException
//	 * @throws InvalidOriginException
//	 */
//	private function checkSignature(IRequest $request): string {
//		$signatureHeader = $request->getHeader('Signature');
//
//		$sign = $this->parseSignatureHeader($signatureHeader);
//		$this->mustContains(['keyId', 'headers', 'signature'], $sign);
//
//		$keyId = $sign['keyId'];
//		$origin = $this->getKeyOrigin($keyId);
//
//		$headers = $sign['headers'];
//		$signed = base64_decode($sign['signature']);
//		$estimated = $this->generateEstimatedSignature($headers, $request);
//
//		$publicKey = $this->retrieveKey($keyId);
//
//		if ($publicKey === '' || openssl_verify($estimated, $signed, $publicKey, 'sha256') !== 1) {
//			throw new SignatureException('signature cannot be checked');
//		}
//
//		return $origin;
//	}
//

//	/**
//	 * @param $id
//	 *
//	 * @return string
//	 * @throws InvalidOriginException
//	 */
//	private function getKeyOrigin($id) {
//		$host = parse_url($id, PHP_URL_HOST);
//		if (is_string($host) && ($host !== '')) {
//			return $host;
//		}
//
//		throw new InvalidOriginException();
//	}
//
//
//	/**
//	 * @param string $headers
//	 * @param IRequest $request
//	 *
//	 * @return string
//	 */
//	private function generateEstimatedSignature(string $headers, IRequest $request): string {
//		$keys = explode(' ', $headers);
//
//		$target = '';
//		try {
//			$target = strtolower($request->getMethod()) . " " . $request->getRequestUri();
//		} catch (Exception $e) {
//		}
//
//		$estimated = "(request-target): " . $target;
//
//		foreach ($keys as $key) {
//			if ($key === '(request-target)') {
//				continue;
//			}
//
//			$estimated .= "\n" . $key . ': ' . $request->getHeader($key);
//		}
//
//		return $estimated;
//	}
//
//
//	/**
//	 * @param $signatureHeader
//	 *
//	 * @return array
//	 */
//	private function parseSignatureHeader($signatureHeader) {
//		$sign = [];
//
//		$entries = explode(',', $signatureHeader);
//		foreach ($entries as $entry) {
//			list($k, $v) = explode('=', $entry, 2);
//			preg_match('/"([^"]+)"/', $v, $varr);
//			$v = trim($varr[0], '"');
//
//			$sign[$k] = $v;
//		}
//
//		return $sign;
//	}


//	/**
//	 * @param $keyId
//	 *
//	 * @return string
//	 * @throws InvalidResourceException
//	 * @throws RequestException
//	 * @throws SocialAppConfigException
//	 * @throws UrlCloudException
//	 * @throws Request410Exception
//	 */
//	private function retrieveKey($keyId): string {
//		$actor = $this->cacheActorService->getFromId($keyId);
//
//		return $actor->getPublicKey();
//	}


	/**
	 * @param ACore $activity
	 */
	private function saveActivity(ACore $activity) {
		// TODO: save activity in DB ?

		if ($activity->gotObject()) {
			$this->saveObject($activity->getObject());
		}
	}


	/**
	 * @param ACore $activity
	 */
	private function saveObject(ACore $activity) {
		try {
			if ($activity->gotObject()) {
				$this->saveObject($activity->getObject());
			}

			$service = AP::$activityPub->getInterfaceForItem($activity);
			$service->save($activity);
		} catch (UnknownItemException $e) {
		}
	}


}

