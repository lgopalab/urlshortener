<?php

namespace UrlShortner\Exceptions;

use Slim\Exception\HttpSpecializedException;

class InvalidContentTypeException extends HttpSpecializedException
{
    /**
     * @var int
     */
    protected $code = 415;

    /**
     * @var string
     */
    protected $message = "Invalid Content Type";

    protected $title = "Invalid Content Type";
    protected $description = "Only JSON type is allowed";

    public const INVALID_CONTENT_TYPE = 'INVALID_CONTENT_TYPE';
}
