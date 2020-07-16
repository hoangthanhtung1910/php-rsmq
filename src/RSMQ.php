<?php
declare(strict_types=1);

namespace AndrewBreksa\RSMQ;

use Predis\ClientInterface;

class RSMQ
{
    const MAX_DELAY        = 9999999;
    const MIN_MESSAGE_SIZE = 1024;
    const MAX_PAYLOAD_SIZE = 65536;

    /**
     * @var ClientInterface
     */
    private $redis;

    /**
     * @var string
     */
    private $ns;

    /**
     * @var bool
     */
    private $realtime;

    /**
     * @var Util
     */
    private $util;

    /**
     * @var string
     */
    private $receiveMessageSha1;

    /**
     * @var string
     */
    private $popMessageSha1;

    /**
     * @var string
     */
    private $changeMessageVisibilitySha1;

    public function __construct(ClientInterface $redis, string $ns = 'rsmq', bool $realtime = false)
    {
        $this->redis = $redis;
        $this->ns = "$ns:";
        $this->realtime = $realtime;

        $this->util = new Util();

        $this->initScripts();
    }

    private function initScripts(): void
    {
        $receiveMessageScript = 'local msg = redis.call("ZRANGEBYSCORE", KEYS[1], "-inf", KEYS[2], "LIMIT", "0", "1")
			if #msg == 0 then
				return {}
			end
			redis.call("ZADD", KEYS[1], KEYS[3], msg[1])
			redis.call("HINCRBY", KEYS[1] .. ":Q", "totalrecv", 1)
			local mbody = redis.call("HGET", KEYS[1] .. ":Q", msg[1])
			local rc = redis.call("HINCRBY", KEYS[1] .. ":Q", msg[1] .. ":rc", 1)
			local o = {msg[1], mbody, rc}
			if rc==1 then
				redis.call("hset", KEYS[1] .. ":Q", msg[1] .. ":fr", KEYS[2])
				table.insert(o, KEYS[2])
			else
				local fr = redis.call("HGET", KEYS[1] .. ":Q", msg[1] .. ":fr")
				table.insert(o, fr)
			end
			return o';

        $popMessageScript = 'local msg = redis.call("ZRANGEBYSCORE", KEYS[1], "-inf", KEYS[2], "LIMIT", "0", "1")
			if #msg == 0 then
				return {}
			end
			redis.call("HINCRBY", KEYS[1] .. ":Q", "totalrecv", 1)
			local mbody = redis.call("HGET", KEYS[1] .. ":Q", msg[1])
			local rc = redis.call("HINCRBY", KEYS[1] .. ":Q", msg[1] .. ":rc", 1)
			local o = {msg[1], mbody, rc}
			if rc==1 then
				table.insert(o, KEYS[2])
			else
				local fr = redis.call("HGET", KEYS[1] .. ":Q", msg[1] .. ":fr")
				table.insert(o, fr)
			end
			redis.call("zrem", KEYS[1], msg[1])
			redis.call("hdel", KEYS[1] .. ":Q", msg[1], msg[1] .. ":rc", msg[1] .. ":fr")
			return o';

        $changeMessageVisibilityScript = 'local msg = redis.call("ZSCORE", KEYS[1], KEYS[2])
			if not msg then
				return 0
			end
			redis.call("ZADD", KEYS[1], KEYS[3], KEYS[2])
			return 1';

        $this->receiveMessageSha1 = $this->redis->script('load', $receiveMessageScript);
        $this->popMessageSha1 = $this->redis->script('load', $popMessageScript);
        $this->changeMessageVisibilitySha1 = $this->redis->script('load', $changeMessageVisibilityScript);
    }

    public function createQueue(string $name, int $vt = 30, int $delay = 0, int $maxSize = 65536): bool
    {
        $this->validate([
                            'queue'   => $name,
                            'vt'      => $vt,
                            'delay'   => $delay,
                            'maxsize' => $maxSize,
                        ]);

        $key = "{$this->ns}$name:Q";

        $resp = $this->redis->time();
        $this->redis->multi();
        $this->redis->hsetnx($key, 'vt', (string)$vt);
        $this->redis->hsetnx($key, 'delay', (string)$delay);
        $this->redis->hsetnx($key, 'maxsize', (string)$maxSize);
        $this->redis->hsetnx($key, 'created', $resp[0]);
        $this->redis->hsetnx($key, 'modified', $resp[0]);
        $resp = $this->redis->exec();

        if (!$resp[0]) {
            throw new Exception('Queue already exists.');
        }

        return (bool)$this->redis->sadd("{$this->ns}QUEUES", [$name]);
    }

    /**
     * @param array<string, mixed> $params
     * @throws Exception
     */
    public function validate(array $params): void
    {
        if (isset($params['queue']) && !preg_match('/^([a-zA-Z0-9_-]){1,160}$/', $params['queue'])) {
            throw new Exception('Invalid queue name');
        }

        if (isset($params['id']) && !preg_match('/^([a-zA-Z0-9:]){32}$/', $params['id'])) {
            throw new Exception('Invalid message id');
        }

        if (isset($params['vt']) && ($params['vt'] < 0 || $params['vt'] > self::MAX_DELAY)) {
            throw new Exception('Visibility time must be between 0 and ' . self::MAX_DELAY);
        }

        if (isset($params['delay']) && ($params['delay'] < 0 || $params['delay'] > self::MAX_DELAY)) {
            throw new Exception('Delay must be between 0 and ' . self::MAX_DELAY);
        }

        if (isset($params['maxsize']) &&
            ($params['maxsize'] < self::MIN_MESSAGE_SIZE || $params['maxsize'] > self::MAX_PAYLOAD_SIZE)) {
            $message = "Maximum message size must be between %d and %d";
            throw new Exception(sprintf($message, self::MIN_MESSAGE_SIZE, self::MAX_PAYLOAD_SIZE));
        }
    }

    /**
     * @return array<string>
     */
    public function listQueues(): array
    {
        return $this->redis->smembers("{$this->ns}QUEUES");
    }

    public function deleteQueue(string $name): void
    {
        $this->validate([
                            'queue' => $name,
                        ]);

        $key = "{$this->ns}$name";
        $this->redis->multi();
        $this->redis->del(["$key:Q", $key]);
        $this->redis->srem("{$this->ns}QUEUES", $name);
        $resp = $this->redis->exec();

        if (!$resp[0]) {
            throw new Exception('Queue not found.');
        }
    }

    /**
     * @param string   $queue
     * @param int|null $vt
     * @param int|null $delay
     * @param int|null $maxSize
     * @return array<string, mixed>
     * @throws Exception
     */
    public function setQueueAttributes(string $queue, int $vt = null, int $delay = null, int $maxSize = null): array
    {
        $this->validate([
                            'vt'      => $vt,
                            'delay'   => $delay,
                            'maxsize' => $maxSize,
                        ]);
        $this->getQueue($queue);

        $time = $this->redis->time();
        $this->redis->multi();

        $this->redis->hset("{$this->ns}$queue:Q", 'modified', $time[0]);
        if ($vt !== null) {
            $this->redis->hset("{$this->ns}$queue:Q", 'vt', (string)$vt);
        }

        if ($delay !== null) {
            $this->redis->hset("{$this->ns}$queue:Q", 'delay', (string)$delay);
        }

        if ($maxSize !== null) {
            $this->redis->hset("{$this->ns}$queue:Q", 'maxsize', (string)$maxSize);
        }

        $this->redis->exec();

        return $this->getQueueAttributes($queue);
    }

    /**
     * @param string $name
     * @param bool   $uid
     * @return array<string, mixed>
     * @throws Exception
     */
    private function getQueue(string $name, bool $uid = false): array
    {
        $this->validate([
                            'queue' => $name,
                        ]);

        $transaction = $this->redis->transaction();
        $transaction->hmget("{$this->ns}$name:Q", ['vt', 'delay', 'maxsize']);
        $transaction->time();
        $resp = $transaction->execute();

        if (!isset($resp[0][0])) {
            throw new Exception('Queue not found.');
        }

        $ms = $this->util->formatZeroPad((int)$resp[1][1], 6);


        $queue = [
            'vt'      => (int)$resp[0][0],
            'delay'   => (int)$resp[0][1],
            'maxsize' => (int)$resp[0][2],
            'ts'      => (int)($resp[1][0] . substr($ms, 0, 3)),
        ];

        if ($uid) {
            $uid = $this->util->makeID(22);
            $queue['uid'] = base_convert(($resp[1][0] . $ms), 10, 36) . $uid;
        }

        return $queue;
    }

    /**
     * @param string $queue
     * @return array<string, mixed>
     * @throws Exception
     */
    public function getQueueAttributes(string $queue): array
    {
        $this->validate([
                            'queue' => $queue,
                        ]);

        $key = "{$this->ns}$queue";
        $resp = $this->redis->time();

        $transaction = $this->redis->transaction();
        $transaction->hmget("$key:Q", ['vt', 'delay', 'maxsize', 'totalrecv', 'totalsent', 'created', 'modified']);
        $transaction->zcard($key);
        $transaction->zcount($key, $resp[0] . '0000', "+inf");
        $resp = $transaction->execute();

        if (!isset($resp[0][0])) {
            throw new Exception('Queue not found.');
        }

        $attributes = [
            'vt'         => (int)$resp[0][0],
            'delay'      => (int)$resp[0][1],
            'maxsize'    => (int)$resp[0][2],
            'totalrecv'  => (int)$resp[0][3],
            'totalsent'  => (int)$resp[0][4],
            'created'    => (int)$resp[0][5],
            'modified'   => (int)$resp[0][6],
            'msgs'       => $resp[1],
            'hiddenmsgs' => $resp[2],
        ];

        return $attributes;
    }

    /**
     * @param string               $queue
     * @param string               $message
     * @param array<string, mixed> $options
     * @return string
     * @throws Exception
     */
    public function sendMessage(string $queue, string $message, array $options = []): string
    {
        $this->validate([
                            'queue' => $queue,
                        ]);

        $q = $this->getQueue($queue, true);
        $delay = $options['delay'] ?? $q['delay'];

        if ($q['maxsize'] !== -1 && mb_strlen($message) > $q['maxsize']) {
            throw new Exception('Message too long');
        }

        $key = "{$this->ns}$queue";

        $this->redis->multi();
        $this->redis->zadd($key, [$q['uid'] => $q['ts'] + $delay * 1000]);
        $this->redis->hset("$key:Q", $q['uid'], $message);
        $this->redis->hincrby("$key:Q", 'totalsent', 1);

        if ($this->realtime) {
            $this->redis->zcard($key);
        }

        $resp = $this->redis->exec();

        if ($this->realtime) {
            $this->redis->publish("{$this->ns}rt:$$queue", $resp[3]);
        }

        return $q['uid'];
    }

    /**
     * @param string               $queue
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws Exception
     */
    public function receiveMessage(string $queue, array $options = []): array
    {
        $this->validate([
                            'queue' => $queue,
                        ]);

        $q = $this->getQueue($queue);
        $vt = $options['vt'] ?? $q['vt'];

        $args = [
            "{$this->ns}$queue",
            $q['ts'],
            $q['ts'] + $vt * 1000
        ];
        $resp = $this->redis->evalsha(
            $this->receiveMessageSha1,
            3,
            "{$this->ns}$queue", $q['ts'],
            $q['ts'] + $vt * 1000
        );
        if (empty($resp)) {
            return [];
        }
        return [
            'id'      => $resp[0],
            'message' => $resp[1],
            'rc'      => $resp[2],
            'fr'      => $resp[3],
            'sent'    => base_convert(substr($resp[0], 0, 10), 36, 10) / 1000,
        ];
    }

    /**
     * @param string $queue
     * @return array<string, mixed>
     * @throws Exception
     */
    public function popMessage(string $queue): array
    {
        $this->validate([
                            'queue' => $queue,
                        ]);

        $q = $this->getQueue($queue);

        $resp = $this->redis->evalsha($this->popMessageSha1, 2, "{$this->ns}$queue", $q['ts']);
        if (empty($resp)) {
            return [];
        }
        return [
            'id'      => $resp[0],
            'message' => $resp[1],
            'rc'      => $resp[2],
            'fr'      => $resp[3],
            'sent'    => base_convert(substr($resp[0], 0, 10), 36, 10) / 1000,
        ];
    }

    public function deleteMessage(string $queue, string $id): bool
    {
        $this->validate([
                            'queue' => $queue,
                            'id'    => $id,
                        ]);

        $key = "{$this->ns}$queue";
        $this->redis->multi();
        $this->redis->zrem($key, $id);
        $this->redis->hdel("$key:Q", [$id, "$id:rc", "$id:fr"]);
        $resp = $this->redis->exec();

        return $resp[0] === 1 && $resp[1] > 0;
    }

    public function changeMessageVisibility(string $queue, string $id, int $vt): bool
    {
        $this->validate([
                            'queue' => $queue,
                            'id'    => $id,
                            'vt'    => $vt,
                        ]);

        $q = $this->getQueue($queue, true);

        $resp = $this->redis->evalsha(
            $this->changeMessageVisibilitySha1,
            3,
            "{$this->ns}$queue",
            $id,
            $q['ts'] + $vt * 1000
        );

        return (bool)$resp;
    }
}
