<?php

namespace Open\Api;

use Predis\Client;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class OpenClient - SDK Open API
 * @package Open\Api
 */
final class OpenClient
{
	/**
	 * Предоставляет гибкие методы для синхронного или асинхронного запроса ресурсов HTTP.
	 * @var HttpClientInterface|null
	 */
	private ?HttpClientInterface $client;

	/**
	 * Url аккаунта Open API
	 * @var string
	 */
	private string $account;

	/**
	 * Токен аккаунта
	 * @var string|null
	 */
	private ?string $token;

	/**
	 * app_id интеграции
	 * @var mixed $appID
	 */
	private $appID;

	/**
	 * Secret key интеграции
	 * @var false|string $secret
	 */
	private $secret;

	/**
	 * Является уникальным идентификатором команды
	 * @var false|string $nonce
	 */
	private $nonce;

	/**
	 * SymfonyHttpClient constructor.
	 * @param string $account - url аккаунта
	 * @param string|null $appID - app_id интеграции
	 * @param string|null $secret - Secret key интеграции
	 * @param HttpClientInterface|null $client - Symfony http клиент
	 * @throws ClientExceptionInterface
	 * @throws DecodingExceptionInterface
	 * @throws RedirectionExceptionInterface
	 * @throws ServerExceptionInterface
	 * @throws TransportExceptionInterface
	 * @throws \JsonException
	 */
	public function __construct(string $account, string $appID, string $secret, HttpClientInterface $client = null)
	{
		$this->appID = $appID;
		$this->secret = $secret;
		$this->nonce = "nonce_" . str_replace(".", "", microtime(true));
		#Подключение к redis
		$cacheRedis = RedisAdapter::createConnection(
			getenv('REDIS_DSN'),
			['class' => Client::class, 'timeout' => 3]
		);
		#Получаем ссылку от аккаунта
		$this->account = $account;
		# HttpClient - выбирает транспорт cURL если расширение PHP cURL включено,
		# и возвращается к потокам PHP в противном случае
		# Добавляем в header токен из cache
		$this->client = $client ?? HttpClient::create(
				[
					'http_version' => '2.0',
					'headers' => [
						'Content-Type' => 'application/json'
					]
				]
			);
		#Проверяем, есть токен в cache или нет
		if ($cacheRedis->exists('OpenTokenCache') === 0) {
			#Добавляем токен в cache на 10 часов
			$this->token = $cacheRedis->setex('OpenTokenCache', 83000, $this->getNewToken());
		} else {
			#Получаем текущий токен
			$this->token = $cacheRedis->get('OpenTokenCache');
		}
	}

	/**
	 * Метод позволяет выполнить запрос к Open API
	 * @param string $method - Метод
	 * @param string $model - Модель
	 * @param array $params - Параметры
	 * @return array|string
	 * @throws \JsonException
	 * @throws ClientExceptionInterface
	 * @throws DecodingExceptionInterface
	 * @throws RedirectionExceptionInterface
	 * @throws ServerExceptionInterface
	 * @throws TransportExceptionInterface
	 */
	public function request(string $method, string $model, array $params = [])
	{
		#Создаем ссылку
		$url = $this->account . $model;
		#Отправляем request запрос
		$response = $this->client->request(
			strtoupper($method),
			$url,
			[
				'headers' => [
					'sign' => $this->getSign($params)
				],
				'body' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
			]
		);
		#Получаем статус запроса
		$statusCode = $response->getStatusCode();
		if ($statusCode === 200) {
			return json_decode(
				$response->getContent(false),
				true,
				512,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
			);
		}
		#false - убрать throw от Symfony.....
		return $response->toArray(false);
	}

	/**
	 * Метод выполняет запрос на получение информации о состоянии системы.
	 * @return array - Возвращаем ответ о состоянии системы
	 * @throws \JsonException
	 * @throws ClientExceptionInterface
	 * @throws DecodingExceptionInterface
	 * @throws RedirectionExceptionInterface
	 * @throws ServerExceptionInterface
	 * @throws TransportExceptionInterface
	 */
	public function getStateSystem(): array
	{
		return $this->request(
			"GET",
			"StateSystem",
			[
				"app_id" => $this->appID,
				"nonce" => $this->nonce,
				"token" => $this->token,
			]
		);
	}

	/**
	 * Метод отправляет запрос на открытие смены на ККТ.
	 * @param string $commandName - Кастомное наименование для command
	 * @return array Возвращает ответ открытия смены на ККТ
	 * @throws \JsonException
	 * @throws ClientExceptionInterface
	 * @throws DecodingExceptionInterface
	 * @throws RedirectionExceptionInterface
	 * @throws ServerExceptionInterface
	 * @throws TransportExceptionInterface
	 */
	public function openShift(string $commandName = "name"): array
	{
		return $this->request(
			"POST",
			"Command",
			[
				"app_id" => $this->appID,
				"command" => [
					"report_type" => "false",
					"author" => $commandName
				],
				"nonce" => $this->nonce,
				"token" => $this->token,
				"type" => "openShift"
			]

		);
	}

	/**
	 * Метод отправляет запрос на закрытие смены на ККТ.
	 * @param string $commandName - Кастомное наименование для command
	 * @return array Возвращает ответ закрытия смены на ККТ
	 * @throws \JsonException
	 * @throws ClientExceptionInterface
	 * @throws DecodingExceptionInterface
	 * @throws RedirectionExceptionInterface
	 * @throws ServerExceptionInterface
	 * @throws TransportExceptionInterface
	 */
	public function closeShift(string $commandName = "name"): array
	{
		return $this->request(
			"POST",
			"Command",
			[
				"app_id" => $this->appID,
				"command" => [
					"report_type" => "false",
					"author" => $commandName
				],
				"nonce" => $this->nonce,
				"token" => $this->token,
				"type" => "closeShift"
			]
		);
	}

	/**
	 * Метод выполняет запрос на печать чека прихода на ККТ.
	 * @param array $command Массив параметров чека.
	 * @return array Возвращает command_id
	 * @throws \JsonException
	 * @throws ClientExceptionInterface
	 * @throws DecodingExceptionInterface
	 * @throws RedirectionExceptionInterface
	 * @throws ServerExceptionInterface
	 * @throws TransportExceptionInterface
	 */
	public function printCheck(array $command): array
	{
		return $this->request(
			"POST",
			"Command",
			[
				"app_id" => $this->appID,
				"command" => $command,
				"nonce" => $this->nonce,
				"token" => $this->token,
				"type" => "printCheck"
			]

		);
	}

	/**
	 * Метод выполняет запрос на печать чека возврата прихода на ККТ.
	 * @param array $command Массив параметров чека.
	 * @return array Возвращает command_id
	 * @throws \JsonException
	 * @throws ClientExceptionInterface
	 * @throws DecodingExceptionInterface
	 * @throws RedirectionExceptionInterface
	 * @throws ServerExceptionInterface
	 * @throws TransportExceptionInterface
	 */
	public function printPurchaseReturn(array $command): array
	{
		return $this->request(
			"POST",
			"Command",
			[
				"app_id" => $this->appID,
				"command" => $command,
				"nonce" => $this->nonce,
				"token" => $this->token,
				"type" => "printPurchaseReturn"
			]
		);
	}

	/**
	 * Метод выполняет запрос на печать чека возврата прихода на ККТ.
	 * @param string $commandID - CommandID чека.
	 * @return array Возвращает данные по command_id
	 * @throws \JsonException
	 * @throws ClientExceptionInterface
	 * @throws DecodingExceptionInterface
	 * @throws RedirectionExceptionInterface
	 * @throws ServerExceptionInterface
	 * @throws TransportExceptionInterface
	 */
	public function dataCommandID(string $commandID): array
	{
		return $this->request(
			"GET",
			"Command/$commandID",
			[
				"nonce" => "nonce_" . str_replace(".", "", microtime(true)),
				"token" => $this->token,
				"app_id" => $this->appID
			]
		);
	}

	/**
	 * Получаем токен
	 * @return string
	 * @throws ClientExceptionInterface
	 * @throws DecodingExceptionInterface
	 * @throws RedirectionExceptionInterface
	 * @throws ServerExceptionInterface
	 * @throws TransportExceptionInterface
	 * @throws \JsonException
	 */
	private function getNewToken(): string
	{
		#Получаем новый токен
		$this->token = $this->request(
			"GET",
			"Token",
			[
				"app_id" => $this->appID,
				"nonce" => $this->nonce
			]
		)["token"];
		return $this->token;
	}

	/**
	 * Метод генерирует подпись запроса и возвращает подпись.
	 * @param array<array> $params Параметры запроса для генерации на основе их подписи.
	 * Не добавлять в json_encode -  JSON_PRETTY_PRINT
	 * @return string Подпись запроса.
	 * @throws \JsonException
	 */
	private function getSign(array $params): string
	{
		ksort($params);
		return md5(
			json_encode(
				$params,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
			) . $this->secret
		);
	}
}
