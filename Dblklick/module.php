<?
//Modul überwacht die letzte Aktualisierung bei einer Variable und startet ein 
//Skript, falls die Zeit der letzten zur aktuellen Aktualisierung kleiner gleich 
//einem Wert ist
class BTPClient extends IPSModule {
  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('idSourceString', 0);
    //$this->RegisterPropertyInteger('ScanInterval', 60);
  }
  public function ApplyChanges() {
    parent::ApplyChanges();
	  
    //$this->RegisterPropertyInteger('ScanInterval', 30);
    $this->RegisterPropertyInteger('idSourceString', 0);	  
    $stateId = $this->RegisterVariableBoolean('STATE', 'Zustand', '~Presence', 1);
//    $presentId = $this->RegisterVariableInteger('PRESENT_SINCE', 'Anwesend seit', '~UnixTimestamp', 3);
//    $absentId = $this->RegisterVariableInteger('ABSENT_SINCE', 'Abwesend seit', '~UnixTimestamp', 3);
//    $nameId = $this->RegisterVariableString('NAME', 'Name_Device', '', 2);
//    IPS_SetIcon($this->GetIDForIdent('STATE'), 'Motion');
//    IPS_SetIcon($this->GetIDForIdent('NAME'), 'Keyboard');
//    IPS_SetIcon($this->GetIDForIdent('PRESENT_SINCE'), 'Clock');
//    IPS_SetIcon($this->GetIDForIdent('ABSENT_SINCE'), 'Clock');
    if($this->ReadPropertyInteger('idSourceString')!=0){  
    	$this->RegisterTimer('OnStringChange', 0, 'BTPC_Scan($id)');
    }
  }
  protected function RegisterTimer($ident, $interval, $script) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
      IPS_DeleteEvent($id);
      $id = 0;
    }
    if (!$id) {
      $id = IPS_CreateEvent(0);
      IPS_SetEventTrigger($id, 1, $this->ReadPropertyInteger('idSourceString')); //Bei Änderung von der gewählten Variable 
      IPS_SetEventActive($id, true);             //Ereignis aktivieren
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
    }
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, "\$id = \$_IPS['TARGET'];\n$script;");
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
  }
 
  public function Scan() {
    if(IPS_SemaphoreEnter('BTPCScan', 5000)) {
      //$mac = $this->ReadPropertyString('Mac');
      $string=GetValueString($this->ReadPropertyInteger('idSourceString'));
      IPS_LogMessage('BTPClient',"String eingelesen");
      $array=explode(";",$string);
      foreach($array as $item){
      if($item!=""){
      $subarray=explode("=",$item);
      $tag=$subarray[0];
      $value=$subarray[1];
      IPS_LogMessage('BTPClient',"Tag:".$tag." Value:".$value);
      //echo("tag:".$tag.chr(13));
      //echo("value:".$value.chr(13));
      switch($tag){
 	      case "User" : $user = $value; break;
	      case "Name": $name = $value; break;
	      case "Zustand": $state = $value; break;
	      case "Anwesend seit": $anw = $value; break;
	      case "Abwesend seit": $abw = $value; break;
        default : IPS_LogMessage('BTPClient',"Tag=".$tag." nicht erkannt!"); 
 	      }
       }
      }
      /*if (preg_match('/^(?:[0-9A-F]{2}[:]?){6}$/i', $mac)) {
        $lastState = GetValueBoolean($this->GetIDForIdent('STATE'));
        $search = trim(shell_exec("hcitool name $mac"));
        $state = ($search != '');
        }*/
	//User Namen prüfen, ob Instance schon angelegt ist
	$inst_id=IPS_GetParent($this->GetIDForIdent('STATE'));	// ID der aktuellen Instanz 
	$parent_id=IPS_GetParent($inst_id);  			// ID der übergeordneten Instanz  
	$inst_obj=IPS_GetObject($inst_id);   			// Objekt_Info der aktuellen Instanz lesen
	$inst_name=$inst_obj['ObjectName'];  			// Name der aktuellen Instanz lesen
	IPS_LogMessage('BTPClient',"Objekt Name:".$inst_name);
	$UserInstID = @IPS_GetInstanceIDByName($user, $parent_id); // Instanz mit Namen suchen, der im "USER"-Eintrag steht
	if ($UserInstID === false){				// Instanz nicht gefunden
    	 IPS_LogMessage('BTPClient',"Instanz mit Namen: ".$user." nicht gefunden! Muss neu angelegt werden!");
	 IPS_LogMessage('BTPClient',"Anlegen in: ".$parent_id);	
	 $NewInsID = IPS_CreateInstance("{58C01EE2-6859-492A-9B7B-25EDAA6D48FE}");
	 IPS_SetName($NewInsID, $user); // Instanz benennen
	 IPS_SetParent($NewInsID, $parent_id); // Instanz einsortieren unter der übergeordneten Instanz
	 $UserInstID=$NewInsID;
	}
	else{							// instanz gefunden
    	 IPS_LogMessage('BTPClient',"Instanz mit Namen: ".$user." gefunden! ID:".$UserInstID);
	 
	}
        /*$lastState = GetValueBoolean($this->GetIDForIdent('STATE'));
        SetValueBoolean($this->GetIDForIdent('STATE'), $state);
        if ($state) SetValueString($this->GetIDForIdent('NAME'), $name);
        if ($lastState != $state) {
          if ($state) SetValueInteger($this->GetIDForIdent('PRESENT_SINCE'), $anw);
          if (!$state) SetValueInteger($this->GetIDForIdent('ABSENT_SINCE'), $abw);
        
      
        IPS_SetHidden($this->GetIDForIdent('PRESENT_SINCE'), !$state);
        IPS_SetHidden($this->GetIDForIdent('ABSENT_SINCE'), $state);
	*/
	IPS_LogMessage('BTPClient',"Suche Zustand in ID: ".$UserInstID);
	$id_state=@IPS_GetVariableIDByName('Zustand', $UserInstID); 
	if($id_state === false){
		IPS_LogMessage('BTPClient',"Fehler : Variable Zustand nicht gefunden!");
		exit;
	}
	IPS_LogMessage('BTPClient',"Gefunden! ID: ".$id_state);
	$lastState = GetValueBoolean($id_state);
        SetValueBoolean($id_state, $state);
	IPS_LogMessage('BTPClient',"Suche Name_Device in ID: ".$UserInstID);
	$id_name=@IPS_GetVariableIDByName('Name_Device', $UserInstID);
	if($id_name === false){
		IPS_LogMessage('BTPClient',"Fehler : Variable Name_Device nicht gefunden!");
		exit;
	}
	IPS_LogMessage('BTPClient',"Gefunden! ID: ".$id_name);
        if ($state) SetValueString($id_name, $name);
	IPS_LogMessage('BTPClient',"Suche Anwesend seit in ID: ".$UserInstID);
	$id_anw=@IPS_GetVariableIDByName('Anwesend seit', $UserInstID);
	if($id_name === false){
		IPS_LogMessage('BTPClient',"Fehler : Variable Abwesend seit nicht gefunden!");
		exit;
	}    
	IPS_LogMessage('BTPClient',"Gefunden! ID: ".$id_anw);
	IPS_LogMessage('BTPClient',"Suche Abwesend seit in ID: ".$UserInstID);
	$id_abw=@IPS_GetVariableIDByName('Abwesend seit', $UserInstID);
	if($id_name === false){
		IPS_LogMessage('BTPClient',"Fehler : Variable Anwesend seit nicht gefunden!");
		exit;
	} 
	IPS_LogMessage('BTPClient',"Gefunden! ID: ".$id_abw);
        if ($lastState != $state) {
          if ($state) SetValueInteger($id_anw, $anw);
          if (!$state) SetValueInteger($id_abw, $abw);
        
      
        IPS_SetHidden($id_anw, !$state);
        IPS_SetHidden($id_abw, $state);
      }
      IPS_SemaphoreLeave('BTPCScan');
    } else {
      IPS_LogMessage('BTPClient', 'Semaphore Timeout');
    }
   }
} 
?>
