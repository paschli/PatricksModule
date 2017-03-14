<?
//Modul überwacht die letzte Aktualisierung bei einer Variable und startet ein 
//Skript, falls die Zeit der letzten zur aktuellen Aktualisierung kleiner gleich 
//einem Wert ist
class DBLClick extends IPSModule {
  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('idSourceInstance', 0);
    $this->RegisterPropertyInteger('DblClickTime', 1);
  }
  public function ApplyChanges() {
    parent::ApplyChanges();
    
    $this->RegisterPropertyInteger('DblClickTime', 1);
    $this->RegisterPropertyInteger('idSourceInstance', 0); //Id der zu beobachtenden Variable	  
    $DBLClickDetectId = $this->RegisterVariableBoolean('DBLClickDetect', 'DoppelKlickErkannt','', 1); //Boolean anlegen, der bei erkennung gesetzt wird 
    $stringInhalt="<?\n\n SetValueBoolean($DBLClickDetectId, FALSE); \n//Start your code here\n\n?>"; //Inhalt für Skript erzeugen, das bei Erkennung ausgeführt wird 
    $scriptID = $this->RegisterScript('SCRIPT', 'DBLClickScript',$stringInhalt,2);//Skript anlegen
    $lastUpdID = $this->RegisterVariableInteger('LASTUPD','last_updated','~UnixTimestamp',3);//Hilfsvariable anlegen
//    $presentId = $this->RegisterVariableInteger('PRESENT_SINCE', 'Anwesend seit', '~UnixTimestamp', 3);
//    $absentId = $this->RegisterVariableInteger('ABSENT_SINCE', 'Abwesend seit', '~UnixTimestamp', 3);
//    $nameId = $this->RegisterVariableString('NAME', 'Name_Device', '', 2);
//    IPS_SetIcon($this->GetIDForIdent('DBLClickDetect'), 'Motion');
//    IPS_SetIcon($this->GetIDForIdent('SCRIPT'), 'Keyboard');
    IPS_SetIcon($this->GetIDForIdent('LASTUPD'), 'Clock');
    
    if($this->ReadPropertyInteger('idSourceInstance')!=0){  
    	$this->RegisterTimer('OnVariableUpdate', 0, 'BTPC_Check($id)');
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
      IPS_SetEventTrigger($id, 0, $this->ReadPropertyInteger('idSourceInstance')); //Bei Update von der gewählten Variable 
      IPS_SetEventActive($id, true);             //Ereignis aktivieren
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
    }
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, "\$id = \$_IPS['TARGET'];\n$script;");
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
  }
 
  public function Check() {
    if(IPS_SemaphoreEnter('DBLClick', 1000)) {
      $stringID=$this->ReadPropertyInteger('idSourceInstance');
      $stringInfo= IPS_GetVariable($stringID);
      $zeit = $stringInfo['VariableUpdated'];//Zeitpunkt des aktuellen Updates
      $lastUpdID=$this->GetIDForIdent('LASTUPD');// ID für LastUpd suchen 
      $lastUpdValue= GetValueInteger($lastUpdID);// WErt für LastUpd lesen
      $string=GetValueString($stringID);
      $DBLClickTime=ReadPropertyInteger('DBLClickTime');
      IPS_LogMessage('DBLClick',"Wert eingelesen");
      
      if(strstr($string, "111")===FALSE){ //Falls Update nicht durch einfachen Klick verursacht
          IPS_LogMessage('DBLClick',"Update war kein Einfach-klick");
          exit ();
      }
      
      SetValueInteger($lastUpdID, $zeit);
      IPS_LogMessage('DBLClick',"Update bei",$zeit);
      if(($zeit-$lastUpdValue)<=$DBLClickTime)
	SetValueBoolean($ID_doppelklick, true);
      else
	SetValueBoolean($ID_doppelklick, false);    
        
      IPS_SemaphoreLeave('BTPCScan');
    } else {
      IPS_LogMessage('BTPClient', 'Semaphore Timeout');
    }
   }
} 
?>
