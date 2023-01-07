<?php

namespace TelegramRSS;

use Swoole\Coroutine;

class Client
{
    private const RETRY = 5;
    private const RETRY_INTERVAL = 3;
    private const TIMEOUT = 30;
    public const MESSAGE_CLIENT_UNAVAILABLE = 'Telegram client connection error';

    /**
     * Client constructor.
     *
     * @param string $address
     * @param int $port
     */
    public function __construct(string $address = '', int $port = 0)
    {
        $this->config = Config::getInstance()->get('client');
        $this->config = [
            'address' => $address ?: $this->config['address'],
            'port' => $port ?: $this->config['port'],
        ];
    }

    public function getHistoryHtml(array $data)
    {
        $data = array_merge(
            [
                'peer' => '',
                'limit' => 10,
            ],
            $data
        );
        return $this->get('getHistoryHtml', ['data' => $data]);
    }

    public function getMedia(array $data, array $headers)
    {
        $data = array_merge(
            [
                'peer' => '',
                'id' => [0],
                'size_limit' => Config::getInstance()->get('media.max_size'),
            ],
            $data
        );

        return $this->get('getMedia', ['data' => $data], $headers, 'media');
    }

    public function getMediaPreview(array $data, array $headers)
    {
        $data = array_merge(
            [
                'peer' => '',
                'id' => [0],
            ],
            $data
        );

        return $this->get('getMediaPreview', ['data' => $data], $headers,'media');
    }

    public function getMediaInfo(object $message)
    {
        return $this->get('getDownloadInfo', ['message' => $message]);
    }

    public function getInfo(string $peer)
    {
        return $this->get('getInfo', $peer);
    }

    public function search(string $username): ?\stdClass
    {
        $username = ltrim( $username, '@');
        $peers = $this->get('contacts.search', ['data' => [
            'q' => "@{$username}",
            'limit' => 1,
        ]]);

        foreach (array_merge($peers->chats, $peers->users) as $peer) {
            if (strtolower($peer->username ?? '') === strtolower($username)) {
                return $peer;
            }
        }
        return null;
    }

    public function getId($chat) {
        return $this->get('getId', [$chat]);
    }

    public function getSponsoredMessages($peer) {
        $messages = (array) $this->get('getSponsoredMessages', $peer);
        foreach ($messages as $message) {
            $id = $this->getId($message->from_id);
            $message->peer = $this->getInfo($id);
        }
        return $messages;
    }

    public function viewSponsoredMessage($peer, $message) {
        return $this->get('viewSponsoredMessage', ['peer' => $peer, 'message' => $message]);
    }

    /**
     * @param string $method
     * @param mixed $parameters
     * @param array $headers
     * @param string $responseType
     * @param int $retry
     *
     * @return object
     * @throws \Exception
     */
    private function get(string $method, $parameters = [], array $headers = [], string $responseType = 'json', $retry = 0)
    {
        unset(
            $headers['host'],
            $headers['remote_addr'],
            $headers['x-forwarded-for'],
            $headers['connection'],
            $headers['cache-control'],
            $headers['upgrade-insecure-requests'],
            $headers['accept-encoding'],
        );
        if ($retry) {
            //Делаем попытку реконекта
            echo 'Client crashed and restarting. Resending request.' . PHP_EOL;
            Log::getInstance()->add('Client crashed and restarting. Resending request.');
            Coroutine::sleep(static::RETRY_INTERVAL);
        }

        $curl = new Coroutine\Http\Client($this->config['address'], $this->config['port'], false);
        $curl->setHeaders(array_merge(['content-type' => 'application/json'], $headers));
        $curl->post("/api/$method", json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
        $curl->recv(static::TIMEOUT);
        $curl->close();

        $body = '';
        $errorMessage = '';

        if ($curl->statusCode === 302 && !empty($curl->headers['location'])) {
            $responseType = 'redirect';
        } elseif (!empty($curl->headers['content-type']) && strpos($curl->headers['content-type'], 'json') !== false) {
            $responseType = 'json';
        }

        unset(
            $curl->headers['content-encoding'],
            $curl->headers['connection'],
            $curl->headers['keep-alive'],
            $curl->headers['transfer-encoding'],
        );

        switch ($responseType) {
            case 'json':
                $body = json_decode($curl->body, false);
                $errorMessage = $body->errors[0]->message ?? '';
                break;
            case 'media':
                if (
                    in_array($curl->statusCode, [200,206], true) &&
                    !empty($curl->body) &&
                    !empty($curl->headers['content-type'])
                ) {
                    $body = (object)[
                        'response' => [
                            'file' => $curl->body,
                            'headers' => $curl->headers,
                            'code' => $curl->statusCode,
                        ],
                    ];
                }
                break;
            case 'redirect':
                $body = (object)[
                    'response' => [
                        'headers' => [
                            'Location' => $curl->headers['location'],
                        ],
                    ],
                ];
                break;
        }

        if (!in_array($curl->statusCode, [200,206,302], true) || $curl->errCode || $errorMessage) {
            if (!$errorMessage && $retry < static::RETRY) {
                return $this->get($method, $parameters, $headers, $responseType, ++$retry);
            }
            if ($errorMessage) {
                throw new \UnexpectedValueException($errorMessage, $body->errors[0]->code ?? 400);
            }
            throw new \UnexpectedValueException(static::MESSAGE_CLIENT_UNAVAILABLE, $curl->statusCode);
        }

        if (!property_exists($body, 'response')) {
            throw new \UnexpectedValueException(static::MESSAGE_CLIENT_UNAVAILABLE, $curl->statusCode);
        }
        return $body->response;

    }
}
