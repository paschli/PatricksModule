<?
//Modul schaltet einen Ausgang (LCN-Ausgang, LCN-Lämpchen, LCN-Relais, entfernte Variable (JSON Zugriff) 


class AutSw extends IPSModule {
  
  
  //var $jsontest=0;  
    
  public function Create() {
    parent::Create();
      
    $this->RegisterPropertyInteger('Auswahl', 0); //Auswahl des Typs
    $this->RegisterPropertyInteger('idLCNInstance', 0); //ID der zu schaltenden Instanz
    $this->RegisterPropertyInteger('idVariable', 0); //ID der zu schaltenden Instanz
    $this->RegisterPropertyInteger('idStatus', 0); //ID des Status der zu schaltenden Instanz
    $this->RegisterPropertyBoolean('valStatus', FALSE); //Statuswert der zu schaltenden Instanz
    $this->RegisterPropertyInteger('LaempchenNr', 0); //Falls es Lämpchen sind
    $this->RegisterPropertyInteger('idLightInstance', 0); //Falls es Lämpchen sind
    $this->RegisterPropertyInteger('Rampe', 2); // Rampe für das Schalten eines LCN Ausgangs
    $this->RegisterPropertyString('IPAddress', ''); //IP Adesse für remote schalten eines anderen IP-Symcon
    $this->RegisterPropertyString('Password', '');// Passwort für JSON-Verbindung
    $this->RegisterPropertyInteger('ZielID', 0);// ID des zu schaltenden entfernten Objekts
    $this->RegisterPropertyString('Name','');//Otionaler Name für die erstellte Instanz
    $this->RegisterPropertyInteger('State', 0); //Status der Instanz
    $this->RegisterPropertyBoolean('AutoOff_Switch', FALSE);
    $this->RegisterPropertyBoolean('Timer_Switch', FALSE);
    $this->RegisterPropertyBoolean('WatchTarget',FALSE);
    $this->RegisterPropertyBoolean('SelAutoOff',FALSE);
    $this->RegisterPropertyBoolean('SelTimer',FALSE);
    $this->RegisterPropertyInteger('SliderAnz',0);
    $this->RegisterPropertyBoolean('TimerMsg',FALSE);
    $this->RegisterPropertyBoolean('AutoTime',FALSE);
    $this->RegisterVariableBoolean('Status','Status','~Switch');//
    $this->RegisterPropertyBoolean('Status', FALSE);
    IPS_SetIcon($this->GetIDForIdent('Status'), 'Light');
    
    
  }
  public function ApplyChanges() {
    parent::ApplyChanges();
    $this->EnableAction("Status");
    $instID= IPS_GetParent($this->GetIDforIdent('Status'));  
    if($this->ReadPropertyString('Name')!='')
        IPS_SetName($instID, $this->ReadPropertyString('Name'));
    $CatID = @IPS_GetCategoryIDByName('Konfig', $instID);
    if(!$CatID){    
//Kategorie erstellen 
    $CatID= $this->CreateCategorie($instID);    
//Auswahlvariable für Laufzeit erstellen
    $this->CreateAnzVar('SetLaufzeit','Set Laufzeit',$CatID,20,'Hourglass','<?SetValue($_IPS["VARIABLE"],$_IPS["VALUE"]); ?>','');
//Laufzeit Anzeige erstellen
    $this->CreateAnzVar('Laufzeit','Laufzeit',$instID,10,'Hourglass','','');
//Wahlschalter "AutoOff" erstellen
    $this->CreateWahlVar('AutoOff_Switch', 'Auto Off', '~Switch', $CatID, 10);
//Wahlschalter "Timer" erstellen        
    $this->CreateWahlVar('Timer_Switch', 'Timer', '~Switch', $CatID, 30);
    }
//Aktion, falls zu schaltendes Objekt von anderen Instanzen oder Schaltern geschaltet wird
    $scriptDevice="\$id = \$_IPS['TARGET'];\n".
                    'AutSw_EventTrigger($id, $id, GetValueBoolean(IPS_GetEvent($_IPS["EVENT"])["TriggerVariableID"]));';
    if($this->ReadPropertyInteger('idLCNInstance')){
        $typ= $this->ReadPropertyInteger('Auswahl');
    }
    else if($this->ReadPropertyInteger('ZielID'))
        $typ= $this->ReadPropertyInteger('Auswahl');
    else 
        $typ=0;
    
    
    if($this->ReadPropertyBoolean('SelAutoOff')){
        $this->RegisterTimer('AutoOffTimer', 60, "\$id = \$_IPS['TARGET'];\n".'AutSw_AutoOff($id);');
        $TimerID=$this->GetIDForIdent('AutoOffTimer');
        IPS_SetEventActive($TimerID, false);
        $AutoOffID=IPS_GetObjectIDByIdent('AutoOff_Switch', $CatID);
        IPS_SetHidden($AutoOffID, FALSE);
        $LaufzeitID= IPS_GetObjectIDByIdent('SetLaufzeit', $CatID);
        IPS_SetHidden($LaufzeitID, FALSE);
        
    }
    else{
        $TimerID=@$this->GetIDforIdent('AutoOffTimer');
        if($TimerID){
            IPS_SetEventActive($TimerID, False);   
        }
        $AutoOffID=IPS_GetObjectIDByIdent('AutoOff_Switch', $CatID);
        $LaufzeitID= IPS_GetObjectIDByIdent('SetLaufzeit', $CatID);
        IPS_SetHidden($AutoOffID, True);
        IPS_SetHidden($LaufzeitID, TRUE);
    }
    
    if($this->ReadPropertyBoolean('SelTimer')){
        $TimerSelID=IPS_GetObjectIDByIdent('Timer_Switch', $CatID);
        IPS_SetHidden($TimerSelID, FALSE);
    }
    else{
        $TimerSelID=IPS_GetObjectIDByIdent('Timer_Switch', $CatID);
        IPS_SetHidden($TimerSelID, TRUE);
    }
    
      
//Zusätzliche Aktionen für spezielle Typen    
    switch($typ){
            case 0: //falls Instanz nicht gewählt wurde
                break;
            case 1: //falls Instanz LCN Ausgang
                $this->CheckEvent($scriptDevice,1);//prüft ob Event vorhanden ist und setzt die Überwachung auf den Status der Instanz
                if(!@IPS_GetObjectIDByIdent('SliderAnz', $instID)){
                    $script='<?'.chr(13).
                            'SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);'.chr(13).
                            'LCN_SetIntensity('.$this->ReadPropertyInteger("idLCNInstance").', $_IPS["VALUE"],0);'.chr(13).
                            '?>';
                    $SliderID=$this->CreateAnzVar('SliderAnz', 'Slider', $instID, 20, 'Intensity',$script,'~Intensity.100' );
                    IPS_SetHidden($SliderID, FALSE);
                }
                break;
            case 2: //falls Instanz LCN Relais
                $this->CheckEvent($scriptDevice,1);//prüft ob Event vorhanden ist und setzt die Überwachung auf den Status der Instanz
                break;
            case 3: //falls Instanz LCN Lämpchen
                break;
            case 4: //falls Instanz Fernzugriff
                
                break;
            case 5: //falls Instanz Switch-Modul
                $this->CheckEvent($scriptDevice,1);//prüft ob Event vorhanden ist und setzt die Überwachung auf den Status der Instanz
                break;
            case 6://falls Instanz PIIOC
                break;
            case 7://falls Instanz Sonoff
                $this->CheckEvent($scriptDevice,2);//prüft ob Event vorhanden ist und setzt die Überwachung auf den Status der Instanz
                break;
            case 8://falls Instanz PI_GPIO_Output
                break;
            case 9://falls Instanz PI_MQTT_Output
                break;
            case 10://falls Instanz Zigbee2MQTT
               $this->CheckEvent($scriptDevice,1);//prüft ob Event vorhanden ist und setzt die Überwachung auf den Status der Instanz
                break;
            default:
                break;
        }
    if($typ!=1){
        if($SliderID=@IPS_GetObjectIDByIdent('SliderAnz', $instID)){
            if($scriptID=@IPS_GetObjectIDByName('control', $SliderID))
                IPS_DeleteScript ($scriptID, TRUE);
            IPS_DeleteVariable($SliderID);
        }
            
    }     
    
    $this->GetConfigurationForm(); 
  }
  
 

 public function GetConfigurationForm() {
     
    $status_entry='{ "code": 101, "icon": "inactive", "caption": "Instanz wird erstellt" },
             { "code": 102, "icon": "active", "caption": "Instanz aktiv" },
             { "code": 200, "icon": "error", "caption": "Instanz fehlerhaft" }'; 
     
     
    $elements_entry_device='
        { "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 },
            { "label": "Schalter", "value": 5 },
            { "label": "PIIOC", "value": 6 },
            { "label": "Sonoff", "value": 7 },
            { "label": "PI_GPIO_Output", "value": 8},
            { "label": "MQTT_Output", "value": 9},
            { "label": "Zigbee2MQTT", "value": 10}
          ]
        }';
    $elements_entry_lcnOutput=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
        { "type": "NumberSpinner", "name": "Rampe", "caption": "Sekunden" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
    $elements_entry_lcnRelais=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
    
    $elements_entry_Sonoff=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "Sonoff Instanz" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
    
    $elements_entry_PIGPIO=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "PIGPIO_OutputX Instanz" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
    $elements_entry_MQTT=',
    { "name": "idLCNInstance", "type": "SelectInstance", "caption": "MQTT_Set Instanz", "validVariableTypes": [1, 2],
        "requiredAction": 1,
        "requiredLogging": 1 },
    { "name": "idStatus", "type": "SelectVariable","validVariableTypes": [3], "caption": "MQTT_Output Instanz" },
    { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';

  $elements_entry_Zig2MQTT=',
    { "name": "idLCNInstance", "type": "SelectVariable", "caption": "MQTT_Set Variable","validVariableTypes": [0],
        "requiredAction": 1},
    { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';    

    $elements_entry_lcnLämpchen=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
        { "name": "LaempchenNr", "type": "Select", "caption": "Lämpchen Nr.", 
        "options":[
            { "label": "Lämpchen 1", "value": 1 },
            { "label": "Lämpchen 2", "value": 2 },
            { "label": "Lämpchen 3", "value": 3 },
            { "label": "Lämpchen 4", "value": 4 },
            { "label": "Lämpchen 5", "value": 5 },
            { "label": "Lämpchen 6", "value": 6 },
            { "label": "Lämpchen 7", "value": 7 },
            { "label": "Lämpchen 8", "value": 8 },
            { "label": "Lämpchen 9", "value": 9 },
            { "label": "Lämpchen 10", "value": 10 },
            { "label": "Lämpchen 11", "value": 11 },
            { "label": "Lämpchen 12", "value": 12 }
          ]
        },
        { "name": "idLightInstance", "type": "SelectInstance", "caption": "Instanz für Lamp Status" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
    $elements_entry_jsonZugriff=',
        { "type": "ValidationTextBox", "name": "IPAddress", "caption": "Host"},
        { "type": "PasswordTextBox", "name": "Password", "caption": "Passwort" },
        { "type": "NumberSpinner", "name": "ZielID", "caption": "Ziel ID"},
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
    $elements_entry_AutoOff=',{ "type": "CheckBox", "name": "SelAutoOff", "caption": "Countdown-Timer-Funktion hinzufügen" }';
    
    $elements_entry_Timer=',{ "type": "CheckBox", "name": "SelTimer", "caption": "Timer Funktion" }';
    
    $elements_entry_AutoOffWatch=',{ "type": "CheckBox", "name": "WatchTarget", "caption": "Ziel überwachen" }'; 
    
    $elements_entry_TimerMsg=',{ "type": "CheckBox", "name": "TimerMsg", "caption": "Nachricht bei Timer Event" }'; 
            
    $action_entry='';
    $action_entry1='{ "type": "Label", "label": "Bitte die zu steuernde Instanz wählen" },
          { "type": "Button", "label": "An", "onClick": "AutSw_SetOn($id);" },
          { "type": "Button", "label": "Aus", "onClick": "AutSw_SetOff($id);" }';
     
     
     
    $wahl=$this->ReadPropertyInteger('Auswahl');
    switch($wahl){
        case 0:  $elements_entry=$elements_entry_device; break;
        case 1:  $elements_entry=$elements_entry_device.$elements_entry_lcnOutput; break;
        case 2:  $elements_entry=$elements_entry_device.$elements_entry_lcnRelais; break;
        case 3:  $elements_entry=$elements_entry_device.$elements_entry_lcnLämpchen; break;
        case 4:  $elements_entry=$elements_entry_device.$elements_entry_jsonZugriff; break;
        case 5:  $elements_entry=$elements_entry_device.$elements_entry_lcnRelais; break;
        case 6:  $elements_entry=$elements_entry_device.$elements_entry_jsonZugriff; break;
        case 7:  $elements_entry=$elements_entry_device.$elements_entry_Sonoff; break;
        case 8:  $elements_entry=$elements_entry_device.$elements_entry_PIGPIO; break;
        case 9:  $elements_entry=$elements_entry_device.$elements_entry_MQTT; break;
        case 10: $elements_entry=$elements_entry_device.$elements_entry_Zig2MQTT; break; 
        
    }
//Option für WatchEvent - geht nur bei LCN-Instanz, LCN-Relais, Switch_Modul 
    if($this->ReadPropertyBoolean('SelAutoOff')&&($wahl!=3)&&($wahl!=4)&&($wahl!=6)){
        $elements_entry_AutoOff=$elements_entry_AutoOff.$elements_entry_AutoOffWatch; 
    } 
    else{
        $elements_entry_AutoOff=$elements_entry_AutoOff;
    }
//Option für AutoOff und Timer CheckBoxen        
    if($this->ReadPropertyInteger('idLCNInstance')){
        $action_entry=$action_entry1;
        $elements_entry=$elements_entry.$elements_entry_AutoOff.$elements_entry_Timer;
    }
    else if((($wahl==4)||($wahl==6))&&($this->ReadPropertyInteger('ZielID')>0)){
        if($this->checkVerb($wahl)==1){
            $action_entry=$action_entry1;
            $elements_entry=$elements_entry.$elements_entry_AutoOff.$elements_entry_Timer;
        }
    }
    else{
        $this->SendDebug("AutoSwitch_GetConfigurationForm","Konfiguration nicht vollständig!",0);
        $action_entry='';
    }
    if($this->ReadPropertyBoolean('SelTimer'))
        $elements_entry=$elements_entry.$elements_entry_TimerMsg;
    
    $form='{ "status":['.$status_entry.'],"elements":['.$elements_entry.'],"actions":['.$action_entry.']}';
    return $form;
      
} 

public function EventTrigger(int $par,bool $value) {
    $this->SendDebug("AutoSwitch_EventTrigger","Name: ".IPS_GetName($par)." Value: ".$value,0);
    $par= IPS_GetParent(($this->GetIDForIdent("Status")));
    $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
    if(IPS_GetObject($IDLaufz)['ObjectIsHidden']){
        $this->Set($value,TRUE);
        $this->SendDebug("AutoSwitch_EventTrigger","Set ausführen mit Anzeige",0);
        return 1;
    }
    $this->SendDebug("AutoSwitch_EventTrigger","Set ausführen ohne Anzeige",0);
    $this->Set($value,False);
    
}

 public function RequestAction($ident, $value) {
    if(IPS_SemaphoreEnter('AutoSwitch_RequestAction', 5000)) {
     $par= IPS_GetParent(($this->GetIDForIdent('Status')));

     $CatID =IPS_GetCategoryIDByName('Konfig', $par);
     
     if($ident=='AutoOff_Switch'){
        $this->SendDebug("AutoSwitch_RequestAction","AutoOff Einstellung geändert".$value,0);
        SetValue(IPS_GetObjectIDByIdent($ident, $CatID),$value);
        $timerID= @IPS_GetObjectIDByIdent('AutoOffTimer', $par);            //ID vom Timer_Event
        if($value){
            $LaufzeitID= IPS_GetVariableIDByName('Set Laufzeit', $CatID);   //Variable mit eingestellter Laufzeit finden
            $Laufzeit= GetValueInteger($LaufzeitID);                        //Laufzeit auslesen
            $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);            //ID für Zählervariable finden
            SetValueInteger($IDLaufz, $Laufzeit);                           //Laufzeit im Zähler setzen
            $eventID= @IPS_GetObjectIDByIdent('WatchTarget', $par);         //ID von event
            if($eventID){
                IPS_SetEventActive($eventID, True);                         //Event aktivieren
            }
            $ID_Status= @IPS_GetObjectIDByIdent('Status', $par);            //ID vom Status
            if(GetValueBoolean($ID_Status)){                                //Falls Gerät an,
                IPS_SetEventActive($timerID, TRUE);                         //Aktiviere Timer
            }
        }
        else{
            //$timerID= @IPS_GetObjectIDByIdent('AutoOffTimer', $par);      //wird bereits vor IF ermittelt
            if($timerID)
                IPS_SetEventActive($timerID, FALSE);

        }
     } 
     else if($ident=='Timer_Switch'){
         $this->SendDebug("AutoSwitch_RequestAction","Zeitplan Erreignis",0);
         SetValue(IPS_GetObjectIDByIdent($ident, $CatID),$value);
         $this->TimerSwitchAction($CatID); 
        //$this->Set($value);
     }
     else if($ident=='Status'){
        $this->SendDebug("AutoSwitch_RequestAction","Status-Variable geändert: ".$value,0);
        $LaufzeitID= IPS_GetVariableIDByName('Set Laufzeit', $CatID);
        $Laufzeit= GetValueInteger($LaufzeitID);
        $AutoOffID=IPS_GetObjectIDByIdent('AutoOff_Switch', $CatID);
        $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
        if($value && GetValueBoolean($AutoOffID)){
            $TimerID=@$this->GetIDForIdent('AutoOffTimer');
            if($TimerID)
                IPS_SetEventActive($TimerID, TRUE);
            SetValueInteger($IDLaufz, $Laufzeit);
            IPS_SetHidden($IDLaufz, FALSE);
            $this->SendDebug("AutoSwitch_RequestAction","Laufzeit zeigen",0);
        }
        else {
            IPS_SetHidden($IDLaufz, TRUE);
            $this->SendDebug("AutoSwitch_RequestAction","Laufzeit verbergen",0);
            
        }
        $this->Set($value,TRUE);
        
     }
     else if($ident=='SliderAnz'){
         $this->SendDebug("AutoSwitch_RequestAction","Slider Anzeige ".$value,0);
        SetValue(IPS_GetObjectIDByIdent($ident, $CatID),$value);
        $instID=$this->ReadPropertyInteger('idLCNInstance');
        LCN_SetIntensity($instID, $value, 0);
     }
     else if($ident=="AutoTime"){
         $this->SendDebug("AutoSwitch_RequestAction","Zeitplan verstellen",0);
         SetValue(IPS_GetObjectIDByIdent($ident, $CatID),$value);
         if($value){
            $this->AutoTimeUpdate($CatID,1);
         }
         else{
            $this->AutoTimeUpdate($CatID,0);
         }
         
               
         
     }
    IPS_SemaphoreLeave('AutoSwitch_RequestAction');
    } 
    else {
      $this->SendDebug('AutoSwitch_RequestAction', 'Semaphore Timeout',0);
    }
     
//Neuen Wert in die Statusvariable schreiben
      
}
public function Toggle(){
    $status= GetValueBoolean($this->GetIDForIdent('Status'));
    if($status)
        $this->SetOff ();
    else
        $this->SetOn ();
      
}

public function SetOn() {
      $this->Set(True,TRUE);
           
}

public function SetOff() {
      $this->Set(False,TRUE);

}

private function checkVerb($wahl) {
      $password= $this->ReadPropertyString('Password'); 
      $IPAddr= $this->ReadPropertyString('IPAddress');
      $TargetID=(integer) $this->ReadPropertyInteger('ZielID');
      $mes="http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/";
      //$this->SendDebug("AutoSwitch_Check","Aufruf:".$mes."Target ID".$TargetID);
      
      try {
          $rpc =@ new JSONRPC("http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/");
          if($wahl==4)
            @$rpc->GetValue($TargetID);
          else if($wahl==6)
            @$rpc->IPS_GetKernelVersion();
          else{
            $this->SendDebug("AutoSwitch_checkVerb","Verbindung konnte nicht verifiziert werden! Aufrufparameter falsch!",0);
            return 0;
          }
        } 
      catch (JSONRPCException $e) {
          $this->SendDebug("AutoSwitch_checkVerb","Verbindung konnte nicht verifiziert werden! RPC Problem!",0);
          //echo 'RPC Problem: ',  $e->getMessage(), "\n";
          return 0;
        } 
      catch (Exception $e) {
          $this->SendDebug("AutoSwitch_checkVerb","Verbindung konnte nicht verifiziert werden! IP- oder Passwort Problem!",0);
          return 0;
        }
        $this->SendDebug("AutoSwitch_checkVerb","Verbindung verifiziert!",0);
        return 1;
        
           
}     
      
public function AutoOff() {
    $par= IPS_GetParent(($this->GetIDForIdent('Status')));
    $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
    $Lauzeit= GetValueInteger($IDLaufz);
    $Lauzeit--;
    if($Lauzeit)
        SetValueInteger ($IDLaufz, $Lauzeit);
    else{
        SetValueInteger ($IDLaufz, $Lauzeit);
        IPS_SetHidden($IDLaufz, TRUE);
        $timerID= IPS_GetObjectIDByIdent('AutoOffTimer', $par);
        IPS_SetEventActive($timerID, FALSE);
        $this->Set(FALSE,TRUE);
    } 
    if(!GetValueBoolean($this->GetIDForIdent('Status'))){
        IPS_SetHidden($IDLaufz, TRUE);
        $timerID= IPS_GetObjectIDByIdent('AutoOffTimer', $par);
        IPS_SetEventActive($timerID, FALSE);
        
    }
}

protected function RegisterTimer($ident, $interval, $script) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    
    if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
      IPS_DeleteEvent($id);
      $id = 0;
    }
    if (!$id) {
      $id = IPS_CreateEvent(1);
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
      $this->SendDebug("AutoSwitch_RegisterTimer","Timer ".$id." erstellt",0);
    }
    
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, $script);
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
    if (!($interval > 0)) {
        IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, 1);
        IPS_SetEventActive($id, false);
    } 
    else {
        IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, $interval);
        IPS_SetEventActive($id, true);
    }
  }

  protected function RegisterEvent($ident,$ZielID, $script) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    if ($id && IPS_GetEvent($id)['EventType'] <> 0) {
      IPS_DeleteEvent($id);
      $id = 0;
    }
    if (!$id) {
      $id = IPS_CreateEvent(0);
      IPS_SetEventTrigger($id, 1, $ZielID); //Bei Update von der gewählten Variable 
      IPS_SetEventActive($id, true);             //Ereignis aktivieren
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
      $this->SendDebug("AutoSwitch_RegisterEvent","Event ".$id." erstellt",0);
    }
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, $script);
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
    return($id);
  }
  
  
  public function Set_Timer(int $Laufzeit) {
    $this->SendDebug("AutoSwitch_Set_Timer","Laufzeit:".$Laufzeit,0);
    $par= IPS_GetParent(($this->GetIDForIdent("Status")));
    $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
    $TimerID=@$this->GetIDForIdent('AutoOffTimer');
    if($Laufzeit>0){
        if($TimerID){
            IPS_SetEventActive($TimerID, TRUE);
        }
        SetValueInteger($IDLaufz, $Laufzeit);
        IPS_SetHidden($IDLaufz, FALSE);
        $this->Set(TRUE,FALSE);
    }
    else{
        $this->Set(FALSE,TRUE);
    }
    $this->SendDebug("AutoSwitch_Set_Timer","Funktion Beendet",0);
  }
  
public function Set(bool $value, bool $anzeige) {
    $func="Set_";
    $typ= $this->ReadPropertyInteger('Auswahl');
    $sem_id='AutoSwitch_Set_'.$typ;
    $par= IPS_GetParent(($this->GetIDForIdent('Status')));
    $name= IPS_GetName($par);
//    if(IPS_SemaphoreEnter('AutoSwitch_Set', 15000)) {
    if(IPS_SemaphoreEnter($sem_id, 15000)) {    
//      $this->SendDebug("AutoSwitch_".$func,"Semaphore: ".$sem_id." gesetzt!");

      $func="Set_".$name;
      $this->SendDebug("AutoSwitch_".$func,"Semaphore: ".$sem_id." gesetzt!",0);
      $CatID =IPS_GetCategoryIDByName('Konfig', $par);
      $value_dim=0;
      
      $this->SendDebug("AutoSwitch_".$func,"Set für ".$name." aufgerufen mit ". $this->boolToString($value)."!",0);
      $EventID=@IPS_GetObjectIDByIdent('WatchEvent', $this->InstanceID);
      if($EventID){
          IPS_SetEventActive($EventID,false);
          $this->SendDebug("AutoSwitch_".$func,"WatchEvent deaktivieren!",0);
      }
      switch($typ){
        case 0: 
            $result=0;
        break;

        case 1: 
          //for($i = 1 ; $i <= 3 ; $i++){
              $result=$this->Set_LCN_Dim($value);
              $this->SendDebug('AutoSwitch_Set_LCN_Out', 'Aktion ausgeführt. Ergebnis= '.$result,0);
            //  if($result==1)
            //      break;
          //}
        break;
          
        case 2: 
          //for($i = 1 ; $i <= 3 ; $i++){
              $result=$this->Set_LCN_Rel($value);
              $this->SendDebug('AutoSwitch_Set_LCN_Relais', 'Aktion ausgeführt. Ergebnis= '.$result,0);
          //    if($result==1)
          //        break;
          //}

        break;
        
        case 3: 
          $this->Set_LCN_Lamp($value); 
            $result=1;
          break;
      
        case 4: 
          //for($i = 1 ; $i <= 3 ; $i++){
              $result=$this->Set_JSON($value,$sem_id);
              $this->SendDebug('AutoSwitch_Set_JSON', 'Aktion ausgeführt. Ergebnis= '.$result,0);
           //   if($result==1)
           //       break;
          //}
        break;
        
        case 5: 
          $this->Set_Schalter($value);
          $result=1;
           
        break; 
    
        case 6: 
          $this->Set_PIIOC($value,$sem_id);
          $result=1;
        break;
      
        case 7:
          //for($i = 1 ; $i <= 3 ; $i++){
              $result=$this->Set_Tasmota($value);
              $this->SendDebug('AutoSwitch_Set_Tasmota', 'Aktion ausgeführt. Ergebnis= '.$result,0);
          //    if($result==1)
          //        break;
         // }
        break;
        
        case 8:
          //for($i = 1 ; $i <= 3 ; $i++){
              $result=$this->Set_PIGPIO($value);
              $this->SendDebug('AutoSwitch_Set_PIGPIO', 'Aktion ausgeführt. Ergebnis= '.$result,0);
          //    if($result==1)
          //        break;
          //}
        case 9:
        //for($i = 1 ; $i <= 3 ; $i++){
            $result=$this->Set_MQTT($value);
            $this->SendDebug('AutoSwitch_Set_MQTT', 'Aktion ausgeführt. Ergebnis= '.$result,0);
        //    if($result==1)
        //        break;
        //}
        break;
        case 10:
        //for($i = 1 ; $i <= 3 ; $i++){
            $result=$this->Set_Zig2MQTT($value);
            $this->SendDebug('AutoSwitch_Set_Zig2MQTT', 'Aktion ausgeführt. Ergebnis= '.$result,0);
        //    if($result==1)
        //        break;
        //}
        break;
        default: 
          $result=0;
        break;
      }
      if($result==1){
          $this->SendDebug('AutoSwitch_Set', 'Aktion erfolgreich!',0);
          if($this->ReadPropertyBoolean('TimerMsg')){
              $value ? WFC_PushNotification(33722, "Info AutoSwitchModul", $name . " erfolgreich eingeschaltet", "", 0):
                       WFC_PushNotification(33722, "Info AutoSwitchModul", $name . " erfolgreich ausgeschaltet", "", 0);
          }
      }
      else{
          $this->SendDebug('AutoSwitch_Set', 'Aktion fehlgeschlagen!',0);
          $wert=$this->boolToString($value);
          WFC_PushNotification(33722, "Info AutoSwitchModul", "Fehler bei SET für ".$name."/ Sollwert= ".$wert." / Typ=".$typ, "", 0);
          IPS_SemaphoreLeave($sem_id);
          exit();
      }
      if($EventID){
          IPS_SetEventActive($EventID,true);
          $this->SendDebug("AutoSwitch_".$func,"WatchEvent aktivieren!",0);
      }
      $AutoTimeID=@IPS_GetObjectIDByIdent('AutoTime', $CatID);
      if(($AutoTimeID)){
          if(GetValueBoolean($AutoTimeID))
            $this->AutoTimeUpdate($CatID,1);
      }
      
      if($anzeige){
        $AutoOffID=IPS_GetObjectIDByIdent('AutoOff_Switch', $CatID);
        $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
        if($value && GetValueBoolean($AutoOffID) && $this->ReadPropertyBoolean('SelAutoOff')){
            $LaufzeitID= IPS_GetVariableIDByName('Set Laufzeit', $CatID);
            $Laufzeit= GetValueInteger($LaufzeitID);
            $TimerID=@$this->GetIDForIdent('AutoOffTimer');
            if($TimerID)
                IPS_SetEventActive($TimerID, TRUE);
            SetValueInteger($IDLaufz, $Laufzeit);
            IPS_SetHidden($IDLaufz, FALSE);
            $this->SendDebug("AutoSwitch_Set","Laufzeit zeigen",0);
        }
        else{
            IPS_SetHidden($IDLaufz, TRUE);
            $this->SendDebug("AutoSwitch_Set","Laufzeit verbergen",0);
        }
      }
      else {
        if(!$value){
           $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
           IPS_SetHidden($IDLaufz, TRUE);
           $this->SendDebug("AutoSwitch_Set","Laufzeit verbergen",0);
        }
      }    
//      IPS_SemaphoreLeave('AutoSwitch_Set');
      IPS_SemaphoreLeave($sem_id);
      $this->SendDebug("AutoSwitch_".$func,"Semaphore: ".$sem_id." verlassen!",0);
     }
     
     else {
      $this->SendDebug('AutoSwitch_Set', 'Semaphore Timeout',0);
      WFC_PushNotification(33722, "Info AutoSwitchModul", $name . " Semaphore Timeout", "", 0);
    }
   }

private function Set_LCN_Dim($value) {
    $instID=$this->ReadPropertyInteger('idLCNInstance');
    $dim_time= $this->ReadPropertyInteger('Rampe');
    $SliderID=@$this->GetIDForIdent('SliderAnz');
    if($value){
        LCN_SetIntensity($instID, 100, $dim_time);
        SetValueInteger($SliderID, 100);
    }
    else {
        LCN_SetIntensity($instID, 0, $dim_time);
        SetValueInteger($SliderID, 0);
    }
    if($dim_time){
        sleep($dim_time);
    }
    else {
        usleep(100000);
    }
    usleep(100000);
    $status_id= $this->get_status_id($instID,'Status');
    $wert=$this->boolToString($status_id);
    $this->SendDebug('AutoSwitch_Set_LCN_Dim', 'Status für '.$instID.' = '.$wert,0);

    if($status_id==$value){
        SetValue($this->GetIDForIdent("Status"), $status_id);
        return 1;
    }
    else {
        return 0;
    }
}   
   
private function Set_LCN_Rel($value) {
    $instID=$this->ReadPropertyInteger('idLCNInstance');
    LCN_SwitchRelay($instID, $value);
    usleep(200000);
    $status= $this->get_status_id($instID,'Status');
    $this->SendDebug('Set_LCN_Rel', 'Status= '.$status.' Value= '.$value,0);
    
    if($status==$value){
        SetValue($this->GetIDForIdent("Status"), $status);
        return 1;
    }
    else {
        return 0;
    }
    
}

private function Set_LCN_Lamp($value) {
    $lcn_instID=$this->ReadPropertyInteger('idLCNInstance');
    $lampNo=$this->ReadPropertyInteger('LaempchenNr');
    $check=0;
    if($this->ReadpropertyInteger('idLightInstance')){
        $check=1;
        $idcheckLamp=$this->ReadPropertyInteger('idLightInstance');
        $this->SendDebug("AutoSwitch_Set_LCN_Lamp","Mit Check!",0);
    }
    
    for ($i = 0; $i < 3; $i++) {
        if($value){
            $this->SendDebug("AutoSwitch_Set_LCN_Lamp","Schreibe: Id: ".$lcn_instID." - Tableau Licht: ".$lampNo." = E",0);
            LCN_SetLamp($lcn_instID,$lampNo,'E');
            $lamp_status='E';
            
        }
        else{
            $this->SendDebug("AutoSwitch_Set_LCN_Lamp","Schreibe: Id: ".$lcn_instID." - Tableau Licht: ".$lampNo." = A",0);
            LCN_SetLamp($lcn_instID,$lampNo,'A');
            $lamp_status='A';
        }
        if($check==0){
            $this->SendDebug("AutoSwitch_Set_LCN_Lamp","Befehl ohne Check ausgeführt",0);
        }
        else if(($this->Check_LCN_Lamp($idcheckLamp,$lampNo,$lamp_status))){
            $this->SendDebug("AutoSwitch_Set_LCN_Lamp","Befehl erfolgreich ausgeführt! (".($i+1)." Versuch(e))",0);
            SetValue($this->GetIDForIdent("Status"), $value);
            break;
        }
        else {
            $this->SendDebug("AutoSwitch_Set_LCN_Lamp","Befehl konnte nicht erfolgreich ausgeführt werden!",0);
        }
    }
    
}

private function Check_LCN_Lamp($idcheckLamp,$lampNo,$lamp_value) {
    LCN_RequestLights($idcheckLamp);
    $this->SendDebug("AutoSwitch_Check_LCN_Lamp","Überprüfe Ausführung...",0);
    foreach (IPS_GetChildrenIDs($idcheckLamp) as $element) {
//        $this->SendDebug("AutoSwitch_Check_LCN_Lamp","Checke:".IPS_GetName($element)." - Tableau Licht ".(string)$lampNo);
        if(strstr(IPS_GetName($element),'Tableau Licht '.(string)$lampNo)){
            $this->SendDebug("AutoSwitch_Check_LCN_Lamp","Ist-Wert= ".GetValueString($element)." / Soll-Wert= ".$lamp_value,0);
            if(GetValueString($element)==$lamp_value){
                
              return 1;  
            }
            
        }
    }
    return 0;
}

private function Set_JSON($value,$sem_id) {
    $password= $this->ReadPropertyString('Password'); 
    $IPAddr= $this->ReadPropertyString('IPAddress');
    $TargetID=(integer) $this->ReadPropertyInteger('ZielID');
    $mes="http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/";
    $this->SendDebug("AutoSwitch_Set","Aufruf".$mes,0);
    $this->SendDebug("AutoSwitch_Set","Target ID".$TargetID,0);
    try {
        $rpc = new JSONRPC("http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/");
        if($value){
            //$this->SendDebug(Modul,"Value = True => Relais An");
            $rpc->SetValue($TargetID, true);
        }           
        else{
            //$this->SendDebug(Modul,"Value = False => Relais Aus");
            $rpc->SetValue($TargetID, false);
        }
    }
    catch (JSONRPCException $e) {
        echo 'RPC Problem', "\n";
        //IPS_SemaphoreLeave('AutoSwitch_Set');
        IPS_SemaphoreLeave($sem_id);
        $this->SendDebug('AutoSwitch_Set', 'RPC Fehler',0);
        return 0;
    } 
    catch (Exception $e) {
       echo 'Server Problem',"\n";
       //IPS_SemaphoreLeave('AutoSwitch_Set');
       IPS_SemaphoreLeave($sem_id);
       $this->SendDebug('AutoSwitch_Set', 'Verbindungsfehler',0);
       return 0;
    }

    $result=(bool)$rpc->GetValue($TargetID);
    if($result==$value){
        SetValue($this->GetIDForIdent("Status"), $result);
        return 1;
    }
    else {
        return 0;
    }
    
    
}

private function Set_Schalter($value) {
    $lcn_instID=$this->ReadPropertyInteger('idLCNInstance');
    if($value){
        $this->SendDebug("AutoSwitch_Set","Aufruf AN Schalter_Set ID=".$lcn_instID,0);
        Schalter_Set($lcn_instID,1);
    }
    else{
        $this->SendDebug("AutoSwitch_Set","Aufruf AUS Schalter_Set ID=".$lcn_instID,0);
        Schalter_Set($lcn_instID,0);
    }
    SetValue($this->GetIDForIdent("Status"), $value);
}

private function Set_PIIOC($value,$sem_id) {
    $password= $this->ReadPropertyString('Password'); 
    $IPAddr= $this->ReadPropertyString('IPAddress');
    $TargetID=(integer) $this->ReadPropertyInteger('ZielID');
    $mes="http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/";
    $this->SendDebug("AutoSwitch_Set","Aufruf".$mes,0);
    $this->SendDebug("AutoSwitch_Set","Target ID".$TargetID,0);
    try{
        $rpc = new JSONRPC("http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/");
        if($value){
            //$this->SendDebug(Modul,"Value = True => Relais An");
            $rpc->PIIOC_set($TargetID);
        }           
        else{
            //$this->SendDebug(Modul,"Value = False => Relais Aus");
            $rpc->PIIOC_clear($TargetID);
        }

    }
    catch (JSONRPCException $e) {
        echo 'RPC Problem', "\n";
        //IPS_SemaphoreLeave('AutoSwitch_Set');
        IPS_SemaphoreLeave($sem_id);
        $this->SendDebug('AutoSwitch_Set', 'RPC Fehler',0);
        return 0;
    } 
    catch (Exception $e) {
       echo 'Server Problem',"\n";
       //IPS_SemaphoreLeave('AutoSwitch_Set');
       IPS_SemaphoreLeave($sem_id);
       $this->SendDebug('AutoSwitch_Set', 'Verbindungsfehler',0);
       return 0;
    }

    SetValue($this->GetIDForIdent("Status"), $value);
    $this->SendDebug('AutoSwitch_Set', 'Verbindung erfolgreich!',0);
}

private function Set_Tasmota($value) {
    $instID=$this->ReadPropertyInteger('idLCNInstance');
    $value ? Tasmota_setPower($instID, 1, 1) : Tasmota_setPower($instID, 1, 0);
    sleep(1);
    $status_id= $this->get_status_id($instID,'POWER');
    
    if($status_id==$value){
        SetValue($this->GetIDForIdent("Status"), $status_id);
        return 1;
    }
    else {
        return 0;
    }
}

private function Set_PIGPIO($value) {
    $instID=$this->ReadPropertyInteger('idLCNInstance');
    I2GOUT_Set_Status($instID, $value);
    usleep(100000);
    $status_id= $this->get_status_id($instID,'Status');
    if($status_id==$value){
        SetValue($this->GetIDForIdent("Status"), $status_id);
        return 1;
    }
    else {
        return 0;
    }
    //return $result;
}
        
private function GetCommandValue($type,$value){

        switch($type){
            case 0: //Bool
                    $commandValue= ($value == 0) ? false : true ;
                    break;
            case 1: //Integer
                    $commandValue=-1;
                    break;
            case 2: //Float
                    $commandValue=-1;
                    break;
            case 3: //String
                    $commandValue= ($value == 0) ? "OFF" : "ON" ;
                    break;
            default: $commandValue=-1;
        }
    return $commandValue;
}
private function Set_MQTT($value) {
        $SetID=IPS_GetChildrenIDs($this->ReadPropertyInteger('idLCNInstance'))[0];
        $StatusID=$this->ReadPropertyInteger('idStatus');
        $setType=IPS_GetVariable($SetID)['VariableType'];
        $commandValue=$this->GetCommandValue($setType,$value);
        if($commandValue===-1){
            $this->SendDebug('AutoSwitch_Set_MQTT', 'Konnte $commandValue nicht ermitteln -> Exit ($setType='.$setType.')',0);
            exit();
        }
        
        RequestAction($SetID, $commandValue);
        //Änderungen 1.10.2025 Wiederholung entfernt
        usleep(500000); //notwendig???
    
        $result=GetValueString($this->ReadPropertyInteger('idStatus'));
        if($setType==0){
            $commandValue= $commandValue ? "ON" : "OFF";
        }
        $this->SendDebug('AutoSwitch_Set_MQTT', 'Ist-Status ('.$this->ReadPropertyInteger('idStatus').') = '.$result.' / Soll Status='.$commandValue,0);
        
        if($result==$commandValue){
            SetValue($this->GetIDForIdent("Status"), $value);
            return 1;
        }
        else {
            return 0;
        }
        
        //return 1;
    }

    private function Set_Zig2MQTT($value) {
        //$SetID=IPS_GetChildrenIDs($this->ReadPropertyInteger('idLCNInstance'))[0];
        $SetID=$this->ReadPropertyInteger('idLCNInstance');
        //$StatusID=$this->ReadPropertyInteger('idStatus');
        /*switch($value){
            case 0: $commandValue="OFF";
                break;
            case 1: $commandValue="ON";
                break;
            default: $commandValue="OFF";
        }*/
        RequestAction($SetID, $value);
        usleep(500000);
        $StatusValue=GetValueBoolean($SetID);
        if($StatusValue==$value){
            SetValue($this->GetIDForIdent("Status"), $value);
            return 1;
        }
        else {
            return 0;
        }
    }
    
private function get_status_id($id, $name){ //!!!!!!!!!!!!!!!!!!!Hier besser nach Ident suchen!!!!!!!!!!!!!!!!!!!!!!!!!
    $arr=IPS_GetChildrenIDs($id);
    $status_id=0;
    foreach($arr as $child){
        if(IPS_GetName($child)==$name) 
            $status_id=$child;
    }
    $this->SendDebug("get_status_id","Status_Id = ".$status_id,0);
    return GetValueBoolean($status_id);
}
    
private function get_mqtt_status($id, $name){//!!!!!!!!!!!!!!!!!!Gleich wie die obere Funktion????? !!!!!!!!!!!!!!!!!!!
    $arr=IPS_GetChildrenIDs($id);
    $status_id=0;
    foreach($arr as $child){
        if(IPS_GetName($child)==$name)
            $status_id=$child;
    }
    return GetValueBoolean($status_id);
}

private function boolToString($boolVal){
  return ($boolVal ? 'true' : 'false');
}

private function CreateCategorie($instID) {
    $CatID = IPS_CreateCategory();       // Kategorie anlegen
    IPS_SetName($CatID, "Konfig"); // Kategorie benennen
    IPS_SetParent($CatID,$instID ); // Kategorie einsortieren unter dem Objekt 
    IPS_SetIcon($CatID, 'Gear'); //Icon setzen
    return($CatID);
   }
private function CreateAnzVar($ident,$name,$CatID,$pos,$icon,$script,$profil){
    $VarID= IPS_CreateVariable(1);
    IPS_SetName($VarID, $name); // Variable benennen
    IPS_SetPosition($VarID, $pos);
    IPS_SetIcon($VarID, $icon);
    IPS_SetParent($VarID,$CatID );
    IPS_SetIdent($VarID,$ident);
    if($script){
        $SkriptID=IPS_CreateScript(0);
        IPS_SetName($SkriptID,'control');
        IPS_SetParent($SkriptID,$VarID);
        IPS_SetHidden($SkriptID, True);
        IPS_SetScriptContent($SkriptID, $script);
        IPS_SetVariableCustomAction($VarID, $SkriptID);
        if ($profil!=''){
            IPS_SetVariableCustomProfile($VarID, $profil);
        }
        else    
        IPS_SetVariableCustomProfile($VarID, 'Time_4h');   
    }
    else {
        IPS_SetHidden($VarID, True);
    }
    return($VarID);    
}

private function CreateWahlVar($ident,$name,$icon,$par, $pos){
    $ID=$this->RegisterVariableBoolean($ident,$name,$icon);//
    //$this->RegisterPropertyBoolean($ident, FALSE); 
    IPS_SetPosition($ID, $pos);
    $this->EnableAction($ident);
    IPS_SetParent($ID,$par );
    return($ID);
}
        

private function FindTargetStatusofDevices($type) {
    
// ID der zu steuernden Instanz ermitteln
    //$Reference = IPS_GetScriptThread()['ScriptID'];
    $ZielID= $this->ReadPropertyInteger('idLCNInstance');
    $this->SendDebug("FindTargetStatusofDevices","Suche Id vom Typ= ".$type." bei ID=".$ZielID,0);
//Children dieser Instanz ermitteln
    $ID_Children=IPS_GetChildrenIds($ZielID);
    switch($type){
            case 1: $target="Status";
                    break;
            case 2: $target="POWER";
                    break;
    }
//Children durchsuchen
    if(!empty($ID_Children)){
        $this->SendDebug("AutoSwitch_FindTargetStatusofDevices","Children von ".$ZielID." gefunden mit ".count($ID_Children),0);
        for($i=0;$i<=count($ID_Children)-1;$i++){
        //Falls "Status" gefunden wird
            if(IPS_GetName($ID_Children[$i])==$target){//Suche nach Child mit Bezeichnung Status oder Power
                $test_variable=$ID_Children[$i];
                $this->SendDebug("AutoSwitch_FindTargetStatusofDevices","Gefunden: Variable = ".$ID_Children[$i]." Typ = ".IPS_GetVariable($test_variable)['VariableType'],0);
                return($test_variable);
            }
              
        }
    }
    else{
        $this->SendDebug("AutoSwitch_FindTargetStatusofDevices","Variable = ".$ZielID,0);
        return($ZielID);

    }
    
    $this->SendDebug("AutoSwitch_FindTargetStatusofDevices","Keine ID gefunden!",0);
    return(0);
}

private function CheckEvent($script,$type) {
    $this->SendDebug("AutoSwitch_CheckEvent","Start",0);
    $EventID=@IPS_GetObjectIDByIdent('WatchEvent', $this->InstanceID);
    if($EventID){
        $this->SendDebug("AutoSwitch_CheckEvent","Lösche altes WatchEvent",0);
        IPS_DeleteEvent($EventID);
    }    
    $ID=$this->FindTargetStatusofDevices($type);
    if($ID){
        $this->SendDebug("AutoSwitch_CheckEvent","registriere WatchEvent",0);
        $EventID=$this->RegisterEvent('WatchEvent', $ID, $script);
    }
    
    //IPS_SetEventActive($EventID, FALSE);
}

private function TimerSwitchAction($CatID) {
    //Timer
    $T_Switch_Val=GetValue(IPS_GetObjectIDByIdent('Timer_Switch', $CatID));
    $eventScript="\$id = \$_IPS['TARGET'];\n".'$idp = IPS_GetParent($id);';
    
    $esOn="\n".'AutSw_SetOn($idp);';
    $esOff="\n".'AutSw_SetOff($idp);';
    
    $eventScriptOn=$eventScript.$esOn;
    $eventScriptOff=$eventScript.$esOff;
    
    if($this->ReadPropertyBoolean('SelTimer'))    
    if($T_Switch_Val){
        if(!@IPS_GetObjectIDByIdent('AutoTime', $CatID))
            $this->CreateWahlVar('AutoTime', 'Dämerungsautomatik', '~Switch', $CatID, 80);
        else {
            $AutoTimeID=@IPS_GetObjectIDByIdent('AutoTime', $CatID);
            IPS_SetHidden($AutoTimeID, FALSE);
        }
        $Set_1_ID=@IPS_GetObjectIDByIdent('Set_1', $CatID);
        if(!$Set_1_ID){
            $this->CreateTimeEvent('Set_1', $CatID, 40, $eventScriptOn);
        }
        else
            IPS_SetHidden ($Set_1_ID, FALSE);
        
        $Clear_1_ID=@IPS_GetObjectIDByIdent('Clear_1', $CatID);
        if(!$Clear_1_ID){
            $eventScript=$eventScript.$esOff;
            $this->CreateTimeEvent('Clear_1', $CatID, 50, $eventScriptOff);   
        }
        else
            IPS_SetHidden ($Clear_1_ID, FALSE);
        
        $Set_2_ID=@IPS_GetObjectIDByIdent('Set_2', $CatID);
        if(!$Set_2_ID){
            $this->CreateTimeEvent('Set_2', $CatID, 60, $eventScriptOn);   
        }
        else
            IPS_SetHidden ($Set_2_ID, FALSE);
        
        $Clear_2_ID=@IPS_GetObjectIDByIdent('Clear_2', $CatID);
        if(!$Clear_2_ID){
            $this->CreateTimeEvent('Clear_2', $CatID, 70, $eventScriptOff);   
        }
        else
            IPS_SetHidden ($Clear_2_ID, FALSE);  
    }
    else{
        $AutoTimeID=@IPS_GetObjectIDByIdent('AutoTime', $CatID);
        if($AutoTimeID){
            IPS_SetHidden($AutoTimeID, TRUE);
        }
            
        
        $Set_1_ID=@IPS_GetObjectIDByIdent('Set_1', $CatID);
        if($Set_1_ID){
            IPS_SetHidden ($Set_1_ID, TRUE);
            IPS_SetEventActive($Set_1_ID, FALSE);    
        }
        
        $Clear_1_ID=@IPS_GetObjectIDByIdent('Clear_1', $CatID);
        if($Clear_1_ID){
            IPS_SetHidden ($Clear_1_ID, TRUE);
            IPS_SetEventActive($Clear_1_ID, FALSE);  
        }
        
        $Set_2_ID=@IPS_GetObjectIDByIdent('Set_2', $CatID);
        if($Set_2_ID){
            IPS_SetHidden ($Set_2_ID, TRUE);
            IPS_SetEventActive($Set_2_ID, FALSE);
        }
        
        $Clear_2_ID=@IPS_GetObjectIDByIdent('Clear_2', $CatID);
        if($Clear_2_ID){
            IPS_SetHidden ($Clear_2_ID, TRUE);
            IPS_SetEventActive($Clear_2_ID, FALSE);
        }
    }
    
}

private function CreateTimeEvent($ident, $parentID, $Position, $content){
    $eid= IPS_CreateEvent(1);
    IPS_SetEventCyclic($eid, 3, 1, 127, 1, 0, 2);
    IPS_SetParent($eid, $parentID);
    IPS_SetIcon($eid, 'Clock');
    IPS_SetIdent($eid, $ident);
    IPS_SetName($eid, $ident);
    IPS_SetEventActive($eid, FALSE);
    IPS_SetPosition($eid, $Position);
    IPS_SetEventScript($eid, $content);  
 }

 private function AutoTimeUpdate($CatID, $value) {
//Dämmerungszeit Früh kopieren
$this->SendDebug("AutoSwitch_AutoTimeUpdate","Start",0);
$ids=IPS_GetEventIDByName('Set_2', $CatID);
$idf=IPS_GetEventIDByName('Clear_1', $CatID);
if($value){ 
//Dämmerungszeit Früh
    $ID_LocationControl=IPS_GetObjectIDByName('Location Control', 0);
    //$ID_LocationControl=33556;
    $this->SendDebug("AutoSwitch_AutoTimeUpdate","ID_Location=".$ID_LocationControl,0);
    $ID_Früh= IPS_GetObjectIDByIdent('Sunrise', $ID_LocationControl);
    $this->SendDebug("AutoSwitch_AutoTimeUpdate","ID_Früh=".$ID_Früh,0);
    $timestamp = GetValueInteger($ID_Früh);
    $Stunde = date("H", $timestamp);
    $Minute = date("i", $timestamp);
    $Sekunde = date("s", $timestamp);
    $ids2=IPS_GetEventIDByName('Set_1', $CatID);
    $this->SendDebug("AutoSwitch_AutoTimeUpdate","EventActive = "
                .(int)IPS_GetEvent($ids2)['EventActive'],0);
    if(IPS_GetEvent($ids2)['EventActive']){
        $this->SendDebug("AutoSwitch_AutoTimeUpdate","Event = "
                .$idf." Zeit = ".$Stunde.":".$Minute.":".$Sekunde,0);
        IPS_SetEventCyclicTimeFrom($idf, $Stunde, $Minute, $Sekunde);
        IPS_SetEventActive($idf, TRUE);
    }
    else{
        IPS_SetEventActive ($idf, FALSE);
        $this->SendDebug("AutoSwitch_AutoTimeUpdate","Clear Event!",0);
    }
    
//Dämmerungszeit Spät
    $ID_Spät=@IPS_GetObjectIDByIdent('CivilTwilightEnd', $ID_LocationControl);
    $this->SendDebug("AutoSwitch_AutoTimeUpdate","ID_Spät=".$ID_Spät,0);
    $timestamp = GetValueInteger($ID_Spät);
    $Stunde = date("H", $timestamp);
    $Minute = date("i", $timestamp);
    $Sekunde = date("s", $timestamp);
    $ids=IPS_GetEventIDByName('Set_2', $CatID);
    $idf2=IPS_GetEventIDByName('Clear_2', $CatID);
    $this->SendDebug("AutoSwitch_AutoTimeUpdate","EventActive = "
                .(int)IPS_GetEvent($idf2)['EventActive'],0);
    if(IPS_GetEvent($idf2)['EventActive']){
        $this->SendDebug("AutoSwitch_AutoTimeUpdate","Event = "
                .$idf2." Zeit = ".$Stunde.":".$Minute.":".$Sekunde,0);
        IPS_SetEventCyclicTimeFrom($ids, $Stunde, $Minute, $Sekunde);
        IPS_SetEventActive($ids, TRUE);
    }
    else{
        IPS_SetEventActive ($ids, FALSE);
        $this->SendDebug("AutoSwitch_AutoTimeUpdate","Clear Event!",0);
    }
    IPS_SetEventCyclicTimeFrom($ids, $Stunde, $Minute, $Sekunde);
//    IPS_SetDisabled($idf, true);
//    IPS_SetDisabled($ids, true);
    IPS_SetEventActive($ids, TRUE);
    IPS_SetEventActive($idf, TRUE);
}
else{
    IPS_SetDisabled($idf, false);
    IPS_SetEventActive($idf, FALSE);
    IPS_SetDisabled($ids, false);
    IPS_SetEventActive($ids, FALSE);
}
 
}
} 
?>
