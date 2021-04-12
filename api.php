<?php 
   /**
   * Narodmon API
   *
   * Клиент API narodmon
   *
   */
namespace narodmon;

class api {	
	
	//язык приложения ISO 639-1 
	public $app_lang = 'ru';
	
	//домен сайта
	private $domain;
	
	//адрес API
	private $api_url = 'http://narodmon.ru/api';
	
	//выдерживать ли паузу между запросами
	private $wait = true;
	
	//таймер отсчета времени между запросами
	private $timer;
	
	//устройства
	public $devices = array();
	
	//вебкамеры
	public $webcams = array();
	
	//сенсоры
	public $sensors = array();	
	
	//текущая широта
	public $lat = NULL;
	
	//текущая долгота
	public $lon = NULL;
	
	//текущий адрес
	public $addr = NULL;
	
	//справочник типов датчиков
	public $types = array();
	
	//ошибки
	public static $errors = array();

	
	public function __construct($key,$name=NULL) {	
		$this->domain =  $_SERVER['HTTP_HOST'];	
		//базовый запрос к серверу
		$this->base = [
			'uuid'=>$this->uuid(),
			'api_key'=>$key,
			'lang' => $this->app_lang,
		];
	}
	
	// проверка актуальности версии приложения при первом запуске и раз в сутки, проверка авторизации пользователя, его местонахождения, избранного и справочник типов датчиков.
	//Параметры запроса:
	//- version версия приложения пользователя для контроля, например: 1.1;
	//- platform версия платформы(ОС), например 6.0.1;
	public function appInit($version=1,$platform=1) {
		$request = array_merge(
			['cmd'=>'appInit','version'=>$version,'platform'=>$platform ],$this->base
		);	
		$data = $this->get($request);	
		$this->lat = (isset($data['lat'])) ? $data['lat'] : NULL;
		$this->lon = (isset($data['lon'])) ? $data['lon'] : NULL;
		$this->lon = (isset($data['addr'])) ? $data['addr'] : NULL;
		$this->types = (isset($data['types'])) ? $data['types'] : [];
		return $this;
	}
	
	//  запрос списка датчиков и веб-камер в указанной прямоугольной области карты	
	// bounds массив координат углов области просмотра {широта-мин, долгота-мин, широта-макс, долгота-макс} в десятичном виде
	public function mapBounds($bounds,$limit=20) {
		$request = array_merge(
			['cmd'=>'mapBounds','bounds'=>$bounds,'limit'=>$limit ],$this->base
		);	
		$data = $this->get($request);	
		if (isset($data['devices'])) $this->devices = array_merge($this->devices,$data['devices']);
		if (isset($data['webcams'])) $this->webcams = array_merge($this->webcams,$data['webcams']);
		return $this;
	}
	
	//  запрос списка ближайших к пользователю датчиков + свои + избранные	
	// Параметры запроса:
	//lat, lon широта и долгота нового местонахождения пользователя в десятичном виде;
	//my опционально, если = 1, то вывод датчиков только со своих приборов (требуется авторизация);
	//pub опционально, если = 1, то вывод только публичных датчиков;
	//radius опционально максимальное удаление от пользователя до датчиков в км, максимум ~111км (1°);
	//limit опционально максимальное кол-во ближайших публичных приборов мониторинга в ответе сервера, по умолчанию 20, максимум 50;
	public function sensorsNearby($radius, $lat=NULL,$lon=NULL,$my=0,$pub=1,$limit=30) {
		$coords = array();		
		if (!empty($lat)) {
			$coords = ['lat'=>$lat,'lon'=>$lon];
		} else {
			if (!empty($this->lat) and !empty($this->lon)) $coords = ['lat'=>$this->lat,'lon'=>$this->lon];
		}
		$request = array_merge(
			['cmd'=>'sensorsNearby','my'=>$my,'pub'=>$pub,'limit'=>$limit],$this->base, $coords
		);	
		$data = $this->get($request);	
		if (isset($data['devices'])) $this->devices = array_merge($this->devices,$data['devices']);		
		return $this;
	}
	
	// запрос списка датчиков и их показаний по ID прибора	
	// Параметры запроса:
	//id ID прибора из ссылки вида https://narodmon.ru/id в балуне на карте;
	//trends = 1, необязательный параметр включающий расчет тендеции показаний;
	//info = 1, необязательный параметр включает вывод полного описания прибора.
	public function sensorsOnDevice($id,$trends=1,$info=1) {		
		$request = array_merge(
			['cmd'=>'sensorsOnDevice','id'=>$id,'trends'=>$trends,'info'=>$info],$this->base
		);	
		$data = $this->get($request);	
		if (isset($data)) $this->devices = array_merge($this->devices,[$data['id']=>$data]);		
		return $this;
	}
	
	// регулярное обновление показаний выбранных датчиков	
	// Параметры запроса:
	//- sensors массив кодов датчиков для запроса показаний, max 50 датчиков;
	//- trends = 1, необязательный параметр включающий расчет тендеции показаний.
	public function sensorsValues($sensors,$trends=1) {		
		$request = array_merge(
			['cmd'=>'sensorsValues','trends'=>$trends],$this->base
		);	
		$data = $this->get($request);	
		if (isset($data['sensors'])) $this->sensors = array_merge($this->sensors,$data['sensors']);		
		return $this;
	}
	
	// история показаний датчика за период (для графиков и тенденций)	
	// Параметры запроса:
	//- id код датчика для запроса истории показаний;
	//- period название периода показаний: 'hour','day','week','month','year';
	//- offset смещение по выбранному периоду в прошлое, т.е. 1(day) = вчера, 1(month) = прошл.месяц.
	public function sensorsHistory($id,$period='day',$offset=0) {		
		$request = array_merge(
			['cmd'=>'sensorsHistory','id'=>$id,'period'=>$period,'offset'=>$offset],$this->base
		);	
		$data = $this->get($request);	
		if (isset($data['sensors'])) $this->sensors = array_merge($this->sensors,$data['sensors']);		
		return $this;
	}
	
	// запрос текущего и установка нового местонахождение пользователя (точки отсчета)	
	// Параметры запроса:
	//-- lat, lon опционально широта и долгота местонахождения пользователя;
	// gui флаг указывающий на необходимость получения полного адреса.
	public function userLocation($lat=NULL,$lon=NULL,$gui=1) {	
		$coords = array();
		if (!empty($lat)) $coords = ['lat'=>$lat,'lon'=>$lon];	
		$request = array_merge(
			['cmd'=>'userLocation','gui'=>$gui],$this->base, $coords
		);	
		$data = $this->get($request);	
		if (!empty($data)) {
			$this->lat = $data['lat'];
			$this->lon = $data['lon'];
			$this->addr = $data['addr'];
		} 
		return $this;
	}
	
	protected function get($request) {
		try {
			$response = $this->get_curl($request,true); 
			$data = $this->decode($response->reply);
		} catch (\Exception $e) {		
			return NULL;
		}
		return $data;
	}
	
	protected function get_curl($request, $callDetails = false) {
		$this->check_timer(); //проверка минутной выдержки между запросами
		//коды ошибок API
		$response_errors = [
			'400' => 'ошибка синтаксиса в запросе к API',
			'401' => 'требуется авторизация',
			'403' => 'в доступе к объекту отказано',
			'404' => 'искомый объект не найден',
			'423' => 'ключ API заблокирован администратором',
			'429' => 'более 1 запроса в минуту',
			'434' => 'искомый объект отключен',
			'503' => 'сервер временно не обрабатывает запросы по техническим причинам',
		];
		$return = new \STDClass;
		header('Content-Type: text/html; charset=utf-8');		
		$ch = curl_init($this->api_url);
		if(!$ch) {
			throw new \Exception('Ошибка инициализации cURL');
			self::$errors[] = 'Ошибка инициализации cURL';
			return NULL;
		}
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->domain);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
		$return->reply = curl_exec($ch);
		if ($callDetails) {
			$return->details['info'] = curl_getinfo($ch);
			$return->details['errno'] = curl_errno($ch);
			$return->details['error'] = curl_error($ch);
			$return->details['http'] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		}
		curl_close($ch);
		if (isset($response_errors[$return->details['http']])) {
			throw new \Exception($response_errors[$return->details['http']]);
			self::$errors[] = $response_errors[$return->details['http']];
		}
		if(empty($return->reply)) {
			throw new \Exception('Ошибка сети');
			self::$errors[] = 'Ошибка сети';
			return NULL;
		}
		return $return;
	}
	
	protected function decode($reply) {
		$data = json_decode($reply, true);
		if(empty($data) or !is_array($data)) {
			throw new \Exception('Ошибка в данных');
			self::$errors[] = 'Ошибка в данных';
			return NULL; 
		}
		if(isset($data['error'])) {
			throw new \Exception($data['error']);
			self::$errors[] = $data['error'];
			return NULL; 
		} 
		return $data;
	}
	
	private function uuid() {
		return md5($this->domain);
	}
	
	private function check_timer() {		
		if ($this->wait !== true) return true;		
		$now = microtime(true);
		if (!empty($this->timer)) {
			$time = $now - $this->timer;	
			if ($time>65) {
				$this->timer = $now;
				return true;
			} else {
				sleep(65-$time);
				$this->timer = $now;
				return false;
			}
		} else {
			$this->timer = $now;
			return true;
		}
	}	

}

?>
