<?php

namespace App\Service\Instagram;

enum InstagramPublisherFailure: string
{
    case InvalidConfiguration = 'invalid_configuration';
    case InvalidArgument = 'invalid_argument';
    case Transport = 'transport';
    case Http = 'http';
    case InvalidResponse = 'invalid_response';
    case ContainerError = 'container_error';
    case ContainerExpired = 'container_expired';
    case PollingTimeout = 'polling_timeout';
}
