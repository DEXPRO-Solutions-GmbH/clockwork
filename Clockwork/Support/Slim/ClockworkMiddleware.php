<?php namespace Clockwork\Support\Slim;

use Clockwork\Clockwork;
use Clockwork\Authentication\NullAuthenticator;
use Clockwork\DataSource\PsrMessageDataSource;
use Clockwork\DataSource\XdebugDataSource;
use Clockwork\Storage\FileStorage;
use Clockwork\Helpers\ServerTiming;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

// Slim 4 middleware
class ClockworkMiddleware
{
	protected $app;
	protected $clockwork;
	protected $startTime;

	public function __construct($app, $storagePathOrClockwork, $startTime = null)
	{
		$this->app = $app;
		$this->clockwork = $storagePathOrClockwork instanceof Clockwork
			? $storagePathOrClockwork : $this->createDefaultClockwork($storagePathOrClockwork);
		$this->startTime = $startTime ?: microtime(true);
	}

	public function __invoke(Request $request, RequestHandler $handler)
	{
		return $this->process($request, $handler);
	}

	public function process(Request $request, RequestHandler $handler)
	{
		$authUri = '#/__clockwork/auth#';
		if (preg_match($authUri, $request->getUri()->getPath(), $matches)) {
			return $this->authenticate($request);
		}

		$clockworkDataUri = '#/__clockwork(?:/(?<id>[0-9-]+))?(?:/(?<direction>(?:previous|next)))?(?:/(?<count>\d+))?#';
		if (preg_match($clockworkDataUri, $request->getUri()->getPath(), $matches)) {
			$matches = array_merge([ 'id' => null, 'direction' => null, 'count' => null ], $matches);
			return $this->retrieveRequest($request, $matches['id'], $matches['direction'], $matches['count']);
		}

		$response = $handler->handle($request);

		return $this->logRequest($request, $response);
	}

	protected function authenticate(Request $request)
	{
		$token = $this->clockwork->authenticator()->attempt($request->getParsedBody());

		return $this->jsonResponse([ 'token' => $token ], $token ? 200 : 403);
	}

	protected function retrieveRequest(Request $request, $id, $direction, $count)
	{
		$authenticator = $this->clockwork->authenticator();
		$storage = $this->clockwork->storage();

		$authenticated = $authenticator->check(current($request->getHeader('X-Clockwork-Auth')));

		if ($authenticated !== true) {
			return $this->jsonResponse([ 'message' => $authenticated, 'requires' => $authenticator->requires() ], 403);
		}

		if ($direction == 'previous') {
			/** @var \Clockwork\Request\Request[] $data */
			$data = $storage->previous($id, $count);
			for ($i = 0; $i < count($data); $i++) {
				if (file_exists($data[$i]->xdebug['profile'])) {
					$data[$i]->xdebug['profileData'] = file_get_contents($data[$i]->xdebug['profile']);
				}
			}
		} elseif ($direction == 'next') {
			/** @var \Clockwork\Request\Request[] $data */
			$data = $storage->next($id, $count);
			for ($i = 0; $i < count($data); $i++) {
				if (file_exists($data[$i]->xdebug['profile'])) {
					$data[$i]->xdebug['profileData'] = file_get_contents($data[$i]->xdebug['profile']);
				}
			}
		} elseif ($id == 'latest' or $id === null) {
			/** @var \Clockwork\Request\Request $data */
			$data = $storage->latest();
			if (file_exists($data->xdebug['profile'])) {
				$data->xdebug['profileData'] = file_get_contents($data->xdebug['profile']);
			}
		} else {
			/** @var \Clockwork\Request\Request $data */
			$data = $storage->find($id);
			if (file_exists($data->xdebug['profile'])) {
				$data->xdebug['profileData'] = file_get_contents($data->xdebug['profile']);
			}
		}

		return $this->jsonResponse($data);
	}

	protected function logRequest(Request $request, $response)
	{
		$this->clockwork->timeline()->finalize($this->startTime);
		$this->clockwork->addDataSource(new PsrMessageDataSource($request, $response));
		$this->clockwork->addDataSource(new XdebugDataSource());

		$this->clockwork->resolveRequest();
		$this->clockwork->storeRequest();

		$clockworkRequest = $this->clockwork->request();

		$response = $response
			->withHeader('X-Clockwork-Id', $clockworkRequest->id)
			->withHeader('X-Clockwork-Version', Clockwork::VERSION);

		if ($basePath = $this->app->getBasePath()) {
			$response = $response->withHeader('X-Clockwork-Path', '/api/v2/__clockwork/');
		}

		return $response->withHeader('Server-Timing', ServerTiming::fromRequest($clockworkRequest)->value());
	}

	protected function createDefaultClockwork($storagePath)
	{
		$clockwork = new Clockwork();

		$clockwork->storage(new FileStorage($storagePath));
		$clockwork->authenticator(new NullAuthenticator);

		return $clockwork;
	}

	protected function jsonResponse($data, $status = 200)
	{
		$response = $this->app->getResponseFactory()
			->createResponse($status)
			->withHeader('Content-Type', 'application/json');

		$response->getBody()->write(json_encode($data));

		return $response;
	}
}
