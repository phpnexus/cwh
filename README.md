[![Stand With Ukraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://vshymanskyy.github.io/StandWithUkraine)

# AWS CloudWatch Logs Handler for Monolog

[![Actions Status](https://github.com/phpnexus/cwh/workflows/Pipeline/badge.svg)](https://github.com/phpnexus/cwh/actions)
[![Coverage Status](https://img.shields.io/coveralls/phpnexus/cwh/master.svg)](https://coveralls.io/github/phpnexus/cwh?branch=master)
[![License](https://img.shields.io/packagist/l/phpnexus/cwh.svg)](https://github.com/phpnexus/cwh/blob/master/LICENSE)
[![Version](https://img.shields.io/packagist/v/phpnexus/cwh.svg)](https://packagist.org/packages/phpnexus/cwh)
[![Downloads](https://img.shields.io/packagist/dt/phpnexus/cwh.svg)](https://packagist.org/packages/phpnexus/cwh/stats)

***This is a fork and continuation of the original [maxbanton/cwh](https://github.com/maxbanton/cwh) repository.***

Handler for PHP logging library [Monolog](https://github.com/Seldaek/monolog) for sending log entries to
[AWS CloudWatch Logs](http://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/WhatIsCloudWatchLogs.html) service.

Before using this library, it's recommended to get acquainted with the [pricing](https://aws.amazon.com/en/cloudwatch/pricing/) for AWS CloudWatch services.

Please press **&#9733; Star** button if you find this library useful.

## Disclaimer
This library uses AWS API through AWS PHP SDK, which has limits on concurrent requests. It means that on high concurrent or high load applications it may not work on it's best way. Please consider using another solution such as logging to the stdout and redirecting logs with fluentd.

## Requirements
* PHP >=8.1
* AWS account with proper permissions (see list of permissions below)

## Features
* Up to 10000 batch logs sending in order to avoid _Rate exceeded_ errors
* Log Groups creating with tags
* AWS CloudWatch Logs staff lazy loading
* Suitable for web applications and for long-living CLI daemons and workers
* **New!** Configurable rate limit, useful with small batch sizes on long-living CLI daemons and workers

## Installation
Install the latest version with [Composer](https://getcomposer.org/) by running

```bash
$ composer require phpnexus/cwh:^3.0
```

## Basic Usage
```php
<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Formatter\JsonFormatter;
use PhpNexus\Cwh\Handler\CloudWatch;

$sdkParams = [
    'region' => 'eu-west-1',
    'version' => 'latest',
    'credentials' => [
        'key' => 'your AWS key',
        'secret' => 'your AWS secret',
        'token' => 'your AWS session token', // token is optional
    ]
];

// Instantiate AWS SDK CloudWatch Logs Client
$client = new CloudWatchLogsClient($sdkParams);

// Log group name, will be created if none
$groupName = 'php-logtest';

// Log stream name, will be created if none
$streamName = 'ec2-instance-1';

// Days to keep logs, 14 by default. Set to `null` to allow indefinite retention.
$retentionDays = 30;

// Instantiate handler (tags are optional)
$handler = new CloudWatch($client, $groupName, $streamName, $retentionDays, 10000, ['my-awesome-tag' => 'tag-value'], Level::Info);

// Optionally set the JsonFormatter to be able to access your log messages in a structured way
$handler->setFormatter(new JsonFormatter());

// Create a log channel
$log = new Logger('name');

// Set handler
$log->pushHandler($handler);

// Add records to the log
$log->debug('Foo');
$log->warning('Bar');
$log->error('Baz');
```

## Advanced Usage
### Prevent automatic creation of log groups and streams
The default behavior is to check if the destination log group and log stream exists and create the log group and log stream if necessary.

This activity always sends a `DescribeLogGroups` and `DescribeLogStreams` API call to AWS, and will send a `CreateLogGroup` API call or `CreateLogStream` API call to AWS if the log group or log stream doesn't exist.

AWS have a default quota of [5 requests per second](https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html) for both `DescribeLogGroups` and `DescribeLogStreams` per region per account, which will become a bottleneck even in medium traffic environments.

By setting `$createGroup` and `$createStream` to `false`, this library will not automatically create the destination log group or log stream, and hence will not send any `DescribeLogGroups` or `DescribeLogStreams` API calls to AWS.

```php
<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Formatter\JsonFormatter;
use PhpNexus\Cwh\Handler\CloudWatch;

$sdkParams = [
    'region' => 'ap-northeast-1',
    'version' => 'latest',
    'credentials' => [
        'key' => 'your AWS key',
        'secret' => 'your AWS secret',
        'token' => 'your AWS session token', // token is optional
    ]
];

// Instantiate AWS SDK CloudWatch Logs Client
$client = new CloudWatchLogsClient($sdkParams);

// Log group name (must exist already)
$groupName = 'php-logtest';

// Log stream name (must exist already)
$streamName = 'ec2-instance-1';

// Instantiate handler
$handler = new CloudWatch($client, $groupName, $streamName, level: Level::Info, createGroup: false, createStream: false);

// Optionally set the JsonFormatter to be able to access your log messages in a structured way
$handler->setFormatter(new JsonFormatter());

// Create a log channel
$log = new Logger('name');

// Set handler
$log->pushHandler($handler);

// Add records to the log
$log->debug('Foo');
$log->warning('Bar');
$log->error('Baz');
```

### **New!** Rate limiting
The default behavior is to send logs in batches of 10000, or when the script terminates. This is appropriate for short-lived requests, but not for long-lived CLI daemons and workers.

For these cases, a smaller `$batchSize` of 1 would be more appropriate. However, with a smaller batch size the number of `putLogEvents` requests to AWS will increase and may reach the [per account per region limit](https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html).

To help avoid this rate limit, use the `$rpsLimit` option to limit the number of requests per second that your CLI daemon or worker can send.

*Note: This limit is only applicable for one instance of a CLI daemon or worker. With multiple instances, adjust the `$rpsLimit` accordingly.*

```php
<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Formatter\JsonFormatter;
use PhpNexus\Cwh\Handler\CloudWatch;

$sdkParams = [
    'region' => 'ap-northeast-1',
    'version' => 'latest',
    'credentials' => [
        'key' => 'your AWS key',
        'secret' => 'your AWS secret',
        'token' => 'your AWS session token', // token is optional
    ]
];

// Instantiate AWS SDK CloudWatch Logs Client
$client = new CloudWatchLogsClient($sdkParams);

// Log group name, will be created if none
$groupName = 'php-logtest';

// Log stream name, will be created if none
$streamName = 'cli-worker';

// Instantiate handler
$handler = new CloudWatch($client, $groupName, $streamName, batchSize: 1, level: Level::Info, rpsLimit: 100);

// Optionally set the JsonFormatter to be able to access your log messages in a structured way
$handler->setFormatter(new JsonFormatter());

// Create a log channel
$log = new Logger('name');

// Set handler
$log->pushHandler($handler);

// Add lots of records to the log very quickly
$i = 0;
do {
    $log->info('Foo');
} while ($i++ < 500);
```

## Frameworks integration
 - [Silex](http://silex.sensiolabs.org/doc/master/providers/monolog.html#customization)
 - [Symfony](http://symfony.com/doc/current/logging.html) ([Example](https://github.com/maxbanton/cwh/issues/10#issuecomment-296173601))
 - [Lumen](https://lumen.laravel.com/docs/5.2/errors)
 - [Laravel](https://laravel.com/docs/5.4/errors) ([Example](https://stackoverflow.com/a/51790656/1856778))

 [And many others](https://github.com/Seldaek/monolog#framework-integrations)

## AWS IAM needed permissions
If you prefer to use a separate programmatic IAM user (recommended) or want to define a policy, you will need the following permissions depending on your configuration.

Always required:
1. `PutLogEvents` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html)

If `$createGroup` is `true` (default):
1. `DescribeLogGroups` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_DescribeLogGroups.html)
1. `CreateLogGroup` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_CreateLogGroup.html)
1. `PutRetentionPolicy` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutRetentionPolicy.html)

If `$createStream` is `true` (default):
1. `CreateLogStream` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_CreateLogStream.html)
1. `DescribeLogStreams` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_DescribeLogStreams.html)

*Note: The below samples include permissions to create log groups and streams. Remove the "AllowCreateLogGroup" statement when setting the `$createGroup` argument to `false`. Remove the "AllowCreateLogStream" statement when setting the `$createStream` argument to `false`.*

### Sample 1: Write to any log stream in any log group
This policy example allows writing to any log stream in a log group (named `my-app`). The log streams will be created automatically.

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "AllowCreateLogGroup",
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogGroup",
                "logs:DescribeLogGroups",
                "logs:PutRetentionPolicy"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:*"
        },
        {
            "Sid": "AllowCreateLogStream",
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogStream",
                "logs:DescribeLogStreams"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:*:*"
        },
        {
            "Sid": "AllowPutLogEvents",
            "Effect": "Allow",
            "Action": "logs:PutLogEvents",
            "Resource": "arn:aws:logs:*:*:log-group:*:*"
        }
    ]
}
```

### Sample 2: Write to any log stream in a log group
This policy example allows writing to any log stream in a log group (named `my-app`). The log streams will be created automatically.

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "AllowCreateLogGroup",
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogGroup",
                "logs:DescribeLogGroups",
                "logs:PutRetentionPolicy"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:*"
        },
        {
            "Sid": "AllowCreateLogStream",
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogStream",
                "logs:DescribeLogStreams"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:my-app:*"
        },
        {
            "Sid": "AllowPutLogEvents",
            "Effect": "Allow",
            "Action": "logs:PutLogEvents",
            "Resource": "arn:aws:logs:*:*:log-group:my-app:*"
        }
    ]
}
```

### Sample 3: Write to specific log streams in a log group
This policy example allows writing to specific log streams (named `my-stream-1` and `my-stream-2`) in a log group (named `my-app`).

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "AllowCreateLogGroup",
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogGroup",
                "logs:DescribeLogGroups",
                "logs:PutRetentionPolicy"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:*"
        },
        {
            "Sid": "AllowCreateLogStream",
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogStream",
                "logs:DescribeLogStreams"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:my-app:*"
        },
        {
            "Sid": "AllowPutLogEvents",
            "Effect": "Allow",
            "Action": "logs:PutLogEvents",
            "Resource": [
                "arn:aws:logs:*:*:log-group:my-app:log-stream:my-stream-1",
                "arn:aws:logs:*:*:log-group:my-app:log-stream:my-stream-2",
            ]
        }
    ]
}
```

Reference: [Actions, resources, and condition keys for Amazon CloudWatch Logs](https://docs.aws.amazon.com/service-authorization/latest/reference/list_amazoncloudwatchlogs.html)

## Issues
Feel free to [report any issues](https://github.com/phpnexus/cwh/issues/new)

## Contributing
Please check [this document](https://github.com/phpnexus/cwh/blob/master/CONTRIBUTING.md)

___

Made in Ukraine 🇺🇦
