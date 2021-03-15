<?php

namespace UrlShortner\Exceptions;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpSpecializedException;

class CustomException extends HttpSpecializedException
{

    /**
     * @var int
     */
    protected $code = 500;

    /**
     * @var string
     */
    protected $message = null;

    protected $title = "SERVER_ERROR";
    protected $description = "An internal error has occurred while processing your request.";
    protected $type = "SERVER_ERROR";

    /**
     * @param ServerRequestInterface $request
     * @param string|null $message
     * @param string|null $description
     * @param int|null $code
     * @param string|null $title
     * @param string|null $type
     * @param Throwable|null $previous
     */
    public function __construct(ServerRequestInterface $request, ?string $message = null, ?string $description = null, ?int $code = null, ?string $title = null, ?string $type = null, ?Throwable $previous = null)
    {
        if ($message !== null) {
            $this->message = $message;
        }
        if ($description !== null) {
            $this->description = $description;
        }

        if ($code !== null) {
            $this->code = $code;
        }

        if ($title !== null) {
            $this->title = $message;
        }

        if ($type !== null) {
            $this->type = $type;
        }

        parent::__construct($request, $this->message, $previous);
    }

    /**
     * @param integer $code
     * @return self
     */
    public function setCode(int $code): CustomException
    {
        $this->code = $code;
        return $this;
    }

    /**
     * @param string $type
     * @return self
     */
    public function setType(string $type): CustomException
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @param string $message
     * @return self
     */
    public function setMessage(string $message): CustomException
    {
        $this->message = $message;
        return $this;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

}
