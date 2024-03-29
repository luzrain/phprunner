<?php

declare(strict_types=1);

namespace Luzrain\PHPStreamServer\Exception;

use Luzrain\PHPStreamServer\Internal\Functions;
use Luzrain\PHPStreamServer\Server\Http\ErrorPage;
use Luzrain\PHPStreamServer\Server\Http\Psr7\Response;
use Luzrain\PHPStreamServer\Server\Http\Psr7\StringStream;
use Psr\Http\Message\ResponseInterface;

final class HttpException extends \Exception
{
    private ResponseInterface $response;

    public function __construct(private int $httpCode = 500, private bool $closeConnection = false, private \Throwable|null $previous = null)
    {
        $this->response = new Response(code: $this->httpCode);

        parent::__construct($this->response->getReasonPhrase(), $this->httpCode, $this->previous);
    }

    public function getResponse(): ResponseInterface
    {
        $errorPage = (new ErrorPage(
            code: $this->httpCode,
            title: $this->response->getReasonPhrase(),
            exception: $this->previous !== null && Functions::reportErrors() ? $this->previous : null,
        ));

        if ($this->closeConnection) {
            $this->response = $this->response->withHeader('Connection', 'close');
        }

        return $this
            ->response
            ->withHeader('Content-Type', 'text/html')
            ->withBody(new StringStream($errorPage))
        ;
    }

    public static function createNotFoundException(): self
    {
        return new self(404);
    }
}
