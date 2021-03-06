<?php


namespace Navari\Instagram;


use Navari\Instagram\Exception\InstagramAgeRestrictedException;
use Navari\Instagram\Exception\InstagramException;
use Navari\Instagram\Exception\InstagramNotFoundException;
use Navari\Instagram\Http\Request;
use Navari\Instagram\Models\Account;
use Navari\Instagram\Models\Activity;
use Navari\Instagram\Models\Comment;
use Navari\Instagram\Models\Highlight;
use Navari\Instagram\Models\Location;
use Navari\Instagram\Models\Media;
use Navari\Instagram\Models\Story;
use Navari\Instagram\Models\Thread;
use Navari\Instagram\Models\UserStories;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;
use stdClass;

class Instagram
{
    const HTTP_NOT_FOUND = 404;
    const HTTP_OK = 200;
    const HTTP_FORBIDDEN = 403;
    const HTTP_BAD_REQUEST = 400;

    const MAX_COMMENTS_PER_REQUEST = 300;
    const MAX_LIKES_PER_REQUEST = 300;
    const PAGING_TIME_LIMIT_SEC = 1800; // 30 mins time limit on operations that require multiple requests
    const PAGING_DELAY_MINIMUM_MICROSEC = 1000000; // 1 sec min delay to simulate browser
    const PAGING_DELAY_MAXIMUM_MICROSEC = 3000000; // 3 sec max delay to simulate browser

    const X_IG_APP_ID = '936619743392459';

    /** @var CacheInterface $instanceCache */
    private static $instanceCache = null;

    public int $pagingTimeLimitSec = self::PAGING_TIME_LIMIT_SEC;
    public int $pagingDelayMinimumMicrosec = self::PAGING_DELAY_MINIMUM_MICROSEC;
    public int $pagingDelayMaximumMicrosec = self::PAGING_DELAY_MAXIMUM_MICROSEC;
    public string $cookies;
    public string $userSession;
    public string $csrfToken;
    private $rhxGis = null;
    private string $userAgent = 'Mozilla/5.0 (Linux; Android 8.1.0; motorola one Build/OPKS28.63-18-3; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/70.0.3538.80 Mobile Safari/537.36 Instagram 72.0.0.21.98 Android (27/8.1.0; 320dpi; 720x1362; motorola; motorola one; deen_sprout; qcom; pt_BR; 132081645';
    private $customCookies = null;

    /**
     * Instagram constructor.
     * @param ClientInterface $client
     */
    public function __construct(ClientInterface $client)
    {
        Request::setHttpClient($client);
    }

    /**
     * @param ClientInterface $client
     */
    public function setHttpClient(ClientInterface $client): void
    {
        Request::setHttpClient($client);
    }

    /**
     * @param ClientInterface $client
     * @param string $userSession
     * @param string $csrfToken
     * @param string $cookies
     * @return Instagram
     */
    public static function withCredentials(ClientInterface $client, string $userSession, string $csrfToken, string $cookies): Instagram
    {
        $instance = new self($client);
        $instance->userSession = $userSession;
        $instance->csrfToken = $csrfToken;
        $instance->cookies = $cookies;
        return $instance;
    }


    /**
     * @param stdClass|string $rawError
     *
     * @return string
     */
    private static function getErrorBody($rawError): string
    {
        if (is_string($rawError)) {
            return $rawError;
        }
        if (is_object($rawError)) {
            $str = '';
            foreach ($rawError as $key => $value) {
                $str .= ' ' . $key . ' => ' . $value . ';';
            }
            return $str;
        } else {
            return 'Unknown body format';
        }

    }

    /**
     * Set how many media objects should be retrieved in a single request
     * @param int $count
     */
    public static function setAccountMediasRequestCount($count)
    {
        Endpoints::setAccountMediasRequestCount($count);
    }

    /**
     * @param string $username
     * @param int $count
     * @return Account[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function searchAccountsByUsername(string $username, int $count = 10): iterable
    {
        $response = Request::get(Endpoints::getGeneralSearchJsonLink($username, $count), $this->generateHeaders());

        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

        if (!isset($jsonResponse['status']) || $jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response code is not equal 200. Something went wrong. Please report issue.');
        }
        if (!isset($jsonResponse['users']) || empty($jsonResponse['users'])) {
            return [];
        }

        $accounts = [];
        foreach ($jsonResponse['users'] as $jsonAccount) {
            $accounts[] = Account::create($jsonAccount['user']);
        }
        return $accounts;
    }

    /**
     * @return Account
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getLoggedUser(): Account
    {
        $response = Request::get(Endpoints::MY_PROFILE,$this->generateHeaders());

        if(static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

        if (!isset($jsonResponse['status']) || $jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response code is not equal 200. Something went wrong. Please report issue.');
        }

        return $this->getAccount($jsonResponse['data']['user']['username']);
    }


    /**
     * @param array $extraHeaders
     * @return array
     */
    private function generateHeaders(array $extraHeaders = []): array
    {
        $headers = [
            'cookie' => $this->cookies,
            'referer' => Endpoints::BASE_URL . '/',
            'x-csrftoken' => $this->csrfToken ?? md5(uniqid()),
        ];
        if($this->getUserAgent())
            $headers['user-agent'] = $this->getUserAgent();
        $headers = array_merge($headers, $extraHeaders);
        return $headers;
    }

    /**
     * @return string
     */
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * @param string $userAgent
     * @return string
     */
    public function setUserAgent(string $userAgent): string
    {
        return $this->userAgent = $userAgent;
    }


    /**
     * @param $rawBody
     * @return mixed
     */
    private function decodeRawBodyToJson($rawBody)
    {
        return json_decode($rawBody, true, 512, JSON_BIGINT_AS_STRING);
    }

    /**
     * @return null
     */
    public function resetUserAgent()
    {
        return $this->userAgent = null;
    }

    /**
     * Gets logged user feed.
     *
     * @return     Media[]
     * @throws     InstagramNotFoundException
     *
     * @throws     InstagramException|\Psr\Http\Client\ClientExceptionInterface
     */
    public function getFeed(): iterable
    {
        $response = Request::get(Endpoints::USER_FEED,
            $this->generateHeaders());

        if ($response->code === static::HTTP_NOT_FOUND) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $this->parseCookies($response->headers);
        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);
        $medias = [];
        $nodes = (array)@$jsonResponse['data']['user']["edge_web_feed_timeline"]['edges'];
        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray['node']);
        }
        return $medias;
    }


    public function getCookies(): string
    {
        return $this->cookies;
    }

    private function parseCookies($headers)
    {
        if($this->cookies){
            return $this->getCookies();
        }
        $rawCookies = isset($headers['Set-Cookie']) ? $headers['Set-Cookie'] : (isset($headers['set-cookie']) ? $headers['set-cookie'] : []);
        if (!is_array($rawCookies)) {
            $rawCookies = [$rawCookies];
        }
        $not_secure_cookies = [];
        $secure_cookies = [];
        foreach ($rawCookies as $cookie) {
            $cookie_array = 'not_secure_cookies';
            $cookie_parts = explode(';', $cookie);
            foreach ($cookie_parts as $cookie_part) {
                if (trim($cookie_part) == 'Secure') {
                    $cookie_array = 'secure_cookies';
                    break;
                }
            }
            $value = array_shift($cookie_parts);
            $parts = explode('=', $value);
            if (sizeof($parts) >= 2 && !is_null($parts[1])) {
                ${$cookie_array}[$parts[0]] = $parts[1];
            }
        }
        $cookies = $secure_cookies + $not_secure_cookies;
        if (isset($cookies['sessionid'])) {
            $this->userSession = $cookies['sessionid'];
        }
        if(isset($cookies['csrftoken'])){
            $this->csrfToken = $cookies['csrftoken'];
        }
        return $cookies;
    }

    /**
     * Gets logged user activity.
     *
     * @return     Activity
     * @throws     InstagramNotFoundException
     *
     * @throws     InstagramException|\Psr\Http\Client\ClientExceptionInterface
     */
    public function getActivity(): Activity
    {
        $response = Request::get(Endpoints::getActivityUrl(),
            $this->generateHeaders());

        if ($response->code === static::HTTP_NOT_FOUND) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $this->parseCookies($response->headers);
        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

        return Activity::create((array)@$jsonResponse['graphql']['user']['activity_feed']);
    }

    /**
     * @param string $username
     * @param int $count
     * @param string $maxId
     *
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getMedias(string $username, int $count = 20, string $maxId = ''): array
    {

        $account = $this->getAccount($username);
        return $this->getMediasByUserId($account->getId(), $count, $maxId);
    }

    /**
     * @param string $username
     *
     * @return Account
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getAccount(string $username): Account
    {
        $response = Request::get(Endpoints::getAccountPageLink($username), $this->generateHeaders());

        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $userArray = self::extractSharedDataFromBody($response->raw_body);

        if ($this->isAccountAgeRestricted($userArray, $response->raw_body)) {
            throw new InstagramAgeRestrictedException('Account with given username is age-restricted.');
        }

        if (!isset($userArray['entry_data']['ProfilePage'][0]['graphql']['user'])) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }
        return Account::create($userArray['entry_data']['ProfilePage'][0]['graphql']['user']);
    }

    public function getAccountInfo($username)
    {
        $response = Request::get(Endpoints::getAccountJsonLink($username), $this->generateHeaders());

        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $userArray = $this->decodeRawBodyToJson($response->raw_body);

        if (!isset($userArray['graphql']['user'])) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        return Account::create($userArray['graphql']['user']);
    }


    private static function extractSharedDataFromBody($body)
    {
        if (preg_match_all('#\_sharedData \= (.*?)\;\<\/script\>#', $body, $out)) {
            return json_decode($out[1][0], true, 512, JSON_BIGINT_AS_STRING);
        }
        return null;
    }

    private function isAccountAgeRestricted($userArray, $body): bool
    {
        if ($userArray === null && strpos($body, '<h2>Restricted profile</h2>') !== false) {
            return true;
        }

        return false;
    }

    /**
     * @param int $id
     * @param int $count
     * @param string $maxId
     *
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getMediasByUserId($id, $count = 12, $maxId = ''): array
    {
        $index = 0;
        $medias = [];
        $isMoreAvailable = true;
        while ($index < $count && $isMoreAvailable) {
            $variables = json_encode([
                'id' => (string)$id,
                'first' => (string)$count,
                'after' => (string)$maxId
            ]);

            $response = Request::get(Endpoints::getAccountMediasJsonLink($variables), $this->generateHeaders($this->userSession, $this->generateGisToken($variables)));

            if (static::HTTP_NOT_FOUND === $response->code) {
                throw new InstagramNotFoundException('Account with given id does not exist.');
            }
            if (static::HTTP_OK !== $response->code) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }

            $arr = $this->decodeRawBodyToJson($response->raw_body);

            if (!is_array($arr)) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }

            $nodes = $arr['data']['user']['edge_owner_to_timeline_media']['edges'];
            // fix - count takes longer/has more overhead
            if (!isset($nodes) || empty($nodes)) {
                return [];
            }
            foreach ($nodes as $mediaArray) {
                if ($index === $count) {
                    return $medias;
                }
                $medias[] = Media::create($mediaArray['node']);
                $index++;
            }
            $maxId = $arr['data']['user']['edge_owner_to_timeline_media']['page_info']['end_cursor'];
            $isMoreAvailable = $arr['data']['user']['edge_owner_to_timeline_media']['page_info']['has_next_page'];
        }
        return $medias;
    }

    /**
     * @param string $username
     * @param int $count
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getMediasFromFeed(string $username, int $count = 20): array
    {
        $medias = [];
        $index = 0;
        $response = Request::get(Endpoints::getAccountJsonLink($username), $this->generateHeaders($this->userSession));
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }
        $userArray = $this->decodeRawBodyToJson($response->raw_body);
        if (!isset($userArray['graphql']['user'])) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }
        $nodes = $userArray['graphql']['user']['edge_owner_to_timeline_media']['edges'];
        if (!isset($nodes) || empty($nodes)) {
            return [];
        }
        foreach ($nodes as $mediaArray) {
            if ($index === $count) {
                return $medias;
            }
            $medias[] = Media::create($mediaArray['node']);
            $index++;
        }
        return $medias;
    }
    /**
     * @param $mediaId
     *
     * @return Media
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getMediaById($mediaId)
    {
        $mediaLink = Media::getLinkFromId($mediaId);
        return $this->getMediaByUrl($mediaLink);
    }

    /**
     * @param string $mediaUrl
     *
     * @return Media
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getMediaByUrl($mediaUrl)
    {
        if (filter_var($mediaUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Malformed media url');
        }
        $response = Request::get(rtrim($mediaUrl, '/') . '/?__a=1', $this->generateHeaders($this->userSession));

        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Media with given code does not exist or account is private.');
        }

        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $mediaArray = $this->decodeRawBodyToJson($response->raw_body);
        if (!isset($mediaArray['graphql']['shortcode_media'])) {
            throw new InstagramException('Media with this code does not exist');
        }
        return Media::create($mediaArray['graphql']['shortcode_media']);
    }

    /**
     * @param string $mediaCode (for example BHaRdodBouH)
     *
     * @return Media
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */

    public function getMediaByCode($mediaCode)
    {
        $url = Endpoints::getMediaPageLink($mediaCode);
        return $this->getMediaByUrl($url);

    }

    /**
     * @param string $username
     * @param string $maxId
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getPaginateMedias($username, $maxId = '')
    {
        $account = $this->getAccount($username);

        return $this->getPaginateMediasByUserId(
            $account->getId(),
            Endpoints::getAccountMediasRequestCount(),
            $maxId
        );
    }

    /**
     * @param int $id
     * @param int $count
     * @param string $maxId
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getPaginateMediasByUserId($id, $count = 12, $maxId = '')
    {
        $index = 0;
        $hasNextPage = true;
        $medias = [];

        $toReturn = [
            'medias' => $medias,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        while ($index < $count && $hasNextPage) {
            $variables = json_encode([
                'id' => (string)$id,
                'first' => (string)$count,
                'after' => (string)$maxId
            ]);

            $response = Request::get(
                Endpoints::getAccountMediasJsonLink($variables),
                $this->generateHeaders($this->userSession, $this->generateGisToken($variables))
            );

            if (static::HTTP_NOT_FOUND === $response->code) {
                throw new InstagramNotFoundException('Account with given id does not exist.');
            }

            if (static::HTTP_OK !== $response->code) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }

            $arr = $this->decodeRawBodyToJson($response->raw_body);

            if (!is_array($arr)) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }

            $nodes = $arr['data']['user']['edge_owner_to_timeline_media']['edges'];

            //if (count($arr['items']) === 0) {
            // I generally use empty. Im not sure why people would use count really - If the array is large then count takes longer/has more overhead.
            // If you simply need to know whether or not the array is empty then use empty.
            if (empty($nodes)) {
                return $toReturn;
            }

            foreach ($nodes as $mediaArray) {
                if ($index === $count) {
                    return $medias;
                }
                $medias[] = Media::create($mediaArray['node']);
                $index++;
            }

            $maxId = $arr['data']['user']['edge_owner_to_timeline_media']['page_info']['end_cursor'];
            $hasNextPage = $arr['data']['user']['edge_owner_to_timeline_media']['page_info']['has_next_page'];
        }

        $toReturn = [
            'medias' => $medias,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        return $toReturn;
    }

    /**
     * @param $mediaId
     * @param int $count
     * @param null $maxId
     *
     * @return Comment[]
     * @throws InstagramException
     */
    public function getMediaCommentsById($mediaId, $count = 10, $maxId = null)
    {
        $code = Media::getCodeFromId($mediaId);
        return static::getMediaCommentsByCode($code, $count, $maxId);
    }

    /**
     * @param      $code
     * @param int $count
     * @param null $maxId
     *
     * @return Comment[]
     * @throws InstagramException
     */
    public function getMediaCommentsByCode($code, $count = 10, $maxId = null)
    {
        $comments = [];
        $index = 0;
        $hasPrevious = true;
        while ($hasPrevious && $index < $count) {
            if ($count - $index > static::MAX_COMMENTS_PER_REQUEST) {
                $numberOfCommentsToRetrieve = static::MAX_COMMENTS_PER_REQUEST;
            } else {
                $numberOfCommentsToRetrieve = $count - $index;
            }

            $variables = json_encode([
                'shortcode' => (string)$code,
                'first' => (string)$numberOfCommentsToRetrieve,
                'after' => (string)$maxId
            ]);

            $commentsUrl = Endpoints::getCommentsBeforeCommentIdByCode($variables);
            $response = Request::get($commentsUrl, $this->generateHeaders($this->userSession, $this->generateGisToken($variables)));

            if (static::HTTP_OK !== $response->code) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }
            $this->parseCookies($response->headers);
            $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

            if (
                !isset($jsonResponse['data']['shortcode_media']['edge_media_to_comment']['edges'])
                || !isset($jsonResponse['data']['shortcode_media']['edge_media_to_comment']['count'])
                || !isset($jsonResponse['data']['shortcode_media']['edge_media_to_comment']['page_info']['has_next_page'])
                || !array_key_exists('end_cursor', $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['page_info'])
            ) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }

            $nodes = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['edges'];
            $hasPrevious = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['page_info']['has_next_page'];
            $numberOfComments = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['count'];
            $maxId = $jsonResponse['data']['shortcode_media']['edge_media_to_comment']['page_info']['end_cursor'];

            if (empty($nodes) && $numberOfComments === 0) {
                break;
            }

            foreach ($nodes as $commentArray) {
                $comments[] = Comment::create($commentArray['node']);
                $index++;
            }

            if ($count > $numberOfComments) {
                $count = $numberOfComments;
            }
        }
        return $comments;
    }

    /**
     * @param      $code
     * @param int $count
     * @param null $maxId
     *
     * @return array
     * @throws InstagramException
     */
    public function getMediaLikesByCode($code, $count = 10, $maxId = null)
    {
        $remain = $count;
        $likes = [];
        $index = 0;
        $hasPrevious = true;
        while ($hasPrevious && $index < $count) {
            if ($remain > self::MAX_LIKES_PER_REQUEST) {
                $numberOfLikesToRetreive = self::MAX_LIKES_PER_REQUEST;
                $remain -= self::MAX_LIKES_PER_REQUEST;
                $index += self::MAX_LIKES_PER_REQUEST;
            } else {
                $numberOfLikesToRetreive = $remain;
                $index += $remain;
                $remain = 0;
            }
            if (!isset($maxId)) {
                $maxId = '';

            }
            $commentsUrl = Endpoints::getLastLikesByCode($code, $numberOfLikesToRetreive, $maxId);
            $response = Request::get($commentsUrl, $this->generateHeaders($this->userSession));
            if ($response->code !== static::HTTP_OK) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . $response->body . ' Something went wrong. Please report issue.', $response->code);
            }
            $this->parseCookies($response->headers);

            $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

            $nodes = $jsonResponse['data']['shortcode_media']['edge_liked_by']['edges'];
            if (empty($nodes)) {
                return [];
            }

            foreach ($nodes as $likesArray) {
                $likes[] = Like::create($likesArray['node']);
            }

            $hasPrevious = $jsonResponse['data']['shortcode_media']['edge_liked_by']['page_info']['has_next_page'];
            $numberOfLikes = $jsonResponse['data']['shortcode_media']['edge_liked_by']['count'];
            if ($count > $numberOfLikes) {
                $count = $numberOfLikes;
            }
            if (sizeof($nodes) == 0) {
                return $likes;
            }
            $maxId = $jsonResponse['data']['shortcode_media']['edge_liked_by']['page_info']['end_cursor'];
        }

        return $likes;
    }

    /**
     * @param string $id
     *
     * @return Account
     * @throws InstagramException
     * @throws InvalidArgumentException
     * @throws InstagramNotFoundException|\Psr\Http\Client\ClientExceptionInterface
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getAccountById($id): Account
    {
        $username = $this->getUsernameById($id);
        return $this->getAccount($username);
    }

    /**
     * @param string $id
     * @return string
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getUsernameById($id)
    {
        $privateInfo = $this->getAccountPrivateInfo($id);
        return $privateInfo['username'];
    }

    /**
     * @param string $id
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getAccountPrivateInfo($id)
    {
        $response = Request::get(Endpoints::getAccountJsonPrivateInfoLinkByAccountId($id), $this->generateHeaders($this->userSession));
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException('Failed to fetch account with given id');
        }

        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        if (!($responseArray = json_decode($response->raw_body, true))) {
            throw new InstagramException('Response does not JSON');
        }

        if ($responseArray['data']['user'] === null){
            throw new InstagramNotFoundException('Failed to fetch account with given id');
        }

        if ($responseArray['status'] !== 'ok') {
            throw new InstagramException((isset($responseArray['message']) ? $responseArray['message'] : 'Unknown Error'));
        }

        return $responseArray['data']['user']['reel']['user'];
    }

    /**
     * @param string $tag
     * @param int $count
     * @param string $maxId
     * @param string $minTimestamp
     *
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getMediasByTag($tag, $count = 12, $maxId = '', $minTimestamp = null)
    {
        $index = 0;
        $medias = [];
        $mediaIds = [];
        $hasNextPage = true;
        while ($index < $count && $hasNextPage) {
            $response = Request::get(Endpoints::getMediasJsonByTagLink($tag, $maxId),
                $this->generateHeaders($this->userSession));
            if ($response->code === static::HTTP_NOT_FOUND) {
                throw new InstagramNotFoundException('This tag does not exists or it has been hidden by Instagram');
            }
            if ($response->code !== static::HTTP_OK) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }

            $this->parseCookies($response->headers);

            $arr = $this->decodeRawBodyToJson($response->raw_body);

            if (!is_array($arr)) {
                throw new InstagramException('Response decoding failed. Returned data corrupted or this library outdated. Please report issue');
            }
            if (empty($arr['graphql']['hashtag']['edge_hashtag_to_media']['count'])) {
                return [];
            }

            $nodes = $arr['graphql']['hashtag']['edge_hashtag_to_media']['edges'];
            foreach ($nodes as $mediaArray) {
                if ($index === $count) {
                    return $medias;
                }
                $media = Media::create($mediaArray['node']);
                if (in_array($media->getId(), $mediaIds)) {
                    return $medias;
                }
                if (isset($minTimestamp) && $media->getCreatedTime() < $minTimestamp) {
                    return $medias;
                }
                $mediaIds[] = $media->getId();
                $medias[] = $media;
                $index++;
            }
            if (empty($nodes)) {
                return $medias;
            }
            $maxId = $arr['graphql']['hashtag']['edge_hashtag_to_media']['page_info']['end_cursor'];
            $hasNextPage = $arr['graphql']['hashtag']['edge_hashtag_to_media']['page_info']['has_next_page'];
        }
        return $medias;
    }

    /**
     * @param string $tag
     * @param string $maxId
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getPaginateMediasByTag($tag, $maxId = '')
    {
        $hasNextPage = false;
        $medias = [];

        $toReturn = [
            'medias' => $medias,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        $response = Request::get(Endpoints::getMediasJsonByTagLink($tag, $maxId),
            $this->generateHeaders($this->userSession));

        if ($response->code === static::HTTP_NOT_FOUND) {
            throw new InstagramNotFoundException('This tag does not exists or it has been hidden by Instagram');
        }

        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $this->parseCookies($response->headers);

        $arr = $this->decodeRawBodyToJson($response->raw_body);

        if (!is_array($arr)) {
            throw new InstagramException('Response decoding failed. Returned data corrupted or this library outdated. Please report issue');
        }

        if (empty($arr['graphql']['hashtag']['edge_hashtag_to_media']['count'])) {
            return $toReturn;
        }

        $nodes = $arr['graphql']['hashtag']['edge_hashtag_to_media']['edges'];

        if (empty($nodes)) {
            return $toReturn;
        }

        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray['node']);
        }

        $maxId = $arr['graphql']['hashtag']['edge_hashtag_to_media']['page_info']['end_cursor'];
        $hasNextPage = $arr['graphql']['hashtag']['edge_hashtag_to_media']['page_info']['has_next_page'];
        $count = $arr['graphql']['hashtag']['edge_hashtag_to_media']['count'];

        $toReturn = [
            'medias' => $medias,
            'count' => $count,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        return $toReturn;
    }

    /**
     * @param string $facebookLocationId
     * @param string $maxId
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getPaginateMediasByLocationId($facebookLocationId, $maxId = '')
    {
        $hasNextPage = true;
        $medias = [];

        $toReturn = [
            'medias' => $medias,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        $response = Request::get(Endpoints::getMediasJsonByLocationIdLink($facebookLocationId, $maxId),
            $this->generateHeaders($this->userSession));

        if ($response->code === static::HTTP_NOT_FOUND) {
            throw new InstagramNotFoundException('Location with this id doesn\'t exist');
        }

        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $this->parseCookies($response->headers);

        $arr = $this->decodeRawBodyToJson($response->raw_body);

        if (!is_array($arr)) {
            throw new InstagramException('Response decoding failed. Returned data corrupted or this library outdated. Please report issue');
        }

        if (empty($arr['graphql']['location']['edge_location_to_media']['count'])) {
            return $toReturn;
        }

        $nodes = $arr['graphql']['location']['edge_location_to_media']['edges'];

        if (empty($nodes)) {
            return $toReturn;
        }

        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray['node']);
        }

        $maxId = $arr['graphql']['location']['edge_location_to_media']['page_info']['end_cursor'];
        $hasNextPage = $arr['graphql']['location']['edge_location_to_media']['page_info']['has_next_page'];
        $count = $arr['graphql']['location']['edge_location_to_media']['count'];

        $toReturn = [
            'medias' => $medias,
            'count' => $count,
            'maxId' => $maxId,
            'hasNextPage' => $hasNextPage,
        ];

        return $toReturn;
    }

    /**
     * @param $tagName
     *
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getCurrentTopMediasByTagName($tagName)
    {
        $response = Request::get(Endpoints::getMediasJsonByTagLink($tagName, ''),
            $this->generateHeaders($this->userSession));

        if ($response->code === static::HTTP_NOT_FOUND) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }

        $this->parseCookies($response->headers);
        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);
        $medias = [];
        $nodes = (array)@$jsonResponse['graphql']['hashtag']['edge_hashtag_to_top_posts']['edges'];
        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray['node']);
        }
        return $medias;
    }

    /**
     * @param $facebookLocationId
     *
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getCurrentTopMediasByLocationId($facebookLocationId)
    {
        $response = Request::get(Endpoints::getMediasJsonByLocationIdLink($facebookLocationId),
            $this->generateHeaders($this->userSession));
        if ($response->code === static::HTTP_NOT_FOUND) {
            throw new InstagramNotFoundException('Location with this id doesn\'t exist');
        }
        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }
        $this->parseCookies($response->headers);
        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);
        $nodes = $jsonResponse['location']['top_posts']['nodes'];
        $medias = [];
        foreach ($nodes as $mediaArray) {
            $medias[] = Media::create($mediaArray);
        }
        return $medias;
    }

    /**
     * @param string $facebookLocationId
     * @param int $quantity
     * @param string $offset
     *
     * @return Media[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getMediasByLocationId(string $facebookLocationId, $quantity = 24, $offset = ''): array
    {
        $index = 0;
        $medias = [];
        $hasNext = true;
        while ($index < $quantity && $hasNext) {
            $response = Request::get(Endpoints::getMediasJsonByLocationIdLink($facebookLocationId, $offset),
                $this->generateHeaders($this->userSession));
            if ($response->code === static::HTTP_NOT_FOUND) {
                throw new InstagramNotFoundException('Location with this id doesn\'t exist');
            }
            if ($response->code !== static::HTTP_OK) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }
            $this->parseCookies($response->headers);
            $arr = $this->decodeRawBodyToJson($response->raw_body);
            $nodes = $arr['graphql']['location']['edge_location_to_media']['edges'];
            foreach ($nodes as $mediaArray) {
                if ($index === $quantity) {
                    return $medias;
                }
                $medias[] = Media::create($mediaArray['node']);
                $index++;
            }
            if (empty($nodes)) {
                return $medias;
            }
            $hasNext = $arr['graphql']['location']['edge_location_to_media']['page_info']['has_next_page'];
            $offset = $arr['graphql']['location']['edge_location_to_media']['page_info']['end_cursor'];
        }
        return $medias;
    }

    /**
     * @param string $facebookLocationId
     *
     * @return Location
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getLocationById(string $facebookLocationId): Location
    {
        $response = Request::get(Endpoints::getMediasJsonByLocationIdLink($facebookLocationId),
            $this->generateHeaders($this->userSession));

        if ($response->code === static::HTTP_NOT_FOUND) {
            throw new InstagramNotFoundException('Location with this id doesn\'t exist');
        }
        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $this->parseCookies($response->headers);
        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);
        return Location::create($jsonResponse['graphql']['location']);
    }

    /**
     * @param string $accountId Account id of the profile to query
     * @param int $count Total followers to retrieve
     * @param int $pageSize Internal page size for pagination
     * @param bool $delayed Use random delay between requests to mimic browser behaviour
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getFollowers($accountId, $count = 20, $pageSize = 20, $delayed = true): array
    {
        $result = $this->getPaginateFollowers($accountId, $count, $pageSize, $delayed, '');
        return $result['accounts'] ?? [];
    }

    /**
     * @param string $accountId Account id of the profile to query
     * @param int $count Total followers to retrieve
     * @param int $pageSize Internal page size for pagination
     * @param bool $delayed Use random delay between requests to mimic browser behaviour
     * @param string $nextPage Use to paginate results (ontop of internal pagination)
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getPaginateFollowers(string $accountId, $count = 20, $pageSize = 20, $delayed = true, $nextPage = ''): array
    {
        if ($delayed) {
            set_time_limit($this->pagingTimeLimitSec);
        }
        $index = 0;
        $accounts = [];
        $endCursor = $nextPage;
        $lastPagingInfo = [];
        if ($count < $pageSize) {
            throw new InstagramException('Count must be greater than or equal to page size.');
        }
        while (true) {
            $response = Request::get(Endpoints::getFollowersJsonLink($accountId, $pageSize, $endCursor),
                $this->generateHeaders($this->userSession));
            if ($response->code === static::HTTP_NOT_FOUND) {
                throw new InstagramNotFoundException('Account with this id doesn\'t exist');
            }
            if ($response->code !== static::HTTP_OK) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }
            $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);
            if ($jsonResponse['data']['user']['edge_followed_by']['count'] === 0) {
                return $accounts;
            }
            $edgesArray = $jsonResponse['data']['user']['edge_followed_by']['edges'];
            if (count($edgesArray) === 0) {
                throw new InstagramException('Failed to get followers of account id ' . $accountId . '. The account is private.', static::HTTP_FORBIDDEN);
            }
            $pageInfo = $jsonResponse['data']['user']['edge_followed_by']['page_info'];
            $lastPagingInfo = $pageInfo;
            if ($pageInfo['has_next_page']) {
                $endCursor = $pageInfo['end_cursor'];
                $hasNextPage = true;
            } else {
                $hasNextPage = false;
            }
            foreach ($edgesArray as $edge) {
                $accounts[] = $edge['node'];
                $index++;
                if ($index >= $count) {
                    break 2;
                }
            }
            if (!$hasNextPage) {
                break;
            }
            if ($delayed) {
                // Random wait between 1 and 3 sec to mimic browser
                $microsec = rand($this->pagingDelayMinimumMicrosec, $this->pagingDelayMaximumMicrosec);
                usleep($microsec);
            }
        }
        $toReturn = [
            'hasNextPage' => $lastPagingInfo['has_next_page'],
            'nextPage' => $lastPagingInfo['end_cursor'],
            'accounts' => $accounts
        ];
        return $toReturn;
    }

    /**
     * @param $accountId
     * @param int $pageSize
     * @param string $nextPage
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getPaginateAllFollowers($accountId, $pageSize = 20, $nextPage = ''): array
    {
        $response = Request::get(Endpoints::getFollowersJsonLink($accountId, $pageSize, $nextPage),
            $this->generateHeaders($this->userSession));
        if ($response->code === static::HTTP_NOT_FOUND) {
            throw new InstagramNotFoundException('Account with this id doesn\'t exist');
        }
        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }
        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);
        $count = $jsonResponse['data']['user']['edge_followed_by']['count'];
        if ($count === 0) {
            return [];
        }
        $edgesArray = $jsonResponse['data']['user']['edge_followed_by']['edges'];
        if (count($edgesArray) === 0) {
            throw new InstagramException('Failed to get followers of account id ' . $accountId . '. The account is private.', static::HTTP_FORBIDDEN);
        }
        $accounts = [];
        foreach ($edgesArray as $edge) {
            $accounts[] = $edge['node'];
        }
        $pageInfo = $jsonResponse['data']['user']['edge_followed_by']['page_info'];
        return [
            'count' => $count,
            'hasNextPage' => $pageInfo['has_next_page'],
            'nextPage' => $pageInfo['end_cursor'],
            'accounts' => $accounts
        ];
    }

    /**
     * @param string $accountId Account id of the profile to query
     * @param int $count Total followed accounts to retrieve
     * @param int $pageSize Internal page size for pagination
     * @param bool $delayed Use random delay between requests to mimic browser behaviour
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     */
    public function getFollowing(string $accountId, $count = 20, $pageSize = 20, $delayed = true): array
    {
        $res = $this->getPaginateFollowing($accountId, $count, $pageSize, $delayed, '');
        return $res;
    }

    /**
     * @param string $accountId Account id of the profile to query
     * @param int $count Total followed accounts to retrieve
     * @param int $pageSize Internal page size for pagination
     * @param bool $delayed Use random delay between requests to mimic browser behaviour
     * @param string $nextPage Use to paginate results (on top of internal pagination)
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getPaginateFollowing(string $accountId, $count = 20, $pageSize = 20, $delayed = true, $nextPage = ''): array
    {
        if ($delayed) {
            set_time_limit($this->pagingTimeLimitSec);
        }
        $index = 0;
        $accounts = [];
        $endCursor = $nextPage;
        $lastPagingInfo = [];
        if ($count < $pageSize) {
            throw new InstagramException('Count must be greater than or equal to page size.');
        }
        while (true) {
            $response = Request::get(Endpoints::getFollowingJsonLink($accountId, $pageSize, $endCursor),
                $this->generateHeaders());
            if ($response->code === static::HTTP_NOT_FOUND) {
                throw new InstagramNotFoundException('Account with this id doesn\'t exist');
            }
            if ($response->code !== static::HTTP_OK) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }
            $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);
            if ($jsonResponse['data']['user']['edge_follow']['count'] === 0) {
                return $accounts;
            }
            $edgesArray = $jsonResponse['data']['user']['edge_follow']['edges'];
            if (count($edgesArray) === 0) {
                throw new InstagramException('Failed to get followers of account id ' . $accountId . '. The account is private.', static::HTTP_FORBIDDEN);
            }
            $pageInfo = $jsonResponse['data']['user']['edge_follow']['page_info'];
            $lastPagingInfo = $pageInfo;
            if ($pageInfo['has_next_page']) {
                $endCursor = $pageInfo['end_cursor'];
                $hasNextPage = true;
            } else {
                $hasNextPage = false;
            }
            foreach ($edgesArray as $edge) {
                $accounts[] = $edge['node'];
                $index++;
                if ($index >= $count) {
                    break 2;
                }
            }
            if (!$hasNextPage) {
                break;
            }
            if ($delayed) {
                $microsec = rand($this->pagingDelayMinimumMicrosec, $this->pagingDelayMaximumMicrosec);
                usleep($microsec);
            }
        }
        $toReturn = [
            'hasNextPage' => $lastPagingInfo['has_next_page'],
            'nextPage' => $lastPagingInfo['end_cursor'],
            'accounts' => $accounts
        ];
        return $toReturn;
    }

    /**
     * @param $accountId
     * @param int $pageSize
     * @param string $nextPage
     *
     * @return array
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getPaginateAllFollowing($accountId, $pageSize = 20, $nextPage = ''): array
    {
        $response = Request::get(Endpoints::getFollowingJsonLink($accountId, $pageSize, $nextPage),
            $this->generateHeaders());
        if ($response->code === static::HTTP_NOT_FOUND) {
            throw new InstagramNotFoundException('Account with this id doesn\'t exist');
        }
        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

        $count = $jsonResponse['data']['user']['edge_follow']['count'];
        if ($count === 0) {
            return [];
        }

        $edgesArray = $jsonResponse['data']['user']['edge_follow']['edges'];
        if (count($edgesArray) === 0) {
            throw new InstagramException('Failed to get following of account id ' . $accountId . '. The account is private.', static::HTTP_FORBIDDEN);
        }

        $accounts = [];
        foreach ($edgesArray as $edge) {
            $accounts[] = $edge['node'];
        }

        $pageInfo = $jsonResponse['data']['user']['edge_follow']['page_info'];

        return [
            'count' => $count,
            'hasNextPage' => $pageInfo['has_next_page'],
            'nextPage' => $pageInfo['end_cursor'],
            'accounts' => $accounts
        ];
    }

    /**
     * @param array $reel_ids - array of instagram user ids
     * @return array
     * @throws InstagramException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getStories($reel_ids = null): array
    {
        $variables = ['precomposed_overlay' => false, 'reel_ids' => []];
        if (empty($reel_ids)) {
            $response = Request::get(Endpoints::getUserStoriesLink(),
                $this->generateHeaders());
            print_r($response);
            die();
            if ($response->code !== static::HTTP_OK) {
                throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
            }

            $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

            if (empty($jsonResponse['data']['user']['feed_reels_tray']['edge_reels_tray_to_reel']['edges'])) {
                return [];
            }

            foreach ($jsonResponse['data']['user']['feed_reels_tray']['edge_reels_tray_to_reel']['edges'] as $edge) {
                $variables['reel_ids'][] = $edge['node']['id'];
            }
        } else {
            $variables['reel_ids'] = $reel_ids;
        }

        $response = Request::get(Endpoints::getStoriesLink($variables),
            $this->generateHeaders());

        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

        if (empty($jsonResponse['data']['reels_media'])) {
            return [];
        }

        $stories = [];
        foreach ($jsonResponse['data']['reels_media'] as $user) {
            $UserStories = UserStories::create();
            $UserStories->setOwner(Account::create($user['user']));
            foreach ($user['items'] as $item) {
                $UserStories->addStory(Story::create($item));
            }
            $stories[] = $UserStories;
        }
        return $stories;
    }

    /**
     * @return string
     */
    private function getCacheKey(): string
    {
        return md5($this->userSession);
    }

    /**
     * @param $session
     *
     * @return bool
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function isLoggedIn($session): bool
    {
        if ($session === null || !isset($session['sessionid'])) {
            return false;
        }
        $sessionId = $session['sessionid'];
        $csrfToken = $session['csrftoken'];
        $headers = [
            'cookie' => "ig_cb=1; csrftoken=$csrfToken; sessionid=$sessionId;",
            'referer' => Endpoints::BASE_URL . '/',
            'x-csrftoken' => $csrfToken,
            'X-CSRFToken' => $csrfToken,
            'user-agent' => $this->getUserAgent(),
        ];
        $response = Request::get(Endpoints::BASE_URL, $headers);
        if ($response->code !== static::HTTP_OK) {
            return false;
        }
        $cookies = $this->parseCookies($response->headers);
        if (!isset($cookies['ds_user_id'])) {
            return false;
        }
        return true;
    }

    /**
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function saveSession()
    {
        static::$instanceCache->set($this->getCacheKey(), $this->userSession);
    }

    /**
     * @param int|string|Media $mediaId
     *
     * @return void
     * @throws InstagramException|\Psr\Http\Client\ClientExceptionInterface
     */
    public function like($mediaId): void
    {
        $mediaId = $mediaId instanceof Media ? $mediaId->getId() : $mediaId;
        $response = Request::post(Endpoints::getLikeUrl($mediaId), $this->generateHeaders(['x-requested-with' => 'XMLHttpRequest', 'x-instagram-ajax' => '2102073a1373']));

        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

        if ($jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response status is ' . $jsonResponse['status'] . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }
    }

    /**
     * @param int|string|Media $mediaId
     *
     * @return void
     * @throws InstagramException|\Psr\Http\Client\ClientExceptionInterface
     */
    public function unlike($mediaId): void
    {
        $mediaId = $mediaId instanceof Media ? $mediaId->getId() : $mediaId;
        $response = Request::post(Endpoints::getUnlikeUrl($mediaId), $this->generateHeaders());

        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

        if ($jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response status is ' . $jsonResponse['status'] . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }
    }

    /**
     * @param int|string|Media $mediaId
     * @param int|string $text
     * @param int|string|Comment|null $repliedToCommentId
     *
     * @return Comment
     * @throws InstagramException|\Psr\Http\Client\ClientExceptionInterface
     */
    public function addComment($mediaId, $text, $repliedToCommentId = null): Comment
    {
        $mediaId = $mediaId instanceof Media ? $mediaId->getId() : $mediaId;
        $repliedToCommentId = $repliedToCommentId instanceof Comment ? $repliedToCommentId->getId() : $repliedToCommentId;

        $body = ['comment_text' => $text, 'replied_to_comment_id' => $repliedToCommentId];
        $response = Request::post(Endpoints::getAddCommentUrl($mediaId), $this->generateHeaders(), $body);

        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

        if ($jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response status is ' . $jsonResponse['status'] . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        return Comment::create($jsonResponse);
    }

    /**
     * @param string|Media $mediaId
     * @param int|string|Comment $commentId
     * @return void
     * @throws InstagramException|\Psr\Http\Client\ClientExceptionInterface
     */
    public function deleteComment($mediaId, $commentId): void
    {
        $mediaId = $mediaId instanceof Media ? $mediaId->getId() : $mediaId;
        $commentId = $commentId instanceof Comment ? $commentId->getId() : $commentId;
        $response = Request::post(Endpoints::getDeleteCommentUrl($mediaId, $commentId), $this->generateHeaders());

        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

        if ($jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response status is ' . $jsonResponse['status'] . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }
    }
    /**
     * @param string $url
     * @return mixed|null
     * @throws InstagramException
     * @throws InstagramNotFoundException|\Psr\Http\Client\ClientExceptionInterface
     */
    private function getSharedDataFromPage($url = Endpoints::BASE_URL)
    {
        $response = Request::get(rtrim($url, '/') . '/', $this->generateHeaders());
        if (static::HTTP_NOT_FOUND === $response->code) {
            throw new InstagramNotFoundException("Page {$url} not found");
        }

        if (static::HTTP_OK !== $response->code) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        return self::extractSharedDataFromBody($response->raw_body);
    }

    /**
     * @param string $userId
     *
     * @return Highlight[]
     * @throws InstagramException
     * @throws InstagramNotFoundException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getHighlights(string $userId): array
    {
        $response = Request::get(Endpoints::getHighlightUrl($userId),
            $this->generateHeaders());

        if ($response->code === static::HTTP_NOT_FOUND) {
            throw new InstagramNotFoundException('Account with given username does not exist.');
        }
        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.', $response->code);
        }

        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);

        if (!isset($jsonResponse['status']) || $jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response code is not equal 200. Something went wrong. Please report issue.');
        }

        if (empty($jsonResponse['data']['user']['edge_highlight_reels']['edges'])) {
            return [];
        }

        $highlights = [];
        foreach ($jsonResponse['data']['user']['edge_highlight_reels']['edges'] as $highlight_reel) {
            $highlights[] = Highlight::create($highlight_reel['node']);
        }
        return $highlights;
    }

    /**
     * @param int $limit
     * @param int $messageLimit
     * @param string|null $cursor
     *
     * @return array
     * @throws InstagramException|\Psr\Http\Client\ClientExceptionInterface
     */
    public function getPaginateThreads(int $limit = 10, int $messageLimit = 10, int $cursor = null): array
    {
        $response = Request::get(
            Endpoints::getThreadsUrl($limit, $messageLimit, $cursor),
            array_merge(
                ['x-ig-app-id' => self::X_IG_APP_ID],
                $this->generateHeaders()
            )
        );
        if ($response->code !== static::HTTP_OK) {
            throw new InstagramException('Response code is ' . $response->code . '. Body: ' . static::getErrorBody($response->body) . ' Something went wrong. Please report issue.');
        }
        $jsonResponse = $this->decodeRawBodyToJson($response->raw_body);
        if (!isset($jsonResponse['status']) || $jsonResponse['status'] !== 'ok') {
            throw new InstagramException('Response code is not equal 200. Something went wrong. Please report issue.');
        }
        if (!isset($jsonResponse['inbox']['threads']) || empty($jsonResponse['inbox']['threads'])) {
            return [];
        }
        $threads = [];
        foreach ($jsonResponse['inbox']['threads'] as $jsonThread) {
            $threads[] = Thread::create($jsonThread);
        }
        return [
            'hasOlder' => (bool) $jsonResponse['inbox']['has_older'],
            'oldestCursor' => isset($jsonResponse['inbox']['oldest_cursor']) ? $jsonResponse['inbox']['oldest_cursor'] : null,
            'threads' => $threads,
        ];
    }

    /**
     * @param int $count
     * @param int $limit
     * @param int $messageLimit
     *
     * @return array
     * @throws InstagramException
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getThreads($count = 10, $limit = 10, $messageLimit = 10): array
    {
        $threads = [];
        $cursor = null;
        while (count($threads) < $count) {
            $result = $this->getPaginateThreads($limit, $messageLimit, $cursor);
            $threads = array_merge($threads, $result['threads']);
            if (!$result['hasOlder'] || !$result['oldestCursor']) {
                break;
            }
            $cursor = $result['oldestCursor'];
        }
        return $threads;
    }


}