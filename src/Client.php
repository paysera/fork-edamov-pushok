<?php

/*
 * This file is part of the Pushok package.
 *
 * (c) Arthur Edamov <edamov@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pushok;

use Psr\Log\LoggerInterface;

/**
 * Class Client
 * @package Pushok
 */
class Client
{
    /**
     * Authentication provider.
     *
     * @var AuthProviderInterface
     */
    private $authProvider;

    /**
     * Production or sandbox environment.
     *
     * @var bool
     */
    private $isProductionEnv;

    /**
     * Number of concurrent requests to multiplex in the same connection.
     *
     * @var int
     */
    private $nbConcurrentRequests = 20;

    /**
     * Number of maximum concurrent connections established to the APNS servers.
     *
     * @var int
     */
    private $maxConcurrentConnections = 1;

    /**
     * Flag to know if we should automatically close connections to the APNS servers or keep them alive.
     *
     * @var bool
     */
    private $autoCloseConnections = true;

    /**
     * Current curl_multi handle instance.
     *
     * @var resource
     */
    private $curlMultiHandle;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Client constructor.
     *
     * @param AuthProviderInterface $authProvider
     * @param LoggerInterface $logger
     * @param bool $isProductionEnv
     */
    public function __construct(
        AuthProviderInterface $authProvider,
        LoggerInterface $logger,
        bool $isProductionEnv = false
    ) {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
        $this->isProductionEnv = $isProductionEnv;
    }

    /**
     * Push notifications to APNs.
     *
     * @param Notification[]
     * @return ApnsResponseInterface[]
     */
    public function push(array $notifications): array
    {
        if (!$this->curlMultiHandle) {
            $this->curlMultiHandle = curl_multi_init();

            if (!defined('CURLPIPE_MULTIPLEX')) {
                define('CURLPIPE_MULTIPLEX', 2);
            }

            curl_multi_setopt($this->curlMultiHandle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
            curl_multi_setopt($this->curlMultiHandle, CURLMOPT_MAX_HOST_CONNECTIONS, $this->maxConcurrentConnections);
        }

        $mh = $this->curlMultiHandle;
        $responseCollection = [];

        $i = 0;
        while (!empty($notifications) && $i++ < $this->nbConcurrentRequests) {
            $notification = array_pop($notifications);
            curl_multi_add_handle($mh, $this->prepareHandle($notification));
        }

        do {
            while (($execrun = curl_multi_exec($mh, $running)) == CURLM_CALL_MULTI_PERFORM);

            if ($execrun != CURLM_OK) {
                break;
            }

            if ($running && curl_multi_select($mh) === -1) {
                usleep(250);
            }

            while ($done = curl_multi_info_read($mh)) {
                $handle = $done['handle'];

                $result = curl_multi_getcontent($handle);

                // find out which token the response is about
                $token = curl_getinfo($handle, CURLINFO_PRIVATE);

                $responseParts = explode("\r\n\r\n", $result, 2);
                $headers = '';
                $body = '';
                if (isset($responseParts[0])) {
                    $headers = $responseParts[0];
                }
                if (isset($responseParts[1])) {
                    $body = $responseParts[1];
                }
                $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                if ($statusCode === 0) {
                    $this->logger->error(
                        'Unable to extract HTTP_CODE',
                        [
                            'result' => $result,
                            'notifications' => $notifications,
                        ]
                    );
                }
                $responseCollection[] = new Response($statusCode, $headers, $body, $token);
                curl_multi_remove_handle($mh, $handle);
                curl_close($handle);

                if (!empty($notifications)) {
                    $notification = array_pop($notifications);
                    curl_multi_add_handle($mh, $this->prepareHandle($notification));
                }
            }
        } while ($running);

        if ($this->autoCloseConnections) {
            $this->close();
        }

        return $responseCollection;
    }

    public function pushOne(Notification $notification)
    {
        $curl = $this->prepareHandle($notification);
        $output = curl_exec($curl);

        if (curl_errno($curl) > 0) {
            $this->logger->error(
                'Got curl error while sending ANPS',
                [
                    'err_no' => curl_errno($curl),
                    'notification' => $notification->getPayload()->toJson(),
                ]
            );
        }

        $responseParts = explode("\r\n\r\n", $output, 2);
        $headers = '';
        $body = '';
        if (isset($responseParts[0])) {
            $headers = $responseParts[0];
        }
        if (isset($responseParts[1])) {
            $body = $responseParts[1];
        }
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $token = curl_getinfo($curl, CURLINFO_PRIVATE);

        curl_close($curl);

        return new Response($statusCode, $headers, $body, $token);
    }

    /**
     * Prepares a curl handle from a Notification object.
     *
     * @param Notification $notification
     * @return resource
     */
    private function prepareHandle(Notification $notification)
    {
        $request = new Request($notification, $this->isProductionEnv);
        $ch = curl_init();

        $this->authProvider->authenticateClient($request);

        curl_setopt_array($ch, $request->getOptions());
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request->getDecoratedHeaders());

        // store device token to identify response
        curl_setopt($ch, CURLOPT_PRIVATE, $notification->getDeviceToken());

        return $ch;
    }

    /**
     * Close the current curl multi handle.
     */
    public function close()
    {
        if ($this->curlMultiHandle) {
            curl_multi_close($this->curlMultiHandle);
            $this->curlMultiHandle = null;
        }
    }

    /**
     * Set the number of concurrent requests sent through the multiplexed connections.
     *
     * @param int $nbConcurrentRequests
     */
    public function setNbConcurrentRequests($nbConcurrentRequests)
    {
        $this->nbConcurrentRequests = $nbConcurrentRequests;
    }


    /**
     * Set the number of maximum concurrent connections established to the APNS servers.
     *
     * @param int $maxConcurrentConnections
     */
    public function setMaxConcurrentConnections($maxConcurrentConnections)
    {
        $this->maxConcurrentConnections = $maxConcurrentConnections;
    }

    /**
     * Set whether or not the client should automatically close the connections. Apple recommends keeping
     * connections open if you send more than a few notification per minutes.
     *
     * @param bool $autoCloseConnections
     */
    public function setAutoCloseConnections($autoCloseConnections)
    {
        $this->autoCloseConnections = $autoCloseConnections;
    }
}
