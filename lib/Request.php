<?php
require_once  dirname(__FILE__)."/../libphonenumber-for-PHP/PhoneNumberUtil.php";

use com\google\i18n\phonenumbers\PhoneNumberUtil;
use com\google\i18n\phonenumbers\PhoneNumberFormat;
use com\google\i18n\phonenumbers\NumberParseException;

define("ASCIO_WSDL_LIVE","https://aws.ascio.com/2012/01/01/AscioService.wsdl");
define("ASCIO_WSDL_TEST","https://awstest.ascio.com/2012/01/01/AscioService.wsdl");

Class SessionCache {
	public static function get($account) {
		$filename = dirname(realpath ( __FILE__ ))."/../sessioncache/ascio-session_".$account.".txt";
		$fp = fopen($filename,"r");
		$contents = fread($fp, filesize($filename));
		fclose($fp);
		if(trim($contents) == "false") $contents = false;
		return $contents;
	}
	public static function put($sessionId,$account) {
		$filename = dirname(realpath ( __FILE__ ))."/../sessioncache/ascio-session_".$account.".txt";
		$fp = fopen($filename,"w");		
		fwrite($fp,$sessionId);
		fclose($fp);
	}
	public static function clear($account) {
		SessionCache::put("false",$account);
	}
}
function createRequest($params) {
	$tld = $params["tld"];
	$filename = realpath(dirname(__FILE__))."/../tlds/$tld/$tld.php";
	$defExists = file_exists($filename);	
	if($tld && $defExists) {
		require_once($filename);
		$tldRequest = new $tld($params);
		return $tldRequest;
	} else {
		return new Request($params);
	}
}
Class Request {
	var $account;
	var $password; 
	var $params;

	public function __construct($params) {
		$this->setParams($params);
	}
	private function login() {
		$session = array(
		             'Account'=> $this->account,
		             'Password' =>  $this->password
		);
		return $this->sendRequest('LogIn',array('session' => $session ));
		 
	}
	public function request($functionName, $ascioParams)  {	
		$sessionId = SessionCache::get($this->account);	
		if (!$sessionId) {				
			$result = $this->login(); 
			if(is_array($result)) return $result;
			$ascioParams["sessionId"] = $result->sessionId; 		
			SessionCache::put($result->sessionId, $this->account);
		} else {		
			$ascioParams["sessionId"] = $sessionId; 
		}
		$result = $this->sendRequest($functionName,$ascioParams);
		if(is_array($result) && strpos($result["error"],"Invalid Session") > -1) {
			SessionCache::clear($this->account);
			return  $this->request($functionName, $ascioParams);		
		} else {	
			return $result;
			
		}	
	}
	private function sendRequest($functionName,$ascioParams) {		
		syslog(LOG_INFO, "WHMCS Request:".$functionName ."(". $this->account .")" );
		$cfg = getRegistrarConfigOptions("ascio");
		$wsdl = $cfg["TestMode"]=="on" ? ASCIO_WSDL_TEST : ASCIO_WSDL_LIVE;
        $client = new SoapClient($wsdl,array( "trace" => 1));
        $result = $client->__call($functionName, array('parameters' => $ascioParams));      
		$resultName = $functionName . "Result";	
		$status = $result->$resultName;
		syslog(LOG_INFO, "WHMCS ".$functionName  .": ".$status->Values->string . " ResultCode:" . $status->ResultCode);
		if ( $status->ResultCode==200) {
			return $result;
		} else if( $status->ResultCode==554)  {
			$messages = "Temporary error. Please try later or contact your support.";
		} elseif (count($status->Values->string) > 1 ){
				$messages = join(", \r\n",$status->Values->string);	
		} else {
			$messages = $status->Values->string;
		}
		return array('error' => $status->Message . ", \r\n" .$messages);     
	}
	public function getDomain($handle) {
		$ascioParams = array(
			'sessionId' => 'mySessionId',
			'domainHandle' => $handle
		);
		$result =  $this->request("GetDomain", $ascioParams,true); 
		return $result;
	}
	public function searchDomain($params) {
		$params = $this->setParams($params);
		$criteria= array(
			'Mode' => 'Strict',
			'Clauses' => Array(
				'Clause' => Array(
					'Attribute' => 'DomainName', 
					'Value' => $this->domainName , '
					Operator' => 'Is'
				)
			)
		);
		$ascioParams = array(
			'sessionId' => 'mySessionId',
			'criteria' => $criteria
		);
		$result =  $this->request("SearchDomain",$ascioParams,true);
		if(is_array($result)) return $result;
		else {
			$status = !$result->domains->Domain->DomainName ? NULL : $result->domains->Domain->Status;
			$this->setWhmcsStatus($this->domainName,$status);
			return $result->domains->Domain;
		}
	}
	public function getCallbackData($orderStatus,$messageId,$orderId) {
		$ascioParams = array(
			'sessionId' => 'mySessionId',
			'msgId' => $messageId
		);
		$result = $this->request("GetMessageQueue", $ascioParams,true);
		$order =  $this->getOrder($orderId);
		$domainName = $order->order->Domain->DomainName;
		$domainId   = $this->getDomainId($domainName);
		$domainResult = $this->getDomain($order->order->Domain->DomainHandle);
		$domain = $domainResult->domain;
		// External WHMCS API: Set Status
		// External WHMCS API: Send Mail
		$msgPart = "Domain order ". $domainId . ", ".$domainName;
		$whmcsStatus = $this->setWhmcsStatus($domainName,$domain->Status,$order->order->Type,$domainId);

		syslog(LOG_INFO, json_encode($domain));
		syslog(LOG_INFO, "Domain status ".$domainname. ": ".$domain->Status);
		syslog(LOG_INFO, "Order status ".$domainname. ": ".$whmcsStatus);
		if ($orderStatus=="Completed") {
			$message = Tools::formatOK($msgPart);
			if(
				$this->params["AutoExpire"] =="on" && 
				($order->order->Type =="Register_Domain" || $order->order->Type =="Transfer_Domain")
			) {
				$this->expireDomain(array ("domainname" => $domainName));	
			}	
		} else {
			$message =  Tools::formatError($result->item->StatusList->CallbackStatus,$msgPart);
		}
		if($this->params["DetailedOrderStatus"]) {
			syslog(LOG_INFO, "DetailedOrderStatus ". $this->params["DetailedOrderStatus"]);
		}
 		if($this->params["DetailedOrderStatus"] == "on") {
 		 	$values = array( 'messagename' => 'Test Template', 'id' => '1', );
	 		$adminuser = 'admin';
			$command = "sendemail";
			$values["customtype"] = "domain";
			$values["customsubject"] = $msgPart ." ". strtolower($orderStatus);
			$values["custommessage"] = $message;
			$values["id"] = $domainId;
			localAPI($command,$values,$adminuser);
			// Ascio ACK Message
			$ascioParams = array(
				'sessionId' => 'mySessionId',
				'msgId' => $messageId
			);	
 		} 		
		$this->sendAuthCode($order->order,$domainId);		
		$result = $this->request("AckMessage", $ascioParams,true);
		if( $order->order->Type=="Register_Domain" || $order->order->Type=="Transfer_Domain") {
			$this->autoCreateZone($domainName);
		}
	}
	public function autoCreateZone($domain) {
		$params = $this->setParams();		
		syslog(LOG_INFO, "Creating DNS zone ".$domain);	
		$cfg = getRegistrarConfigOptions("ascio");		
		if($cfg["AutoCreateDNS"]=="on") {
			$dns = $cfg["DNS_Default_Zone"];
			$mx1 = $cfg["DNS_Default_Mailserver"];
			$mx2 = $cfg["DNS_Default_Mailserver_2"];
			$zone = new DnsZone($params,$domain);
			$params["dnsrecords"] = array(
				array("hostname" => "@","type" => "A","address" => $dns),
				array("hostname" => "www","type" => "A","address" => $dns),
				array("hostname" => "mail","type" => "A","address" => $mx1),
				array("hostname" => "mail2","type" => "A","address" => $mx2),
				array("hostname" => "@", "type" => "MX","address" => "mail1", "priority" => 10),
				array("hostname" => "@", "type" => "MX","address" => "mail2","priority" => 20)
			);
			$result = $zone->update($params);
			echo ("Created DNS zone: ".$domain."\n");
		}
	}
	public function setWhmcsStatus($domain,$ascioStatus,$orderType,$domainId) {	
		// set the status of the domain based on ascio's domain-data
		if($ascioStatus==NULL) $ascioStatus = "deleted";
		$statusMap = array (
			"pending" => "Pending",
			"active"  => "Active",
			"deleted" => "Cancelled",
			"parked"  => "Cancelled",

		);
		$whmcsStatus = $statusMap[strtolower($ascioStatus)];
		if ($orderType=="Transfer_Domain" && $whmcsStatus == "Pending") {
			$whmcsStatus = "Pending Transfer";
		}
		if(strpos($ascioStatus,"pending")!==false) {
			$whmcsStatus = "Pending";
		}
		$command = "updateclientdomain";
		$values["domain"] =  $domain;
		$values["status"] = $whmcsStatus;
			$results = localAPI($command,$values,"admin"); 			
		syslog(LOG_INFO, "Set new WHMCS status for ".$domain. ": ".$whmcsStatus);
		return $whmcsStatus;
	}
	public function getOrder($orderId) {
		$ascioParams = array(
			'sessionId' => 'mySessionId',
			'orderId' => $orderId
		);
		$result =  $this->request("GetOrder", $ascioParams,true); 
		return $result;
	}
	public function sendAuthCode($order,$domainId) {
		console.log("Start send AuthCode");
		if($order->Type != "Update_AuthInfo") return;
		$domain =  $this->getDomain($order->Domain->DomainHandle);
		$msg = "New AuthCode generated for ".$domain->domain->DomainName . ": ".$domain->domain->AuthInfo;
		$values = array();
		$values["customtype"] = "domain";
		$values["customsubject"] = $domain->domain->DomainName . ": New AuthCode generated";
		$values["custommessage"] = $msg;
		$values["id"] = $domainId;
		console.log("Send AuthCode to ???");
		$results = localAPI("sendemail",$values,"admin");
		return $results;
	}
	private function getDomainId($domain) {
		$query = 'SELECT id FROM  `tbldomains` WHERE domain =  "'.$domain.'" LIMIT 0 , 1';
		$result = mysql_query($query);
		$row = mysql_fetch_assoc($result);		
	    $id = $row["id"];
	    return $id; 
	}
	public function poll() {
		$ascioParams = array(
			'sessionId' => 'mySessionId',
			'msgType' 	=> 'Message_to_Partner'
		);
		$result =  $this->request("PollMessage",$ascioParams,true);
		if(is_array($result)) return $result;
		else return $result;
	}
	public function ack($msgId) {
		$ascioParams = array(
			'sessionId' => 'mySessionId',
			'msgId' 	=> $msgId
		);
		$result =  $this->request("AckMessage",$ascioParams,true);
		if(is_array($result)) return $result;
		else return $result;  
	}
	public function registerDomain($params=false) {
		// register domains
		$params = $this->setParams($params);
		try {			
			$ascioParams = $this->mapToOrder($params,"Register_Domain");
		} catch (AscioException $e) {
			die( $e);
			return array("error" => $e->getMessage());
		}
		$result = $this->request("CreateOrder",$ascioParams);
		if (!$result) {
			$this->setWhmcsStatus($domainName,"Pending","Register_Domain");
		}
		return $result;
	}
	public function transferDomain ($params=false) {		
		$params = $this->setParams($params);
		try {
			$ascioParams = $this->mapToOrder($params,"Transfer_Domain");
		} catch (AscioException $e) {
			return array("error" => $e->getMessage());
		}
		$result = $this->request("CreateOrder",$ascioParams);
		if (!$result) {
			$this->setWhmcsStatus($domain,"Pending","Transfer_Domain");
		}
		return $result;
	}
	public function updateContacts ($params=false) {
		$params = $this->setParams($params);
		$old = $this->searchDomain($params);
		$newRegistrant 	= $this->mapToContact2($params["contactdetails"]["Registrant"],"Registrant");
		$newAdmin 		= $this->mapToContact2($params["contactdetails"]["Admin"],"Contact");
		$newTech 		= $this->mapToContact2($params["contactdetails"]["Tech"],"Contact");
		$updateRegistrant = Tools::compareRegistrant($newRegistrant,$old->Registrant);
		$updateAdmin = Tools::compareContact($newAdmin,$old->AdminContact);
		$updateTech = Tools::compareContact($newTech,$old->TechContact);	

		if($updateRegistrant) {
			syslog(LOG_INFO,"Update Registrant: ".$registrantResult);
			try {
				$ascioParams = $this->mapToOrder($params,$updateRegistrant);		
			} catch (AscioException $e) {
				return array("error" => $e->getMessage());
			}
			$ascioParams["order"]["Domain"]["Registrant"] = $newRegistrant;
			// Do the Adminchange within the owner-change
			if($updateAdmin && $updateRegistrant=="Owner_Change") {
				syslog(LOG_INFO,"Owner_Change + Admin_Change");
				$ascioParams["order"]["Domain"]["AdminContact"] = $newAdmin;
			}
			$registrantResult = $this->request("CreateOrder",$ascioParams);		
		} 
		if($updateTech || $updateBilling || ($updateAdmin && $updateRegistrant!="Owner_Change")) {
			syslog(LOG_INFO,"Contact_Update");
			try {
				$ascioParams = $this->mapToOrder($params,"Contact_Update");		
			} catch (AscioException $e) {
				return array("error" => $e->getMessage());
			}
			if($updateAdmin) {
				syslog(LOG_INFO,"Update Tech");
				$ascioParams["order"]["Domain"]["AdminContact"] = $newAdmin;
			} else {
				$ascioParams["order"]["Domain"]["AdminContact"] = $old->AdminContact;
			}
			if($updateTech) {
				syslog(LOG_INFO,"Update Tech");
				$ascioParams["order"]["Domain"]["AdminContact"] = $newTech;
			} else {
				$ascioParams["order"]["Domain"]["TechContact"] = $old->TechContact;
			}
			$ascioParams["order"]["Domain"]["BillingContact"] = $old->BillingContact;
			$contactResult = $this->request("CreateOrder",$ascioParams);
		}
		return array_merge($registrantResult,$contactResult);
	}
	public function renewDomain($params) {
		$params = $this->setParams($params);
		try {
			$ascioParams = $this->mapToOrder($params,"Unexpire_Domain");
		} catch (AscioException $e) {
			return array("error" => $e->getMessage());
		}
		$result =  $this->request("CreateOrder",$ascioParams);
		if (!$result) {
			$this->setWhmcsStatus($domain,"Pending","Unexpire_Domain");
		}
		return $result;
	}

	public function expireDomain($params) {
		$params = $this->setParams($params);
		try {
			$ascioParams = $this->mapToOrder($params,"Expire_Domain");
		} catch (AscioException $e) {
			return array("error" => $e->getMessage());
		}
		$result =  $this->request("CreateOrder",$ascioParams);
		if (!$result) {
			$this->setWhmcsStatus($domain,"Pending","Expire_Domain");
		}
		return $result;
	}	
	function saveNameservers($params) {
		$params = $this->setParams($params);
		try {
			$ascioParams = $this->mapToOrder($params,"Nameserver_Update");
		} catch (AscioException $e) {
			return array("error" => $e->getMessage());
		}
		$result =  $this->request("CreateOrder",$ascioParams);
		if (!$result) {
			$this->setWhmcsStatus($domain,"Pending","Nameserver_Update");
		}
		return $result;
	}	
	function saveRegistrarLock($params) {
		$params = $this->setParams($params);
		if ($params["lockenabled"]) {
			$lockstatus="Lock";
		} else {
			$lockstatus="Unlock";
		}
		try {
			$ascioParams = $this->mapToOrder($params,"Change_Locks");
		} catch (AscioException $e) {
			return array("error" => $e->getMessage());
		}
		$ascioParams->Order->Domain->TransferLock = $lockstatus;
		return $this->request("CreateOrder",$ascioParams);
	}	
	public function getEPPCode($params) {
		$params = $this->setParams($params);
	    try {
	    	$ascioParams = $this->mapToOrder($params,"Update_AuthInfo");
		} catch (AscioException $e) {
			return array("error" => $e->getMessage());
		}
		$result = $this->request("CreateOrder",$ascioParams,true);
		if(is_array($result)) {
			return $result;
		} else {
			return array("eppcode" => $ascioParams->Order->Domain->AuthInfo);
		}
	}	
	protected function mapToRegistrant($params) {
		$result =  $this->mapToContact($params,"Registrant");
		$result["Name"] = $params["firstname"] . " " . $params["lastname"];
		$result["RegistrantType"] = $params["custom"]["RegistrantType"];
		$result["VatNumber"] = $params["custom"]["VatNumber"];
		$result["NexusCategory"] = $params["custom"]["NexusCategory"];
		$result["RegistrantNumber"] = $params["custom"]["RegistrantNumber"];
		$result["Details"] = $params["custom"]["Details"];
		return $result;
	}
	protected function addContactFields($params,$type) {
		$result =  $this->mapToContact($params,$type);
		$result["Type"] = $params["custom"]["Type"];
		$result["Details"] = $params["custom"]["Details"];
		$result["NexusCategory"] = $params["custom"]["NexusCategory"];
		$result["OrganisationNumber"] = $params["custom"]["OrganisationNumber"];
		return $result;
	}
	protected function mapToAdmin($params) {
		return $this->addContactFields($params,"Admin");
	}	
	protected function mapToTech($params) {
		return $this->addContactFields($params,"Admin");
	}	
	protected function mapToBilling($params) {
		return $this->addContactFields($params,"Admin");
	}
	protected function mapToTrademark($params) {
		return null; 
	}
	public function mapToOrder ($params,$orderType) {
		//	get custom-field names. Params only has IDs but the names are needed
		$fields = $customfields = $params["custom"] = array();
 		$result = mysql_query("select id,fieldname from tblcustomfields");
 		foreach ($params["customfields"] as $key => $value) {
 			$customFields[$value["id"]] = $value["value"];
 		}
 		while ($row = mysql_fetch_assoc($result)) {
 			$params["custom"][$row['fieldname']]=$customFields[$row['id']] ;
		}
		$params = $this->setParams($params);
		$domainName = $params["domainname"];
		syslog(LOG_INFO, "WHMCS ". $orderType . ": ".$domainName);
		$domain = array( 
			'DomainName' => $domainName,
			'RegPeriod' =>  $params["original"]["regperiod"],
			'AuthInfo'	=> 	$params["eppcode"],
			'DomainPurpose' =>  $params["Application Purpose"],
			'Registrant' 	=>  $this->mapToRegistrant($params),
			'AdminContact' 	=>  $this->mapToAdmin($params), 
			'TechContact' 	=>  $this->mapToTech($params), 
			'BillingContact'=>  $this->mapToBilling($params),
			'NameServers' 	=>  $this->mapToNameservers($params),
			'Trademark' 	=>  $this->mapToTrademark($params),
			'Comment'		=>  $params["Comment"]
		);
		$order = 
			array( 
			'Type' => $orderType, 
			'TransactionComment' => "WHMCS", 
			'Domain' => $domain,
			'Comments'	=>	$params["userid"]
			); 
		return array(
				'sessionId' => "set-it-later",
				'order' => $order
	        );
	}
	// map contact from Ascio to WHMCS - admincompanyname
	public function mapToContact($params,$type) {
		$contactName = array();
		$errors = array();
		$prefix = "";
		if($type == "Registrant") {
			$contactName["Name"] = $params["firstname"] . " " . $params["lastname"];
			//$contactName["NexusCategory"] = $params["Nexus Category"];
			//$contactName["RegistrantNumber"] = "55203780600585";
		} else {
			$prefix = strtolower($type);
			$contactName["FirstName"] = $params[$prefix . "firstname"];
			$contactName["LastName"] = $params[$prefix . "lastname"];
		}
		$country =  $params[$prefix . "country"];	
		try {
			$contact = Array(
				'OrgName' 		=>  $params[$prefix . "companyname"],
				'Address1' 		=>  $params[$prefix . "address1"],	
				'Address2' 		=>  $params[$prefix . "address2"],
				'PostalCode' 	=>  $params[$prefix . "postcode"],
				'City' 			=>  $params[$prefix . "city"],
				'State' 		=>  $params[$prefix . "state"],		
				'CountryCode' 	=>  $country,
				'Email' 		=>  $params[$prefix . "email"],
				'Phone'			=>  Tools::fixPhone($params[$prefix . "fullphonenumber"],$country),
				'Fax' 			=> 	Tools::fixPhone($params[$prefix . "custom"]["Fax"],$country)
			);
		} catch (AscioException $e) {
			throw new AscioException($type . ", ". $e->getMessage());			
		}		
		return array_merge($contactName,$contact);
	}
	// WHMCS has 2 contact structures. Flat and nested.
	// This function in converting from adminfirstname to Admin["First Name"]
	public function mapToContact2($params,$type) {
		$ascio = (object) array(
			'OrgName'  				=> $params["Organisation Name"],
			'Address1'  			=> $params["Address 1"],
			'Address2'  			=> $params["Address 2"],
			'PostalCode'  			=> $params["ZIP Code"],
			'City'  				=> $params["City"],
			'State'	  				=> $params["State"],
			'CountryCode'  			=> $params["Country"],
			'Email'  				=> $params["Email"],
			'Phone'  				=> Tools::fixPhone($params["Phone"],$params["Country"]), 
			// todo test!
			'Fax'  					=> Tools::fixPhone($params["custom"]["Fax"],$params["Country"]),
		);
		if($type=="Registrant") {
			$ascio->Name = $params["First Name"]. " ". $params["Last Name"];		
		} else {
			$ascio->FirstName 	= $params["First Name"];
			$ascio->LastName 	= $params["Last Name"];
		}
		return $ascio; 
	}
	public function mapToNameservers($params) {
		return array (
					'NameServer1' => Array('HostName' => $params["ns1"]), 
					'NameServer2' => Array('HostName' => $params["ns2"]),
					'NameServer3' => Array('HostName' => $params["ns3"]),
					'NameServer4' => Array('HostName' => $params["ns4"])
		);
	}
	public function setParams($params) {
		if($params) {
			$this->params = $params; 
			$this->domainName = $params["sld"] ."." . $params["tld"];
			if($this->params["Username"]) $this->account = $this->params["Username"];
			if($this->params["Password"]) $this->password = $this->params["Password"];
		} 
		return $this->params;
	}
}
?>
