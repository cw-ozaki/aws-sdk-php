<?php
namespace Aws\Common;

use Aws\Common\Exception\AwsException;
use Aws\Sdk;
use Aws\Common\Api\Service;
use Aws\Common\Credentials\CredentialsInterface;
use Aws\Common\Paginator\ResourceIterator;
use Aws\Common\Paginator\ResultPaginator;
use Aws\Common\Signature\SignatureInterface;
use Aws\Common\Waiter\ResourceWaiter;
use Aws\Common\Waiter\Waiter;
use GuzzleHttp\Command\AbstractClient;
use GuzzleHttp\Command\Command;
use GuzzleHttp\Command\CommandInterface;
use GuzzleHttp\Command\CommandTransaction;
use GuzzleHttp\Exception\RequestException;

/**
 * Default AWS client implementation
 */
class AwsClient extends AbstractClient implements AwsClientInterface
{
    /** @var CredentialsInterface AWS credentials */
    private $credentials;

    /** @var SignatureInterface Signature implementation of the service */
    private $signature;

    /** @var array Default command options */
    private $defaults;

    /** @var string */
    private $region;

    /** @var string */
    private $endpoint;

    /** @var Service */
    private $api;

    /** @var string */
    private $commandException;

    /** @var callable */
    private $errorParser;

    /** @var callable */
    private $serializer;

    /**
     * The AwsClient constructor requires the following constructor options:
     *
     * - api: The Api object used to interact with a web service
     * - credentials: CredentialsInterface object used when signing.
     * - client: {@see GuzzleHttp\Client} used to send requests.
     * - signature: string representing the signature version to use (e.g., v4)
     * - region: (optional) Region used to interact with the service
     * - error_parser: A callable that parses response exceptions
     * - exception_class: (optional) A specific exception class to throw that
     *   extends from {@see Aws\Common\Exception\AwsException}.
     * - serializer: callable used to serialize a request for a provided
     *   CommandTransaction argument. The callable must return a
     *   RequestInterface object.
     *
     * @param array $config Configuration options
     *
     * @throws \InvalidArgumentException if any required options are missing
     */
    public function __construct(array $config)
    {
        static $required = ['api', 'credentials', 'client', 'signature',
                            'error_parser', 'endpoint', 'serializer'];

        foreach ($required as $r) {
            if (!isset($config[$r])) {
                throw new \InvalidArgumentException("$r is a required option");
            }
        }

        $this->serializer = $config['serializer'];
        $this->api = $config['api'];
        $this->endpoint = $config['endpoint'];
        $this->credentials = $config['credentials'];
        $this->signature = $config['signature'];
        $this->errorParser = $config['error_parser'];
        $this->region = isset($config['region']) ? $config['region'] : null;
        $this->defaults = isset($config['defaults']) ? $config['defaults'] : [];
        $this->commandException = isset($config['exception_class'])
            ? $config['exception_class']
            : 'Aws\Common\Exception\AwsException';

        parent::__construct($config['client']);
    }

    /**
     * Creates a new client based on the provided configuration options.
     *
     * @param array $config Configuration options
     *
     * @return static
     */
    public static function factory(array $config = [])
    {
        // Convert SDKv2 configuration options to SDKv3 configuration options.
        (new Compat)->convertConfig($config);

        // Determine the service being called
        $class = get_called_class();
        $service = substr($class, strrpos($class, '\\') + 1, -6);

        // Create the client using the Sdk class
        return (new Sdk)->getClient($service, $config);
    }

    public function getCredentials()
    {
        return $this->credentials;
    }

    public function getSignature()
    {
        return $this->signature;
    }

    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function getRegion()
    {
        return $this->region;
    }

    public function getApi()
    {
        return $this->api;
    }

    /**
     * Executes an AWS command.
     *
     * @param CommandInterface $command Command to execute
     *
     * @return mixed Returns the result of the command
     * @throws AwsException when an error occurs during transfer
     */
    public function execute(CommandInterface $command)
    {
        try {
            return parent::execute($command);
        } catch (AwsException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Wrap other uncaught exceptions for consistency
            $exceptionClass = $this->commandException;
            throw new $exceptionClass(
                sprintf('Uncaught exception while executing %s::%s - %s',
                    get_class($this),
                    $command->getName(),
                    $e->getMessage()),
                new CommandTransaction($this, $command),
                $e
            );
        }
    }

    public function getCommand($name, array $args = [])
    {
        // Fail fast if the command cannot be found in the description.
        if (!isset($this->api['operations'][$name])) {
            $name = ucfirst($name);
            if (!isset($this->api['operations'][$name])) {
                throw new \InvalidArgumentException("Operation not found: $name");
            }
        }

        if (isset($args['@future'])) {
            $future = $args['@future'];
            unset($args['@future']);
        } else {
            $future = false;
        }

        return new Command($name, $args + $this->defaults, [
            'emitter' => clone $this->getEmitter(),
            'future' => $future
        ]);
    }

    public function getIterator($name, array $args = [], array $config = [])
    {
        $config += $this->api->getPaginatorConfig($name);

        if ($config['result_key']) {
            return new ResourceIterator(
                new ResultPaginator($this, $name, $args, $config),
                $config
            );
        }

        throw new \UnexpectedValueException("There are no resources to iterate "
            . "for the {$name} operation of {$this->api['serviceFullName']}.");
    }

    public function getPaginator($name, array $args = [], array $config = [])
    {
        $config += $this->api->getPaginatorConfig($name);
        if ($config['output_token'] && $config['input_token']) {
            return new ResultPaginator($this, $name, $args, $config);
        }

        throw new \UnexpectedValueException("Results for the {$name} operation "
            . "of {$this->api['serviceFullName']} cannot be paginated.");
    }

    public function getWaiter($name, array $args = [], array $config = [])
    {
        $config += $this->api->getWaiterConfig($name);

        return new ResourceWaiter($this, $name, $args, $config);
    }

    public function waitUntil($name, array $args = [], array $config = [])
    {
        $waiter = is_callable($name)
            ? new Waiter($name, $config + $args)
            : $this->getWaiter($name, $args, $config);

        $waiter->wait();
    }

    /**
     * Creates AWS specific exceptions.
     *
     * {@inheritdoc}
     *
     * @return AwsException
     */
    public function createCommandException(CommandTransaction $transaction)
    {
        // Throw AWS exceptions as-is
        if ($transaction->exception instanceof AwsException) {
            return $transaction->exception;
        }

        $exceptionClass = $this->commandException;

        if ($transaction->exception instanceof RequestException) {
            $url = $transaction->exception->getRequest()->getUrl();
            $parser = $this->errorParser;
            // Add the parsed response error to the exception.
            $transaction->context['aws_error'] = $parser(
                $transaction->exception->getResponse()
            );
            // Only use the AWS error code if the parser could parse resposne.
            if (empty($transaction->context['aws_error']['type'])) {
                $serviceError = $transaction->exception->getMessage();
            } else {
                // Create an easy to read error message.
                $serviceError = trim($transaction->context->getPath('aws_error/code')
                    . ' (' . $transaction->context->getPath('aws_error/type')
                    . ' error): ' . $transaction->context->getPath('aws_error/message'));
            }
        } else {
            $url = null;
            $transaction->context->set('aws_error', []);
            $serviceError = $transaction->exception->getMessage();
        }

        return new $exceptionClass(
            sprintf('Error executing %s::%s() on "%s"; %s',
                get_class($this),
                lcfirst($transaction->command->getName()),
                $url,
                $serviceError),
            $transaction,
            $transaction->exception
        );
    }

    protected function createFutureResult(CommandTransaction $transaction)
    {
        return new FutureResult(
            // Deref function derefs the response which populates the result.
            function () use ($transaction) {
                $transaction->response = $transaction->response->deref();
                return $transaction->result;
            },
            // Cancel function just proxies to the response's cancel function.
            function () use ($transaction) {
                return $transaction->response->cancel();
            }
        );
    }

    protected function serializeRequest(CommandTransaction $trans)
    {
        $fn = $this->serializer;
        return $fn($trans);
    }
}