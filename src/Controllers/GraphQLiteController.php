<?php


namespace TheCodingMachine\GraphQLite\Laravel\Controllers;


use Illuminate\Http\Request;
use GraphQL\Error\Debug;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Server\StandardServer;
use GraphQL\Upload\UploadMiddleware;
use function json_decode;
use function json_last_error;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;


class GraphQLiteController
{
    /**
     * @var HttpMessageFactoryInterface
     */
    private $httpMessageFactory;
    /** @var StandardServer */
    private $standardServer;
    /** @var bool|int */
    private $debug;

    public function __construct(StandardServer $standardServer, HttpMessageFactoryInterface $httpMessageFactory = null, ?int $debug = Debug::RETHROW_UNSAFE_EXCEPTIONS)
    {
        $this->standardServer = $standardServer;
        $this->httpMessageFactory = $httpMessageFactory ?: new DiactorosFactory();
        $this->debug = $debug === null ? false : $debug;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function index(Request $request): JsonResponse
    {
        $psr7Request = $this->httpMessageFactory->createRequest($request);

        if (strtoupper($request->getMethod()) === "POST" && empty($psr7Request->getParsedBody())) {
            $content = $psr7Request->getBody()->getContents();
            $parsedBody = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON received in POST body: '.json_last_error_msg());
            }
            $psr7Request = $psr7Request->withParsedBody($parsedBody);
        }

        // Let's parse the request and adapt it for file uploads.
        $uploadMiddleware = new UploadMiddleware();
        $psr7Request = $uploadMiddleware->processRequest($psr7Request);

        $result = $this->handlePsr7Request($psr7Request);

        $response = new JsonResponse($result);

        return $response;
    }

    private function handlePsr7Request(ServerRequestInterface $request): array
    {
        $result = $this->standardServer->executePsrRequest($request);

        if ($result instanceof ExecutionResult) {
            return $result->toArray($this->debug);
        }
        if (is_array($result)) {
            return array_map(function (ExecutionResult $executionResult) {
                return $executionResult->toArray($this->debug);
            }, $result);
        }
        if ($result instanceof Promise) {
            throw new RuntimeException('Only SyncPromiseAdapter is supported');
        }
        throw new RuntimeException('Unexpected response from StandardServer::executePsrRequest'); // @codeCoverageIgnore
    }
}
