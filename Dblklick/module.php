<?
//Modul überwacht die letzte Aktualisierung bei einer Variable und startet ein 
//Skript, falls die Zeit der letzten zur aktuellen Aktualisierung kleiner gleich 
//einem Wert ist
class DBLClick extends IPSModule {
  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('idSourceInstance', 0);
    $this->RegisterPropertyInteger('DBLClickTime', 1);
  }
  public function ApplyChanges() {
    parent::ApplyChanges();
    
    $this->RegisterPropertyInteger('DBLClickTime', 1);
    $this->RegisterPropertyInteger('idSourceInstance', 0); //Id der zu beobachtenden Variable	  
    $DBLClickDetectId = $this->RegisterVariableBoolean('DBLClickDetect', 'DoppelKlickErkannt','', 1); //Boolean anlegen, der bei erkennung gesetzt wird 
    $stringInhalt="<?\n IPS_LogMessage('DBLClick_Script','Starte User_Script.....................'); \n SetValueBoolean($DBLClickDetectId, FALSE); \n//Start your code here\n\n?>"; //Inhalt für Skript erzeugen, das bei Erkennung ausgeführt wird 
    $scriptID = $this->RegisterScript('SCRIPT', 'DBLClickScript',$stringInhalt,2);//Skript anlegen
    $lastUpdID = $this->RegisterVariableInteger('LASTUPD','last_updated','~UnixTimestamp',3);//Hilfsvariable anlegen
//    $presentId = $this->RegisterVariableInteger('PRESENT_SINCE', 'Anwesend seit', '~UnixTimestamp', 3);
//    $absentId = $this->RegisterVariableInteger('ABSENT_SINCE', 'Abwesend seit', '~UnixTimestamp', 3);
//    $nameId = $this->RegisterVariableString('NAME', 'Name_Device', '', 2);
//    IPS_SetIcon($this->GetIDForIdent('DBLClickDetect'), 'Motion');
//    IPS_SetIcon($this->GetIDForIdent('SCRIPT'), 'Keyboard');
    IPS_SetIcon($this->GetIDForIdent('LASTUPD'), 'Clock');
    
    if($this->ReadPropertyInteger('idSourceInstance')!=0){  
    	$this->RegisterTimer('OnVariableUpdate', 0, 'DBLC_Check($id)');
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
    //IPS_LogMessage('DBLClick',"Setze Semaphore");
    if(IPS_SemaphoreEnter('DBLClick', 1000)) {
      IPS_LogMessage('DBLClick',"Starte Check.....................");
      $stringID=$this->ReadPropertyInteger('idSourceInstance');
      $DBLClickDetectID=$this->GetIDForIdent('DBLClickDetect');
      $stringInfo= IPS_GetVariable($stringID);
      $AktuelleZeit = $stringInfo['VariableUpdated'];//Zeitpunkt des aktuellen Updates
      $lastUpdID=$this->GetIDForIdent('LASTUPD');// ID für LastUpd suchen 
      $lastUpdValue= GetValueInteger($lastUpdID);// WErt für LastUpd lesen
      $string=GetValueString($stringID);
      $DBLClickTime= $this->ReadPropertyInteger('DBLClickTime');
      IPS_LogMessage('DBLClick',"Wert eingelesen");
      
      if(strstr($string, "111")===FALSE){ //Falls Update nicht durch einfachen Klick verursacht
          IPS_LogMessage('DBLClick',"Update war kein Einfach-klick");
          IPS_SemaphoreLeave('DBLClick');
          exit ();
      }
      
      SetValueInteger($lastUpdID, $AktuelleZeit);
      IPS_LogMessage('DBLClick',"Aktuelle Zeit =".$AktuelleZeit);
      IPS_LogMessage('DBLClick',"Letzer Click bei =".$lastUpdValue);
      IPS_LogMessage('DBLClick',"Differenz =".($AktuelleZeit-$lastUpdValue));
      if(($AktuelleZeit-$lastUpdValue)<=$DBLClickTime){ 
	SetValueBoolean($DBLClickDetectID, true);
        IPS_LogMessage('DBLClick',"Doppelklick erkannt");
        $scriptID=$this->GetIDForIdent('SCRIPT');
        IPS_RunScript($scriptID);
      }
      else{
	//SetValueBoolean($DBLClickDetectID, false);
        IPS_LogMessage('DBLClick',"Doppelklick nicht erkannt");
      }
      IPS_SemaphoreLeave('DBLClick');
     } 
     else {
      IPS_LogMessage('DBLClick', 'Semaphore Timeout');
    }
   }
} 
?>
