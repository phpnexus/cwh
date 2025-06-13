<?php

namespace PhpNexus\Cwh\Test\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Result;
use PhpNexus\Cwh\Handler\CloudWatch;
use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class CloudWatchTest extends TestCase
{
    private MockObject | CloudWatchLogsClient $clientMock;
    private MockObject | Result $awsResultMock;
    private string $groupName = 'group';
    private string $streamName = 'stream';

    protected function setUp(): void
    {
        $this->clientMock = $this
            ->getMockBuilder(CloudWatchLogsClient::class)
            ->addMethods(
                [
                    'describeLogGroups',
                    'CreateLogGroup',
                    'PutRetentionPolicy',
                    'DescribeLogStreams',
                    'CreateLogStream',
                    'PutLogEvents'
                ]
            )
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testInitializeWithCreateGroupDisabled(): void
    {
        $this
            ->clientMock
            ->expects($this->never())
            ->method('describeLogGroups');

        $this
            ->clientMock
            ->expects($this->never())
            ->method('createLogGroup');

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName,
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $handler = new CloudWatch(
            $this->clientMock,
            $this->groupName,
            $this->streamName,
            14,
            10000,
            [],
            Level::Debug,
            true,
            false
        );

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithCreateStreamDisabled(): void
    {
        $this
            ->clientMock
            ->expects($this->never())
            ->method('describeLogGroups');

        $this
            ->clientMock
            ->expects($this->never())
            ->method('createLogGroup');

        $this
            ->clientMock
            ->expects($this->never())
            ->method('describeLogStreams');

        $handler = new CloudWatch(
            $this->clientMock,
            $this->groupName,
            $this->streamName,
            14,
            10000,
            [],
            Level::Debug,
            true,
            false,
            false
        );

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithExistingLogGroup(): void
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName]]]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName,
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $handler = $this->getCUT();

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithTags(): void
    {
        $tags = [
            'applicationName' => 'dummyApplicationName',
            'applicationEnvironment' => 'dummyApplicationEnvironment'
        ];

        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName . 'foo']]]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with([
                'logGroupName' => $this->groupName,
                'tags' => $tags
            ]);

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName,
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $handler = new CloudWatch($this->clientMock, $this->groupName, $this->streamName, 14, 10000, $tags);

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithEmptyTags(): void
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName . 'foo']]]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with(['logGroupName' => $this->groupName]); //The empty array of tags is not handed over

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName,
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $handler = new CloudWatch($this->clientMock, $this->groupName, $this->streamName);

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithMissingGroupAndStream(): void
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName . 'foo']]]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with(['logGroupName' => $this->groupName]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('putRetentionPolicy')
            ->with([
                'logGroupName' => $this->groupName,
                'retentionInDays' => 14,
            ]);

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName . 'bar',
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName
            ]);

        $handler = $this->getCUT();

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testBatchSizeLimitExceeded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CloudWatch($this->clientMock, 'a', 'b', batchSize: 10001));
    }

    public function testInvalidRpsLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CloudWatch($this->clientMock, 'a', 'b', rpsLimit: -1));
    }

    public function testSendsOnClose(): void
    {
        $this->prepareMocks();

        $this
            ->clientMock
            ->expects($this->once())
            ->method('PutLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = $this->getCUT(1);

        $handler->handle($this->getRecord(Level::Debug));

        $handler->close();
    }

    public function testSendsBatches(): void
    {
        $this->prepareMocks();

        $this
            ->clientMock
            ->expects($this->exactly(2))
            ->method('PutLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = $this->getCUT(3);

        foreach ($this->getMultipleRecords() as $record) {
            $handler->handle($record);
        }

        $handler->close();
    }

    public function testSendWithRPSLimit(): void
    {
        $this->prepareMocks();

        $this
            ->clientMock
            ->expects($this->exactly(4))
            ->method('PutLogEvents')
            ->willReturnCallback(function (array $data) {
                $this->assertStringContainsString('record', $data['logEvents'][0]['message']);

                return $this->awsResultMock;
            });

        $handler = new CloudWatch(
            $this->clientMock,
            $this->groupName,
            $this->streamName,
            14,
            1,
            [],
            Level::Debug,
            true,
            true,
            true,
            2
        );

        // Get access to remainingRequests property
        $reflection = new \ReflectionClass($handler);
        $remainingRequestsProperty = $reflection->getProperty('remainingRequests');
        $remainingRequestsProperty->setAccessible(true);

        // Initial log entry
        $handler->handle($this->getRecord(Level::Debug, 'record'));

        // Ensure remainingRequests was decremented to 1 after initial log entry
        $this->assertEquals(1, $remainingRequestsProperty->getValue($handler));

        // Second log entry immediately after
        $handler->handle($this->getRecord(Level::Debug, 'record'));

        // Ensure remainingRequests was decremented to 0 after second log entry
        $this->assertEquals(0, $remainingRequestsProperty->getValue($handler));

        // Third log entry immediately after
        $handler->handle($this->getRecord(Level::Debug, 'record'));

        // Ensure remainingRequests is now 1 after third log entry
        // Note: Would have been reset to 2 after internal throttling, then decremented to 1
        $this->assertEquals(1, $remainingRequestsProperty->getValue($handler));

        // Final log entry 1 second later
        sleep(1);
        $handler->handle($this->getRecord(Level::Debug, 'record'));

        // Ensure remainingRequests was decremented to 1 after final log entry
        // Note: Would have been reset to 2 after sleep(1), then decremented
        $this->assertEquals(1, $remainingRequestsProperty->getValue($handler));
    }

    public function testFormatter(): void
    {
        $handler = $this->getCUT();

        $formatter = $handler->getFormatter();

        $expected = new LineFormatter("%channel%: %level_name%: %message% %context% %extra%", null, false, true);

        $this->assertEquals($expected, $formatter);
    }

    public function testExceptionFromDescribeLogGroups(): void
    {
        // e.g. 'User is not authorized to perform logs:DescribeLogGroups'
        /** @var CloudWatchLogsException */
        $awsException = $this->getMockBuilder(CloudWatchLogsException::class)
            ->disableOriginalConstructor()
            ->getMock();

        // if this fails ...
        $this
            ->clientMock
            ->expects($this->atLeastOnce())
            ->method('describeLogGroups')
            ->will($this->throwException($awsException));

        // ... this should not be called:
        $this
            ->clientMock
            ->expects($this->never())
            ->method('describeLogStreams');

        $this->expectException(CloudWatchLogsException::class);

        $handler = $this->getCUT(0);
        $handler->handle($this->getRecord(Level::Info));
    }

    private function prepareMocks(): void
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName]]]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName,
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this->awsResultMock = $this
            ->getMockBuilder(Result::class)
            ->onlyMethods(['get'])
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testSortsEntriesChronologically(): void
    {
        $this->prepareMocks();

        $this
            ->clientMock
            ->expects($this->once())
            ->method('PutLogEvents')
            ->willReturnCallback(function (array $data) {
                $this->assertStringContainsString('record1', $data['logEvents'][0]['message']);
                $this->assertStringContainsString('record2', $data['logEvents'][1]['message']);
                $this->assertStringContainsString('record3', $data['logEvents'][2]['message']);
                $this->assertStringContainsString('record4', $data['logEvents'][3]['message']);

                return $this->awsResultMock;
            });

        $handler = $this->getCUT(4);

        // created with chronological timestamps:
        $records = [];

        for ($i = 1; $i <= 4; ++$i) {
            $dt = \DateTimeImmutable::createFromFormat('U', time() + $i);
            $record = $this->getRecord(Level::Info, 'record' . $i, [], $dt);
            $records[] = $record;
        }

        // but submitted in a different order:
        $handler->handle($records[2]);
        $handler->handle($records[0]);
        $handler->handle($records[3]);
        $handler->handle($records[1]);

        $handler->close();
    }

    public function testSendsBatchesSpanning24HoursOrLess(): void
    {
        $this->prepareMocks();

        $this
            ->clientMock
            ->expects($this->exactly(3))
            ->method('PutLogEvents')
            ->willReturnCallback(function (array $data) {
                /** @var int|null */
                $earliestTime = null;

                /** @var int|null */
                $latestTime = null;

                foreach ($data['logEvents'] as $logEvent) {
                    $logTimestamp = $logEvent['timestamp'];

                    if (!$earliestTime || $logTimestamp < $earliestTime) {
                        $earliestTime = $logTimestamp;
                    }

                    if (!$latestTime || $logTimestamp > $latestTime) {
                        $latestTime = $logTimestamp;
                    }
                }

                $this->assertNotNull($earliestTime);
                $this->assertNotNull($latestTime);
                $this->assertGreaterThanOrEqual($earliestTime, $latestTime);
                $this->assertLessThanOrEqual(24 * 60 * 60 * 1000, $latestTime - $earliestTime);

                return $this->awsResultMock;
            });

        $handler = $this->getCUT();

        // write 15 log entries spanning 3 days
        for ($i = 1; $i <= 15; ++$i) {
            $dt = \DateTimeImmutable::createFromFormat('U', time() + $i * 5 * 60 * 60);
            $record = $this->getRecord(Level::Info, 'record' . $i, [], $dt);
            $handler->handle($record);
        }

        $handler->close();
    }

    private function getCUT(int $batchSize = 1000): CloudWatch
    {
        return new CloudWatch($this->clientMock, $this->groupName, $this->streamName, 14, $batchSize);
    }

    private function getRecord(
        Level $level,
        string $message = 'test',
        array $context = [],
        \DateTimeImmutable $dt = new \DateTimeImmutable()
    ): LogRecord {
        return new LogRecord(
            $dt,
            'test',
            $level,
            $message,
            $context
        );
    }

    private function getMultipleRecords(): array
    {
        return [
            $this->getRecord(Level::Debug, 'debug message 1'),
            $this->getRecord(Level::Debug, 'debug message 2'),
            $this->getRecord(Level::Info, 'information'),
            $this->getRecord(Level::Warning, 'warning'),
            $this->getRecord(Level::Error, 'error'),
        ];
    }
}
