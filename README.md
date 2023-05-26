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

## Frameworks integration
 - [Silex](http://silex.sensiolabs.org/doc/master/providers/monolog.html#customization)
 - [Symfony](http://symfony.com/doc/current/logging.html) ([Example](https://github.com/maxbanton/cwh/issues/10#issuecomment-296173601))
 - [Lumen](https://lumen.laravel.com/docs/5.2/errors)
 - [Laravel](https://laravel.com/docs/5.4/errors) ([Example](https://stackoverflow.com/a/51790656/1856778))

 [And many others](https://github.com/Seldaek/monolog#framework-integrations)

# AWS IAM needed permissions
If you prefer to use a separate programmatic IAM user (recommended) or want to define a policy, make sure following permissions are included:
1. `CreateLogGroup` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_CreateLogGroup.html)
1. `CreateLogStream` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_CreateLogStream.html)
1. `PutLogEvents` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html)
1. `PutRetentionPolicy` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutRetentionPolicy.html)
1. `DescribeLogStreams` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_DescribeLogStreams.html)
1. `DescribeLogGroups` [aws docs](https://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_DescribeLogGroups.html)

When setting the `$createGroup` argument to `false`, permissions `DescribeLogGroups` and `CreateLogGroup` can be omitted

## Sample 1: Write to any log stream in a log group
This policy example allows writing to any log stream in a log group (named `my-app`). The log streams will be created automatically.

*Note: The first statement allows creation of log groups, and is not required when setting the `$createGroup` argument to `false`.*

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogGroup",
                "logs:DescribeLogGroups"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogStream",
                "logs:DescribeLogStreams",
                "logs:PutRetentionPolicy",
                "logs:PutLogEvents"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:my-app:*"
        }
    ]
}
```

## Sample 2: Write to specific log streams in a log group
This policy example allows writing to specific log streams (named `my-stream-1` and `my-stream-2`) in a log group (named `my-app`). The log streams will be created automatically.

*Note: The first statement allows creation of log groups, and is not required when setting the `$createGroup` argument to `false`.*

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogGroup",
                "logs:DescribeLogGroups"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogStream",
                "logs:DescribeLogStreams",
                "logs:PutRetentionPolicy"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:my-app:*"
        },
        {
            "Effect": "Allow",
            "Action": [
                "logs:PutLogEvents"
            ],
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
