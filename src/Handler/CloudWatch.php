<?php

namespace PhpNexus\Cwh\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use DateTime;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Cache\CacheItemPoolInterface;

class CloudWatch extends AbstractProcessingHandler
{
    /**
     * Event size limit (https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html)
     */
    public const EVENT_SIZE_LIMIT = 1048550; // 1048576 - reserved 26

    /**
     * Data amount limit (http://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html)
     */
    private const DATA_AMOUNT_LIMIT = 1048576;

    /**
     * The batch of log events in a single PutLogEvents request cannot span more than 24 hours.
     */
    public const TIMESPAN_LIMIT = 86400000;

    private readonly CloudWatchLogsClient $client;

    private readonly string $group;

    private readonly string $stream;

    private readonly int|null $retention;

    private readonly int $batchSize;

    private readonly array $tags;

    private readonly bool $createGroup;

    private readonly bool $createStream;

    /**
     * Requests per second limit (https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html)
     */
    private readonly int $rpsLimit;

    private bool $initialized = false;

    /** @var LogRecord[] $buffer */
    private array $buffer = [];

    private int $currentDataAmount = 0;

    private int $remainingRequests;

    private readonly DateTime $rpsTimestamp;

    private int|null $earliestTimestamp = null;

    private ?CacheItemPoolInterface $cacheItemPool = null;

    private int $cacheItemTtl = 60 * 5;

    /**
     * CloudWatchLogs constructor.
     *
     *  Log group names must be unique within a region for an AWS account.
     *  Log group names can be between 1 and 512 characters long.
     *  Log group names consist of the following characters: a-z, A-Z, 0-9, '_' (underscore), '-' (hyphen),
     * '/' (forward slash), and '.' (period).
     *
     *  Log stream names must be unique within the log group.
     *  Log stream names can be between 1 and 512 characters long.
     *  The ':' (colon) and '*' (asterisk) characters are not allowed.
     *
     * @param CloudWatchLogsClient $client AWS SDK CloudWatchLogs client to use with this handler.
     * @param string $group Name of the log group.
     * @param string $stream Name of the log stream within the log group.
     * @param int|null $retention (Optional) Number of days to retain log entries.
     *                            Only used when CloudWatch handler creates a log group.
     * @param int $batchSize (Optional) Number of logs to queue before sending to CloudWatch.
     * @param array $tags (Optional) Tags to apply to the log group.
     *                            Only used when CloudWatch handler creates a log group.
     * @param int|string|Level $level (Optional) The minimum logging level at which this handler will be
     *                                        triggered.
     * @param bool $bubble (Optional) Whether the messages that are handled can bubble up the stack or not.
     * @param bool $createGroup (Optional) Whether to create the log group if the log group does not exist.
     * @param bool $createStream (Optional) Whether to create log stream if log stream does not exist in the log group.
     * @param int $rpsLimit (Optional) Number of requests per second before a 1-second sleep is triggered.
     *                      Set to 0 to disable.
     * @param CacheItemPoolInterface|null $cacheItemPool (Optional) PSR-6 cache pool to use for the caching log group
     *                      and stream creation.
     * @param int $cacheItemTtl (Optional) TTL for cache items in seconds.
     *
     * @throws Exception
     */
    public function __construct(
        CloudWatchLogsClient $client,
        string $group,
        string $stream,
        int|null $retention = 14,
        int $batchSize = 10000,
        array $tags = [],
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        bool $createGroup = true,
        bool $createStream = true,
        int $rpsLimit = 0,
        ?CacheItemPoolInterface $cacheItemPool = null,
        int $cacheItemTtl = 60 * 5
    ) {
        // Assert batch size is not above 10,000
        if ($batchSize > 10000) {
            throw new InvalidArgumentException('Batch size can not be greater than 10000');
        }
        // Assert RPS limit is not a negative number
        if ($rpsLimit < 0) {
            throw new InvalidArgumentException('RPS limit can not be a negative number');
        }

        $this->client = $client;
        $this->group = $group;
        $this->stream = $stream;
        $this->retention = $retention;
        $this->batchSize = $batchSize;
        $this->tags = $tags;
        $this->createGroup = $createGroup;
        $this->createStream = $createStream;
        $this->rpsLimit = $rpsLimit;

        if (!$createGroup && !$createStream && $cacheItemPool) {
            throw new InvalidArgumentException('Cache pool can not be used without creating log group or stream');
        }

        $this->cacheItemPool = $cacheItemPool;
        $this->cacheItemTtl = $cacheItemTtl;

        parent::__construct($level, $bubble);

        // Initialize remaining requests and RPS timestamp for rate limiting
        $this->resetRemainingRequests();
        $this->rpsTimestamp = new DateTime();
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function write(LogRecord $record): void
    {
        $records = $this->formatRecords($record);

        foreach ($records as $record) {
            if ($this->willMessageSizeExceedLimit($record) || $this->willMessageTimestampExceedLimit($record)) {
                $this->flushBuffer();
            }

            $this->addToBuffer($record);

            if (count($this->buffer) >= $this->batchSize) {
                $this->flushBuffer();
            }
        }
    }

    private function addToBuffer(array $record): void
    {
        $this->currentDataAmount += $this->getMessageSize($record);

        $timestamp = $record['timestamp'];

        if (!$this->earliestTimestamp || $timestamp < $this->earliestTimestamp) {
            $this->earliestTimestamp = (int)$timestamp;
        }

        $this->buffer[] = $record;
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function flushBuffer(): void
    {
        if (!empty($this->buffer)) {
            if (false === $this->initialized) {
                $this->initialize();
            }

            try {
                // send items
                $this->send($this->buffer);
            } catch (CloudWatchLogsException $e) {
                error_log('AWS CloudWatchLogs threw an exception while sending items: ' . $e->getMessage());

                // wait for 1 second and try to send items again (in the case of per account per region rate limiting)
                sleep(1);
                $this->send($this->buffer);
            }

            // clear buffer
            $this->buffer = [];

            // clear the earliest timestamp
            $this->earliestTimestamp = null;

            // clear data amount
            $this->currentDataAmount = 0;
        }
    }

    private function checkThrottle(): void
    {
        if ($this->rpsLimit > 0) {
            // Calculate the number of seconds between now and the last RPS timestamp
            $diff = $this->rpsTimestamp->diff(new DateTimeImmutable())->s;
            $sameSecond = $diff === 0;

            if ($sameSecond) {
                // If no remaining requests for the current second
                if ($this->remainingRequests === 0) {
                    // Sleep and reset remaining requests
                    sleep(1);
                    $this->resetRemainingRequests();
                }
            } else {
                // Different second, reset remaining requests
                $this->resetRemainingRequests();
            }

            // Decrement remaining requests
            $this->remainingRequests--;

            // Update RPS timestamp to current time
            $this->rpsTimestamp->setTimestamp(time());
        }
    }

    private function resetRemainingRequests(): void
    {
        $this->remainingRequests = $this->rpsLimit;
    }

    /**
     * http://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html
     */
    private function getMessageSize(array $record): int
    {
        return strlen($record['message']) + 26;
    }

    /**
     * Determine whether the specified record's message size in addition to the
     * size of the current queued messages will exceed AWS CloudWatch's limit.
     */
    protected function willMessageSizeExceedLimit(array $record): bool
    {
        return $this->currentDataAmount + $this->getMessageSize($record) >= self::DATA_AMOUNT_LIMIT;
    }

    /**
     * Determine whether the specified record's timestamp exceeds the 24-hour timespan limit
     * for all batched messages written in a single call to PutLogEvents.
     */
    protected function willMessageTimestampExceedLimit(array $record): bool
    {
        return $this->earliestTimestamp && $record['timestamp'] - $this->earliestTimestamp > self::TIMESPAN_LIMIT;
    }

    /**
     * Event size in the batch cannot be bigger than 1 MB
     * https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html
     */
    private function formatRecords(LogRecord $entry): array
    {
        $entries = str_split($entry['formatted'], self::EVENT_SIZE_LIMIT);
        $timestamp = $entry['datetime']->format('U.u') * 1000;
        $records = [];

        foreach ($entries as $entry) {
            $records[] = [
                'message' => $entry,
                'timestamp' => $timestamp
            ];
        }

        return $records;
    }

    /**
     * The batch of events must satisfy the following constraints:
     *  - The maximum batch size is 1,048,576 bytes, and this size is calculated as the sum of all event messages in
     * UTF-8, plus 26 bytes for each log event.
     *  - None of the log events in the batch can be more than 2 hours in the future.
     *  - None of the log events in the batch can be older than 14 days or the retention period of the log group.
     *  - The log events in the batch must be in chronologically ordered by their timestamp
     *    (the time the event occurred, expressed as the number of milliseconds since Jan 1, 1970 00:00:00 UTC).
     *  - The maximum number of log events in a batch is 10,000.
     *  - A batch of log events in a single request cannot span more than 24 hours. Otherwise, the operation fails.
     *
     * @param LogRecord[] $entries
     *
     * @throws CloudWatchLogsException Thrown by putLogEvents()
     */
    private function send(array $entries): void
    {
        // AWS expects to receive entries in chronological order...
        usort($entries, static function (array $a, array $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        $data = [
            'logGroupName' => $this->group,
            'logStreamName' => $this->stream,
            'logEvents' => $entries
        ];

        $this->checkThrottle();

        $this->client->putLogEvents($data);
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function initializeGroup(): void
    {
        // Check if a PSR-6 cache pool is available
        if ($this->cacheItemPool !== null) {
            // Attempt to retrieve the cached state for the current log group
            $cacheItem = $this->cacheItemPool->getItem($this->group);

            // If the group is already cached, skip further initialization
            if ($cacheItem->isHit()) {
                return;
            }
        }

        // fetch existing groups
        $existingGroups = $this
            ->client
            ->describeLogGroups(['logGroupNamePrefix' => $this->group])
            ->get('logGroups');

        // extract existing groups names
        $existingGroupsNames = array_column($existingGroups, 'logGroupName');

        // create group and set retention policy if not created yet
        if (!in_array($this->group, $existingGroupsNames, true)) {
            $createLogGroupArguments = ['logGroupName' => $this->group];

            if (!empty($this->tags)) {
                $createLogGroupArguments['tags'] = $this->tags;
            }

            $this
                ->client
                ->createLogGroup($createLogGroupArguments);

            if ($this->retention !== null) {
                $this
                    ->client
                    ->putRetentionPolicy(
                        [
                            'logGroupName' => $this->group,
                            'retentionInDays' => $this->retention,
                        ]
                    );
            }
        }

        // Check if a cache pool is configured
        if ($this->cacheItemPool !== null) {
            // Retrieve or create a cache item for the current log group
            $cacheItem = $this->cacheItemPool->getItem($this->group);

            // Mark the log group as initialized/existing
            $cacheItem->set(true);

            // Set the expiration time for this cache entry
            $cacheItem->expiresAfter($this->cacheItemTtl);

            // Persist the item to the cache to avoid redundant initialization checks
            $this->cacheItemPool->save($cacheItem);
        }
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function initializeStream(): void
    {
        // Check if a PSR-6 cache pool is configured
        if ($this->cacheItemPool !== null) {
            // Attempt to retrieve the cached state for the current log stream
            $cacheItem = $this->cacheItemPool->getItem($this->stream);

            // If the stream is already known to exist in cache, skip initialization
            if ($cacheItem->isHit()) {
                return;
            }
        }

        // fetch existing streams
        $existingStreams = $this
            ->client
            ->describeLogStreams(
                [
                    'logGroupName' => $this->group,
                    'logStreamNamePrefix' => $this->stream,
                ]
            )
            ->get('logStreams');

        // extract existing streams names
        $existingStreamsNames = array_column($existingStreams, 'logStreamName');

        // create a stream if not created
        if (!in_array($this->stream, $existingStreamsNames, true)) {
            $this
                ->client
                ->createLogStream(
                    [
                        'logGroupName' => $this->group,
                        'logStreamName' => $this->stream
                    ]
                );
        }

        // If a cache pool is available, mark the log stream as initialized
        if ($this->cacheItemPool !== null) {
            // Create/retrieve a cache item using the stream name as the key
            $cacheItem = $this->cacheItemPool->getItem($this->stream);

            // Set value to true to indicate the stream exists
            $cacheItem->set(true);

            // Set the expiration time based on configured TTL
            $cacheItem->expiresAfter($this->cacheItemTtl);

            // Persist the item to the cache pool
            $this->cacheItemPool->save($cacheItem);
        }
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function initialize(): void
    {
        if ($this->createGroup) {
            $this->initializeGroup();
        }
        if ($this->createStream) {
            $this->initializeStream();
        }

        $this->initialized = true;
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter("%channel%: %level_name%: %message% %context% %extra%", null, false, true);
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function close(): void
    {
        $this->flushBuffer();
    }
}
