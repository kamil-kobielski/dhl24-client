<?php
namespace DHL_Courier;

/**
 * @author		Piotr Filipek <pfilipek@media4u.pl>
 * @version		0.1.3
 * @since		03.08.2017
 * @copyright	(c) 2017, Media4U Sp. z o.o.
 * @license		All right reserved Media4U Sp. z o.o.
 * @link		https://sandbox.dhl24.com.pl/webapi2/doc/index.html Dokumentacja DHL
 * 
 * @property array $config Konfiguracja
 */
class DHL_Courier extends SoapClient {
	private $config;
	
	
	
	/**
	 * Połączenie się z serwisem DHL przy pomocy klienta Soap
	 * 
	 * @param string $user Nazwa użytkownika
	 * @param string $pass Hasło
	 * @param string $wsdl Adres WSDL (domyślnie testowy)
	 */
	public function __construct(string $user, string $pass, string $wsdl = 'https://sandbox.dhl24.com.pl/webapi2'){
		$this->config = [
			'login' => $user,
			'pass' => $pass,
			'wsdl' => $wsdl
		];

		if(!empty($this->config)){
			parent::__construct($this->config['wsdl'], array(
				'trace' => true,
				'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
				'stream_context' => stream_context_create(
					array(
						'ssl' => array(
							'verify_peer' => false,
							'verify_peer_name' => false,
						)
					)
				)
			));
		}
	}
	
	
	
	/**
	 * Metoda pozwala zweryfikować poprawność podanego kodu pocztowego oraz pobrać usługi DHL dostępne dla tego kodu.
	 * Wynik działania metody może zależeć od godziny wywołania metody (dostępność usług Domestic 9 oraz Domestic 12).
	 * 
	 * W przypadku kodu pocztowego nie obsługiwanego przez DHL (lub niepoprawnego) metoda zwróci informację o braku dostępnych usług dla tego kodu.
	 * Podczas sprawdzania restrykcji na dzień bieżący metoda zwróci możliwe okienko czasowe tylko,
	 * jeśli zamówienie kuriera jest jeszcze w danym dniu możliwe.
	 * 
	 * @param string $postCode Kod pocztowy, np. 97-300 lub 97300
	 * @param string $pickupDate Data kiedy paczka ma być zabrana. Domyślnie data dzisiejsza.
	 * @return array
	 */
	public function getPostcodeServices(string $postCode, string $pickupDate = null){
		$postCode = preg_replace('/-/', '', $postCode);
		
		return $this->getPostalCodeServices([
			'postCode' => $postCode,
			'pickupDate' => is_null($pickupDate) ? date('Y-m-d') : $pickupDate
		]);
	}
	
	
	
	/**
	 * Metoda pozwala na anulowanie zlecenia zamówienia kuriera.
	 * 
	 * @param array|string $bookingNumbers Numery zlecenia zamówienia kuriera, uzyskany za pomocą metody bookCourier
	 * @return array
	 */
	public function cancelCourier($bookingNumbers){
		if(!is_array($bookingNumbers)){
			$bookingNumbers = [ $bookingNumbers ];
		}
		
		return $this->cancelCourierBooking([
			'orders' => $bookingNumbers
		]);
	}
	
	
	
	/**
	 * Pobiera domyślną konfiguracją nadawcy z pliku konfiguracyjnego
	 * 
	 * @return array
	 */
	public function getDefaultFromLocation(){
		if(!isset($this->config['fromLocation']) || !is_array($this->config['fromLocation']))
			return [];
		
		if(array_keys($this->config['fromLocation']) !== range(0, count($this->config['fromLocation']) - 1))
			return $this->config['fromLocation'];
		
		return $this->config['fromLocation'][0];
	}
	
	
	
	/**
	 * Metoda zwraca tablicę asoacyjną,
	 * która używana jest przy każdym wywołaniu zdalnej metody.
	 *
	 * @return array Tablica z danymi logowania do serwisu
	 */
	private function getAuthData(){
		return [
			'username' => $this->config['login'],
			'password' => $this->config['pass']
		];
	}
	
	
	
	/**
	 * Przy pomocy tej metody możliwe jest utworzenie przesyłki i zamówienie kuriera.
	 *
	 * @param array $deliveryInfo Informacje o przesyłce (content - zawartość; comment - komentarz na liście przewozowym)
	 * @param array $receiver Dane adresowe odbiorcy paczki
	 * @param array $sender Dane adresowe nadawcy paczki
	 * @param array $shipmentTime Informacja o czasie nadania przesyłki
	 * @param array $pieceList Dane nt. paczek w przesyłce
	 * @return array
	 */
	public function registerParcel(array $deliveryInfo, array $receiver, array $sender, array $shipmentTime, array $pieceList){
		$billing = new stdClass();
		$billing->billingAccountNumber = $this->getClientNumber();
		$billing->shippingPaymentType = 'SHIPPER';
		$billing->paymentType = 'BANK_TRANSFER';

		$shipmentInfo = new stdClass();
		$shipmentInfo->dropOffType = 'REGULAR_PICKUP';
		$shipmentInfo->serviceType = 'AH';
		$shipmentInfo->labelType = 'LP';
		$shipmentInfo->billing = $billing;
		$shipmentInfo->shipmentTime = $shipmentTime;
		$shipmentInfo->specialServices = [
			'item' => [
				'serviceType' => 'ODB'
			]
		];

		
		$data = new stdClass();
		$data->content = $deliveryInfo['content'];
		$data->comment = $deliveryInfo['comment'];
		$data->shipmentInfo = $shipmentInfo;

		$data->pieceList = $pieceList;
		
		$data->ship = new stdClass();
		$data->ship->shipper = [ 'address' => $sender ];
		$data->ship->receiver = [ 'address' => $receiver ];


		return $this->createShipment([
			'shipment' => $this->toArray($data)
		]);
	}
	
	
	
	/**
	 * Metoda pomocnicza, która pozwala na przekonwertowanie obiektu stdClass na tablicę
	 * 
	 * @param object $object Obiekt, który ma zostać zmieniony na tablicę
	 * @return array
	 */
	public function toArray($object) : array {
		return json_decode(json_encode($object), true);
	}
	
	
	
	/**
	 * Pobiera listę przesyłek z ostatnich 90 dni,
	 * bo na tyle można maksymalnie ustawić.
	 * 
	 * @param int $offset Usługa zwraca maksymalnie 100 rekordów - w przypadku gdy w danym przedziale czasowym jest ich więcej, należy skorzystać z offsetu.
	 * @return array
	 */
	public function getAllShipments($offset = 0) : array {
		return $this->getMyShipments([
			'createdFrom' => date('Y-m-d', time()-3600*24*90),
			'createdTo' => date('Y-m-d', time()),
			'offset' => $offset
		]);
	}
	
	
	
	/**
	 * Metoda zwraca ilość przesyłek danego użytkownika utworzonych w zadanym przedziale czasowym.
	 * Jej wykorzystanie pozwala w wygodniejszy sposób korzystać z parametru offset metody getMyShipments.
	 * 
	 * @return array
	 */
	public function getShipmentsCount(){
		return $this->getMyShipmentsCount([
			'createdFrom' => date('Y-m-d', time()-3600*24*90),
			'createdTo' => date('Y-m-d', time())
		]);
	}
	
	
	
	/**
	 * Pobiera informacje na temat numeru przesyłki (tracking number)
	 * 
	 * @param int $trackingNumber Numer przesyłki
	 * @return array
	 */
	public function getTrackingNumberInfo(int $trackingNumber) : array {
		return $this->getTrackAndTraceInfo([
			'shipmentId' => $trackingNumber
		]);
	}
	
	
	
	/**
	 * Metoda umożliwia zamówienie kuriera do zdefiniowanych wcześniej przesyłek, na konkretny dzień w ramach wskazanych godzin.
	 * Po otrzymaniu żądania metoda sprawdza dane wejściowe: czy możliwy jest przyjazd kuriera w wyznaczonym czasie
	 * i czy wszystkie wskazane przesyłki mogą być odebrane w jednym miejscu (zgodność adresów nadania).
	 * W przypadku wystąpienia błędu komunikat zwrotny będzie wskazywał przyczynę problemu.
	 * 
	 * Zamówienie kuriera dla listy przygotowanych wcześniej przesyłek (przez podanie identyfikatorów przesyłek w parametrze shipmentIdList)
	 * 
	 * @param string $pickupDate Data kiedy ma przyjechać kurier w formacie RRRR-MM-DD
	 * @param string $pickupTimeFrom Godzina, od której przesyłka jest gotowa do odebrania (w formacie GG:MM)
	 * @param string $pickupTimeTo Godzina, do której można odebrać przesyłkę (w formacie GG:MM)
	 * @param array $shipmentIdList Tablica elementów typu string, zawierająca identyfikatory przesyłek
	 * @param string $additionalInfo Dodatkowe informacje dla kuriera
	 * @return array
	 */
	public function bookCourierForCreatedShipments(string $pickupDate, string $pickupTimeFrom, string $pickupTimeTo, array $shipmentIdList, string $additionalInfo = null){
		$request = [
			'pickupDate' => $pickupDate,
			'pickupTimeFrom' => $pickupTimeFrom,
			'pickupTimeTo' => $pickupTimeTo,
			'shipmentIdList' => $shipmentIdList
		];
		
		if(!is_null($additionalInfo)){
			$request['additionalInfo'] = $additionalInfo;
		}
		
		return $this->bookCourier($request);
	}
	
	
	
	/**
	 * Metoda umożliwia zamówienie kuriera do zdefiniowanych wcześniej przesyłek, na konkretny dzień w ramach wskazanych godzin.
	 * Po otrzymaniu żądania metoda sprawdza dane wejściowe: czy możliwy jest przyjazd kuriera w wyznaczonym czasie
	 * i czy wszystkie wskazane przesyłki mogą być odebrane w jednym miejscu (zgodność adresów nadania).
	 * W przypadku wystąpienia błędu komunikat zwrotny będzie wskazywał przyczynę problemu.
	 * 
	 * Zamówienie kuriera dla listy przygotowanych wcześniej przesyłek (przez podanie identyfikatorów przesyłek w parametrze shipmentIdList)
	 *
	 * @param string $pickupDate Data kiedy ma przyjechać kurier w formacie RRRR-MM-DD
	 * @param string $pickupTimeFrom Godzina, od której przesyłka jest gotowa do odebrania (w formacie GG:MM)
	 * @param string $pickupTimeTo Godzina, do której można odebrać przesyłkę (w formacie GG:MM)
	 * @param array $shipper Dane nadawcy
	 * @param int $numberOfExPieces Ilość przesyłek ekspresowych
	 * @param int $numberOfDrPieces Ilość przesyłek drobnicowych
	 * @param int $totalWeight Łączna waga paczek
	 * @param int $heaviestPieceWeight Waga najcięższej paczki
	 * @param string $additionalInfo Dodatkowe informacje dla kuriera
	 * @return array
	 */
	public function bookCourierWithoutDefinedShipments(string $pickupDate, string $pickupTimeFrom, string $pickupTimeTo, string $shipmentIdList, array $shipper, int $numberOfExPieces, int $numberOfDrPieces, int $totalWeight, int $heaviestPieceWeight, string $additionalInfo = null){
		$request = [
			'pickupDate' => $pickupDate,
			'pickupTimeFrom' => $pickupTimeFrom,
			'pickupTimeTo' => $pickupTimeTo,
			'shipmentIdList' => $shipmentIdList,
			'shipmentOrderInfo' => [
				'shipper' => $shipper,
				'numberOfExPieces' => $numberOfExPieces,
				'numberOfDrPieces' => $numberOfDrPieces,
				'totalWeight' => $totalWeight,
				'heaviestPieceWeight' => $heaviestPieceWeight
			]
		];
		
		if(!is_null($additionalInfo)){
			$request['additionalInfo'] = $additionalInfo;
		}
		
		return $this->bookCourier($request);
	}
	
	
	
	/**
	 * Możliwość pobierania etykiet stosowanych w procesie zamawiania kuriera DHL24.
	 * Możliwe jest pobranie etykiet: listu przewozowego oraz BLP (w formacie PDF oraz ZPL)
	 * 
	 * - Jeżeli używacie Państwo drukarki laserowej – etykieta wygenerowana w pliku pdf – parametr BLP
	 * - Jeżeli używacie Państwo drukarki termicznej zebra - etykieta wygenerowana w pliku zpl – parametr ZBLP
	 * 
	 * @param array $itemsToPrint Lista struktur (maksymalnie trzy struktury)
	 * @return array
	 * 
	 * @example getLabels([<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'labelType' => 'ZBLP',<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'shipmentId' => '11102224567'<br>]);
	 */
	public function getLabels(array $itemsToPrint){
		return $this->getLabels([
			'itemsToPrint' => $itemsToPrint
		]);
	}
	
	
	
	/**
	 * Wywołuje od razu zdalną metodę i zwraca wynik
	 * w postaci tablicy asoacyjnej. Jeśli nie uda się
	 * Zwrócić odpowiednniego elementu, to zwracany jest
	 * cały obiekt.
	 *
	 * @param string $functionName Nazwa funkcji, która ma zostać wywołana
	 * @param array $arguments Argumenty jakie przyjmuje wywoływana funkcja
	 * @return mixed
	 */
	public function __call($functionName, $arguments = []){
		$authData = [
			'authData' => $this->getAuthData()
		];
		
		if(empty($arguments[0])){
			$arguments[0] = [];
		}

		if(!empty($arguments[0]) && is_object($arguments[0])){
			$arguments[0] = $this->toArray($arguments[0]);
		}
		
		$response = parent::__call($functionName, [
			array_merge($authData, $arguments[0])
		]);

		if(property_exists($response, $functionName.'Result')){
			return $this->toArray($response)[$functionName.'Result'];
		}

		return $response;
	}
}
